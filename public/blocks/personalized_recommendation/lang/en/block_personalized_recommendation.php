<?php
// This file is part of Moodle - http://moodle.org/

/**
 * English strings for block_personalized_recommendation.
 *
 * @package    block_personalized_recommendation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Your Personalized Recommendation';
$string['personalized_recommendation:addinstance'] = 'Add a new personalized Recommendation block';
$string['personalized_recommendation:myaddinstance'] = 'Add a new personalized Recommendation block to Dashboard';
$string['personalized_recommendation:view'] = 'See personalized activity recommendations';

$string['heading'] = 'Next Best Activity';
$string['loading'] = 'Finding your next activity…';
$string['start'] = 'Start activity';
$string['review'] = 'Review';
$string['alsoconsider'] = 'Also worth a look';
$string['minutes'] = '{$a} min';
$string['whythis'] = 'Why this?';
$string['empty'] = 'No recommendation is available for you right now. Keep exploring the course and check back soon.';
$string['unavailable'] = 'Recommendations are temporarily unavailable. The rest of the course works as usual.';
$string['notlearner'] = 'Recommendations are shown to enrolled students.';

$string['apinotconfigured'] = 'The recommendation API URL or token is not configured.';
$string['apiunavailable'] = 'The recommendation service could not be reached.';
$string['apiauth'] = 'The recommendation service rejected the configured API token.';
$string['apibusy'] = 'The recommendation service is busy.';
$string['apierror'] = 'The recommendation service returned an error (HTTP {$a}).';
$string['apibadresponse'] = 'The recommendation service returned an unreadable response.';

$string['setting_api_url'] = 'API base URL';
$string['setting_api_url_desc'] = 'Address of the Python FastAPI service as seen from the Moodle server (inside Docker usually http://host.docker.internal:8010). Moodle\'s HTTP security settings must allow this host and port.';
$string['setting_api_token'] = 'API token';
$string['setting_api_token_desc'] = 'Shared secret sent in the X-API-Token header. Must equal API_TOKEN on the Python service.';
$string['setting_timeout'] = 'Request timeout (seconds)';
$string['setting_timeout_desc'] = 'Keep this short: students wait for it when the service is slow.';
$string['setting_method'] = 'Recommendation method';
$string['setting_method_desc'] = 'Which baseline recommender serves students. Useful for A/B comparison during evaluation.';
$string['setting_maxitems'] = 'Items shown';
$string['setting_maxitems_desc'] = 'How many activities to show, including the main recommendation (1 to 5).';

$string['privacy:metadata:recsys_api'] = 'The Moodle user id is sent to the recommendation service to look up that learner\'s recommendations.';
$string['privacy:metadata:recsys_api:userid'] = 'The Moodle user id of the learner.';
