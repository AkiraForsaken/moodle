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

/**
 * Shared formatting of the engine dashboard: jobs, refresh requests, engine timestamps.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine_format {
    /**
     * An engine timestamp (ISO 8601, UTC) as a Unix time, or null.
     *
     * @param string|null $iso
     * @return int|null
     */
    public static function time(?string $iso): ?int {
        if (!$iso) {
            return null;
        }
        $time = strtotime($iso);
        return $time === false ? null : $time;
    }

    /**
     * An engine timestamp for display.
     *
     * @param string|null $iso
     * @return string
     */
    public static function date(?string $iso): string {
        $time = self::time($iso);
        return $time ? userdate($time, get_string('strftimedatetimeshort', 'langconfig')) : '-';
    }

    /**
     * Job rows for a table.
     *
     * @param array $jobs as the engine describes them
     * @param bool $withcourse show the course column
     * @return array
     */
    public static function jobs(array $jobs, bool $withcourse): array {
        $states = ['queued' => 'secondary', 'running' => 'info', 'done' => 'success', 'failed' => 'danger'];
        $rows = [];
        foreach ($jobs as $job) {
            $start = self::time($job['started_at'] ?? null);
            $end = self::time($job['finished_at'] ?? null);
            $learners = $job['learners'] ?? null;
            $state = (string) ($job['state'] ?? 'unknown');
            $rows[] = [
                'withcourse' => $withcourse,
                'courseid' => (int) ($job['course_id'] ?? 0),
                'kind' => get_string('engine:job:' . ($job['kind'] ?? 'unknown'), 'local_microlearning'),
                'learners' => is_array($learners) ? count($learners) : '-',
                'state' => get_string('engine:state:' . $state, 'local_microlearning'),
                'statecolor' => $states[$state] ?? 'secondary',
                'started' => self::date($job['started_at'] ?? $job['created_at'] ?? null),
                'duration' => ($start && $end) ? format_time(max(1, $end - $start)) : '-',
                'error' => isset($job['error']) ? shorten_text(s($job['error']), 160) : '',
            ];
        }
        return $rows;
    }

    /**
     * Pending refresh requests by reason.
     *
     * @param array $summary reason => record (reason, requests, oldest)
     * @return array
     */
    public static function pending(array $summary): array {
        $rows = [];
        foreach ($summary as $row) {
            $rows[] = [
                'reason' => get_string('engine:reason:' . $row->reason, 'local_microlearning'),
                'requests' => (int) $row->requests,
                'oldest' => format_time(max(1, time() - (int) $row->oldest)),
            ];
        }
        return $rows;
    }
}
