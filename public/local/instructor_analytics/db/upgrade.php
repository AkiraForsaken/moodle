<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Upgrade steps for local_instructor_analytics.
 *
 * @package    local_instructor_analytics
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade local_instructor_analytics.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_instructor_analytics_upgrade(int $oldversion): bool {
    if ($oldversion < 2026101802) {
        $timeout = get_config('local_instructor_analytics', 'pipeline_timeout');
        if ($timeout === false || (int)$timeout === 120) {
            set_config('pipeline_timeout', 600, 'local_instructor_analytics');
        }

        upgrade_plugin_savepoint(true, 2026101802, 'local', 'instructor_analytics');
    }

    return true;
}
