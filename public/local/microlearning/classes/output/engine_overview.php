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

namespace local_microlearning\output;

use local_microlearning\local\engine_client;
use local_microlearning\local\repository;
use renderable;
use renderer_base;
use templatable;

/**
 * Engine dashboard, overview: is the engine reachable, what is it doing, what waits for it.
 *
 * Combines the engine's own answers (health, recent jobs) with Moodle's records
 * (snapshots, pending refresh requests, failing refresh tasks), so the page is
 * still useful when the engine is down.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine_overview implements renderable, templatable {
    /** @var engine_client|null */
    protected $client;

    /**
     * @param engine_client|null $client null when no engine URL is configured
     */
    public function __construct(?engine_client $client) {
        $this->client = $client;
    }

    public function export_for_template(renderer_base $output): array {
        $health = null;
        $jobs = [];
        $error = '';
        if ($this->client) {
            try {
                $health = $this->client->health();
                $jobs = $this->client->jobs(null, 20)['jobs'] ?? [];
            } catch (\moodle_exception $e) {
                $error = $e->a ?? $e->getMessage();
            }
        }
        $served = array_map('intval', $health['courses'] ?? []);
        $pending = repository::pending_refresh_summary();
        $pendingtotal = array_sum(array_map(fn($row) => (int) $row->requests, $pending));
        $failing = repository::failing_refresh_tasks();

        $tiles = [
            ['label' => get_string('engine:status', 'local_microlearning'),
             'value' => get_string($health ? 'engine:reachable' : 'engine:unreachable', 'local_microlearning'),
             'detail' => $health ? get_string('engine:version', 'local_microlearning', s($health['version'] ?? '?')) : '',
             'color' => $health ? '#008300' : '#d03b3b'],
            ['label' => get_string('engine:courses', 'local_microlearning'), 'value' => count($served),
             'detail' => '', 'color' => '#2a78d6'],
            ['label' => get_string('engine:activejobs', 'local_microlearning'),
             'value' => (int) ($health['active_jobs'] ?? 0), 'detail' => '', 'color' => '#2a78d6'],
            ['label' => get_string('engine:pendingrequests', 'local_microlearning'), 'value' => $pendingtotal,
             'detail' => '', 'color' => $pendingtotal ? '#eda100' : '#008300'],
            ['label' => get_string('engine:failingtasks', 'local_microlearning'), 'value' => $failing,
             'detail' => '', 'color' => $failing ? '#d03b3b' : '#008300'],
        ];

        return [
            'configured' => (bool) $this->client,
            'settingsurl' => (new \moodle_url('/admin/settings.php', ['section' => 'local_microlearning']))->out(false),
            'error' => $error,
            'tiles' => $tiles,
            'courses' => $this->courses($served),
            'hascourses' => true, // The table always lists at least the header.
            'jobs' => engine_format::jobs($jobs, true),
            'hasjobs' => !empty($jobs),
            'pending' => engine_format::pending($pending),
            'haspending' => !empty($pending),
        ];
    }

    /**
     * One row per course the engine serves or Moodle holds snapshots for.
     *
     * @param int[] $served course ids with an engine workspace
     * @return array
     */
    protected function courses(array $served): array {
        global $DB;
        $snapshots = repository::snapshot_courses();
        $ids = array_unique(array_merge($served, array_map('intval', array_keys($snapshots))));
        sort($ids);
        $rows = [];
        foreach ($ids as $courseid) {
            $snap = $snapshots[$courseid] ?? null;
            $active = repository::active_snapshot($courseid);
            $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname');
            $rows[] = [
                'courseid' => $courseid,
                'name' => $course ? format_string($course->fullname) : get_string('engine:coursedeleted', 'local_microlearning'),
                'exists' => (bool) $course,
                'url' => (new \moodle_url('/local/microlearning/enginecourse.php', ['id' => $courseid]))->out(false),
                'served' => in_array($courseid, $served, true),
                'snapshots' => $snap ? (int) $snap->snapshots : 0,
                'lastimport' => $active ? userdate($active->timeimported, get_string('strftimedatetimeshort', 'langconfig')) : '-',
            ];
        }
        return $rows;
    }
}
