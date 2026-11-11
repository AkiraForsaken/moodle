<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Capabilities for local_instructor_analytics.
 *
 * @package    local_instructor_analytics
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // See the cohort's knowledge-state heatmap (shows other learners' data).
    'local/instructor_analytics:view' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'riskbitmask' => RISK_PERSONAL,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    // Rebuild the learner model on the analytics backend.
    'local/instructor_analytics:runpipeline' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'riskbitmask' => RISK_PERSONAL,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];
