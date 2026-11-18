<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Events that can make a learner's recommendations out of date.
 *
 * Moodle also delivers an event to observers of its parent classes, so
 * \core\event\course_module_viewed covers the view event of every module type.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_module_viewed',
        'callback' => '\local_microlearning\observer::activity_changed',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => '\local_microlearning\observer::activity_changed',
        'internal' => false,
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback' => '\local_microlearning\observer::quiz_submitted',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\user_enrolment_created',
        'callback' => '\local_microlearning\observer::learner_enrolled',
        'internal' => false,
    ],
];
