<?php
// This file is part of Moodle - http://moodle.org/

namespace local_microlearning;

use local_microlearning\local\importer;
use local_microlearning\local\repository;
use local_microlearning\observer;

/**
 * Importing a pipeline bundle and reading it back.
 *
 * @package    local_microlearning
 * @covers     \local_microlearning\local\importer
 * @covers     \local_microlearning\local\repository
 * @covers     \local_microlearning\observer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class importer_test extends \advanced_testcase {
    /**
     * Write a v2 bundle directory for a course with one real and one simulated learner.
     *
     * @param \stdClass $course
     * @param \stdClass $real
     * @param \stdClass $simulated
     * @param int $cmid Recommended course module.
     * @param array $options kind, learners, collected_at, mapping_version
     * @return string Bundle directory.
     */
    protected function write_bundle(\stdClass $course, \stdClass $real, \stdClass $simulated, int $cmid,
            array $options = []): string {
        $dir = make_request_directory();
        $learners = $options['learners'] ?? [(int) $real->id, (int) $simulated->id];
        $levels = ['strong_kc_count', 'developing_kc_count', 'weak_kc_count', 'started_kc_count',
            'not_assessed_kc_count'];
        $files = [
            'profiles.csv' => [
                ['userid', 'population', 'knowledge_phase', 'knowledge_model', 'knowledge_status',
                    'engagement_status', 'session_count', 'learning_seconds', 'completed_activities',
                    'course_progress', 'last_active', 'quiz_attempts', 'learning_days'],
                [$real->id, 'moodle_collector', 1, 'bkt_item_level', 'cold_start_only', 'no_observations',
                    '', '', '', '', '', '', ''],
                [$simulated->id, 'simulated', 3, 'akt', 'available', 'available', 4, 600, 3, 0.25,
                    1780272000, 2, 2],
            ],
            'kc_states.csv' => [
                ['userid', 'kc_id', 'kc_name', 'dimension', 'topic', 'unit_number', 'kc_order', 'lesson_cmid',
                    'quiz_cmid', 'attempt_count', 'correct_count', 'last_attempt', 'observed_accuracy',
                    'predicted_correctness', 'state_status', 'knowledge_level', 'concern_status', 'reason'],
                [$simulated->id, 'KC001', 'Topic, with a comma', 'grammar', 'Unit 1', 1, 1, $cmid, '', 8, 2,
                    1780272000, 0.25, 0.4, 'estimated', 'weak', 'evidence_based_concern',
                    'Observed accuracy 0.250 is below 0.60'],
                [$real->id, 'KC001', 'Topic, with a comma', 'grammar', 'Unit 1', 1, 1, $cmid, '', 0, '', '', '',
                    0.4375, 'cold_start', 'not_assessed', 'insufficient_evidence', 'No answers yet'],
            ],
            'skills.csv' => [
                array_merge(['userid', 'skill_id', 'skill_name', 'dimension', 'unit_number', 'kc_count',
                    'estimated_kc_count', 'mean_predicted_correctness', 'mean_observed_accuracy',
                    'attempt_count'], $levels),
                [$simulated->id, 'grammar|Unit 1', 'Grammar: Unit 1', 'grammar', 1, 1, 1, 0.4, 0.25, 8, 0, 0, 1, 0, 0],
            ],
            'dimensions.csv' => [
                array_merge(['userid', 'dimension', 'kc_count', 'estimated_kc_count',
                    'mean_predicted_correctness', 'mean_observed_accuracy', 'attempt_count'], $levels),
                [$simulated->id, 'grammar', 1, 1, 0.4, 0.25, 8, 0, 0, 1, 0, 0],
            ],
            'attempts.csv' => [
                ['userid', 'cmid', 'attempt', 'timefinish', 'score', 'pass_fraction', 'passed'],
                [$simulated->id, $cmid, 1, 1780272000, 0.5, 0.7, 0],
            ],
            'recommendations.csv' => [
                ['userid', 'rank', 'group', 'cmid', 'activity_type', 'kc_id', 'kc_name', 'reason_code',
                    'reason_text', 'basis', 'evidence'],
                [$simulated->id, 1, 'remediation', $cmid, 'page', 'KC001', 'Topic, with a comma', 'review_weak_kc',
                    'You answered 2 of 8 questions correctly. Read this lesson again.', 'evidence',
                    '{"attempts": 8, "correct": 2}'],
                [$real->id, 1, 'forward', $cmid, 'page', '', '', 'start_course', 'Start here.', 'course_order', '{}'],
            ],
        ];
        $kind = $options['kind'] ?? 'snapshot';
        $manifest = [
            'bundle_schema_version' => 2,
            'kind' => $kind,
            'course_id' => (int) $course->id,
            'collected_at' => $options['collected_at'] ?? 1780300000,
            'recommendations_generated_at' => '2026-11-16T00:00:00+00:00',
            'cohort' => 'mixed',
            'mapping_version' => $options['mapping_version'] ?? 'map-1',
            'recommender_version' => '1.1.0',
            'top_n' => 5,
            'max_remediation' => 3,
            'learners' => $kind === 'learner_update' ? $learners : null,
            'files' => [],
        ];
        foreach ($files as $name => $rows) {
            $header = array_shift($rows);
            $rows = array_filter($rows, fn($row) => in_array((int) $row[0], $learners));
            $handle = fopen("$dir/$name", 'w');
            fputcsv($handle, $header, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($handle, $row, ',', '"', '');
            }
            fclose($handle);
            $manifest['files'][$name] = ['rows' => count($rows), 'sha256' => hash_file('sha256', "$dir/$name")];
        }
        file_put_contents("$dir/manifest.json", json_encode($manifest));
        return $dir;
    }

    public function test_import_and_read_back(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $real = $generator->create_and_enrol($course, 'student');
        $simulated = $generator->create_and_enrol($course, 'student', ['idnumber' => 'SIM-0001']);
        $dir = $this->write_bundle($course, $real, $simulated, $page->cmid);

        $result = importer::import($dir, (int) $course->id);

        $this->assertSame(2, $result['rows']['profiles.csv']);
        $this->assertSame(1, $result['rows']['attempts.csv']);
        $snapshot = repository::active_snapshot($course->id);
        $this->assertEquals($result['snapshotid'], $snapshot->id);
        $this->assertSame('mixed', $snapshot->cohort);
        $this->assertEquals(1780300000, $snapshot->collectedat);
        $this->assertEquals(1780300000, repository::profile($snapshot->id, $real->id)->timerefreshed);
        $this->assertSame('forward', repository::recommendations($snapshot->id, $real->id)[0]->recgroup);
        $this->assertEquals(['moodle_collector' => 1, 'simulated' => 1],
            repository::population_counts($snapshot->id));

        // Each learner has only their own rows; empty cells are null, not zero.
        $own = repository::recommendations($snapshot->id, $real->id);
        $this->assertCount(1, $own);
        $this->assertSame('start_course', $own[0]->reasoncode);
        $this->assertNull($own[0]->kcid);
        $this->assertNull(repository::profile($snapshot->id, $real->id)->sessioncount);
        $this->assertSame('Topic, with a comma',
            repository::recommendations($snapshot->id, $simulated->id)[0]->kcname);

        $cohort = repository::cohort($snapshot->id);
        $this->assertCount(2, $cohort);
        $this->assertEquals($simulated->id, $cohort[0]->userid); // Most evidence concerns first.
        $this->assertEquals(1, $cohort[0]->evidenceconcerns);
        $this->assertCount(1, repository::cohort($snapshot->id, 'moodle_collector'));
        $weak = repository::weak_kcs($snapshot->id);
        $this->assertSame('KC001', $weak[0]->kcid);
        $this->assertEquals(1, $weak[0]->concerns);

        // A second import replaces the active snapshot and keeps one predecessor.
        $second = importer::import($dir, (int) $course->id);
        $third = importer::import($dir, (int) $course->id);
        $this->assertEquals($third['snapshotid'], repository::active_snapshot($course->id)->id);
        $this->assertSame(1, $third['removedsnapshots']);
        global $DB;
        $this->assertEqualsCanonicalizing([$second['snapshotid'], $third['snapshotid']],
            $DB->get_fieldset_select('local_microlearning_snapshot', 'id', 'courseid = ?', [$course->id]));
        $this->assertSame(4, $DB->count_records('local_microlearning_rec'));
        $this->assertSame(2, $DB->count_records('local_microlearning_attempt'));
    }

    public function test_a_bundle_of_another_course_or_a_damaged_one_changes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $other = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $otherpage = $generator->create_module('page', ['course' => $other->id]);
        $real = $generator->create_and_enrol($course, 'student');
        $simulated = $generator->create_and_enrol($course, 'student');

        // Wrong course id.
        $dir = $this->write_bundle($course, $real, $simulated, $page->cmid);
        try {
            importer::import($dir, (int) $other->id);
            $this->fail('A bundle for another course was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorcourse', $e->errorcode);
        }
        // A course module of another course (e.g. a bundle made before a rebuild).
        $dir = $this->write_bundle($course, $real, $simulated, $otherpage->cmid);
        try {
            importer::import($dir, (int) $course->id);
            $this->fail('A recommendation for an activity outside the course was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('errormodules', $e->errorcode);
        }
        // A file changed after the manifest was written.
        $dir = $this->write_bundle($course, $real, $simulated, $page->cmid);
        file_put_contents("$dir/skills.csv", "tampered\n", FILE_APPEND);
        try {
            importer::import($dir, (int) $course->id);
            $this->fail('A damaged bundle was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorchecksum', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('local_microlearning_snapshot'));
        $this->assertNull(repository::active_snapshot($course->id));
    }

    public function test_a_learner_update_replaces_only_that_learner(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $other = $generator->create_module('page', ['course' => $course->id]);
        $real = $generator->create_and_enrol($course, 'student');
        $simulated = $generator->create_and_enrol($course, 'student');
        $snapshot = importer::import($this->write_bundle($course, $real, $simulated, $page->cmid),
            (int) $course->id)['snapshotid'];
        observer::request((int) $course->id, (int) $real->id, observer::REASON_LIST_DONE);
        $DB->set_field('local_microlearning_refresh', 'timecreated', 1780300500);

        // The real learner was refreshed later and is now recommended another page.
        $update = $this->write_bundle($course, $real, $simulated, $other->cmid,
            ['kind' => 'learner_update', 'learners' => [(int) $real->id], 'collected_at' => 1780301000]);
        $result = importer::import_learner_update($update, (int) $course->id);

        $this->assertEquals([(int) $real->id], $result['updated']);
        $this->assertSame(1, $result['requestsprocessed']);
        $this->assertFalse(observer::pending((int) $course->id, (int) $real->id));
        $this->assertEquals($snapshot, repository::active_snapshot($course->id)->id); // No new snapshot.
        $this->assertEquals($other->cmid, repository::recommendations($snapshot, $real->id)[0]->cmid);
        $this->assertEquals(1780301000, repository::profile($snapshot, $real->id)->timerefreshed);
        // The simulated learner's rows are untouched.
        $this->assertEquals($page->cmid, repository::recommendations($snapshot, $simulated->id)[0]->cmid);
        $this->assertEquals(1780300000, repository::profile($snapshot, $simulated->id)->timerefreshed);

        // An update older than what is stored is skipped, not applied.
        $stale = $this->write_bundle($course, $real, $simulated, $page->cmid,
            ['kind' => 'learner_update', 'learners' => [(int) $real->id], 'collected_at' => 1780300900]);
        $result = importer::import_learner_update($stale, (int) $course->id);
        $this->assertEquals([(int) $real->id], $result['skipped']);
        $this->assertEquals($other->cmid, repository::recommendations($snapshot, $real->id)[0]->cmid);

        // An update for another question mapping does not fit the snapshot.
        $changed = $this->write_bundle($course, $real, $simulated, $page->cmid,
            ['kind' => 'learner_update', 'learners' => [(int) $real->id], 'collected_at' => 1780302000,
                'mapping_version' => 'map-2']);
        try {
            importer::import_learner_update($changed, (int) $course->id);
            $this->fail('An update for another mapping was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('errormappingchanged', $e->errorcode);
        }
    }
}
