<?php
// This file is part of Moodle - http://moodle.org/

/**
 * External functions called by the dashboard's AMD module (AJAX only).
 *
 * @package    local_instructor_analytics
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_instructor_analytics_get_heatmap' => [
        'classname' => \local_instructor_analytics\external\get_heatmap::class,
        'description' => 'Knowledge-state heatmap for the learners enrolled in a course.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/instructor_analytics:view',
    ],
    'local_instructor_analytics_run_pipeline' => [
        'classname' => \local_instructor_analytics\external\run_pipeline::class,
        'description' => 'Rebuild the learner model on the analytics backend.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/instructor_analytics:runpipeline',
    ],
];
