<?php
// This file is part of Moodle - http://moodle.org/

namespace block_personalized_recommendation;

defined('MOODLE_INTERNAL') || die();

/**
 * Ensures every course has the student recommendation block in its side drawer.
 *
 * @package    block_personalized_recommendation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_provisioner {
    /** Provision this block on all existing non-site courses. */
    public static function add_to_existing_courses(): void {
        global $DB;

        upgrade_set_timeout(3600);
        $courses = $DB->get_recordset_select('course', 'id <> :siteid', ['siteid' => SITEID], '', 'id');
        foreach ($courses as $course) {
            self::ensure_for_course((int) $course->id);
        }
        $courses->close();
    }

    /**
     * Add one inherited side-drawer block to a course when none exists.
     *
     * @param int $courseid Course id.
     */
    public static function ensure_for_course(int $courseid): void {
        global $DB;

        if ($courseid === SITEID) {
            return;
        }

        $context = \context_course::instance($courseid);
        $exists = $DB->record_exists('block_instances', [
            'blockname' => 'personalized_recommendation',
            'parentcontextid' => $context->id,
            'showinsubcontexts' => 1,
            'pagetypepattern' => '*',
        ]);
        if ($exists) {
            return;
        }

        $page = new \moodle_page();
        $page->set_context($context);
        $page->set_pagelayout('incourse');
        $page->blocks->add_region('side-pre', false);
        $page->blocks->add_block(
            'personalized_recommendation',
            'side-pre',
            0,
            true,
            '*'
        );
    }
}
