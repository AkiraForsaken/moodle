<?php
// This file is part of Moodle - http://moodle.org/

namespace block_personalized_recommendation;

use core\http_client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Server-side client for the Python recommendation API.
 *
 * Uses core\http_client, so Moodle's proxy and "HTTP security" settings
 * (blocked hosts / allowed ports) apply. The token never reaches the browser
 * and is never included in exception messages.
 *
 * @package    block_personalized_recommendation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_client {
    /** @var ClientInterface */
    private $http;

    /**
     * @param ClientInterface|null $http Injectable for tests; defaults to a client built from plugin settings.
     */
    public function __construct(?ClientInterface $http = null) {
        $this->http = $http ?? self::default_http();
    }

    /**
     * Build the HTTP client from admin settings.
     *
     * @return ClientInterface
     */
    private static function default_http(): ClientInterface {
        $url = trim((string) get_config('block_personalized_recommendation', 'api_url'));
        $token = (string) get_config('block_personalized_recommendation', 'api_token');
        if ($url === '' || $token === '') {
            throw new \moodle_exception('apinotconfigured', 'block_personalized_recommendation');
        }
        $timeout = (int) get_config('block_personalized_recommendation', 'timeout');
        return new http_client([
            'base_uri' => rtrim($url, '/') . '/',
            'timeout' => $timeout > 0 ? $timeout : 4,
            'connect_timeout' => 2,
            'http_errors' => false,
            'headers' => ['X-API-Token' => $token, 'Accept' => 'application/json'],
        ]);
    }

    /**
     * GET a JSON resource.
     *
     * @param string $path e.g. "recommend/3"
     * @param array $query query-string parameters
     * @return array decoded JSON object
     */
    public function get(string $path, array $query = []): array {
        return $this->request('GET', $path, $query ? ['query' => $query] : []);
    }

    /**
     * Perform the request and normalise every failure into a moodle_exception.
     *
     * @param string $method
     * @param string $path
     * @param array $options Guzzle request options
     * @return array
     */
    private function request(string $method, string $path, array $options = []): array {
        try {
            $response = $this->http->request($method, ltrim($path, '/'), $options);
        } catch (GuzzleException $e) {
            debugging('Analytics API request failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            throw new \moodle_exception('apiunavailable', 'block_personalized_recommendation');
        }

        $status = $response->getStatusCode();
        if ($status === 401) {
            throw new \moodle_exception('apiauth', 'block_personalized_recommendation');
        }
        if ($status === 409) {
            throw new \moodle_exception('apibusy', 'block_personalized_recommendation');
        }
        if ($status < 200 || $status >= 300) {
            throw new \moodle_exception('apierror', 'block_personalized_recommendation', '', $status);
        }

        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \moodle_exception('apibadresponse', 'block_personalized_recommendation');
        }
        if (!is_array($data)) {
            throw new \moodle_exception('apibadresponse', 'block_personalized_recommendation');
        }
        return $data;
    }
}
