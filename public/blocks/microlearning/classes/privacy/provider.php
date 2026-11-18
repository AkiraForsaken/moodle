<?php
// This file is part of Moodle - http://moodle.org/

namespace block_microlearning\privacy;

/**
 * The block stores no personal data; local_microlearning does.
 *
 * @package    block_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\null_provider {
    /**
     * Why the plugin stores nothing.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
