<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Cohort overview: the learners' knowledge at a glance, the hardest topics, and every learner.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once(__DIR__ . '/lib.php');

use local_microlearning\local\repository;

$courseid = required_param('id', PARAM_INT);
$population = optional_param('population', '', PARAM_ALPHAEXT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/microlearning:viewprofiles', $context);

$url = new moodle_url('/local/microlearning/index.php', ['id' => $course->id]);
$PAGE->set_url($url, $population !== '' ? ['population' => $population] : []);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('learnerprofiles', 'local_microlearning'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('learnerprofiles', 'local_microlearning'));

$snapshot = repository::active_snapshot($course->id);
if (!$snapshot) {
    echo $OUTPUT->notification(get_string('nosnapshot', 'local_microlearning'),
        \core\output\notification::NOTIFY_INFO, false);
    echo $OUTPUT->footer();
    exit;
}
echo local_microlearning_snapshot_notice($snapshot);

// Filter: all / real / simulated learners.
$counts = repository::population_counts($snapshot->id);
if (count($counts) > 1) {
    $links = [html_writer::link($url, get_string('alllearners', 'local_microlearning'),
        ['class' => $population === '' ? 'fw-bold' : ''])];
    foreach ($counts as $name => $count) {
        $links[] = html_writer::link(new moodle_url($url, ['population' => $name]),
            get_string('population:' . $name, 'local_microlearning') . " ($count)",
            ['class' => $population === $name ? 'fw-bold' : '']);
    }
    echo html_writer::div(get_string('show', 'local_microlearning') . ': ' . implode(' | ', $links), 'mb-3');
}

echo $OUTPUT->render_from_template('local_microlearning/cohort',
    (new \local_microlearning\output\cohort_page($course, $snapshot, $population !== '' ? $population : null))
        ->export_for_template($OUTPUT));

echo $OUTPUT->footer();
