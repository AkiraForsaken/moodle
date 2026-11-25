<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Upgrade steps of local_microlearning.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Run the upgrade steps.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_microlearning_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026111800) {
        // Bundle v2: full recommendation queue, knowledge levels, dimensions, quiz
        // history, per-learner refresh. The tables hold nothing but pipeline output,
        // which v2 cannot display without its new columns, so they are recreated
        // from install.xml rather than migrated. Push a new bundle afterwards:
        //     python moodle-sync.py push --course <id>
        $xmlfile = __DIR__ . '/install.xml';
        $tables = ['local_microlearning_snapshot', 'local_microlearning_profile',
            'local_microlearning_kcstate', 'local_microlearning_skill', 'local_microlearning_rec',
            'local_microlearning_dim', 'local_microlearning_attempt', 'local_microlearning_refresh'];
        foreach ($tables as $name) {
            $table = new xmldb_table($name);
            if ($dbman->table_exists($table)) {
                $dbman->drop_table($table);
            }
            $dbman->install_one_table_from_xmldb_file($xmlfile, $name);
        }
        upgrade_plugin_savepoint(true, 2026111800, 'local', 'microlearning');
    }

    if ($oldversion < 2026111900) {
        // Bundle v3: "if this quiz is failed" branches in the queue.
        $table = new xmldb_table('local_microlearning_rec');
        $field = new xmldb_field('triggercmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'recgroup');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026111900, 'local', 'microlearning');
    }

    return true;
}
