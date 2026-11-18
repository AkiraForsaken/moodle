<?php
// This file is part of Moodle - http://moodle.org/

/**
 * "Recommended for you": the student's next activities, each with its reason.
 *
 * The block computes nothing itself. It shows the logged-in user's window of
 * their recommendation queue (local_microlearning\local\recommendation_window):
 * the queue items not yet dealt with since the learner's last refresh, picked
 * with the pipeline's slot rules. Because the queue holds every open activity,
 * the list refills as the student works and is empty only when the course is
 * complete. Working through a full list makes Moodle ask the engine for a
 * refresh of this student (local_microlearning\observer).
 *
 * @package    block_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_microlearning\local\recommendation_window;
use local_microlearning\local\repository;

/**
 * Block class.
 */
class block_microlearning extends block_base {
    /**
     * Set the title.
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_microlearning');
    }

    /**
     * Course pages only.
     *
     * @return array
     */
    public function applicable_formats(): array {
        return ['course-view' => true, 'mod' => true, 'site' => false, 'my' => false];
    }

    /**
     * One instance per course.
     *
     * @return bool
     */
    public function instance_allow_multiple(): bool {
        return false;
    }

    /**
     * Build the content.
     *
     * @return stdClass
     */
    public function get_content(): stdClass {
        global $OUTPUT, $USER;

        if ($this->content !== null) {
            return $this->content;
        }
        $this->content = (object) ['text' => '', 'footer' => ''];
        if (!isloggedin() || isguestuser()) {
            return $this->content;
        }
        $course = $this->page->course;
        $context = context_course::instance($course->id);
        $canviewprofiles = has_capability('local/microlearning:viewprofiles', $context);
        if ($canviewprofiles) {
            $this->content->footer = html_writer::link(
                new moodle_url('/local/microlearning/index.php', ['id' => $course->id]),
                get_string('viewprofiles', 'block_microlearning'));
        }

        // Always the viewer's own queue: a student can never see another learner's list.
        $window = new recommendation_window($course, (int) $USER->id);
        if (!$window->has_snapshot()) {
            // Nothing imported yet: say so to teachers, stay invisible for students.
            if ($canviewprofiles) {
                $this->content->text = get_string('nosnapshot', 'block_microlearning');
            }
            return $this->content;
        }
        if (!$window->has_profile() && $canviewprofiles) {
            // Staff (teachers, managers) are not learners of the snapshot.
            $this->content->text = get_string('teachernote', 'block_microlearning');
            return $this->content;
        }

        $items = [];
        foreach ($window->items() as $item) {
            $cm = $item['cm'];
            $rec = $item['rec'];
            $basis = $rec ? $rec->basis : 'course_order';
            $items[] = [
                'name' => $cm->get_formatted_name(),
                'url' => $cm->url->out(false),
                'icon' => $cm->get_icon_url()->out(false),
                'reason' => $rec ? $rec->reasontext : get_string('fallbackreason', 'block_microlearning'),
                'basis' => get_string('basis:' . $basis, 'block_microlearning'),
                'isestimate' => $basis === 'prediction',
            ];
        }
        $refreshed = $window->refreshed_at();
        $this->content->text = $OUTPUT->render_from_template('block_microlearning/content', [
            'items' => $items,
            'hasitems' => !empty($items),
            'complete' => !$items,
            'updated' => $refreshed ? get_string('updated', 'block_microlearning', userdate($refreshed,
                get_string('strftimedatetimeshort', 'langconfig'))) : '',
            'simulated' => ($window->profile()->population ?? '') === repository::POPULATION_SIMULATED,
        ]);
        return $this->content;
    }
}
