<?php
// This file is part of Moodle - http://moodle.org/

namespace block_personalized_recommendation;

defined('MOODLE_INTERNAL') || die();

/**
 * Provisions the recommendation block on newly created courses.
 *
 * @package    block_personalized_recommendation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Add the block after a course is created.
     *
     * @param \core\event\course_created $event Course-created event.
     */
    public static function course_created(\core\event\course_created $event): void {
        block_provisioner::ensure_for_course((int) $event->objectid);
    }
}
