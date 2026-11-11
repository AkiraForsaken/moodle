<?php
// This file is part of Moodle - http://moodle.org/

namespace local_instructor_analytics\privacy;

/**
 * Privacy provider: this plugin only displays data held elsewhere.
 *
 * @package    local_instructor_analytics
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\null_provider {
    /**
     * Explain why nothing is stored.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
