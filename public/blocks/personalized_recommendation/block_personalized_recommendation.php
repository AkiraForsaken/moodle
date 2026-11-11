<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Student-facing "Next Best Activity" block.
 *
 * The block only renders a shell. The recommendation is fetched asynchronously
 * (see amd/src/sidebar.js) so a slow or offline analytics API can never delay
 * or break the course page.
 *
 * @package    block_personalized_recommendation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Block class.
 */
class block_personalized_recommendation extends block_base {
    /**
     * Set the block title.
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_personalized_recommendation');
    }

    /**
     * Only inside courses.
     *
     * @return array
     */
    public function applicable_formats(): array {
        return ['course-view' => true, 'mod' => true, 'site' => false, 'my' => false];
    }

    /**
     * One card per course page is enough.
     *
     * @return bool
     */
    public function instance_allow_multiple(): bool {
        return false;
    }

    /**
     * The block has site-level settings (settings.php).
     *
     * @return bool
     */
    public function has_config(): bool {
        return true;
    }

    /**
     * Build the block content (shell only; data arrives via AJAX).
     *
     * @return stdClass
     */
    public function get_content(): stdClass {
        global $OUTPUT;

        if ($this->content !== null) {
            return $this->content;
        }
        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        if (!isloggedin() || isguestuser()) {
            return $this->content;
        }
        $courseid = (int) $this->page->course->id;
        $context = context_course::instance($courseid);
        if (!has_capability('block/personalized_recommendation:view', $context)) {
            return $this->content;
        }

        $rootid = 'block-pr-' . $this->instance->id;
        $this->page->requires->js_call_amd('block_personalized_recommendation/sidebar', 'init', [[
            'rootid' => $rootid,
            'courseid' => $courseid,
        ]]);
        $this->content->text = $OUTPUT->render_from_template('block_personalized_recommendation/sidebar', [
            'rootid' => $rootid,
        ]);
        return $this->content;
    }
}
