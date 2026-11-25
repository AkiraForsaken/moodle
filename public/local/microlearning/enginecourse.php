<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Engine dashboard, one course (site administrators): readiness, estimator in charge,
 * last training decision, snapshots and jobs; rebuild the course or train its model.
 *
 * Training is deliberate: it starts only from the button here (or the command line),
 * and the engine refuses it while the course is not ready.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_microlearning\local\engine_client;

$courseid = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

admin_externalpage_setup('local_microlearning_engine', '', ['id' => $courseid],
    new moodle_url('/local/microlearning/enginecourse.php', ['id' => $courseid]));

$course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname');
$client = engine_client::configured() ? new engine_client(null, null, 15) : null;
$returnurl = new moodle_url('/local/microlearning/enginecourse.php', ['id' => $courseid]);

if ($action !== '' && $client) {
    require_sesskey();
    if ($action === 'rebuild' && $course) {
        $task = new \local_microlearning\task\rebuild_course();
        $task->set_custom_data(['courseid' => $courseid]);
        \core\task\manager::queue_adhoc_task($task, true);
        redirect($returnurl, get_string('engine:rebuildqueued', 'local_microlearning'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    } else if ($action === 'train') {
        try {
            $client->start_training($courseid);
            redirect($returnurl, get_string('engine:trainingstarted', 'local_microlearning'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        } catch (\moodle_exception $e) {
            redirect($returnurl, get_string('errorengine', 'local_microlearning', $e->a ?? $e->getMessage()), null,
                \core\output\notification::NOTIFY_ERROR);
        }
    }
    redirect($returnurl);
}

$PAGE->navbar->add($course ? format_string($course->shortname) : (string) $courseid);
$title = get_string('enginecourse', 'local_microlearning',
    $course ? format_string($course->fullname) : get_string('engine:coursedeleted', 'local_microlearning'));
$PAGE->set_title($title);

$page = new \local_microlearning\output\engine_course($courseid, $course ?: null, $client);

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
echo html_writer::link(new moodle_url('/local/microlearning/engine.php'),
    get_string('engine:back', 'local_microlearning'));
echo $OUTPUT->render_from_template('local_microlearning/engine_course', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
