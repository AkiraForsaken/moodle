<?php
// This file is part of Moodle - http://moodle.org/

namespace local_instructor_analytics\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_instructor_analytics\api_client;

defined('MOODLE_INTERNAL') || die();

/**
 * Heatmap of learner mastery, restricted to learners enrolled in the course.
 *
 * The Python model is not course-aware, so Moodle is the authority on who may
 * be shown here: only users enrolled as students in THIS course are returned,
 * which also stops one course's dashboard exposing another course's learners.
 *
 * @package    local_instructor_analytics
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_heatmap extends external_api {
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
        global $PAGE;

        ['courseid' => $courseid] = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/instructor_analytics:view', $context);

        $empty = ['status' => 'ok', 'message' => '', 'generatedat' => 0, 'topics' => [], 'learners' => [], 'summary' => []];

        try {
            $payload = (new api_client())->get('analytics/heatmap');
        } catch (\moodle_exception $e) {
            return ['status' => 'unavailable', 'message' => $e->getMessage()] + $empty;
        }

        $anonymize = (bool) get_config('local_instructor_analytics', 'anonymize');
        $namefields = \core_user\fields::for_name()->get_required_fields();
        $userfields = array_unique(array_merge(
            ['id'],
            $namefields,
            \core_user\fields::get_picture_fields()
        ));
        $select = implode(', ', array_map(fn($field) => "u.$field", $userfields));
        // Students only: moodle/course:isincompletionreports is the standard "is a learner" capability.
        $students = get_enrolled_users($context, 'moodle/course:isincompletionreports', 0, $select, 'u.lastname, u.firstname', 0, 0, true);

        $bylearner = [];
        foreach (($payload['learners'] ?? []) as $row) {
            $bylearner[(int) $row['learner_id']] = $row['topics'] ?? [];
        }

        $bands = ['high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0];
        $learners = [];
        $index = 0;
        foreach ($students as $user) {
            $index++;
            $cells = [];
            foreach (($bylearner[(int) $user->id] ?? []) as $topic => $cell) {
                $band = $cell['mastery_level'] ?? 'unknown';
                $band = array_key_exists($band, $bands) ? $band : 'unknown';
                $bands[$band]++;
                $cells[] = [
                    'topic' => (string) $topic,
                    'mastery' => $band,
                    'bestscore' => $cell['best_score'] ?? null,
                    'scoretrend' => $cell['score_trend'] ?? null,
                ];
            }
            $avatarurl = '';
            if (!$anonymize) {
                $picture = new \core\output\user_picture($user);
                $picture->size = 48;
                $picture->alttext = false;
                $avatarurl = $picture->get_url($PAGE)->out(false);
            }
            $learners[] = [
                'userid' => (int) $user->id,
                'name' => $anonymize ? get_string('learnerlabel', 'local_instructor_analytics', $index) : fullname($user),
                'profileurl' => $anonymize ? '' : (new \moodle_url('/user/view.php', ['id' => $user->id, 'course' => $courseid]))->out(false),
                'avatarurl' => $avatarurl,
                'cells' => $cells,
            ];
        }

        $summary = [];
        foreach ($bands as $band => $count) {
            $summary[] = ['band' => $band, 'count' => $count];
        }

        return [
            'status' => 'ok',
            'message' => '',
            'generatedat' => (int) ($payload['generated_at'] ?? 0),
            'topics' => array_map('strval', $payload['topics'] ?? []),
            'learners' => $learners,
            'summary' => $summary,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $nullablefloat = fn(string $desc) => new external_value(PARAM_FLOAT, $desc, VALUE_DEFAULT, null, NULL_ALLOWED);
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'ok or unavailable'),
            'message' => new external_value(PARAM_TEXT, 'Human readable problem description', VALUE_DEFAULT, ''),
            'generatedat' => new external_value(PARAM_INT, 'Unix time the snapshot was loaded by the API'),
            'topics' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Topic name, in course order')),
            'learners' => new external_multiple_structure(new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'Moodle user id'),
                'name' => new external_value(PARAM_TEXT, 'Display name'),
                'profileurl' => new external_value(PARAM_URL, 'Profile link, empty when anonymised'),
                'avatarurl' => new external_value(PARAM_URL, 'Avatar URL, empty when anonymised'),
                'cells' => new external_multiple_structure(new external_single_structure([
                    'topic' => new external_value(PARAM_TEXT, 'Topic name'),
                    'mastery' => new external_value(PARAM_ALPHA, 'high, medium, low or unknown'),
                    'bestscore' => $nullablefloat('Best quiz score 0..1'),
                    'scoretrend' => $nullablefloat('Last minus first attempt score'),
                ])),
            ])),
            'summary' => new external_multiple_structure(new external_single_structure([
                'band' => new external_value(PARAM_ALPHA, 'Mastery band'),
                'count' => new external_value(PARAM_INT, 'Number of learner-topic cells in the band'),
            ])),
        ]);
    }
}
