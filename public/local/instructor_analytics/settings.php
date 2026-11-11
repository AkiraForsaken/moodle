<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Admin settings: where the Python analytics API lives and how to authenticate.
 *
 * @package    local_instructor_analytics
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_instructor_analytics',
        get_string('pluginname', 'local_instructor_analytics')
    );
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configtext(
            'local_instructor_analytics/api_url',
            get_string('setting_api_url', 'local_instructor_analytics'),
            get_string('setting_api_url_desc', 'local_instructor_analytics'),
            'http://host.docker.internal:8010',
            PARAM_URL
        ));
        $settings->add(new admin_setting_configpasswordunmask(
            'local_instructor_analytics/api_token',
            get_string('setting_api_token', 'local_instructor_analytics'),
            get_string('setting_api_token_desc', 'local_instructor_analytics'),
            ''
        ));
        $settings->add(new admin_setting_configtext(
            'local_instructor_analytics/timeout',
            get_string('setting_timeout', 'local_instructor_analytics'),
            get_string('setting_timeout_desc', 'local_instructor_analytics'),
            5,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            'local_instructor_analytics/pipeline_timeout',
            get_string('setting_pipeline_timeout', 'local_instructor_analytics'),
            get_string('setting_pipeline_timeout_desc', 'local_instructor_analytics'),
            600,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_instructor_analytics/anonymize',
            get_string('setting_anonymize', 'local_instructor_analytics'),
            get_string('setting_anonymize_desc', 'local_instructor_analytics'),
            0
        ));
    }
}
