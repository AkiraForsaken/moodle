<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Instructor dashboard page. Renders the shell only; data is loaded over AJAX
 * through Moodle web services, so the browser never talks to the Python API.
 *
 * @package    local_instructor_analytics
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);

require_login($course);
$context = context_course::instance($course->id);
require_capability('local/instructor_analytics:view', $context);

$PAGE->set_url('/local/instructor_analytics/index.php', ['id' => $course->id]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('dashboardtitle', 'local_instructor_analytics'));
$PAGE->set_heading($course->fullname);

$canrun = has_capability('local/instructor_analytics:runpipeline', $context);
$PAGE->requires->js_call_amd('local_instructor_analytics/dashboard', 'init', [[
    'courseid' => (int) $course->id,
    'canrun' => $canrun,
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_instructor_analytics/dashboard', [
    'title' => get_string('dashboardtitle', 'local_instructor_analytics'),
    'canrun' => $canrun,
]);
echo $OUTPUT->footer();
