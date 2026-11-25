<?php
// This file is part of Moodle - http://moodle.org/

/**
 * One learner's knowledge profile: levels, skills, units, topics, quiz history, current list.
 *
 * Teachers and managers (local/microlearning:viewprofiles) open any learner of the
 * course. A learner (local/microlearning:viewownprofile) opens only their own
 * profile, in the same layout without comparisons with other learners.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/completionlib.php');

use local_microlearning\local\repository;
use local_microlearning\output\profile_page;

$courseid = required_param('id', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$unit = optional_param('unit', null, PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
$userid = $userid ?: (int) $USER->id;
$self = $userid === (int) $USER->id;
$canviewall = has_capability('local/microlearning:viewprofiles', $context);
if (!$self || !$canviewall) {
    // Someone else's profile needs viewprofiles; one's own needs viewownprofile.
    require_capability($self ? 'local/microlearning:viewownprofile' : 'local/microlearning:viewprofiles', $context);
}

$overview = new moodle_url('/local/microlearning/index.php', ['id' => $course->id]);
$PAGE->set_url('/local/microlearning/profile.php', ['id' => $course->id, 'userid' => $userid]);
$PAGE->set_pagelayout($self && !$canviewall ? 'incourse' : 'report');
$PAGE->set_title(get_string($self ? 'myprofile' : 'learnerprofile', 'local_microlearning'));
$PAGE->set_heading($course->fullname);
if ($canviewall) {
    $PAGE->navbar->add(get_string('learnerprofiles', 'local_microlearning'), $overview);
}

$snapshot = repository::active_snapshot($course->id);
$profile = $snapshot ? repository::profile($snapshot->id, $userid) : null;
if (!$profile && $self) {
    $PAGE->navbar->add(get_string('myprofile', 'local_microlearning'));
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('myprofile', 'local_microlearning'));
    echo $OUTPUT->notification(get_string('noprofileself', 'local_microlearning'),
        \core\output\notification::NOTIFY_INFO, false);
    echo $OUTPUT->footer();
    exit;
}
if (!$profile) {
    // Also the answer for a user id that is not part of this course's snapshot.
    throw new moodle_exception('noprofile', 'local_microlearning', $overview);
}
$user = core_user::get_user($userid, '*', MUST_EXIST);
$PAGE->navbar->add($self ? get_string('myprofile', 'local_microlearning') : fullname($user));

echo $OUTPUT->header();
if (!$self) {
    echo $OUTPUT->heading(get_string('learnerprofile', 'local_microlearning')
        . local_microlearning_population_badge($profile->population));
    echo local_microlearning_snapshot_notice($snapshot);
    $phasekey = in_array((int) $profile->knowledgephase, [1, 2, 3])
        ? 'phase' . (int) $profile->knowledgephase : 'phaseunknown';
    echo html_writer::tag('p', get_string($phasekey, 'local_microlearning', s($profile->knowledgemodel ?? ''))
        . ' ' . get_string('levelsnote', 'local_microlearning'), ['class' => 'text-muted']);
} else {
    echo $OUTPUT->heading(get_string('myprofile', 'local_microlearning'));
    echo html_writer::tag('p', get_string('selfintro', 'local_microlearning', (object) [
        'refreshed' => userdate($profile->timerefreshed ?: $snapshot->timegenerated),
    ]), ['class' => 'text-muted']);
}
echo $OUTPUT->render_from_template('local_microlearning/profile',
    (new profile_page($course, $user, $snapshot, $profile, $unit, $self))->export_for_template($OUTPUT));
echo $OUTPUT->footer();
