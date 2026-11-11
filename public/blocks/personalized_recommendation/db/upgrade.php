<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the recommendation block plugin.
 *
 * @param int $oldversion Previous plugin version.
 * @param stdClass $block Plugin record.
 * @return bool
 */
function xmldb_block_personalized_recommendation_upgrade($oldversion, $block): bool {
    if ($oldversion < 2026101800) {
        \block_personalized_recommendation\block_provisioner::add_to_existing_courses();
        upgrade_plugin_savepoint(true, 2026101800, 'block', 'personalized_recommendation');
    }
    if ($oldversion < 2026101802) {
        upgrade_plugin_savepoint(true, 2026101802, 'block', 'personalized_recommendation');
    }
    return true;
}
