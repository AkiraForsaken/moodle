<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Scheduled tasks: the nightly full rebuild through the engine.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_microlearning\task\full_sync',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '3',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
