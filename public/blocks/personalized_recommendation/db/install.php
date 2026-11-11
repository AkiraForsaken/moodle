<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Install the recommendation block on existing courses.
 */
function xmldb_block_personalized_recommendation_install(): void {
    \block_personalized_recommendation\block_provisioner::add_to_existing_courses();
}
