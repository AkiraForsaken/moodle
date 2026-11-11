<?php
// This file is part of Moodle - http://moodle.org/

namespace block_personalized_recommendation\external;

use block_personalized_recommendation\api_client;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

/**
 * Returns the Next Best Activity list for the CURRENT user.
 *
 * The learner id is never a parameter: it is always $USER->id, so a student
 * cannot ask for someone else's recommendations. Only activities the user can
 * actually open (visible, available, in this course) are returned, each with its
 * real Moodle URL.
 *
 * @package    block_personalized_recommendation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_next_activity extends external_api {
    /** Fetch enough ranked candidates to replace activities already completed in Moodle. */
    private const CANDIDATE_LIMIT = 20;

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);
    }

    /**
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $USER;
        ['courseid' => $courseid] = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('block/personalized_recommendation:view', $context);

        $result = ['status' => 'ok', 'message' => '', 'topic' => '', 'items' => []];

        // Staff previewing the course are not learners: do not invent a recommendation for them.
        if (!has_capability('moodle/course:isincompletionreports', $context)) {
            return ['status' => 'notlearner', 'message' => get_string('notlearner', 'block_personalized_recommendation')] + $result;
        }

        $max = max(1, min(5, (int) get_config('block_personalized_recommendation', 'maxitems') ?: 3));
        $method = (string) (get_config('block_personalized_recommendation', 'method') ?: 'rule_based');

        $completed = self::get_completed_course_module_ids($courseid, (int) $USER->id);
        try {
            $payload = (new api_client())->get('recommend/' . (int) $USER->id, [
                'k' => self::CANDIDATE_LIMIT,
                'method' => $method,
            ]);
        } catch (\moodle_exception $e) {
            return ['status' => 'unavailable', 'message' => get_string('unavailable', 'block_personalized_recommendation')] + $result;
        }

        $modinfo = get_fast_modinfo($courseid);
        $items = [];
        foreach (($payload['recommendations'] ?? []) as $rec) {
            $cmid = (int) ($rec['course_module_id'] ?? 0);
            if (isset($completed[$cmid])) {
                continue;
            }
            $cm = $modinfo->cms[$cmid] ?? null;
            if (!$cm || !$cm->uservisible || !$cm->url) {
                continue;
            }
            $items[] = [
                'name' => $cm->get_formatted_name(),
                'url' => $cm->url->out(false),
                'typelabel' => get_string('modulename', 'mod_' . $cm->modname),
                'topic' => (string) ($rec['topic'] ?? ''),
                // The API prefixes "[method had no pick; X fallback]" for evaluation; students need only the explanation.
                'reason' => trim((string) preg_replace('/^\[[^\]]*\]\s*/', '', (string) ($rec['reason'] ?? ''))),
                'isreview' => !empty($rec['is_review']),
                'minutes' => isset($rec['est_minutes']) ? (int) $rec['est_minutes'] : null,
            ];
            if (count($items) >= $max) {
                break;
            }
        }

        if (!$items) {
            return ['status' => 'empty', 'message' => get_string('empty', 'block_personalized_recommendation')] + $result;
        }
        $result['items'] = $items;
        $result['topic'] = (string) ($payload['focus']['topic'] ?? '');
        return $result;
    }

    /**
     * Find course activities the learner has completed.
     *
     * Completion records are authoritative for suppressing recommendations.
     * Merely opening an activity does not make it ineligible.
     *
     * @param int $courseid Course id.
     * @param int $userid Learner id.
     * @return array<int, bool> Course-module ids keyed for constant-time lookup.
     */
    private static function get_completed_course_module_ids(int $courseid, int $userid): array {
        global $DB;

        $completed = $DB->get_fieldset_sql(
            "SELECT DISTINCT cmc.coursemoduleid
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
              WHERE cm.course = :courseid
                AND cmc.userid = :userid
                AND cmc.completionstate IN (:complete, :passed, :failed)",
            [
                'courseid' => $courseid,
                'userid' => $userid,
                'complete' => COMPLETION_COMPLETE,
                'passed' => COMPLETION_COMPLETE_PASS,
                'failed' => COMPLETION_COMPLETE_FAIL,
            ]
        );

        $completedids = [];
        foreach ($completed as $cmid) {
            $completedids[(int) $cmid] = true;
        }
        return $completedids;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'ok, empty, unavailable or notlearner'),
            'message' => new external_value(PARAM_TEXT, 'Message to show instead of items', VALUE_DEFAULT, ''),
            'topic' => new external_value(PARAM_TEXT, 'Topic the learner should work on', VALUE_DEFAULT, ''),
            'items' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_TEXT, 'Activity name'),
                'url' => new external_value(PARAM_URL, 'Activity URL'),
                'typelabel' => new external_value(PARAM_TEXT, 'Localised activity type'),
                'topic' => new external_value(PARAM_TEXT, 'Topic'),
                'reason' => new external_value(PARAM_TEXT, 'Why this activity'),
                'isreview' => new external_value(PARAM_BOOL, 'Learner has already used this activity'),
                'minutes' => new external_value(PARAM_INT, 'Estimated minutes', VALUE_DEFAULT, null, NULL_ALLOWED),
            ])),
        ]);
    }
}
