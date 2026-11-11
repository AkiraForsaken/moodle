<?php
// This file is part of Moodle - http://moodle.org/

/**
 * English strings for local_instructor_analytics.
 *
 * The file name must be exactly local_instructor_analytics.php; the previous
 * instructor_analytics.php was never loaded by Moodle.
 *
 * @package    local_instructor_analytics
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Recommendation analytics';
$string['dashboardtitle'] = 'Class knowledge state';
$string['heatmaptitle'] = 'Mastery heatmap';
$string['topicmasterytitle'] = 'Mastery distribution by topic';
$string['topicmasteryaria'] = '{$a->topic}: high {$a->high}, medium {$a->medium}, low {$a->low}, unknown {$a->unknown} (out of {$a->total} learners with data)';
$string['topicmasterysegment'] = '{$a->band}: {$a->count} learners ({$a->percent}%)';
$string['topicmasterynodata'] = 'There is no learner mastery data to chart yet.';
$string['runpipeline'] = 'Run pipeline and update model';
$string['running'] = 'Running pipeline…';
$string['updated'] = 'Model updated';
$string['pipelinefailed'] = 'The pipeline did not complete: {$a}';
$string['loading'] = 'Loading analytics…';
$string['loadfailed'] = 'The analytics could not be loaded: {$a}';
$string['learner'] = 'Learner';
$string['nolearners'] = 'No enrolled learners were found in this course.';
$string['nodata'] = 'No data';
$string['band_high'] = 'High';
$string['band_medium'] = 'Medium';
$string['band_low'] = 'Low';
$string['band_unknown'] = 'Unknown';
$string['trend_up'] = 'Improving';
$string['trend_down'] = 'Declining';
$string['trend_flat'] = 'Unchanged';
$string['bestscore'] = 'Best score';
$string['trend'] = 'Score trend';
$string['learnerlabel'] = 'Learner {$a}';
$string['legend'] = 'Mastery bands come from the learner model (default thresholds: high at 80% or above, medium at 60% or above, otherwise low). Unknown means no scored attempt yet.';
$string['updatedat'] = 'Model data from {$a}';

$string['apinotconfigured'] = 'The analytics API URL or token is not configured (Site administration > Plugins > Local plugins).';
$string['apiunavailable'] = 'The analytics service could not be reached.';
$string['apiauth'] = 'The analytics service rejected the configured API token.';
$string['apibusy'] = 'The pipeline is already running. Try again in a moment.';
$string['apierror'] = 'The analytics service returned an error (HTTP {$a}).';
$string['apibadresponse'] = 'The analytics service returned an unreadable response.';

$string['setting_api_url'] = 'API base URL';
$string['setting_api_url_desc'] = 'Address of the Python FastAPI service as seen from the Moodle server. Inside Docker this is usually http://host.docker.internal:8010, not localhost. Moodle\'s HTTP security settings must allow this host and port.';
$string['setting_api_token'] = 'API token';
$string['setting_api_token_desc'] = 'Shared secret sent in the X-API-Token header. Must equal API_TOKEN on the Python service.';
$string['setting_timeout'] = 'Request timeout (seconds)';
$string['setting_timeout_desc'] = 'Timeout for heatmap requests.';
$string['setting_pipeline_timeout'] = 'Pipeline timeout (seconds)';
$string['setting_pipeline_timeout_desc'] = 'Timeout for the Run pipeline request, including collecting fresh data from Moodle.';
$string['setting_anonymize'] = 'Anonymise learners';
$string['setting_anonymize_desc'] = 'Show "Learner 1, Learner 2, ..." instead of names on the dashboard (useful for screenshots in the thesis).';

$string['instructor_analytics:view'] = 'View recommendation analytics for a course';
$string['instructor_analytics:runpipeline'] = 'Run the learner model pipeline';
$string['privacy:metadata'] = 'The Recommendation analytics plugin does not store any personal data.';
