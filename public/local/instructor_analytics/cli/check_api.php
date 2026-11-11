<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Connectivity check: calls the Python API's /health through the SAME client the
 * plugin uses (core\http_client, so Moodle's HTTP security rules apply) and prints
 * the underlying failure reason, which the UI deliberately hides.
 *
 * Usage (as the web user):
 *   php public/local/instructor_analytics/cli/check_api.php
 *
 * @package    local_instructor_analytics
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');

use local_instructor_analytics\api_client;

// Show the low-level cause that api_client normally logs only at developer level.
set_debugging(DEBUG_DEVELOPER, true);

$url = (string) get_config('local_instructor_analytics', 'api_url');
$hastoken = ((string) get_config('local_instructor_analytics', 'api_token')) !== '';
echo "API URL : " . ($url !== '' ? $url : '(not set)') . "\n";
echo "Token   : " . ($hastoken ? 'set' : 'NOT SET') . "\n";

// --- Diagnose Moodle's HTTP security rules for this URL -------------------------------------
$host = (string) parse_url($url, PHP_URL_HOST);
$port = (int) (parse_url($url, PHP_URL_PORT) ?: 80);
echo "\n--- HTTP security diagnosis ---\n";
echo 'IPv4 addresses : ' . implode(', ', $host !== '' ? (gethostbynamel($host) ?: ['(none)']) : ['(no host)']) . "\n";
$aaaa = $host !== '' ? @dns_get_record($host, DNS_AAAA) : [];
echo 'IPv6 addresses : ' . ($aaaa ? implode(', ', array_column($aaaa, 'ipv6')) : '(none found via DNS; may still be in /etc/hosts)') . "\n";
$flat = fn($v) => implode(', ', array_filter(array_map('trim', preg_split('/\R/', (string) $v))));
echo 'Blocked hosts  : ' . $flat($CFG->curlsecurityblockedhosts ?? '(unset)') . "\n";
echo 'Allowed ports  : ' . $flat($CFG->curlsecurityallowedport ?? '(unset)') . "\n";
try {
    if (class_exists('\core\files\curl_security_helper')) {
        $helper = new \core\files\curl_security_helper();
        foreach (array_unique([80, $port]) as $p) {
            $blocked = $helper->url_is_blocked("http://$host:$p/");
            echo "Moodle says     : http://$host:$p/ is " . ($blocked ? 'BLOCKED' : 'allowed') . "\n";
        }
        echo "(port 80 BLOCKED too => the host's IP is blocked; only $port BLOCKED => the port list)\n";
    }
} catch (\Throwable $e) {
    echo 'Probe unavailable: ' . $e->getMessage() . "\n";
}
echo "-------------------------------\n\n";

try {
    $data = (new api_client())->get('health');
    echo 'OK: ' . json_encode($data) . "\n";
    exit(0);
} catch (moodle_exception $e) {
    echo 'FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}