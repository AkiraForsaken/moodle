<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Admin settings for block_personalized_recommendation.
 *
 * @package    block_personalized_recommendation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext(
        'block_personalized_recommendation/api_url',
        get_string('setting_api_url', 'block_personalized_recommendation'),
        get_string('setting_api_url_desc', 'block_personalized_recommendation'),
        'http://host.docker.internal:8010',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'block_personalized_recommendation/api_token',
        get_string('setting_api_token', 'block_personalized_recommendation'),
        get_string('setting_api_token_desc', 'block_personalized_recommendation'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'block_personalized_recommendation/timeout',
        get_string('setting_timeout', 'block_personalized_recommendation'),
        get_string('setting_timeout_desc', 'block_personalized_recommendation'),
        4,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configselect(
        'block_personalized_recommendation/method',
        get_string('setting_method', 'block_personalized_recommendation'),
        get_string('setting_method_desc', 'block_personalized_recommendation'),
        'rule_based',
        [
            'rule_based' => 'Rule-based (knowledge-based)',
            'popularity' => 'Popularity',
            'content_based' => 'Content-based',
            'collaborative' => 'Collaborative filtering',
        ]
    ));
    $settings->add(new admin_setting_configtext(
        'block_personalized_recommendation/maxitems',
        get_string('setting_maxitems', 'block_personalized_recommendation'),
        get_string('setting_maxitems_desc', 'block_personalized_recommendation'),
        3,
        PARAM_INT
    ));
}
