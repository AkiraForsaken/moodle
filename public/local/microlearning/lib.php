<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Callbacks and shared display helpers of local_microlearning.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add "Learner profiles" (teachers) or "My learning profile" (learners) to the course navigation.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context $context
 */
function local_microlearning_extend_navigation_course($navigation, $course, $context) {
    if (!has_capability('local/microlearning:viewprofiles', $context)) {
        if (has_capability('local/microlearning:viewownprofile', $context) && is_enrolled($context)) {
            $navigation->add(
                get_string('myprofile', 'local_microlearning'),
                new moodle_url('/local/microlearning/profile.php', ['id' => $course->id]),
                navigation_node::TYPE_SETTING,
                null,
                'local_microlearning_self',
                new pix_icon('i/user', '')
            );
        }
        return;
    }
    $navigation->add(
        get_string('learnerprofiles', 'local_microlearning'),
        new moodle_url('/local/microlearning/index.php', ['id' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_microlearning',
        new pix_icon('i/report', '')
    );
    if (has_capability('moodle/site:config', context_system::instance())) {
        $navigation->add(
            get_string('enginecoursenav', 'local_microlearning'),
            new moodle_url('/local/microlearning/enginecourse.php', ['id' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_microlearning_engine',
            new pix_icon('i/settings', '')
        );
    }
}

/**
 * A share (0..1) as a percentage, or a dash when unknown.
 *
 * @param float|string|null $value
 * @return string
 */
function local_microlearning_percent($value): string {
    return $value === null || $value === '' ? '–' : format_float(100 * (float) $value, 0) . ' %';
}

/**
 * The line that says where a snapshot comes from; shown on every teacher page.
 *
 * @param stdClass $snapshot
 * @return string HTML
 */
function local_microlearning_snapshot_notice(stdClass $snapshot): string {
    global $OUTPUT;
    $counts = \local_microlearning\local\repository::population_counts($snapshot->id);
    $parts = [];
    foreach ($counts as $population => $count) {
        $parts[] = $count . ' ' . get_string('population:' . $population, 'local_microlearning');
    }
    $text = get_string('snapshotinfo', 'local_microlearning', (object) [
        'generated' => userdate($snapshot->timegenerated),
        'imported' => userdate($snapshot->timeimported),
        'learners' => implode(', ', $parts),
    ]);
    $html = $OUTPUT->notification($text, \core\output\notification::NOTIFY_INFO, false);
    if (isset($counts[\local_microlearning\local\repository::POPULATION_SIMULATED])) {
        $html .= $OUTPUT->notification(get_string('simulatedwarning', 'local_microlearning'),
            \core\output\notification::NOTIFY_WARNING, false);
    }
    return $html;
}

/**
 * Badge marking a simulated learner (empty for a real one).
 *
 * @param string $population
 * @return string HTML
 */
function local_microlearning_population_badge(string $population): string {
    if ($population !== \local_microlearning\local\repository::POPULATION_SIMULATED) {
        return '';
    }
    return ' ' . html_writer::span(get_string('population:simulated', 'local_microlearning'),
        'badge bg-warning text-dark');
}
