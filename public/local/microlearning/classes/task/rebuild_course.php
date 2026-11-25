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

namespace local_microlearning\task;

use local_microlearning\local\engine_client;

/**
 * Rebuild one course through the engine and import the result (started from the engine dashboard).
 *
 * The same work as the nightly full_sync, for one course, so an administrator does not
 * have to wait for the night. Custom data: {"courseid": int}.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rebuild_course extends \core\task\adhoc_task {
    public function get_name() {
        return get_string('task:rebuildcourse', 'local_microlearning');
    }

    public function execute() {
        $courseid = (int) ($this->get_custom_data()->courseid ?? 0);
        if (!$courseid || !engine_client::configured()) {
            mtrace('local_microlearning: nothing to rebuild (no course or no engine URL).');
            return;
        }
        $client = new engine_client();
        $job = $client->full_run($courseid);
        $result = $client->import_bundle($courseid, $job['result']['bundle']['bundle_id']);
        mtrace("local_microlearning: course {$courseid} rebuilt (snapshot {$result['snapshotid']}).");
    }
}
