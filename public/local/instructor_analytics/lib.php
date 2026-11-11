<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Library callbacks for local_instructor_analytics.
 *
 * @package    local_instructor_analytics
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Adds "Recommendation analytics" to the course navigation ("More" menu).
 *
 * @param navigation_node $parentnode
 * @param stdClass $course
 * @param context_course $context
 */
function local_instructor_analytics_extend_navigation_course(
    navigation_node $parentnode,
    stdClass $course,
    context_course $context
): void {
    if ($course->id == SITEID || !has_capability('local/instructor_analytics:view', $context)) {
        return;
    }
    $parentnode->add(
        get_string('pluginname', 'local_instructor_analytics'),
        new moodle_url('/local/instructor_analytics/index.php', ['id' => $course->id]),
        navigation_node::TYPE_CUSTOM,
        null,
        'localinstructoranalytics',
        new pix_icon('i/report', '')
    );
}
