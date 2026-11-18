<?php
// This file is part of Moodle - http://moodle.org/

namespace local_microlearning\task;

use local_microlearning\local\engine_client;

/**
 * Ask the engine to refresh one learner and import the result (queued by the observer).
 *
 * Runs in cron, never in the learner's page request. If the engine is down the
 * task fails and Moodle retries it later; the learner's refresh request stays
 * pending, and the block keeps working from the stored queue meanwhile.
 *
 * Custom data: {courseid, userid}.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_learner extends \core\task\adhoc_task {
    /**
     * Queue a refresh of one learner (once: an identical queued task is reused).
     *
     * @param int $courseid
     * @param int $userid
     */
    public static function queue(int $courseid, int $userid): void {
        $task = new self();
        $task->set_custom_data(['courseid' => $courseid, 'userid' => $userid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    public function execute() {
        $data = $this->get_custom_data();
        if (!engine_client::configured()) {
            return;
        }
        $result = (new engine_client())->refresh_and_import((int) $data->courseid, (int) $data->userid);
        mtrace("local_microlearning: learner {$data->userid} of course {$data->courseid} refreshed ("
            . ($result['kind'] ?? '?') . ', ' . (int) ($result['requestsprocessed'] ?? 0) . ' request(s) answered)');
    }
}
