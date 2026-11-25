<?php
// This file is part of Moodle - http://moodle.org/

namespace local_microlearning\local;

/**
 * Imports one pipeline bundle into the plugin tables.
 *
 * The bundle (written by `python -m moodle_sync bundle`) is a directory with
 * manifest.json and six CSV files. Nothing is computed here: the plugin
 * stores what the pipeline produced. Two kinds exist:
 *
 *  - snapshot: every learner of the course. It becomes the course's active
 *    snapshot; the previous one is kept, older ones are deleted.
 *  - learner_update: the learners named in the manifest (a per-learner
 *    refresh). Their rows in the active snapshot are replaced; nobody else's
 *    rows are touched.
 *
 * A bundle is rejected as a whole when it does not belong to the course
 * (wrong course id, unknown users or course modules, e.g. a bundle made
 * before the course was rebuilt) or was damaged in transit (checksum
 * mismatch). Either import marks the learners' pending refresh requests as
 * processed when the bundle's data is at least as new as the request.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class importer {
    /** @var int Bundle format this plugin version understands. */
    const SCHEMA_VERSION = 3;

    /** @var string A bundle with every learner of the course. */
    const KIND_SNAPSHOT = 'snapshot';

    /** @var string A bundle with the refreshed learners only. */
    const KIND_LEARNER_UPDATE = 'learner_update';

    /** @var int Rows per bulk insert. */
    const BATCH = 1000;

    /** @var array Per-learner data tables, in the order they are filled. */
    const TABLES = [
        'local_microlearning_profile',
        'local_microlearning_kcstate',
        'local_microlearning_skill',
        'local_microlearning_dim',
        'local_microlearning_attempt',
        'local_microlearning_rec',
    ];

    /** @var array Bundle file => table it fills. */
    const FILES = [
        'profiles.csv' => 'local_microlearning_profile',
        'kc_states.csv' => 'local_microlearning_kcstate',
        'skills.csv' => 'local_microlearning_skill',
        'dimensions.csv' => 'local_microlearning_dim',
        'attempts.csv' => 'local_microlearning_attempt',
        'recommendations.csv' => 'local_microlearning_rec',
    ];

    /**
     * Import a snapshot bundle for a course; it becomes the active snapshot.
     *
     * @param string $dir Bundle directory.
     * @param int $courseid Course the bundle must belong to.
     * @return array Snapshot id and row counts per file.
     */
    public static function import(string $dir, int $courseid): array {
        global $DB;

        [$manifest, $rows] = self::read_bundle($dir, $courseid, self::KIND_SNAPSHOT);
        $now = time();
        $collectedat = (int) ($manifest['collected_at'] ?? 0) ?: $now;
        $transaction = $DB->start_delegated_transaction();
        $snapshotid = $DB->insert_record('local_microlearning_snapshot', (object) [
            'courseid' => $courseid,
            'active' => 0,
            'timegenerated' => self::unix($manifest['recommendations_generated_at'] ?? null) ?? $now,
            'asof' => $manifest['as_of'] ?? null,
            'collectedat' => $collectedat,
            'topn' => (int) ($manifest['top_n'] ?? 5),
            'maxremediation' => (int) ($manifest['max_remediation'] ?? 3),
            'cohort' => $manifest['cohort'] ?? null,
            'mappingversion' => $manifest['mapping_version'] ?? null,
            'recommenderversion' => $manifest['recommender_version'] ?? null,
            'manifest' => json_encode($manifest),
            'timeimported' => $now,
        ]);
        self::insert_rows($rows, $snapshotid, $courseid, $collectedat, $now);

        // Switch: this snapshot becomes the active one, the previously active
        // one is kept as a fallback, anything older is removed.
        $previous = $DB->get_field('local_microlearning_snapshot', 'id', ['courseid' => $courseid, 'active' => 1]);
        $DB->set_field('local_microlearning_snapshot', 'active', 0, ['courseid' => $courseid]);
        $DB->set_field('local_microlearning_snapshot', 'active', 1, ['id' => $snapshotid]);
        $keep = array_filter([$snapshotid, $previous]);
        [$notin, $params] = $DB->get_in_or_equal($keep, SQL_PARAMS_NAMED, 'keep', false);
        $old = $DB->get_fieldset_select('local_microlearning_snapshot', 'id',
            "courseid = :courseid AND id $notin", $params + ['courseid' => $courseid]);
        foreach ($old as $oldid) {
            self::delete_snapshot((int) $oldid);
        }
        $userids = array_map('intval', array_column($rows['profiles.csv'], 'userid'));
        $processed = self::mark_processed($courseid, $userids, $collectedat, $now);
        $transaction->allow_commit();

        return [
            'kind' => self::KIND_SNAPSHOT,
            'snapshotid' => (int) $snapshotid,
            'courseid' => $courseid,
            'rows' => array_map('count', $rows),
            'removedsnapshots' => count($old),
            'requestsprocessed' => $processed,
        ];
    }

    /**
     * Replace the rows of the learners in a learner_update bundle in the active snapshot.
     *
     * A learner whose rows in the snapshot already describe newer data (a full
     * run imported after this refresh was collected) is skipped.
     *
     * @param string $dir Bundle directory.
     * @param int $courseid Course the bundle must belong to.
     * @return array Snapshot id, updated and skipped learners, row counts.
     */
    public static function import_learner_update(string $dir, int $courseid): array {
        global $DB;

        [$manifest, $rows] = self::read_bundle($dir, $courseid, self::KIND_LEARNER_UPDATE);
        $snapshot = repository::active_snapshot($courseid);
        if (!$snapshot) {
            throw new \moodle_exception('errornosnapshotforupdate', 'local_microlearning');
        }
        if ((string) ($manifest['mapping_version'] ?? '') !== (string) ($snapshot->mappingversion ?? '')) {
            // KC ids or questions changed: these rows would not fit the others.
            throw new \moodle_exception('errormappingchanged', 'local_microlearning', '', (object) [
                'bundle' => $manifest['mapping_version'] ?? '?',
                'snapshot' => $snapshot->mappingversion ?? '?',
            ]);
        }
        $learners = array_map('intval', $manifest['learners'] ?? []);
        $profiled = array_map('intval', array_column($rows['profiles.csv'], 'userid'));
        sort($learners);
        sort($profiled);
        if ($learners !== $profiled) {
            throw new \moodle_exception('errormanifest', 'local_microlearning', '', 'learners');
        }

        $now = time();
        $collectedat = (int) ($manifest['collected_at'] ?? 0) ?: $now;
        $refreshed = $DB->get_records_menu('local_microlearning_profile',
            ['snapshotid' => $snapshot->id], '', 'userid, timerefreshed');
        $update = array_values(array_filter($learners,
            fn(int $userid) => (int) ($refreshed[$userid] ?? 0) < $collectedat));
        $skipped = array_values(array_diff($learners, $update));

        $transaction = $DB->start_delegated_transaction();
        if ($update) {
            [$insql, $params] = $DB->get_in_or_equal($update, SQL_PARAMS_NAMED, 'user');
            foreach (self::TABLES as $table) {
                $DB->delete_records_select($table, "snapshotid = :snapshotid AND userid $insql",
                    $params + ['snapshotid' => $snapshot->id]);
            }
            $keep = array_flip($update);
            $selected = array_map(fn(array $filerows) => array_values(array_filter($filerows,
                fn(array $row) => isset($keep[(int) $row['userid']]))), $rows);
            self::insert_rows($selected, (int) $snapshot->id, $courseid, $collectedat, $now);
        }
        // Skipped learners already have newer data: their requests are answered too.
        $processed = self::mark_processed($courseid, $learners, $collectedat, $now);
        $transaction->allow_commit();

        return [
            'kind' => self::KIND_LEARNER_UPDATE,
            'snapshotid' => (int) $snapshot->id,
            'courseid' => $courseid,
            'updated' => $update,
            'skipped' => $skipped,
            'rows' => array_map('count', $rows),
            'requestsprocessed' => $processed,
        ];
    }

    /**
     * Import either kind of bundle, as its manifest says.
     *
     * @param string $dir
     * @param int $courseid
     * @return array
     */
    public static function import_any(string $dir, int $courseid): array {
        $kind = self::read_manifest($dir)['kind'] ?? self::KIND_SNAPSHOT;
        return $kind === self::KIND_LEARNER_UPDATE
            ? self::import_learner_update($dir, $courseid)
            : self::import($dir, $courseid);
    }

    /**
     * Delete a snapshot with all its rows.
     *
     * @param int $snapshotid
     */
    public static function delete_snapshot(int $snapshotid): void {
        global $DB;
        foreach (self::TABLES as $table) {
            $DB->delete_records($table, ['snapshotid' => $snapshotid]);
        }
        $DB->delete_records('local_microlearning_snapshot', ['id' => $snapshotid]);
    }

    /**
     * Read, check and parse a bundle; nothing is written.
     *
     * @param string $dir
     * @param int $courseid
     * @param string $kind Expected kind.
     * @return array [manifest, [file => rows]]
     */
    protected static function read_bundle(string $dir, int $courseid, string $kind): array {
        global $DB;
        // A full queue is every open activity per learner (36k rows for 300
        // learners and 136 activities): more than the default memory limit.
        \raise_memory_limit(MEMORY_HUGE);
        $manifest = self::read_manifest($dir);
        if ((int) ($manifest['bundle_schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            throw new \moodle_exception('errorschema', 'local_microlearning', '',
                $manifest['bundle_schema_version'] ?? '?');
        }
        if (($manifest['kind'] ?? self::KIND_SNAPSHOT) !== $kind) {
            throw new \moodle_exception('errorkind', 'local_microlearning', '', $manifest['kind'] ?? '?');
        }
        if ((int) ($manifest['course_id'] ?? 0) !== $courseid) {
            throw new \moodle_exception('errorcourse', 'local_microlearning', '',
                (object) ['bundle' => $manifest['course_id'] ?? '?', 'course' => $courseid]);
        }
        $DB->get_record('course', ['id' => $courseid], 'id', MUST_EXIST);

        $rows = [];
        foreach (array_keys(self::FILES) as $name) {
            $rows[$name] = self::read_csv($dir, $name, $manifest);
        }

        // Every learner must be a Moodle user, every activity a module of this course.
        $userids = array_unique(array_map('intval', array_column($rows['profiles.csv'], 'userid')));
        self::require_known($userids, 'user', 'deleted = 0', [], 'errorusers');
        $profiled = array_flip($userids);
        foreach ($rows as $name => $filerows) {
            foreach ($filerows as $row) {
                if (!isset($profiled[(int) $row['userid']])) {
                    throw new \moodle_exception('errororphan', 'local_microlearning', '', $row['userid']);
                }
            }
        }
        $cmids = array_merge(
            array_column($rows['recommendations.csv'], 'cmid'),
            array_filter(array_column($rows['recommendations.csv'], 'trigger_cmid')),
            array_column($rows['attempts.csv'], 'cmid'),
            array_filter(array_column($rows['kc_states.csv'], 'lesson_cmid')),
            array_filter(array_column($rows['kc_states.csv'], 'quiz_cmid')),
        );
        $cmids = array_unique(array_map('intval', $cmids));
        self::require_known($cmids, 'course_modules', 'course = :course', ['course' => $courseid], 'errormodules');
        return [$manifest, $rows];
    }

    /**
     * Insert parsed bundle rows into the tables of one snapshot.
     *
     * @param array $rows file => rows
     * @param int $snapshotid
     * @param int $courseid
     * @param int $collectedat When the pipeline read these learners' data.
     * @param int $now
     */
    protected static function insert_rows(array $rows, int $snapshotid, int $courseid, int $collectedat, int $now): void {
        $base = ['snapshotid' => $snapshotid, 'courseid' => $courseid];
        $levels = fn(array $r) => [
            'strongcount' => (int) ($r['strong_kc_count'] ?? 0),
            'developingcount' => (int) ($r['developing_kc_count'] ?? 0),
            'weakcount' => (int) ($r['weak_kc_count'] ?? 0),
            'startedcount' => (int) ($r['started_kc_count'] ?? 0),
            'notassessedcount' => (int) ($r['not_assessed_kc_count'] ?? 0),
        ];
        self::insert('local_microlearning_profile', $rows['profiles.csv'], fn(array $r) => $base + [
            'userid' => (int) $r['userid'],
            'population' => $r['population'],
            'knowledgephase' => self::int($r['knowledge_phase']),
            'knowledgemodel' => $r['knowledge_model'],
            'knowledgestatus' => $r['knowledge_status'],
            'engagementstatus' => $r['engagement_status'],
            'sessioncount' => self::int($r['session_count']),
            'learningseconds' => self::int($r['learning_seconds']),
            'completedactivities' => self::int($r['completed_activities']),
            'courseprogress' => self::float($r['course_progress']),
            'lastactive' => self::int($r['last_active']),
            'quizattempts' => self::int($r['quiz_attempts']),
            'learningdays' => self::int($r['learning_days']),
            'timerefreshed' => $collectedat,
            'timeimported' => $now,
        ]);
        self::insert('local_microlearning_kcstate', $rows['kc_states.csv'], fn(array $r) => $base + [
            'userid' => (int) $r['userid'],
            'kcid' => $r['kc_id'],
            'kcname' => $r['kc_name'],
            'kcdimension' => $r['dimension'],
            'topic' => $r['topic'],
            'unitnumber' => self::int($r['unit_number']),
            'kcorder' => self::int($r['kc_order']),
            'lessoncmid' => self::int($r['lesson_cmid']),
            'quizcmid' => self::int($r['quiz_cmid']),
            'attemptcount' => (int) $r['attempt_count'],
            'correctcount' => self::int($r['correct_count']),
            'lastattempt' => self::int($r['last_attempt']),
            'knowledgelevel' => $r['knowledge_level'],
            'observedaccuracy' => self::float($r['observed_accuracy']),
            'predictedcorrectness' => self::float($r['predicted_correctness']),
            'statestatus' => $r['state_status'],
            'concernstatus' => $r['concern_status'],
            'reason' => $r['reason'],
        ]);
        self::insert('local_microlearning_skill', $rows['skills.csv'], fn(array $r) => $base + [
            'userid' => (int) $r['userid'],
            'skillid' => $r['skill_id'],
            'skillname' => $r['skill_name'],
            'kcdimension' => $r['dimension'],
            'unitnumber' => self::int($r['unit_number']),
            'kccount' => (int) $r['kc_count'],
            'estimatedkccount' => (int) $r['estimated_kc_count'],
            'meanpredicted' => self::float($r['mean_predicted_correctness']),
            'meanobserved' => self::float($r['mean_observed_accuracy']),
            'attemptcount' => (int) $r['attempt_count'],
        ] + $levels($r));
        self::insert('local_microlearning_dim', $rows['dimensions.csv'], fn(array $r) => $base + [
            'userid' => (int) $r['userid'],
            'dimension' => $r['dimension'] ?? '',
            'kccount' => (int) $r['kc_count'],
            'estimatedkccount' => (int) $r['estimated_kc_count'],
            'meanpredicted' => self::float($r['mean_predicted_correctness']),
            'meanobserved' => self::float($r['mean_observed_accuracy']),
            'attemptcount' => (int) $r['attempt_count'],
        ] + $levels($r));
        self::insert('local_microlearning_attempt', $rows['attempts.csv'], fn(array $r) => $base + [
            'userid' => (int) $r['userid'],
            'cmid' => (int) $r['cmid'],
            'attempt' => self::int($r['attempt']),
            'timefinish' => (int) $r['timefinish'],
            'score' => self::float($r['score']),
            'passfraction' => self::float($r['pass_fraction']),
            'passed' => self::int($r['passed']),
        ]);
        self::insert('local_microlearning_rec', $rows['recommendations.csv'], fn(array $r) => $base + [
            'userid' => (int) $r['userid'],
            'recrank' => (int) $r['rank'],
            'recgroup' => $r['group'],
            'triggercmid' => self::int($r['trigger_cmid'] ?? null),
            'cmid' => (int) $r['cmid'],
            'activitytype' => $r['activity_type'],
            'kcid' => $r['kc_id'],
            'kcname' => $r['kc_name'],
            'reasoncode' => $r['reason_code'],
            'reasontext' => $r['reason_text'],
            'basis' => $r['basis'],
            'evidence' => $r['evidence'],
        ]);
    }

    /**
     * Mark the learners' pending refresh requests that this data answers.
     *
     * @param int $courseid
     * @param int[] $userids
     * @param int $collectedat Requests made up to this moment are answered.
     * @param int $now
     * @return int Number of requests marked.
     */
    protected static function mark_processed(int $courseid, array $userids, int $collectedat, int $now): int {
        global $DB;
        $marked = 0;
        foreach (array_chunk($userids, self::BATCH) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'user');
            $where = "courseid = :courseid AND timeprocessed IS NULL AND timecreated <= :collectedat AND userid $insql";
            $params += ['courseid' => $courseid, 'collectedat' => $collectedat];
            $marked += $DB->count_records_select('local_microlearning_refresh', $where, $params);
            $DB->set_field_select('local_microlearning_refresh', 'timeprocessed', $now, $where, $params);
        }
        return $marked;
    }

    /**
     * Read and decode manifest.json.
     *
     * @param string $dir
     * @return array
     */
    protected static function read_manifest(string $dir): array {
        $path = rtrim($dir, '/') . '/manifest.json';
        if (!is_readable($path)) {
            throw new \moodle_exception('errormissingfile', 'local_microlearning', '', $path);
        }
        $manifest = json_decode(file_get_contents($path), true);
        if (!is_array($manifest) || empty($manifest['files'])) {
            throw new \moodle_exception('errormanifest', 'local_microlearning', '', $path);
        }
        return $manifest;
    }

    /**
     * Read one bundle CSV as a list of header-keyed rows; empty cells become null.
     *
     * The file must be listed in the manifest with a matching SHA-256 and row count.
     *
     * @param string $dir
     * @param string $name
     * @param array $manifest
     * @return array
     */
    protected static function read_csv(string $dir, string $name, array $manifest): array {
        $path = rtrim($dir, '/') . '/' . $name;
        if (!is_readable($path) || !isset($manifest['files'][$name])) {
            throw new \moodle_exception('errormissingfile', 'local_microlearning', '', $path);
        }
        if (!hash_equals((string) $manifest['files'][$name]['sha256'], hash_file('sha256', $path))) {
            throw new \moodle_exception('errorchecksum', 'local_microlearning', '', $name);
        }
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows = [];
        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($cells === [null]) {
                continue; // Blank line.
            }
            if (count($cells) !== count($header)) {
                fclose($handle);
                throw new \moodle_exception('errorcsv', 'local_microlearning', '', $name);
            }
            $rows[] = array_combine($header, array_map(fn($cell) => $cell === '' ? null : $cell, $cells));
        }
        fclose($handle);
        if (count($rows) !== (int) $manifest['files'][$name]['rows']) {
            throw new \moodle_exception('errorcsv', 'local_microlearning', '', $name);
        }
        return $rows;
    }

    /**
     * Fail unless every id exists in a table (optionally under a condition).
     *
     * @param int[] $ids
     * @param string $table
     * @param string $where
     * @param array $params
     * @param string $error Language string of the error.
     */
    protected static function require_known(array $ids, string $table, string $where, array $params, string $error): void {
        global $DB;
        $missing = [];
        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'id');
            $found = $DB->get_fieldset_select($table, 'id', "id $insql AND $where", $inparams + $params);
            $missing = array_merge($missing, array_diff($chunk, array_map('intval', $found)));
        }
        if ($missing) {
            throw new \moodle_exception($error, 'local_microlearning', '',
                count($missing) . ' (' . implode(', ', array_slice($missing, 0, 10)) . ')');
        }
    }

    /**
     * Bulk-insert rows after mapping them to table records.
     *
     * @param string $table
     * @param array $rows
     * @param callable $map
     */
    protected static function insert(string $table, array $rows, callable $map): void {
        global $DB;
        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $DB->insert_records($table, array_map(fn(array $row) => (object) $map($row), $chunk));
        }
    }

    /**
     * Nullable integer cell ("3" and "3.0" both give 3).
     *
     * @param string|null $value
     * @return int|null
     */
    protected static function int(?string $value): ?int {
        return $value === null ? null : (int) round((float) $value);
    }

    /**
     * Nullable float cell.
     *
     * @param string|null $value
     * @return float|null
     */
    protected static function float(?string $value): ?float {
        return $value === null ? null : (float) $value;
    }

    /**
     * ISO-8601 time to Unix seconds.
     *
     * @param string|null $iso
     * @return int|null
     */
    protected static function unix(?string $iso): ?int {
        $time = $iso === null ? false : strtotime($iso);
        return $time === false ? null : $time;
    }
}
