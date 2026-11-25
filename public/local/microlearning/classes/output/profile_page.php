<?php
// This file is part of Moodle - http://moodle.org/

namespace local_microlearning\output;

use local_microlearning\local\recommendation_window;
use local_microlearning\local\repository;
use renderable;
use renderer_base;
use templatable;

/**
 * One learner's knowledge profile: for teachers and managers, and for the learner themselves.
 *
 * Both see the same layout. The learner's own view ($self) leaves out the
 * comparison with other learners, the numbers behind each recommendation and
 * the technical notes; it never shows anyone else's data.
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

    /** @var bool The learner is looking at their own profile. */
    protected $self;

    /**
     * Constructor.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @param \stdClass $snapshot Active snapshot.
     * @param \stdClass $profile The learner's profile row in it.
     * @param int|null $unit Unit to list topics for (null: the learner's current unit).
     * @param bool $self The viewer is the learner.
     */
    public function __construct(\stdClass $course, \stdClass $user, \stdClass $snapshot, \stdClass $profile, ?int $unit,
            bool $self = false) {
        $this->course = $course;
        $this->user = $user;
        $this->snapshot = $snapshot;
        $this->profile = $profile;
        $this->unit = $unit;
        $this->self = $self;
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

        $unitcounts = [];
        foreach ($states as $state) {
            $unitcounts[(int) $state->unitnumber][$state->knowledgelevel ?: 'not_assessed'] =
                ($unitcounts[(int) $state->unitnumber][$state->knowledgelevel ?: 'not_assessed'] ?? 0) + 1;
        }
        $unitlabels = [];
        foreach ($units as $number => $name) {
            $unitlabels[$number] = get_string('unitn', 'local_microlearning', $number);
        }

        return [
            'self' => $this->self,
            'teacher' => !$this->self,
            'hero' => $this->hero($output, $counts, $assessed, count($states), $answers, $correct),
            'leveldoughnut' => knowledge_display::level_doughnut($output, $counts,
                get_string('triedlevels', 'local_microlearning')),
            'untried' => $counts['not_assessed'],
            'journey' => knowledge_display::journey($units, $unitcounts),
            'levelguide' => knowledge_display::level_guide(),
            'unitchart' => knowledge_display::unit_level_chart($output, $unitlabels, $unitcounts,
                get_string('topicscount', 'local_microlearning')),
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
                'label' => get_string('unitn', 'local_microlearning', $number),
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
     * The header card: picture, name, course progress and the level counts.
     *
     * @param renderer_base $output
     * @param array $counts level => topics
     * @param int $assessed
     * @param int $topics
     * @param int $answers
     * @param int $correct
     * @return array
     */
    protected function hero(renderer_base $output, array $counts, int $assessed, int $topics, int $answers,
            int $correct): array {
        $progress = $this->profile->courseprogress === null ? 0 : (float) $this->profile->courseprogress;
        $chips = [];
        foreach (knowledge_display::LEVELS as $level) {
            $chips[] = knowledge_display::level_badge($level) + [
                'count' => (int) $counts[$level],
                'textcolor' => in_array($level, ['strong', 'weak']) ? '#ffffff' : '#0b0b0b',
            ];
        }
        $p = $this->profile;
        return [
            'picture' => $output->user_picture($this->user, ['size' => 100, 'link' => !$this->self,
                'courseid' => $this->course->id, 'class' => 'ml-hero-picture']),
            'name' => fullname($this->user),
            'course' => format_string($this->course->fullname),
            'progress' => round(100 * $progress),
            'progressdeg' => round(360 * $progress),
            'chips' => $chips,
            'stats' => [
                ['icon' => 'i/completion-auto-y', 'label' => get_string('tile:assessed', 'local_microlearning'),
                    'value' => "$assessed / $topics"],
                ['icon' => 'i/grade_correct', 'label' => get_string('tile:correct', 'local_microlearning'),
                    'value' => $answers ? knowledge_display::percent($correct / $answers) : '–',
                    'detail' => get_string('tile:correct_detail', 'local_microlearning',
                        (object) ['correct' => $correct, 'answers' => $answers])],
                ['icon' => 'i/grades', 'label' => get_string('quizattempts', 'local_microlearning'),
                    'value' => $p->quizattempts ?? '–'],
                ['icon' => 'i/calendar', 'label' => get_string('learningdays', 'local_microlearning'),
                    'value' => $p->learningdays ?? '–'],
                ['icon' => 'i/duration', 'label' => get_string('learningtime', 'local_microlearning'),
                    'value' => $p->learningseconds === null ? '–' : format_time((int) $p->learningseconds)],
            ],
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
            $mine[$row->dimension] = $row->meanobserved === null ? null : (float) $row->meanobserved;
        }
        // The learner's own view has no comparison with other learners.
        $cohort = null;
        if (!$this->self) {
            $means = repository::cohort_dimension_means($this->snapshot->id, $this->profile->population, self::MIN_COHORT);
            $cohort = $means ? array_map(fn($row) => (float) $row->mean, $means) : null;
        }
        return knowledge_display::dimension_chart($output, $order, $mine,
            get_string($this->self ? 'chart:you' : 'chart:learner', 'local_microlearning'), $cohort,
            get_string('chart:cohort', 'local_microlearning'));
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
            foreach ($this->self ? [] : (array) json_decode($rec->evidence ?? '{}', true) as $key => $value) {
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
                'main' => !$items,
                'remediation' => $rec && $rec->recgroup === recommendation_window::GROUP_REMEDIATION,
                'icon' => $item['cm']->get_icon_url()->out(false),
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
            'pending' => $pending && !$this->self ? get_string('refreshpending', 'local_microlearning', (object) [
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
