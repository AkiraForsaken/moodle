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
 * Engine dashboard, overview (site administrators): engine status, courses, jobs, refresh requests.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_microlearning\local\engine_client;

admin_externalpage_setup('local_microlearning_engine');

$client = engine_client::configured() ? new engine_client(null, null, 15) : null;
$page = new \local_microlearning\output\engine_overview($client);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('enginedashboard', 'local_microlearning'));
echo $OUTPUT->render_from_template('local_microlearning/engine_overview', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
