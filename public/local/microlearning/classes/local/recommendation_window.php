<?php
// This file is part of Moodle - http://moodle.org/

namespace local_microlearning\local;

/**
 * What one learner sees now: their recommendation queue minus what they have done since.
 *
 * The pipeline stores each learner's whole ranked queue (every open activity,
 * remediation first). This class only consumes it:
 *
 *  1. it finds which queue items the learner has dealt with since their last
 *     refresh (the moment the pipeline read their data, profile.timerefreshed);
 *  2. it picks the items to show with the same slot rules as the pipeline
 *     (select(); mirrors recommender.ranking.window in Python, and both are
 *     tested against tests/fixtures/recommendation_window.json);
 *  3. if nothing is left but the course still has unfinished activities, it
 *     falls back to the next one in course order, so the list is empty only
 *     when the course is complete.
 *
 * Failing a quiz: the queue also holds precomputed "if this quiz is failed"
 * branches (group contingency, triggercmid = the quiz). Once the learner has
 * failed that quiz since their refresh (completion state COMPLETE_FAIL, with an
 * attempt finished after timerefreshed), effective_queue() puts its branch
 * first, as remediation: the quiz's lessons, then the retry. The engine's
 * refresh later replaces the branch with a list built from the real attempt.
 *
 * needs_refresh() tells the observer when the learner has worked through a
 * full list, so the engine recomputes their recommendations.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recommendation_window {
    /** @var string Slot of remediation items (revise, retry, review, practise). */
    const GROUP_REMEDIATION = 'remediation';

    /** @var string Slot of lessons recommended before the upcoming quiz. */
    const GROUP_PREPARE = 'prepare';

    /** @var string A row of a failure branch, shown only once its quiz is failed. */
    const GROUP_CONTINGENCY = 'contingency';

    /** @var string[] Reasons done once the activity is complete. */
    const COMPLETION_REASONS = ['continue_course', 'start_course', 'prepare_predicted_difficulty'];

    /** @var string[] Reasons done once their lesson is read again. */
    const REREAD_REASONS = ['review_weak_kc', 'revise_before_retry', 'revisit_prerequisite'];

    /** @var \stdClass */
    protected $course;

    /** @var int */
    protected $userid;

    /** @var \stdClass|null Active snapshot of the course. */
    protected $snapshot;

    /** @var \stdClass|null The learner's profile row in it. */
    protected $profile;

    /** @var \stdClass[] Queue rows, best first. */
    protected $queue = [];

    /** @var int[]|null cmid => cmid of queue items dealt with (computed lazily). */
    protected $done = null;

    /** @var int[]|null Failed quizzes with a branch: cmid => time of the failing attempt. */
    protected $active = null;

    /** @var array|null The effective queue: [['cmid', 'group', 'trigger', 'rec'], ...]. */
    protected $effective = null;

    /**
     * Load a learner's queue from the course's active snapshot.
     *
     * @param \stdClass $course
     * @param int $userid
     * @param \stdClass|null $snapshot Active snapshot, if the caller has it already.
     */
    public function __construct(\stdClass $course, int $userid, ?\stdClass $snapshot = null) {
        $this->course = $course;
        $this->userid = $userid;
        $this->snapshot = $snapshot ?? repository::active_snapshot($course->id);
        if ($this->snapshot) {
            $this->profile = repository::profile($this->snapshot->id, $userid);
            $this->queue = repository::recommendations($this->snapshot->id, $userid);
        }
    }

    /**
     * The slot rules: which of the remaining queue items are shown.
     *
     * Pure function, the PHP twin of recommender.ranking.window: drop done
     * items; take up to $maxremediation remediation items, keeping one slot for
     * a forward item if any is left; then prepare lessons (never taking the
     * last slot from plain course order); then course order.
     *
     * @param array $queue [['cmid' => int, 'group' => string], ...] in rank order.
     * @param int[] $done cmids dealt with.
     * @param int $topn
     * @param int $maxremediation
     * @return int[] cmids to show, in order.
     */
    public static function select(array $queue, array $done, int $topn, int $maxremediation): array {
        $done = array_flip(array_map('intval', $done));
        $remediation = [];
        $forward = [];
        foreach ($queue as $item) {
            $cmid = (int) $item['cmid'];
            if (isset($done[$cmid])) {
                continue;
            }
            if ($item['group'] === self::GROUP_REMEDIATION) {
                $remediation[] = ['cmid' => $cmid, 'group' => $item['group']];
            } else {
                $forward[] = ['cmid' => $cmid, 'group' => $item['group']];
            }
        }
        $chosen = [];
        $seen = [];
        $take = function (array $item) use (&$chosen, &$seen, $topn): bool {
            if (isset($seen[$item['cmid']]) || count($chosen) >= $topn) {
                return false;
            }
            $seen[$item['cmid']] = true;
            $chosen[] = $item['cmid'];
            return true;
        };
        $remediationcmids = array_flip(array_column($remediation, 'cmid'));
        $forwardleft = array_values(array_filter($forward, fn($item) => !isset($remediationcmids[$item['cmid']])));
        $reserved = $forwardleft ? 1 : 0;
        $slots = min($maxremediation, $topn - $reserved);
        $taken = 0;
        foreach ($remediation as $item) {
            if ($taken >= $slots) {
                break;
            }
            $taken += $take($item) ? 1 : 0;
        }
        $prepare = array_values(array_filter($forwardleft, fn($item) => $item['group'] === self::GROUP_PREPARE));
        $onward = array_values(array_filter($forwardleft, fn($item) => $item['group'] !== self::GROUP_PREPARE));
        $keepforonward = $onward ? 1 : 0;
        foreach ($prepare as $item) {
            if (count($chosen) >= $topn - $keepforonward) {
                break;
            }
            $take($item);
        }
        foreach ($onward as $item) {
            $take($item);
        }
        return $chosen;
    }

    /**
     * The queue after failures: active branches first, others dropped, each activity once.
     *
     * Pure function, the PHP twin of recommender.ranking.effective_queue. Items
     * may carry 'trigger' (a branch row); extra keys are kept.
     *
     * @param array $queue [['cmid' => int, 'group' => string, 'trigger' => ?int], ...] in rank order.
     * @param int[] $active Quizzes failed since the refresh.
     * @return array The same items without branch rows of other quizzes; activated
     *     branch rows first, with group remediation.
     */
    public static function effective_queue(array $queue, array $active): array {
        $active = array_flip(array_map('intval', $active));
        $branch = [];
        $plain = [];
        foreach ($queue as $item) {
            $trigger = (int) ($item['trigger'] ?? 0);
            if (!$trigger) {
                $plain[] = $item;
            } else if (isset($active[$trigger])) {
                $branch[] = ['group' => self::GROUP_REMEDIATION] + $item;
            }
        }
        $result = [];
        $seen = [];
        foreach (array_merge($branch, $plain) as $item) {
            $cmid = (int) $item['cmid'];
            if (!isset($seen[$cmid])) {
                $seen[$cmid] = true;
                $result[] = $item;
            }
        }
        return $result;
    }

    /**
     * Has the course data for this learner (an active snapshot)?
     *
     * @return bool
     */
    public function has_snapshot(): bool {
        return (bool) $this->snapshot;
    }

    /**
     * Has the learner a profile (and queue) in the active snapshot?
     *
     * @return bool
     */
    public function has_profile(): bool {
        return (bool) $this->profile;
    }

    /**
     * The learner's profile row in the active snapshot, if any.
     *
     * @return \stdClass|null
     */
    public function profile(): ?\stdClass {
        return $this->profile ?: null;
    }

    /**
     * When the pipeline last read this learner's data (0 if never).
     *
     * @return int
     */
    public function refreshed_at(): int {
        if ($this->profile && !empty($this->profile->timerefreshed)) {
            return (int) $this->profile->timerefreshed;
        }
        return $this->snapshot ? (int) ($this->snapshot->collectedat ?: $this->snapshot->timegenerated) : 0;
    }

    /**
     * Items in the list size the snapshot was made with.
     *
     * @return int
     */
    public function top_n(): int {
        return $this->snapshot ? max(1, (int) $this->snapshot->topn) : 5;
    }

    /**
     * The queue rows as the learner meets them now (activated failure branches first).
     *
     * @return \stdClass[]
     */
    public function queue(): array {
        return array_column($this->effective(), 'rec');
    }

    /**
     * Quizzes with a failure branch that the learner failed since their refresh.
     *
     * @return int[] cmid => timefinish of the latest failing attempt
     */
    public function active_triggers(): array {
        if ($this->active !== null) {
            return $this->active;
        }
        $this->active = [];
        $triggers = array_unique(array_filter(array_map(fn($rec) => (int) ($rec->triggercmid ?? 0), $this->queue)));
        if (!$triggers) {
            return $this->active;
        }
        $modinfo = get_fast_modinfo($this->course, $this->userid);
        $completion = new \completion_info($this->course);
        foreach ($this->finished_since($triggers, $this->refreshed_at()) as $cmid => $timefinish) {
            $cm = $modinfo->cms[$cmid] ?? null;
            if ($cm && $completion->is_enabled($cm)
                    && (int) $completion->get_data($cm, false, $this->userid)->completionstate === COMPLETION_COMPLETE_FAIL) {
                $this->active[$cmid] = $timefinish;
            }
        }
        return $this->active;
    }

    /**
     * The effective queue with each item's original row.
     *
     * @return array [['cmid' => int, 'group' => string, 'trigger' => int, 'rec' => \stdClass], ...]
     */
    protected function effective(): array {
        if ($this->effective === null) {
            $items = array_map(fn($rec) => ['cmid' => (int) $rec->cmid, 'group' => $rec->recgroup,
                'trigger' => (int) ($rec->triggercmid ?? 0), 'rec' => $rec], $this->queue);
            $this->effective = self::effective_queue($items, array_keys($this->active_triggers()));
        }
        return $this->effective;
    }

    /**
     * cmids of the queue items the learner has dealt with since the refresh.
     *
     * @return int[]
     */
    public function done(): array {
        if ($this->done !== null) {
            return $this->done;
        }
        $this->done = [];
        $effective = $this->effective();
        if (!$effective) {
            return $this->done;
        }
        // An item of an activated branch counts from the failing attempt; every
        // other item from the learner's refresh.
        $refreshed = $this->refreshed_at();
        $active = $this->active_triggers();
        $sinceof = fn(array $item) => $item['trigger'] ? max($refreshed, $active[$item['trigger']]) : $refreshed;
        $rereadbysince = [];
        $practicebysince = [];
        foreach ($effective as $item) {
            if (in_array($item['rec']->reasoncode, self::REREAD_REASONS)) {
                $rereadbysince[$sinceof($item)][] = $item['cmid'];
            } else if ($item['rec']->reasoncode === 'practice_weak_kc') {
                $practicebysince[$sinceof($item)][] = $item['cmid'];
            }
        }
        $viewed = [];
        foreach ($rereadbysince as $since => $cmids) {
            $viewed[$since] = $this->viewed_since($cmids, $since);
        }
        $practised = [];
        foreach ($practicebysince as $since => $cmids) {
            $practised[$since] = $this->attempted_since($cmids, $since);
        }

        $modinfo = get_fast_modinfo($this->course, $this->userid);
        $completion = new \completion_info($this->course);
        foreach ($effective as $item) {
            $rec = $item['rec'];
            $cmid = $item['cmid'];
            if (in_array($rec->reasoncode, self::REREAD_REASONS)) {
                $isdone = isset($viewed[$sinceof($item)][$cmid]);
            } else if ($rec->reasoncode === 'practice_weak_kc') {
                $isdone = isset($practised[$sinceof($item)][$cmid]);
            } else {
                $cm = $modinfo->cms[$cmid] ?? null;
                if (!$cm || !$completion->is_enabled($cm)) {
                    $isdone = false;
                } else {
                    $state = (int) $completion->get_data($cm, false, $this->userid)->completionstate;
                    $isdone = $rec->reasoncode === 'retry_quiz'
                        ? $state === COMPLETION_COMPLETE_PASS
                        : $state !== COMPLETION_INCOMPLETE;
                }
            }
            if ($isdone) {
                $this->done[$cmid] = $cmid;
            }
        }
        $this->done = array_values($this->done);
        return $this->done;
    }

    /**
     * Has the learner worked through a full list since the last refresh?
     *
     * @return bool
     */
    public function needs_refresh(): bool {
        return $this->effective() && count($this->done()) >= $this->top_n();
    }

    /**
     * What to show now.
     *
     * @return array [['rec' => \stdClass|null, 'cm' => \cm_info, 'fallback' => bool], ...]
     */
    public function items(): array {
        $modinfo = get_fast_modinfo($this->course, $this->userid);
        // Activities the learner cannot open are not offered (and not counted as done).
        $openable = array_values(array_filter($this->effective(), function ($item) use ($modinfo) {
            $cm = $modinfo->cms[$item['cmid']] ?? null;
            return $cm && $cm->uservisible && $cm->url;
        }));
        $rows = array_column($openable, 'rec', 'cmid');
        $queue = array_map(fn($item) => ['cmid' => $item['cmid'], 'group' => $item['group']], $openable);
        $maxremediation = $this->snapshot ? (int) $this->snapshot->maxremediation : 3;
        $chosen = self::select($queue, $this->done(), $this->top_n(), $maxremediation);
        $items = array_map(fn($cmid) => ['rec' => $rows[$cmid], 'cm' => $modinfo->cms[$cmid], 'fallback' => false],
            $chosen);
        if (!$items && ($cm = $this->next_unfinished())) {
            $items[] = ['rec' => null, 'cm' => $cm, 'fallback' => true];
        }
        return $items;
    }

    /**
     * Is every completion-tracked activity the learner can see complete?
     *
     * @return bool
     */
    public function course_complete(): bool {
        return $this->next_unfinished() === null;
    }

    /**
     * The first visible, completion-tracked activity the learner has not completed.
     *
     * @return \cm_info|null
     */
    public function next_unfinished(): ?\cm_info {
        $completion = new \completion_info($this->course);
        if (!$completion->is_enabled()) {
            return null;
        }
        foreach (get_fast_modinfo($this->course, $this->userid)->get_cms() as $cm) {
            if (!$cm->uservisible || !$cm->url || !$completion->is_enabled($cm)) {
                continue;
            }
            $state = (int) $completion->get_data($cm, false, $this->userid)->completionstate;
            if (!in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS])) {
                return $cm;
            }
        }
        return null;
    }

    /**
     * Which of these activities the learner viewed after $since.
     *
     * @param int[] $cmids
     * @param int $since
     * @return array cmid => true
     */
    protected function viewed_since(array $cmids, int $since): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(array_unique($cmids), SQL_PARAMS_NAMED, 'cm');
        $sql = "SELECT DISTINCT contextinstanceid
                  FROM {logstore_standard_log}
                 WHERE userid = :userid AND contextlevel = :level AND contextinstanceid $insql
                       AND crud = 'r' AND timecreated > :since
                       AND " . $DB->sql_like('eventname', ':viewed', false, false);
        $params += ['userid' => $this->userid, 'level' => CONTEXT_MODULE, 'since' => $since,
            'viewed' => '%course_module_viewed'];
        return array_fill_keys(array_map('intval', $DB->get_fieldset_sql($sql, $params)), true);
    }

    /**
     * The latest finished attempt after $since at each of these quizzes.
     *
     * @param int[] $cmids
     * @param int $since
     * @return array cmid => timefinish
     */
    protected function finished_since(array $cmids, int $since): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(array_unique($cmids), SQL_PARAMS_NAMED, 'cm');
        $sql = "SELECT cm.id, MAX(qa.timefinish) AS timefinish
                  FROM {quiz_attempts} qa
                  JOIN {course_modules} cm ON cm.instance = qa.quiz
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                 WHERE qa.userid = :userid AND cm.id $insql AND qa.state = 'finished'
                       AND qa.preview = 0 AND qa.timefinish > :since
              GROUP BY cm.id";
        $params += ['userid' => $this->userid, 'since' => $since];
        return array_map('intval', $DB->get_records_sql_menu($sql, $params));
    }

    /**
     * Which of these quizzes the learner finished an attempt at after $since.
     *
     * @param int[] $cmids
     * @param int $since
     * @return array cmid => true
     */
    protected function attempted_since(array $cmids, int $since): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(array_unique($cmids), SQL_PARAMS_NAMED, 'cm');
        $sql = "SELECT DISTINCT cm.id
                  FROM {quiz_attempts} qa
                  JOIN {course_modules} cm ON cm.instance = qa.quiz
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                 WHERE qa.userid = :userid AND cm.id $insql AND qa.state = 'finished'
                       AND qa.preview = 0 AND qa.timefinish > :since";
        $params += ['userid' => $this->userid, 'since' => $since];
        return array_fill_keys(array_map('intval', $DB->get_fieldset_sql($sql, $params)), true);
    }
}
