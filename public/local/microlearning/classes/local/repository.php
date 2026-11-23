<?php
// This file is part of Moodle - http://moodle.org/

namespace local_microlearning\local;

/**
 * The only read access to the imported data.
 *
 * The student block and the teacher pages go through this class, so the table
 * layout stays private to the plugin. Every method reads the course's active
 * snapshot; none computes analytics beyond counting rows.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository {
    /** @var string Population label of simulated learners. */
    const POPULATION_SIMULATED = 'simulated';

    /** @var string Concern status backed by the learner's own answers. */
    const CONCERN_EVIDENCE = 'evidence_based_concern';

    /** @var string Concern status backed by a model prediction only. */
    const CONCERN_PREDICTION = 'prediction_based_concern';

    /**
     * The snapshot shown for a course, or null if nothing was imported yet.
     *
     * @param int $courseid
     * @return \stdClass|null
     */
    public static function active_snapshot(int $courseid): ?\stdClass {
        global $DB;
        $records = $DB->get_records('local_microlearning_snapshot',
            ['courseid' => $courseid, 'active' => 1], 'id DESC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * One learner's recommendations, best first.
     *
     * @param int $snapshotid
     * @param int $userid
     * @return \stdClass[]
     */
    public static function recommendations(int $snapshotid, int $userid): array {
        global $DB;
        return array_values($DB->get_records('local_microlearning_rec',
            ['snapshotid' => $snapshotid, 'userid' => $userid], 'recrank ASC'));
    }

    /**
     * One learner's profile row, or null.
     *
     * @param int $snapshotid
     * @param int $userid
     * @return \stdClass|null
     */
    public static function profile(int $snapshotid, int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_microlearning_profile',
            ['snapshotid' => $snapshotid, 'userid' => $userid]) ?: null;
    }

    /**
     * One learner's knowledge-component states.
     *
     * @param int $snapshotid
     * @param int $userid
     * @param string[] $concerns Limit to these concern statuses (empty = all).
     * @return \stdClass[]
     */
    public static function kc_states(int $snapshotid, int $userid, array $concerns = []): array {
        global $DB;
        $where = 'snapshotid = :snapshotid AND userid = :userid';
        $params = ['snapshotid' => $snapshotid, 'userid' => $userid];
        if ($concerns) {
            [$insql, $inparams] = $DB->get_in_or_equal($concerns, SQL_PARAMS_NAMED, 'concern');
            $where .= " AND concernstatus $insql";
            $params += $inparams;
        }
        return array_values($DB->get_records_select('local_microlearning_kcstate', $where, $params,
            'concernstatus ASC, observedaccuracy ASC, predictedcorrectness ASC, kcid ASC'));
    }

    /**
     * One learner's skill summaries (dimension x unit).
     *
     * @param int $snapshotid
     * @param int $userid
     * @return \stdClass[]
     */
    public static function skills(int $snapshotid, int $userid): array {
        global $DB;
        return array_values($DB->get_records('local_microlearning_skill',
            ['snapshotid' => $snapshotid, 'userid' => $userid], 'kcdimension ASC, skillname ASC'));
    }

    /**
     * All learners of a snapshot with their knowledge figures, for the overview.
     *
     * @param int $snapshotid
     * @param string|null $population Limit to one population (null = all).
     * @return \stdClass[] Profile rows with user name fields, idnumber and
     *     topicsanswered, answers, correct, weaktopics, strongtopics,
     *     evidenceconcerns, predictionconcerns.
     */
    public static function cohort(int $snapshotid, ?string $population = null, ?int $groupid = null): array {
        global $DB;
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $params = [
            'snapshotid' => $snapshotid,
            'evidence' => self::CONCERN_EVIDENCE,
            'prediction' => self::CONCERN_PREDICTION,
            'snapshotid2' => $snapshotid,
        ];
        $wherepopulation = self::learner_filter('p', $population, $groupid, $params);
        $sql = "SELECT p.*, $namefields, u.idnumber, u.picture, u.imagealt, u.email,
                       COALESCE(c.topicsanswered, 0) AS topicsanswered,
                       COALESCE(c.answers, 0) AS answers,
                       COALESCE(c.correct, 0) AS correct,
                       COALESCE(c.weaktopics, 0) AS weaktopics,
                       COALESCE(c.strongtopics, 0) AS strongtopics,
                       COALESCE(c.developingtopics, 0) AS developingtopics,
                       COALESCE(c.startedtopics, 0) AS startedtopics,
                       COALESCE(c.topics, 0) AS topics,
                       COALESCE(c.evidenceconcerns, 0) AS evidenceconcerns,
                       COALESCE(c.predictionconcerns, 0) AS predictionconcerns
                  FROM {local_microlearning_profile} p
                  JOIN {user} u ON u.id = p.userid
             LEFT JOIN (SELECT userid,
                               COUNT(1) AS topics,
                               SUM(CASE WHEN attemptcount > 0 THEN 1 ELSE 0 END) AS topicsanswered,
                               SUM(attemptcount) AS answers,
                               SUM(COALESCE(correctcount, 0)) AS correct,
                               SUM(CASE WHEN knowledgelevel = 'weak' THEN 1 ELSE 0 END) AS weaktopics,
                               SUM(CASE WHEN knowledgelevel = 'strong' THEN 1 ELSE 0 END) AS strongtopics,
                               SUM(CASE WHEN knowledgelevel = 'developing' THEN 1 ELSE 0 END) AS developingtopics,
                               SUM(CASE WHEN knowledgelevel = 'started' THEN 1 ELSE 0 END) AS startedtopics,
                               SUM(CASE WHEN concernstatus = :evidence THEN 1 ELSE 0 END) AS evidenceconcerns,
                               SUM(CASE WHEN concernstatus = :prediction THEN 1 ELSE 0 END) AS predictionconcerns
                          FROM {local_microlearning_kcstate}
                         WHERE snapshotid = :snapshotid2
                      GROUP BY userid) c ON c.userid = p.userid
                 WHERE p.snapshotid = :snapshotid $wherepopulation
              ORDER BY weaktopics DESC, u.lastname ASC, u.firstname ASC";
        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * Each learner's weakest dimension (lowest share of correct answers), for the overview.
     *
     * @param int $snapshotid
     * @return array userid => \stdClass (dimension, meanobserved)
     */
    public static function weakest_dimensions(int $snapshotid): array {
        global $DB;
        $weakest = [];
        $rows = $DB->get_recordset_select('local_microlearning_dim',
            'snapshotid = :snapshotid AND meanobserved IS NOT NULL', ['snapshotid' => $snapshotid],
            'userid ASC, meanobserved ASC, dimension ASC', 'id, userid, dimension, meanobserved');
        foreach ($rows as $row) {
            $weakest[$row->userid] = $weakest[$row->userid] ?? $row;
        }
        $rows->close();
        return $weakest;
    }

    /**
     * Knowledge components with the most evidence-based concerns in the cohort.
     *
     * @param int $snapshotid
     * @param int $limit
     * @param string|null $population Limit to one population (null = all).
     * @return \stdClass[] kcid, kcname, kcdimension, topic, unitnumber, learners (with answers),
     *                     concerns (evidence-based), meanaccuracy.
     */
    public static function weak_kcs(int $snapshotid, int $limit = 15, ?string $population = null,
            ?int $groupid = null): array {
        global $DB;
        $params = [
            'snapshotid' => $snapshotid,
            'evidence' => self::CONCERN_EVIDENCE,
            'evidence2' => self::CONCERN_EVIDENCE,
        ];
        $join = 'JOIN {local_microlearning_profile} p ON p.snapshotid = k.snapshotid AND p.userid = k.userid '
            . self::learner_filter('p', $population, $groupid, $params);
        $sql = "SELECT k.kcid, MAX(k.kcname) AS kcname, MAX(k.kcdimension) AS kcdimension, MAX(k.topic) AS topic,
                       MAX(k.unitnumber) AS unitnumber,
                       SUM(CASE WHEN k.attemptcount > 0 THEN 1 ELSE 0 END) AS learners,
                       SUM(CASE WHEN k.concernstatus = :evidence THEN 1 ELSE 0 END) AS concerns,
                       AVG(k.observedaccuracy) AS meanaccuracy
                  FROM {local_microlearning_kcstate} k
                  $join
                 WHERE k.snapshotid = :snapshotid
              GROUP BY k.kcid
                HAVING SUM(CASE WHEN k.concernstatus = :evidence2 THEN 1 ELSE 0 END) > 0
              ORDER BY concerns DESC, k.kcid ASC";
        return array_values($DB->get_records_sql($sql, $params, 0, $limit));
    }

    /**
     * Number of learners per population in a snapshot.
     *
     * @param int $snapshotid
     * @return array population => count
     */
    public static function population_counts(int $snapshotid): array {
        global $DB;
        return $DB->get_records_sql_menu(
            "SELECT population, COUNT(*) FROM {local_microlearning_profile}
              WHERE snapshotid = ? GROUP BY population ORDER BY population", [$snapshotid]);
    }

    /**
     * One learner's dimension summaries (grammar, vocabulary, ...).
     *
     * @param int $snapshotid
     * @param int $userid
     * @return \stdClass[]
     */
    public static function dimensions(int $snapshotid, int $userid): array {
        global $DB;
        return array_values($DB->get_records('local_microlearning_dim',
            ['snapshotid' => $snapshotid, 'userid' => $userid], 'dimension ASC'));
    }

    /**
     * One learner's finished quiz attempts, oldest first.
     *
     * @param int $snapshotid
     * @param int $userid
     * @return \stdClass[]
     */
    public static function attempts(int $snapshotid, int $userid): array {
        global $DB;
        return array_values($DB->get_records('local_microlearning_attempt',
            ['snapshotid' => $snapshotid, 'userid' => $userid], 'timefinish ASC, id ASC'));
    }

    /**
     * Cohort mean of the share of correct answers per dimension, within one population.
     *
     * Only learners with answers in a dimension count, and a dimension is left
     * out below $minlearners of them, so a mean never stands for one or two people.
     *
     * @param int $snapshotid
     * @param string $population
     * @param int $minlearners
     * @return array dimension => (object) [mean, learners]
     */
    public static function cohort_dimension_means(int $snapshotid, ?string $population, int $minlearners = 5,
            ?int $groupid = null): array {
        global $DB;
        $params = ['snapshotid' => $snapshotid, 'minlearners' => $minlearners];
        $where = self::learner_filter('p', $population, $groupid, $params);
        $sql = "SELECT d.dimension, AVG(d.meanobserved) AS mean, COUNT(d.meanobserved) AS learners
                  FROM {local_microlearning_dim} d
                  JOIN {local_microlearning_profile} p ON p.snapshotid = d.snapshotid AND p.userid = d.userid
                 WHERE d.snapshotid = :snapshotid AND d.meanobserved IS NOT NULL $where
              GROUP BY d.dimension
                HAVING COUNT(d.meanobserved) >= :minlearners";
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Cohort heatmap: mean share of correct answers per unit and dimension, within one population.
     *
     * @param int $snapshotid
     * @param string|null $population Null = everyone.
     * @param int $minlearners
     * @return \stdClass[] unitnumber, kcdimension, mean, learners
     */
    public static function cohort_skill_means(int $snapshotid, ?string $population, int $minlearners = 5,
            ?int $groupid = null): array {
        global $DB;
        $params = ['snapshotid' => $snapshotid, 'minlearners' => $minlearners];
        $where = self::learner_filter('p', $population, $groupid, $params);
        $sql = "SELECT s.skillid, MAX(s.unitnumber) AS unitnumber, MAX(s.kcdimension) AS kcdimension,
                       AVG(s.meanobserved) AS mean, COUNT(s.meanobserved) AS learners
                  FROM {local_microlearning_skill} s
                  JOIN {local_microlearning_profile} p ON p.snapshotid = s.snapshotid AND p.userid = s.userid
                 WHERE s.snapshotid = :snapshotid AND s.meanobserved IS NOT NULL $where
              GROUP BY s.skillid
                HAVING COUNT(s.meanobserved) >= :minlearners";
        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * Topics per knowledge level, summed over the learners of one population (or all).
     *
     * @param int $snapshotid
     * @param string|null $population
     * @return array level => count
     */
    public static function cohort_level_counts(int $snapshotid, ?string $population, ?int $groupid = null): array {
        global $DB;
        $params = ['snapshotid' => $snapshotid];
        $where = self::learner_filter('p', $population, $groupid, $params);
        return $DB->get_records_sql_menu("SELECT k.knowledgelevel, COUNT(1)
                  FROM {local_microlearning_kcstate} k
                  JOIN {local_microlearning_profile} p ON p.snapshotid = k.snapshotid AND p.userid = k.userid
                 WHERE k.snapshotid = :snapshotid $where
              GROUP BY k.knowledgelevel", $params);
    }

    /**
     * Learner x topic counts per unit and knowledge level (population and group filters as elsewhere).
     *
     * @param int $snapshotid
     * @param string|null $population
     * @param int|null $groupid Moodle group (null = everyone).
     * @return array unitnumber => [level => count]
     */
    public static function cohort_unit_level_counts(int $snapshotid, ?string $population, ?int $groupid = null): array {
        global $DB;
        $params = ['snapshotid' => $snapshotid];
        $where = self::learner_filter('p', $population, $groupid, $params);
        $rows = $DB->get_recordset_sql("SELECT k.unitnumber, k.knowledgelevel, COUNT(1) AS n
                  FROM {local_microlearning_kcstate} k
                  JOIN {local_microlearning_profile} p ON p.snapshotid = k.snapshotid AND p.userid = k.userid
                 WHERE k.snapshotid = :snapshotid $where
              GROUP BY k.unitnumber, k.knowledgelevel", $params);
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->unitnumber][$row->knowledgelevel ?: 'not_assessed'] = (int) $row->n;
        }
        $rows->close();
        ksort($counts);
        return $counts;
    }

    /**
     * SQL condition limiting profile rows (alias $alias) to a population and a Moodle group.
     *
     * @param string $alias Alias of local_microlearning_profile in the query.
     * @param string|null $population
     * @param int|null $groupid
     * @param array $params Query parameters, extended in place.
     * @return string " AND ..." or ''
     */
    protected static function learner_filter(string $alias, ?string $population, ?int $groupid, array &$params): string {
        $sql = '';
        if ($population !== null) {
            $sql .= " AND $alias.population = :fpopulation";
            $params['fpopulation'] = $population;
        }
        if ($groupid) {
            $sql .= " AND $alias.userid IN (SELECT gm.userid FROM {groups_members} gm WHERE gm.groupid = :fgroupid)";
            $params['fgroupid'] = $groupid;
        }
        return $sql;
    }

    /**
     * The learner's pending refresh request, if the engine has not answered it yet.
     *
     * @param int $courseid
     * @param int $userid
     * @return \stdClass|null
     */
    public static function pending_refresh(int $courseid, int $userid): ?\stdClass {
        global $DB;
        $records = $DB->get_records_select('local_microlearning_refresh',
            'courseid = :courseid AND userid = :userid AND timeprocessed IS NULL',
            ['courseid' => $courseid, 'userid' => $userid], 'timecreated ASC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * Learners of a course with a refresh request the engine has not answered yet.
     *
     * @param int $courseid
     * @return array userid => true
     */
    public static function pending_refresh_users(int $courseid): array {
        global $DB;
        $ids = $DB->get_fieldset_select('local_microlearning_refresh', 'DISTINCT userid',
            'courseid = :courseid AND timeprocessed IS NULL', ['courseid' => $courseid]);
        return array_fill_keys(array_map('intval', $ids), true);
    }
}
