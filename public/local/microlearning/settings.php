<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Admin settings of local_microlearning.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_microlearning', get_string('pluginname', 'local_microlearning'));
    $settings->add(new admin_setting_configcheckbox('local_microlearning/refreshenabled',
        get_string('refreshenabled', 'local_microlearning'),
        get_string('refreshenabled_desc', 'local_microlearning'), 1));
    $settings->add(new admin_setting_configcheckbox('local_microlearning/refreshonfail',
        get_string('refreshonfail', 'local_microlearning'),
        get_string('refreshonfail_desc', 'local_microlearning'), 1));
    $settings->add(new admin_setting_heading('local_microlearning/engine',
        get_string('engine', 'local_microlearning'), get_string('engine_desc', 'local_microlearning')));
    $settings->add(new admin_setting_configtext('local_microlearning/engineurl',
        get_string('engineurl', 'local_microlearning'), get_string('engineurl_desc', 'local_microlearning'),
        '', PARAM_URL));
    $settings->add(new admin_setting_configpasswordunmask('local_microlearning/enginetoken',
        get_string('enginetoken', 'local_microlearning'), get_string('enginetoken_desc', 'local_microlearning'), ''));
    $settings->add(new admin_setting_configtext('local_microlearning/enginetimeout',
        get_string('enginetimeout', 'local_microlearning'), get_string('enginetimeout_desc', 'local_microlearning'),
        300, PARAM_INT));
    $ADMIN->add('localplugins', $settings);
}
