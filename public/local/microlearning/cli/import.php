<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Import a pipeline bundle into local_microlearning.
 *
 *     php public/local/microlearning/cli/import.php --dir=/tmp/microlearning_bundle --course=10
 *
 * The bundle's manifest says its kind: a snapshot (every learner; becomes the
 * active snapshot) or a learner_update (a per-learner refresh; replaces those
 * learners' rows in the active snapshot).
 *
 * Normally run by `python -m moodle_sync push`. Import is CLI-only on purpose:
 * there is no web upload, so the plugin adds no way to write learner data
 * through the browser.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['dir' => null, 'course' => null, 'help' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error('Unknown option: ' . implode(', ', $unrecognised));
}
if ($options['help'] || !$options['dir'] || !$options['course']) {
    cli_writeln('Import learner profiles and recommendations for one course.');
    cli_writeln('');
    cli_writeln('  --dir=PATH     bundle directory (manifest.json and CSV files)');
    cli_writeln('  --course=ID    id of the course the bundle belongs to');
    exit($options['help'] ? 0 : 1);
}

try {
    $result = \local_microlearning\local\importer::import_any($options['dir'], (int) $options['course']);
} catch (\Throwable $error) {
    cli_error('Import failed, nothing was changed: ' . $error->getMessage());
}
$counts = implode(', ', array_map(fn($file, $rows) => "$rows $file", array_keys($result['rows']), $result['rows']));
if ($result['kind'] === \local_microlearning\local\importer::KIND_SNAPSHOT) {
    cli_writeln("Imported snapshot {$result['snapshotid']} for course {$result['courseid']}: $counts "
        . "({$result['removedsnapshots']} old snapshot(s) removed, {$result['requestsprocessed']} refresh request(s) answered).");
} else {
    cli_writeln("Updated learners [" . implode(', ', $result['updated']) . "] in snapshot {$result['snapshotid']} "
        . "of course {$result['courseid']}" . ($result['skipped'] ? '; skipped (newer data present) ['
        . implode(', ', $result['skipped']) . ']' : '') . ": $counts ({$result['requestsprocessed']} refresh request(s) answered).");
}
// Machine-readable last line for the engine.
cli_writeln('RESULT ' . json_encode($result));
