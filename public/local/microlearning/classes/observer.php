<?php
// This file is part of Moodle - http://moodle.org/

namespace local_microlearning;

use local_microlearning\local\recommendation_window;
use local_microlearning\local\repository;

/**
 * Asks the engine to refresh one learner when their recommendations go out of date.
 *
 * Moodle computes no analytics: it writes a request row
 * (local_microlearning_refresh) and, when an engine URL is configured, queues
 * an ad hoc task that asks the engine to recompute this learner and imports
 * the answer (task\refresh_learner). Without an engine URL, an engine worker
 * polls the table instead. Importing the learner's update marks the request
 * processed. Requests are made when
 *
 *  - list_done:   the learner has dealt with a full list (top N items) since
 *                 their last refresh (recommendation_window::needs_refresh);
 *  - quiz_failed: the learner submitted a quiz attempt below its pass mark
 *                 (setting refreshonfail);
 *  - new_learner: a learner was enrolled who has no profile in the snapshot.
 *
 * At most one request per learner and course is pending. An observer must
 * never break the page the learner is on, so every failure is swallowed and
 * reported through debugging() only.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /** @var string Reason: the learner worked through a full list. */
    const REASON_LIST_DONE = 'list_done';

    /** @var string Reason: a quiz attempt below the pass mark. */
    const REASON_QUIZ_FAILED = 'quiz_failed';

    /** @var string Reason: enrolled after the snapshot was made. */
    const REASON_NEW_LEARNER = 'new_learner';

    /**
     * A view or a completion change: has the learner finished their list?
     *
     * @param \core\event\base $event
     */
    public static function activity_changed(\core\event\base $event): void {
        self::guarded(function () use ($event) {
            // Completion events name the learner as the related user.
            $userid = (int) ($event->relateduserid ?: $event->userid);
            $course = self::course_with_snapshot((int) $event->courseid);
            if (!$course || !$userid || self::pending($course->id, $userid)) {
                return;
            }
            $window = new recommendation_window($course, $userid);
            if ($window->has_profile() && $window->needs_refresh()) {
                self::request($course->id, $userid, self::REASON_LIST_DONE);
            }
        });
    }

    /**
     * A submitted quiz attempt: below the pass mark, or the last item of the list?
     *
     * @param \mod_quiz\event\attempt_submitted $event
     */
    public static function quiz_submitted(\core\event\base $event): void {
        self::guarded(function () use ($event) {
            global $CFG, $DB;
            require_once($CFG->libdir . '/gradelib.php');
            $userid = (int) ($event->relateduserid ?: $event->userid);
            $course = self::course_with_snapshot((int) $event->courseid);
            if (!$course || !$userid || self::pending($course->id, $userid)
                    || !repository::profile(repository::active_snapshot($course->id)->id, $userid)) {
                return;
            }
            if (get_config('local_microlearning', 'refreshonfail') !== '0') {
                $attempt = $DB->get_record('quiz_attempts', ['id' => $event->objectid], 'id, quiz, sumgrades');
                $quiz = $attempt ? $DB->get_record('quiz', ['id' => $attempt->quiz], 'id, sumgrades') : null;
                $item = $quiz ? \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz',
                    'iteminstance' => $quiz->id, 'courseid' => $course->id, 'itemnumber' => 0]) : null;
                if ($item && (float) $item->gradepass > 0 && (float) $quiz->sumgrades > 0
                        && $attempt->sumgrades !== null) {
                    $score = (float) $attempt->sumgrades / (float) $quiz->sumgrades;
                    $pass = (float) $item->gradepass / (float) $item->grademax;
                    if ($score + 1e-9 < $pass) {
                        self::request($course->id, $userid, self::REASON_QUIZ_FAILED);
                        return;
                    }
                }
            }
            $window = new recommendation_window($course, $userid);
            if ($window->needs_refresh()) {
                self::request($course->id, $userid, self::REASON_LIST_DONE);
            }
        });
    }

    /**
     * A new enrolment: a learner without a profile gets one at the next refresh.
     *
     * @param \core\event\user_enrolment_created $event
     */
    public static function learner_enrolled(\core\event\base $event): void {
        self::guarded(function () use ($event) {
            $userid = (int) $event->relateduserid;
            $course = self::course_with_snapshot((int) $event->courseid);
            if (!$course || !$userid || self::pending($course->id, $userid)) {
                return;
            }
            $snapshot = repository::active_snapshot($course->id);
            if (!repository::profile($snapshot->id, $userid)) {
                self::request($course->id, $userid, self::REASON_NEW_LEARNER);
            }
        });
    }

    /**
     * Write a request row (one pending request per learner and course).
     *
     * @param int $courseid
     * @param int $userid
     * @param string $reason
     * @return bool True if a request was written.
     */
    public static function request(int $courseid, int $userid, string $reason): bool {
        global $DB;
        if (self::pending($courseid, $userid)) {
            return false;
        }
        $DB->insert_record('local_microlearning_refresh', (object) [
            'courseid' => $courseid,
            'userid' => $userid,
            'reason' => $reason,
            'timecreated' => time(),
            'timeprocessed' => null,
        ]);
        if (local\engine_client::configured()) {
            task\refresh_learner::queue($courseid, $userid);
        }
        return true;
    }

    /**
     * Is a request for this learner still waiting for the engine?
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    public static function pending(int $courseid, int $userid): bool {
        global $DB;
        return $DB->record_exists_select('local_microlearning_refresh',
            'courseid = :courseid AND userid = :userid AND timeprocessed IS NULL',
            ['courseid' => $courseid, 'userid' => $userid]);
    }

    /**
     * The course record if refreshing is on and the course has an active snapshot.
     *
     * @param int $courseid
     * @return \stdClass|null
     */
    protected static function course_with_snapshot(int $courseid): ?\stdClass {
        if ($courseid <= SITEID || get_config('local_microlearning', 'refreshenabled') === '0') {
            return null;
        }
        if (!repository::active_snapshot($courseid)) {
            return null;
        }
        return get_course($courseid);
    }

    /**
     * Run an observer body without ever letting it fail the learner's request.
     *
     * @param callable $body
     */
    protected static function guarded(callable $body): void {
        try {
            $body();
        } catch (\Throwable $error) {
            debugging('local_microlearning observer: ' . $error->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
