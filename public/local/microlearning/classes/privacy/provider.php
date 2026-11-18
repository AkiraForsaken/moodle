<?php
// This file is part of Moodle - http://moodle.org/

namespace local_microlearning\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy API: the plugin stores per-learner analytics derived from course activity.
 *
 * All of it lives in course contexts and can be exported and deleted per user.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /** @var array Tables holding rows per user, with the fields described to the user. */
    const USER_TABLES = [
        'local_microlearning_profile' => ['population', 'knowledgephase', 'knowledgemodel',
            'sessioncount', 'learningseconds', 'completedactivities', 'courseprogress',
            'lastactive', 'quizattempts', 'learningdays', 'timerefreshed'],
        'local_microlearning_kcstate' => ['kcid', 'kcname', 'attemptcount', 'correctcount', 'lastattempt',
            'knowledgelevel', 'observedaccuracy', 'predictedcorrectness', 'concernstatus', 'reason'],
        'local_microlearning_skill' => ['skillname', 'meanpredicted', 'meanobserved', 'attemptcount', 'weakcount'],
        'local_microlearning_dim' => ['dimension', 'meanpredicted', 'meanobserved', 'attemptcount'],
        'local_microlearning_attempt' => ['cmid', 'timefinish', 'score', 'passed'],
        'local_microlearning_rec' => ['recrank', 'recgroup', 'triggercmid', 'cmid', 'kcid', 'reasoncode', 'reasontext',
            'basis', 'evidence'],
        'local_microlearning_refresh' => ['reason', 'timecreated', 'timeprocessed'],
    ];

    /**
     * Describe the stored data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        foreach (self::USER_TABLES as $table => $fields) {
            $described = ['userid' => 'privacy:metadata:userid', 'courseid' => 'privacy:metadata:courseid'];
            foreach ($fields as $field) {
                $described[$field] = "privacy:metadata:$table:$field";
            }
            $collection->add_database_table($table, $described, "privacy:metadata:$table");
        }
        // The engine (an external service) reads activity data and receives learner ids.
        $collection->add_external_location_link('engine', [
            'userid' => 'privacy:metadata:engine:userid',
            'courseid' => 'privacy:metadata:engine:courseid',
        ], 'privacy:metadata:engine');
        return $collection;
    }

    /**
     * Course contexts in which a user has data.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {local_microlearning_profile} p
                  JOIN {context} ctx ON ctx.instanceid = p.courseid AND ctx.contextlevel = :level
                 WHERE p.userid = :userid";
        $contextlist->add_from_sql($sql, ['level' => CONTEXT_COURSE, 'userid' => $userid]);
        // A refresh request can exist before the learner has a profile.
        $sql = "SELECT ctx.id
                  FROM {local_microlearning_refresh} r
                  JOIN {context} ctx ON ctx.instanceid = r.courseid AND ctx.contextlevel = :level
                 WHERE r.userid = :userid";
        $contextlist->add_from_sql($sql, ['level' => CONTEXT_COURSE, 'userid' => $userid]);
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        foreach (['local_microlearning_profile', 'local_microlearning_refresh'] as $table) {
            $userlist->add_from_sql('userid', "SELECT userid FROM {{$table}} WHERE courseid = ?",
                [$context->instanceid]);
        }
    }

    /**
     * Export a user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            foreach (array_keys(self::USER_TABLES) as $table) {
                $rows = $DB->get_records($table, ['courseid' => $context->instanceid, 'userid' => $userid], 'id');
                if ($rows) {
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'local_microlearning'), $table],
                        (object) ['rows' => array_values($rows)]);
                }
            }
        }
    }

    /**
     * Delete everything in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        foreach (array_keys(self::USER_TABLES) as $table) {
            $DB->delete_records($table, ['courseid' => $context->instanceid]);
        }
    }

    /**
     * Delete one user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            foreach (array_keys(self::USER_TABLES) as $table) {
                $DB->delete_records($table, ['courseid' => $context->instanceid, 'userid' => $userid]);
            }
        }
    }

    /**
     * Delete several users' data in one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if ($context->contextlevel != CONTEXT_COURSE || !$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        foreach (array_keys(self::USER_TABLES) as $table) {
            $DB->delete_records_select($table, "courseid = :courseid AND userid $insql",
                $params + ['courseid' => $context->instanceid]);
        }
    }
}
