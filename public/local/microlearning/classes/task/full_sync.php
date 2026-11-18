<?php
// This file is part of Moodle - http://moodle.org/

namespace local_microlearning\task;

use local_microlearning\local\engine_client;

/**
 * Nightly: rebuild every course that has an active snapshot, through the engine.
 *
 * A full run covers what per-learner refreshes cannot: the cohort as a whole,
 * course changes (a new question mapping), and learners whose requests failed.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class full_sync extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('task:fullsync', 'local_microlearning');
    }

    public function execute() {
        global $DB;
        if (!engine_client::configured()) {
            mtrace('local_microlearning: no engine URL configured; nothing to do.');
            return;
        }
        $client = new engine_client();
        $courseids = $DB->get_fieldset_select('local_microlearning_snapshot', 'DISTINCT courseid', 'active = 1');
        $failed = 0;
        foreach ($courseids as $courseid) {
            try {
                $job = $client->full_run((int) $courseid);
                $result = $client->import_bundle((int) $courseid, $job['result']['bundle']['bundle_id']);
                mtrace("local_microlearning: course {$courseid} rebuilt (snapshot {$result['snapshotid']}).");
            } catch (\Throwable $e) {
                $failed++;
                mtrace("local_microlearning: course {$courseid} failed: " . $e->getMessage());
            }
        }
        if ($failed) {
            // Let Moodle record the failure and retry.
            throw new \moodle_exception('errorengine', 'local_microlearning', '', "{$failed} course(s) failed");
        }
    }
}
