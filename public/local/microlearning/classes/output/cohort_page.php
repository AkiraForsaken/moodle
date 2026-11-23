<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_microlearning\output;

use local_microlearning\local\repository;
use renderable;
use renderer_base;
use templatable;

/**
 * A group of learners at a glance (teachers and managers): the whole course, one
 * population, or one Moodle group. Charts summarise everyone in the selection;
 * the learner list below them is paginated, searchable and sortable.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohort_page implements renderable, templatable {
    /** @var int A cohort mean needs at least this many learners with answers. */
    const MIN_COHORT = 5;

    /** @var string[] Sort keys of the learner list. */
    const SORTS = ['weak', 'correct', 'answered', 'name', 'refreshed'];

    /** @var \stdClass */
    protected $course;

    /** @var \stdClass */
    protected $snapshot;

    /** @var string|null */
    protected $population;

    /** @var int|null Moodle group. */
    protected $groupid;

    /** @var array List options: page, perpage, search, sort. */
    protected $list;

    /** @var \moodle_url Base URL of the page (with the filters). */
    protected $baseurl;

    /**
     * Constructor.
     *
     * @param \stdClass $course
     * @param \stdClass $snapshot
     * @param string|null $population Show one population only.
     * @param int|null $groupid Show one Moodle group only.
     * @param array $list ['page' => int, 'perpage' => int, 'search' => string, 'sort' => string]
     * @param \moodle_url $baseurl
     */
    public function __construct(\stdClass $course, \stdClass $snapshot, ?string $population, ?int $groupid,
            array $list, \moodle_url $baseurl) {
        $this->course = $course;
        $this->snapshot = $snapshot;
        $this->population = $population;
        $this->groupid = $groupid ?: null;
        $this->list = $list + ['page' => 0, 'perpage' => 25, 'search' => '', 'sort' => 'weak'];
        $this->baseurl = $baseurl;
    }

    /**
     * Template context.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $learners = repository::cohort($this->snapshot->id, $this->population, $this->groupid);
        $answers = array_sum(array_column($learners, 'answers'));
        $correct = array_sum(array_column($learners, 'correct'));
        $withanswers = count(array_filter($learners, fn($l) => $l->answers > 0));
        $withweak = count(array_filter($learners, fn($l) => $l->weaktopics > 0));
        $progress = array_filter(array_map(fn($l) => $l->courseprogress, $learners), fn($v) => $v !== null);
        [$units, $dimensions] = $this->structure();
        $levelcounts = repository::cohort_level_counts($this->snapshot->id, $this->population, $this->groupid);

        return [
            'tiles' => [
                ['label' => get_string('learners', 'local_microlearning'), 'value' => count($learners),
                    'color' => knowledge_display::DIMENSION_COLORS[0]],
                ['label' => get_string('tile:withanswers', 'local_microlearning'), 'value' => $withanswers,
                    'color' => knowledge_display::DIMENSION_COLORS[2]],
                ['label' => get_string('tile:correct', 'local_microlearning'),
                    'value' => $answers ? knowledge_display::percent($correct / $answers) : '–',
                    'detail' => get_string('tile:correct_detail', 'local_microlearning',
                        (object) ['correct' => $correct, 'answers' => $answers]),
                    'color' => knowledge_display::LEVEL_COLORS['strong']],
                ['label' => get_string('tile:meanprogress', 'local_microlearning'),
                    'value' => $progress ? knowledge_display::percent(array_sum($progress) / count($progress)) : '–',
                    'color' => knowledge_display::DIMENSION_COLORS[3]],
                ['label' => get_string('tile:withweak', 'local_microlearning'), 'value' => $withweak,
                    'color' => knowledge_display::LEVEL_COLORS['weak']],
            ],
            'leveldoughnut' => knowledge_display::level_doughnut($output, $levelcounts,
                get_string('triedlevels', 'local_microlearning')),
            'untried' => (int) ($levelcounts['not_assessed'] ?? 0) + (int) ($levelcounts[''] ?? 0),
            'levelguide' => knowledge_display::level_guide(),
            'unitchart' => knowledge_display::unit_level_chart($output,
                array_combine(array_keys($units), array_map(fn($n) => get_string('unitn', 'local_microlearning', $n),
                    array_keys($units))),
                repository::cohort_unit_level_counts($this->snapshot->id, $this->population, $this->groupid),
                get_string('learnertopics', 'local_microlearning')),
            'dimensionchart' => $this->dimension_chart($output, $dimensions),
            'histogram' => $this->histogram($output, $learners),
            'heatmap' => $this->heatmap($units, $dimensions),
            'ramplegend' => knowledge_display::ramp_legend(),
            'hardest' => $this->hardest(),
        ] + $this->learner_list($output, $learners);
    }

    /**
     * Units (number => name) and dimensions of the course, from one learner's rows.
     *
     * @return array [units, dimensions]
     */
    protected function structure(): array {
        global $DB;
        $any = $DB->get_field_select('local_microlearning_kcstate', 'MIN(userid)', 'snapshotid = ?', [$this->snapshot->id]);
        $states = $any ? repository::kc_states($this->snapshot->id, (int) $any) : [];
        usort($states, fn($a, $b) => [(int) $a->unitnumber, (int) $a->kcorder] <=> [(int) $b->unitnumber, (int) $b->kcorder]);
        $units = [];
        $dimensions = [];
        foreach ($states as $state) {
            $units[(int) $state->unitnumber] = $units[(int) $state->unitnumber] ?? $state->topic;
            $dimensions[$state->kcdimension] = true;
        }
        return [$units, array_keys($dimensions)];
    }

    /**
     * Mean share of correct answers per skill over the selection (at least MIN_COHORT learners).
     *
     * @param renderer_base $output
     * @param string[] $dimensions
     * @return string|null
     */
    protected function dimension_chart(renderer_base $output, array $dimensions): ?string {
        $means = repository::cohort_dimension_means($this->snapshot->id, $this->population, self::MIN_COHORT,
            $this->groupid);
        return knowledge_display::dimension_chart($output, $dimensions,
            array_map(fn($row) => (float) $row->mean, $means), get_string('chart:groupmean', 'local_microlearning'));
    }

    /**
     * How many learners reach each band of correct answers (learners with answers only).
     *
     * @param renderer_base $output
     * @param \stdClass[] $learners
     * @return string|null
     */
    protected function histogram(renderer_base $output, array $learners): ?string {
        $bins = array_fill(0, 5, 0);
        $any = false;
        foreach ($learners as $learner) {
            if ($learner->answers > 0) {
                $bins[min(4, (int) floor(5 * $learner->correct / $learner->answers))]++;
                $any = true;
            }
        }
        if (!$any) {
            return null;
        }
        $series = new \core\chart_series(get_string('learners', 'local_microlearning'), $bins);
        $series->set_colors(knowledge_display::RAMP);
        $chart = new \core\chart_bar();
        $chart->add_series($series);
        $chart->set_labels(array_map(fn($i) => (20 * $i) . '–' . (20 * ($i + 1)) . ' %', range(0, 4)));
        $chart->set_legend_options(['display' => false]);
        $chart->get_xaxis(0, true)->set_label(get_string('tile:correct', 'local_microlearning'));
        $axis = $chart->get_yaxis(0, true);
        $axis->set_label(get_string('learners', 'local_microlearning'));
        $axis->set_min(0);
        return $output->render($chart);
    }

    /**
     * Unit x dimension grid of the selection's mean share of correct answers.
     *
     * @param array $units
     * @param string[] $dimensions
     * @return array
     */
    protected function heatmap(array $units, array $dimensions): array {
        $means = [];
        foreach (repository::cohort_skill_means($this->snapshot->id, $this->population, self::MIN_COHORT,
                $this->groupid) as $row) {
            $means[(int) $row->unitnumber][$row->kcdimension] = $row;
        }
        $rows = [];
        foreach ($units as $number => $name) {
            $row = ['name' => $name, 'cells' => []];
            foreach ($dimensions as $dimension) {
                $cell = $means[$number][$dimension] ?? null;
                $detail = $cell ? get_string('heat:learners', 'local_microlearning', (int) $cell->learners) : '';
                $tooltip = knowledge_display::dimension_name($dimension) . ' · ' . $name . ': ' . ($cell
                    ? knowledge_display::percent($cell->mean) . ' ' . get_string('heat:correct', 'local_microlearning')
                        . ', ' . $detail
                    : get_string('heat:toofew', 'local_microlearning', self::MIN_COHORT));
                $row['cells'][] = knowledge_display::heat_cell($cell ? (float) $cell->mean : null, $detail, $tooltip);
            }
            $rows[] = $row;
        }
        return ['columns' => array_map(fn($d) => ['name' => knowledge_display::dimension_name($d)], $dimensions),
            'rows' => $rows];
    }

    /**
     * Topics with the most learners at the weak level, as a meter list.
     *
     * @return array
     */
    protected function hardest(): array {
        $topics = repository::weak_kcs($this->snapshot->id, 8, $this->population, $this->groupid);
        return array_map(fn($kc) => [
            'name' => $kc->kcname,
            'detail' => knowledge_display::dimension_name($kc->kcdimension) . ' · ' . ($kc->topic ?? ''),
            'width' => $kc->learners ? round(100 * $kc->concerns / $kc->learners) : 0,
            'figure' => get_string('hardest:figure', 'local_microlearning',
                (object) ['weak' => (int) $kc->concerns, 'learners' => (int) $kc->learners]),
            'mean' => knowledge_display::percent($kc->meanaccuracy),
        ], $topics);
    }

    /**
     * One page of the learner list, after search and sort.
     *
     * @param renderer_base $output
     * @param \stdClass[] $learners Everyone in the selection.
     * @return array
     */
    protected function learner_list(renderer_base $output, array $learners): array {
        $search = trim(\core_text::strtolower($this->list['search']));
        if ($search !== '') {
            $learners = array_values(array_filter($learners,
                fn($l) => str_contains(\core_text::strtolower(fullname($l)), $search)));
        }
        $sort = in_array($this->list['sort'], self::SORTS) ? $this->list['sort'] : 'weak';
        $accuracy = fn($l) => $l->answers ? $l->correct / $l->answers : -1;
        usort($learners, match ($sort) {
            'correct' => fn($a, $b) => [$accuracy($a), fullname($a)] <=> [$accuracy($b), fullname($b)],
            'answered' => fn($a, $b) => [-(int) $a->topicsanswered, fullname($a)] <=> [-(int) $b->topicsanswered, fullname($b)],
            'name' => fn($a, $b) => strnatcasecmp(fullname($a), fullname($b)),
            'refreshed' => fn($a, $b) => [-(int) $a->timerefreshed, fullname($a)] <=> [-(int) $b->timerefreshed, fullname($b)],
            default => fn($a, $b) => [-(int) $a->weaktopics, fullname($a)] <=> [-(int) $b->weaktopics, fullname($b)],
        });

        $total = count($learners);
        $perpage = max(1, (int) $this->list['perpage']);
        $page = min(max(0, (int) $this->list['page']), max(0, (int) ceil($total / $perpage) - 1));
        $weakest = repository::weakest_dimensions($this->snapshot->id);
        $pendingusers = repository::pending_refresh_users($this->course->id);

        $rows = [];
        foreach (array_slice($learners, $page * $perpage, $perpage) as $learner) {
            $dimension = $weakest[$learner->userid] ?? null;
            $topics = max(1, (int) $learner->topics);
            $levels = [
                'strong' => (int) $learner->strongtopics,
                'developing' => (int) $learner->developingtopics,
                'weak' => (int) $learner->weaktopics,
                'started' => (int) $learner->startedtopics,
            ];
            $levels['not_assessed'] = max(0, (int) $learner->topics - array_sum($levels));
            $segments = [];
            foreach ($levels as $level => $count) {
                if ($count) {
                    $segments[] = knowledge_display::level_badge($level) + ['count' => $count,
                        'width' => round(100 * $count / $topics, 2)];
                }
            }
            $rows[] = [
                'name' => fullname($learner),
                // Profile rows carry the profile's id; the picture needs the user's.
                'picture' => $output->user_picture((object) (['id' => (int) $learner->userid] + (array) $learner),
                    ['size' => 30, 'link' => false]),
                'url' => (new \moodle_url('/local/microlearning/profile.php',
                    ['id' => $this->course->id, 'userid' => $learner->userid]))->out(false),
                'simulated' => $learner->population === repository::POPULATION_SIMULATED,
                'answered' => "{$learner->topicsanswered} / {$learner->topics}",
                'correct' => $learner->answers ? knowledge_display::percent($learner->correct / $learner->answers) : '–',
                'correctwidth' => $learner->answers ? round(100 * $learner->correct / $learner->answers) : 0,
                'hascorrect' => $learner->answers > 0,
                'segments' => $segments,
                'levelsummary' => implode(', ', array_map(fn($s) => "{$s['name']}: {$s['count']}", $segments)),
                'weak' => (int) $learner->weaktopics,
                'strong' => (int) $learner->strongtopics,
                'progress' => knowledge_display::percent($learner->courseprogress),
                'weakestskill' => $dimension ? knowledge_display::dimension_name($dimension->dimension) . ' ('
                    . knowledge_display::percent($dimension->meanobserved) . ')' : '–',
                'refreshed' => $learner->timerefreshed ? userdate($learner->timerefreshed,
                    get_string('strftimedatetimeshort', 'langconfig')) : '–',
                'pending' => isset($pendingusers[(int) $learner->userid]),
            ];
        }

        $listurl = new \moodle_url($this->baseurl, ['search' => $this->list['search'], 'sort' => $sort,
            'perpage' => $perpage]);
        return [
            'learners' => $rows,
            'learnercount' => $total,
            'pagingbar' => $output->render(new \paging_bar($total, $page, $perpage, $listurl)),
            'search' => $this->list['search'],
            'sorts' => array_map(fn($key) => ['value' => $key, 'name' => get_string('sort:' . $key, 'local_microlearning'),
                'selected' => $key === $sort], self::SORTS),
            'perpages' => array_map(fn($n) => ['value' => $n, 'selected' => $n === $perpage], [10, 25, 50, 100]),
            'formaction' => $this->baseurl->out_omit_querystring(),
            'hidden' => array_map(fn($k, $v) => ['name' => $k, 'value' => $v],
                array_keys($this->baseurl->params()), array_values($this->baseurl->params())),
            'rangetext' => $total ? get_string('showingrange', 'local_microlearning', (object) [
                'from' => $page * $perpage + 1, 'to' => min($total, ($page + 1) * $perpage), 'total' => $total]) : '',
        ];
    }
}
