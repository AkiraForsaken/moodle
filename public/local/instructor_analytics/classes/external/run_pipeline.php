<?php
// This file is part of Moodle - http://moodle.org/

namespace local_instructor_analytics\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_instructor_analytics\api_client;

defined('MOODLE_INTERNAL') || die();

/**
 * Asks the Python backend to rebuild the learner model.
 *
 * @package    local_instructor_analytics
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_pipeline extends external_api {
    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id (used for the permission check)'),
        ]);
    }

    /**
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        ['courseid' => $courseid] = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/instructor_analytics:runpipeline', $context);

        try {
            $result = (new api_client())->run_pipeline();
        } catch (\moodle_exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage(), 'rows' => 0, 'learners' => 0];
        }
        return [
            'status' => 'success',
            'message' => '',
            'rows' => (int) ($result['rows_processed'] ?? 0),
            'learners' => (int) ($result['learners'] ?? 0),
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'success or error'),
            'message' => new external_value(PARAM_TEXT, 'Error description when status is error'),
            'rows' => new external_value(PARAM_INT, 'Learner-topic rows in the rebuilt model'),
            'learners' => new external_value(PARAM_INT, 'Distinct learners in the rebuilt model'),
        ]);
    }
}
