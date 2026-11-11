<?php
// This file is part of Moodle - http://moodle.org/

/**
 * External function called by the block's AMD module (AJAX only).
 *
 * @package    block_personalized_recommendation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'block_personalized_recommendation_get_next_activity' => [
        'classname' => \block_personalized_recommendation\external\get_next_activity::class,
        'description' => 'Next Best Activity recommendations for the current user in a course, excluding activities marked complete.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'block/personalized_recommendation:view',
    ],
];
