<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Version metadata for local_instructor_analytics.
 *
 * @package    local_instructor_analytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_instructor_analytics';
$plugin->version   = 2026101803;
$plugin->requires  = 2025041400;   // Moodle 5.0+ (uses core\http_client and core_external namespaces).
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.2.0';
