<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Student-facing recommendations block.
 *
 * @package    block_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_microlearning';
$plugin->version = 2026111800;
$plugin->requires = 2026041000; // Moodle 5.2.
$plugin->maturity = MATURITY_ALPHA;
$plugin->release = '0.1.0';
// All data comes from the local plugin; the block only displays it.
$plugin->dependencies = ['local_microlearning' => 2026111800];
