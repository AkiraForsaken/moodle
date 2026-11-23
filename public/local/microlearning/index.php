<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Group overview: a group of learners at a glance (the course, one population or one
 * Moodle group), the hardest topics, and a paginated learner list.
 *
 * Teachers and managers only (local/microlearning:viewprofiles).
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once(__DIR__ . '/lib.php');

use local_microlearning\local\repository;

$courseid = required_param('id', PARAM_INT);
$population = optional_param('population', '', PARAM_ALPHAEXT);
$groupid = optional_param('group', 0, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 25, PARAM_INT);
$search = optional_param('search', '', PARAM_NOTAGS);
$sort = optional_param('sort', 'weak', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/microlearning:viewprofiles', $context);

// Groups this teacher may look at; a group id from the URL must be one of them.
$groups = has_capability('moodle/site:accessallgroups', $context)
    ? groups_get_all_groups($course->id)
    : groups_get_all_groups($course->id, $USER->id);
if ($groupid && !isset($groups[$groupid])) {
    $groupid = 0;
}
$perpage = in_array($perpage, [10, 25, 50, 100]) ? $perpage : 25;

$filters = array_filter(['id' => $course->id, 'population' => $population, 'group' => $groupid]);
$baseurl = new moodle_url('/local/microlearning/index.php', $filters);
$PAGE->set_url(new moodle_url($baseurl, array_filter(['page' => $page, 'perpage' => $perpage,
    'search' => $search, 'sort' => $sort])));
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

// Filter bar: population pills and the Moodle group menu.
$counts = repository::population_counts($snapshot->id);
$filterhtml = '';
if (count($counts) > 1) {
    $allurl = new moodle_url($baseurl);
    $allurl->remove_params('population');
    $pills = [html_writer::link($allurl,
        get_string('alllearners', 'local_microlearning'),
        ['class' => 'nav-link' . ($population === '' ? ' active' : '')])];
    foreach ($counts as $name => $count) {
        $pills[] = html_writer::link(new moodle_url($baseurl, ['population' => $name]),
            get_string('population:' . $name, 'local_microlearning') . " ($count)",
            ['class' => 'nav-link' . ($population === $name ? ' active' : '')]);
    }
    $filterhtml .= html_writer::alist($pills, ['class' => 'nav nav-pills ml-pills']);
}
if ($groups) {
    $options = [0 => get_string('allgroups', 'local_microlearning')];
    foreach ($groups as $group) {
        $options[$group->id] = format_string($group->name);
    }
    $groupurl = new moodle_url($baseurl);
    $groupurl->remove_params('group');
    $select = new single_select($groupurl, 'group', $options, $groupid, null);
    $select->set_label(get_string('group'));
    $filterhtml .= $OUTPUT->render($select);
}
if ($filterhtml) {
    echo html_writer::div($filterhtml, 'ml-filterbar d-flex flex-wrap align-items-center gap-3 mb-3');
}

echo $OUTPUT->render_from_template('local_microlearning/cohort',
    (new \local_microlearning\output\cohort_page($course, $snapshot, $population !== '' ? $population : null,
        $groupid ?: null, ['page' => $page, 'perpage' => $perpage, 'search' => $search, 'sort' => $sort], $baseurl))
        ->export_for_template($OUTPUT));

echo $OUTPUT->footer();
