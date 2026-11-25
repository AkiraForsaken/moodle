<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Old address of the learner profile; kept so earlier links still work.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

redirect(new moodle_url('/local/microlearning/profile.php', [
    'id' => required_param('id', PARAM_INT),
    'userid' => required_param('userid', PARAM_INT),
]));
