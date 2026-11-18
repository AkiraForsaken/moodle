<?php
// This file is part of Moodle - http://moodle.org/

namespace local_microlearning\output;

use local_microlearning\local\recommendation_window;
use local_microlearning\local\repository;
use renderable;
use renderer_base;
use templatable;

/**
 * One learner's knowledge profile, for teachers and managers.
 *
 * Everything shown was computed by the pipeline (knowledge levels, skill and
 * dimension summaries, quiz history); this class only arranges it. The only
 * live element is "what the learner sees now", from recommendation_window.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profile_page implements renderable, templatable {
    /** @var int Show a cohort mean only with at least this many learners behind it. */
    const MIN_COHORT = 5;

    /** @var \stdClass */
    protected $course;

    /** @var \stdClass */
    protected $user;

    /** @var \stdClass */
    protected $snapshot;

    /** @var \stdClass */
    protected $profile;

    /** @var int|null Unit whose topics are listed. */
    protected $unit;

    /**
     * Constructor.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @param \stdClass $snapshot Active snapshot.
     * @param \stdClass $profile The learner's profile row in it.
     * @param int|null $unit Unit to list topics for (null: the learner's current unit).
     */
    public function __construct(\stdClass $course, \stdClass $user, \stdClass $snapshot, \stdClass $profile, ?int $unit) {
        $this->course = $course;
        $this->user = $user;
        $this->snapshot = $snapshot;
        $this->profile = $profile;
        $this->unit = $unit;
    }

    /**
     * Template context.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $states = repository::kc_states($this->snapshot->id, $this->user->id);
        usort($states, fn($a, $b) => [(int) $a->unitnumber, (int) $a->kcorder, $a->kcid]
            <=> [(int) $b->unitnumber, (int) $b->kcorder, $b->kcid]);
        $dimensions = $this->dimension_order($states);
        $units = $this->units($states);
        $unit = $this->unit !== null && isset($units[$this->unit]) ? $this->unit : $this->current_unit($states, $units);

        $counts = array_fill_keys(knowledge_display::LEVELS, 0);
        $answers = 0;
        $correct = 0;
        foreach ($states as $state) {
            $counts[$state->knowledgelevel ?: 'not_assessed']++;
            $answers += (int) $state->attemptcount;
            $correct += (int) $state->correctcount;
        }
        $assessed = count($states) - $counts['not_assessed'];
        $modinfo = get_fast_modinfo($this->course);
        $link = function (?int $cmid) use ($modinfo): ?array {
            $cm = $cmid ? ($modinfo->cms[$cmid] ?? null) : null;
            return $cm && $cm->url ? ['name' => $cm->get_formatted_name(), 'url' => $cm->url->out(false)] : null;
        };

        return [
            'tiles' => [
                ['label' => get_string('tile:assessed', 'local_microlearning'),
                    'value' => "$assessed / " . count($states)],
                ['label' => get_string('tile:correct', 'local_microlearning'),
                    'value' => $answers ? knowledge_display::percent($correct / $answers) : '–',
                    'detail' => get_string('tile:correct_detail', 'local_microlearning',
                        (object) ['correct' => $correct, 'answers' => $answers])],
                ['label' => get_string('level:weak', 'local_microlearning'), 'value' => $counts['weak']],
                ['label' => get_string('level:strong', 'local_microlearning'), 'value' => $counts['strong']],
            ],
            'levels' => knowledge_display::segmented_levels($counts),
            'dimensionchart' => $this->dimension_chart($output, $dimensions),
            'heatmap' => $this->heatmap($dimensions, $units),
            'ramplegend' => knowledge_display::ramp_legend(),
            'unittabs' => array_map(fn($number) => [
                'number' => $number,
                'name' => $units[$number],
                'active' => $number === $unit,
                'url' => (new \moodle_url('/local/microlearning/profile.php', ['id' => $this->course->id,
                    'userid' => $this->user->id, 'unit' => $number]))->out(false) . '#topics',
            ], array_keys($units)),
            'unitname' => $units[$unit] ?? '',
            'topics' => array_values(array_map(fn($state) => $this->topic_row($state, $link),
                array_filter($states, fn($state) => (int) $state->unitnumber === $unit))),
            'weak' => $this->weak_topics($states, $link),
            'estimates' => $this->estimated_concerns($states, $link),
            'strengths' => $this->strengths($states),
            'quizchart' => $this->quiz_chart($output),
            'now' => $this->now(),
            'engagement' => $this->engagement(),
        ];
    }

    /**
     * Dimensions in the order the course introduces them.
     *
     * @param \stdClass[] $states Sorted by unit and topic order.
     * @return string[]
     */
    protected function dimension_order(array $states): array {
        $order = [];
        foreach ($states as $state) {
            $order[$state->kcdimension] = true;
        }
        return array_keys($order);
    }

    /**
     * Unit number => unit name.
     *
     * @param \stdClass[] $states
     * @return array
     */
    protected function units(array $states): array {
        $units = [];
        foreach ($states as $state) {
            if ($state->unitnumber !== null && !isset($units[(int) $state->unitnumber])) {
                $units[(int) $state->unitnumber] = $state->topic ?: get_string('unitn', 'local_microlearning',
                    $state->unitnumber);
            }
        }
        ksort($units);
        return $units;
    }

    /**
     * The unit of the learner's most recent answer, else the first unit.
     *
     * @param \stdClass[] $states
     * @param array $units
     * @return int|null
     */
    protected function current_unit(array $states, array $units): ?int {
        $latest = null;
        foreach ($states as $state) {
            if ($state->lastattempt && (!$latest || $state->lastattempt > $latest->lastattempt)) {
                $latest = $state;
            }
        }
        return $latest ? (int) $latest->unitnumber : (array_key_first($units) ?? null);
    }

    /**
     * Horizontal bars: share of correct answers per dimension, learner vs cohort.
     *
     * @param renderer_base $output
     * @param string[] $order
     * @return string|null Chart HTML, or null without answers.
     */
    protected function dimension_chart(renderer_base $output, array $order): ?string {
        $mine = [];
        foreach (repository::dimensions($this->snapshot->id, $this->user->id) as $row) {
            $mine[$row->dimension] = $row;
        }
        if (!array_filter($mine, fn($row) => $row->meanobserved !== null)) {
            return null;
        }
        $cohort = repository::cohort_dimension_means($this->snapshot->id, $this->profile->population, self::MIN_COHORT);
        $labels = array_map([knowledge_display::class, 'dimension_name'], $order);
        $learner = new \core\chart_series(get_string('chart:learner', 'local_microlearning'),
            array_map(fn($d) => isset($mine[$d]) && $mine[$d]->meanobserved !== null
                ? round(100 * $mine[$d]->meanobserved) : null, $order));
        $learner->set_color(knowledge_display::LEARNER_COLOR);
        $chart = new \core\chart_bar();
        $chart->set_horizontal(true);
        $chart->add_series($learner);
        if ($cohort) {
            $context = new \core\chart_series(get_string('chart:cohort', 'local_microlearning'),
                array_map(fn($d) => isset($cohort[$d]) ? round(100 * $cohort[$d]->mean) : null, $order));
            $context->set_color(knowledge_display::CONTEXT_COLOR);
            $chart->add_series($context);
        }
        $chart->set_labels($labels);
        $axis = $chart->get_xaxis(0, true);
        $axis->set_min(0);
        $axis->set_max(100);
        $axis->set_stepsize(20);
        $axis->set_label(get_string('chart:percentcorrect', 'local_microlearning'));
        return $output->render($chart);
    }

    /**
     * Unit x dimension grid of the share of correct answers.
     *
     * @param string[] $dimensions
     * @param array $units
     * @return array
     */
    protected function heatmap(array $dimensions, array $units): array {
        $cells = [];
        foreach (repository::skills($this->snapshot->id, $this->user->id) as $skill) {
            $cells[(int) $skill->unitnumber][$skill->kcdimension] = $skill;
        }
        $rows = [];
        foreach ($units as $number => $name) {
            $row = ['name' => $name, 'cells' => []];
            foreach ($dimensions as $dimension) {
                $skill = $cells[$number][$dimension] ?? null;
                $answered = $skill ? (int) $skill->kccount - (int) $skill->notassessedcount : 0;
                $detail = $skill ? get_string('heat:answered', 'local_microlearning',
                    (object) ['answered' => $answered, 'topics' => (int) $skill->kccount]) : '';
                $share = $skill && $skill->meanobserved !== null ? (float) $skill->meanobserved : null;
                $tooltip = knowledge_display::dimension_name($dimension) . ' · ' . $name . ': '
                    . ($share === null ? get_string('heat:none', 'local_microlearning')
                        : knowledge_display::percent($share) . ' ' . get_string('heat:correct', 'local_microlearning')
                        . ' (' . $detail . ')');
                $row['cells'][] = knowledge_display::heat_cell($share, $detail, $tooltip);
            }
            $rows[] = $row;
        }
        return ['columns' => array_map(fn($d) => ['name' => knowledge_display::dimension_name($d)], $dimensions),
            'rows' => $rows];
    }

    /**
     * One topic of the meter list.
     *
     * @param \stdClass $state
     * @param callable $link
     * @return array
     */
    protected function topic_row(\stdClass $state, callable $link): array {
        $answered = (int) $state->attemptcount > 0;
        return [
            'name' => $state->kcname,
            'dimension' => knowledge_display::dimension_name($state->kcdimension),
            'badge' => knowledge_display::level_badge($state->knowledgelevel),
            'answered' => $answered,
            'width' => $answered ? round(100 * (float) $state->observedaccuracy) : 0,
            'figure' => $answered ? get_string('xofy', 'local_microlearning',
                (object) ['correct' => (int) $state->correctcount, 'answers' => (int) $state->attemptcount]) : '',
            'last' => $state->lastattempt ? userdate($state->lastattempt, get_string('strftimedatefullshort', 'langconfig')) : '',
            'estimate' => !$answered && $state->predictedcorrectness !== null
                ? knowledge_display::percent($state->predictedcorrectness) : null,
            'lesson' => $link($state->lessoncmid ? (int) $state->lessoncmid : null),
            'quiz' => $link($state->quizcmid ? (int) $state->quizcmid : null),
        ];
    }

    /**
     * Evidence-based weak topics, weakest first.
     *
     * @param \stdClass[] $states
     * @param callable $link
     * @return array
     */
    protected function weak_topics(array $states, callable $link): array {
        $weak = array_filter($states, fn($s) => $s->concernstatus === repository::CONCERN_EVIDENCE);
        usort($weak, fn($a, $b) => [(float) $a->observedaccuracy, -(int) $a->attemptcount]
            <=> [(float) $b->observedaccuracy, -(int) $b->attemptcount]);
        return array_map(fn($state) => $this->topic_row($state, $link) + ['unit' => $state->topic],
            array_slice($weak, 0, 6));
    }

    /**
     * Prediction-based concerns (no evidence yet), lowest estimate first.
     *
     * @param \stdClass[] $states
     * @param callable $link
     * @return array
     */
    protected function estimated_concerns(array $states, callable $link): array {
        $estimated = array_filter($states, fn($s) => $s->concernstatus === repository::CONCERN_PREDICTION);
        usort($estimated, fn($a, $b) => (float) $a->predictedcorrectness <=> (float) $b->predictedcorrectness);
        return array_map(fn($state) => $this->topic_row($state, $link) + ['unit' => $state->topic],
            array_slice($estimated, 0, 4));
    }

    /**
     * Strongest topics.
     *
     * @param \stdClass[] $states
     * @return array
     */
    protected function strengths(array $states): array {
        $strong = array_filter($states, fn($s) => $s->knowledgelevel === 'strong');
        usort($strong, fn($a, $b) => [-(float) $a->observedaccuracy, -(int) $a->attemptcount]
            <=> [-(float) $b->observedaccuracy, -(int) $b->attemptcount]);
        return array_map(fn($s) => [
            'name' => $s->kcname,
            'unit' => $s->topic,
            'figure' => get_string('xofy', 'local_microlearning',
                (object) ['correct' => (int) $s->correctcount, 'answers' => (int) $s->attemptcount]),
        ], array_slice($strong, 0, 6));
    }

    /**
     * Line chart of quiz scores over time, with the pass mark.
     *
     * @param renderer_base $output
     * @return string|null
     */
    protected function quiz_chart(renderer_base $output): ?string {
        $attempts = repository::attempts($this->snapshot->id, $this->user->id);
        if (!$attempts) {
            return null;
        }
        $modinfo = get_fast_modinfo($this->course);
        $labels = array_map(function ($attempt) use ($modinfo) {
            $cm = $modinfo->cms[$attempt->cmid] ?? null;
            $name = $cm ? $cm->get_formatted_name() : get_string('missingactivity', 'local_microlearning', $attempt->cmid);
            return userdate($attempt->timefinish, get_string('strftimedateshort', 'langconfig')) . ' · ' . $name
                . ($attempt->attempt > 1 ? ' (' . get_string('attemptn', 'local_microlearning', $attempt->attempt) . ')' : '');
        }, $attempts);
        $score = new \core\chart_series(get_string('chart:score', 'local_microlearning'),
            array_map(fn($a) => $a->score === null ? null : round(100 * $a->score), $attempts));
        $score->set_color(knowledge_display::LEARNER_COLOR);
        $chart = new \core\chart_line();
        $chart->add_series($score);
        $pass = array_map(fn($a) => $a->passfraction === null ? null : round(100 * $a->passfraction), $attempts);
        if (array_filter($pass, fn($v) => $v !== null)) {
            $mark = new \core\chart_series(get_string('chart:passmark', 'local_microlearning'), $pass);
            $mark->set_color(knowledge_display::REFERENCE_COLOR);
            $chart->add_series($mark);
        }
        $chart->set_labels($labels);
        $axis = $chart->get_yaxis(0, true);
        $axis->set_min(0);
        $axis->set_max(100);
        $axis->set_stepsize(20);
        $axis->set_label(get_string('chart:percentmarks', 'local_microlearning'));
        return $output->render($chart);
    }

    /**
     * What the learner's block shows now, and the state of their refresh.
     *
     * @return array
     */
    protected function now(): array {
        $window = new recommendation_window($this->course, (int) $this->user->id, $this->snapshot);
        $items = [];
        foreach ($window->items() as $item) {
            $rec = $item['rec'];
            $evidence = [];
            foreach ((array) json_decode($rec->evidence ?? '{}', true) as $key => $value) {
                if ($value !== null && !is_array($value)) {
                    $evidence[] = "$key: $value";
                }
            }
            $basis = $rec ? $rec->basis : 'course_order';
            $items[] = [
                'name' => $item['cm']->get_formatted_name(),
                'url' => $item['cm']->url->out(false),
                'reason' => $rec ? $rec->reasontext : get_string('fallbackreason', 'local_microlearning'),
                'basis' => get_string('basis:' . $basis, 'local_microlearning'),
                'isestimate' => $basis === 'prediction',
                'evidence' => implode('; ', $evidence),
            ];
        }
        $pending = repository::pending_refresh($this->course->id, $this->user->id);
        $done = count($window->done());
        return [
            'items' => $items,
            'hasitems' => (bool) $items,
            'status' => get_string('nowstatus', 'local_microlearning', (object) [
                'refreshed' => userdate($window->refreshed_at()),
                'done' => $done,
                'left' => max(0, count($window->queue()) - $done),
            ]),
            'pending' => $pending ? get_string('refreshpending', 'local_microlearning', (object) [
                'reason' => get_string('reason:' . $pending->reason, 'local_microlearning'),
                'time' => userdate($pending->timecreated),
            ]) : null,
        ];
    }

    /**
     * Engagement figures (participation, not knowledge).
     *
     * @return array
     */
    protected function engagement(): array {
        $p = $this->profile;
        $rows = [
            ['courseprogress', knowledge_display::percent($p->courseprogress)],
            ['completedactivities', $p->completedactivities ?? '–'],
            ['quizattempts', $p->quizattempts ?? '–'],
            ['sessions', $p->sessioncount ?? '–'],
            ['learningdays', $p->learningdays ?? '–'],
            ['learningtime', $p->learningseconds === null ? '–' : format_time((int) $p->learningseconds)],
            ['lastactive', $p->lastactive ? userdate($p->lastactive) : '–'],
        ];
        return array_map(fn($row) => ['label' => get_string($row[0], 'local_microlearning'),
            'value' => (string) $row[1]], $rows);
    }
}
