<?php
// This file is part of Moodle - http://moodle.org/

namespace local_microlearning\local;

/**
 * Talks to the microlearning engine (the Python service, api/ in the engine repository).
 *
 * Moodle asks; the engine computes and answers with the id of a finished
 * bundle, which Moodle downloads and imports with the importer (all its
 * validation applies). The engine never writes to Moodle. Settings:
 * local_microlearning | engineurl, enginetoken, enginetimeout.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine_client {
    /** @var string Base URL, without a trailing slash. */
    protected $url;

    /** @var string Shared secret sent as X-API-Token. */
    protected $token;

    /** @var int Seconds a call may take (a full run of a course takes a minute or more). */
    protected $timeout;

    /**
     * @param string|null $url Defaults to the plugin setting.
     * @param string|null $token
     * @param int|null $timeout
     */
    public function __construct(?string $url = null, ?string $token = null, ?int $timeout = null) {
        $this->url = rtrim($url ?? (string) get_config('local_microlearning', 'engineurl'), '/');
        $this->token = $token ?? (string) get_config('local_microlearning', 'enginetoken');
        $this->timeout = $timeout ?? max(10, (int) (get_config('local_microlearning', 'enginetimeout') ?: 300));
    }

    /**
     * Is an engine configured at all? Without one, requests stay in the refresh table
     * for an engine worker that polls it.
     *
     * @return bool
     */
    public static function configured(): bool {
        return trim((string) get_config('local_microlearning', 'engineurl')) !== '';
    }

    /**
     * The engine's /health answer.
     *
     * @return array
     */
    public function health(): array {
        return $this->call('GET', '/health');
    }

    /**
     * Recompute one learner; return the finished job (with result.bundle.bundle_id).
     *
     * @param int $courseid
     * @param int $userid
     * @return array
     */
    public function refresh(int $courseid, int $userid): array {
        return $this->finished($this->call('POST', "/v1/courses/{$courseid}/learners/{$userid}/refresh?wait=1"));
    }

    /**
     * Rebuild the whole course; return the finished job.
     *
     * @param int $courseid
     * @return array
     */
    public function full_run(int $courseid): array {
        return $this->finished($this->call('POST', "/v1/courses/{$courseid}/runs?wait=1"));
    }

    /**
     * Recent engine jobs, newest first (engine dashboard).
     *
     * @param int|null $courseid only this course's jobs
     * @param int $limit
     * @return array ['jobs' => [...], 'active' => int]
     */
    public function jobs(?int $courseid = null, int $limit = 20): array {
        $query = '?limit=' . $limit . ($courseid ? '&course_id=' . $courseid : '');
        return $this->call('GET', '/v1/jobs' . $query);
    }

    /**
     * A course's readiness for training per learner population and the recorded decision.
     *
     * @param int $courseid
     * @return array
     */
    public function course_status(int $courseid): array {
        return $this->call('GET', "/v1/courses/{$courseid}/status");
    }

    /**
     * Start training the course's deep model; returns the queued job at once.
     *
     * The engine refuses (HTTP 409) when the course is not ready: training is never forced from Moodle.
     *
     * @param int $courseid
     * @return array
     */
    public function start_training(int $courseid): array {
        return $this->call('POST', "/v1/courses/{$courseid}/train");
    }

    /**
     * Download a bundle and import it into the course.
     *
     * @param int $courseid
     * @param string $bundleid
     * @return array The importer's result.
     */
    public function import_bundle(int $courseid, string $bundleid): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        if (!preg_match('/^[0-9a-f]{20}$/', $bundleid)) {
            throw new \moodle_exception('errorengine', 'local_microlearning', '', 'bad bundle id');
        }
        $dir = make_request_directory();
        $zip = $dir . '/bundle.zip';
        $this->call('GET', "/v1/courses/{$courseid}/bundles/{$bundleid}.zip", $zip);
        $extracted = $dir . '/bundle';
        $packer = get_file_packer('application/zip');
        if (!$packer->extract_to_pathname($zip, $extracted)) {
            throw new \moodle_exception('errorengine', 'local_microlearning', '', 'unreadable bundle');
        }
        return importer::import_any($extracted, $courseid);
    }

    /**
     * Refresh a learner and import the result; fall back to a full run when Moodle
     * cannot apply a learner update (no snapshot yet, or the question mapping changed).
     *
     * @param int $courseid
     * @param int $userid
     * @return array The importer's result.
     */
    public function refresh_and_import(int $courseid, int $userid): array {
        $job = $this->refresh($courseid, $userid);
        try {
            return $this->import_bundle($courseid, $job['result']['bundle']['bundle_id']);
        } catch (\moodle_exception $e) {
            if (!in_array($e->errorcode, ['errornosnapshotforupdate', 'errormappingchanged'])) {
                throw $e;
            }
            $job = $this->full_run($courseid);
            return $this->import_bundle($courseid, $job['result']['bundle']['bundle_id']);
        }
    }

    /**
     * A job answer must be finished (200); 202 means the engine is still working.
     *
     * @param array $job
     * @return array
     */
    protected function finished(array $job): array {
        if (($job['state'] ?? '') !== 'done' || empty($job['result']['bundle']['bundle_id'])) {
            throw new \moodle_exception('errorengine', 'local_microlearning', '',
                'job ' . ($job['job_id'] ?? '?') . ' is ' . ($job['state'] ?? 'unknown'));
        }
        return $job;
    }

    /**
     * One HTTP call. JSON in and out, or a file download when $savepath is given.
     *
     * @param string $method
     * @param string $path
     * @param string|null $savepath
     * @return array
     */
    protected function call(string $method, string $path, ?string $savepath = null): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        if ($this->url === '') {
            throw new \moodle_exception('errorengine', 'local_microlearning', '', 'no engine URL configured');
        }
        // The URL is set by the site administrator and is usually a private address
        // (the engine runs next to Moodle), which curl's default host blocklist refuses.
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader(['X-API-Token: ' . $this->token, 'Accept: application/json']);
        $options = ['CURLOPT_TIMEOUT' => $this->timeout, 'CURLOPT_CONNECTTIMEOUT' => 10];
        $url = $this->url . $path;
        if ($savepath !== null) {
            $curl->download_one($url, null, $options + ['filepath' => $savepath]);
        } else if ($method === 'POST') {
            $curl->setHeader(['Content-Type: application/json']);
            $body = $curl->post($url, '{}', $options);
        } else {
            $body = $curl->get($url, null, $options);
        }
        $info = $curl->get_info();
        $status = (int) ($info['http_code'] ?? 0);
        if ($curl->get_errno() || $status < 200 || $status >= 300) {
            $detail = $curl->error ?: ('HTTP ' . $status . ' ' . substr((string) ($body ?? ''), 0, 300));
            throw new \moodle_exception('errorengine', 'local_microlearning', '', $detail);
        }
        if ($savepath !== null) {
            return ['saved' => $savepath];
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('errorengine', 'local_microlearning', '', 'not JSON');
        }
        return $decoded;
    }
}
