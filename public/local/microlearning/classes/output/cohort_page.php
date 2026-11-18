<?php
// This file is part of Moodle - http://moodle.org/

namespace local_microlearning\output;

use local_microlearning\local\repository;
use renderable;
use renderer_base;
use templatable;

/**
 * The course's learners at a glance: knowledge levels, units, hardest topics, and the learner list.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohort_page implements renderable, templatable {
    /** @var int A cohort mean needs at least this many learners with answers. */
    const MIN_COHORT = 5;

    /** @var \stdClass */
    protected $course;

    /** @var \stdClass */
    protected $snapshot;

    /** @var string|null */
    protected $population;

    /**
     * Constructor.
     *
     * @param \stdClass $course
     * @param \stdClass $snapshot
     * @param string|null $population Show one population only.
     */
    public function __construct(\stdClass $course, \stdClass $snapshot, ?string $population) {
        $this->course = $course;
        $this->snapshot = $snapshot;
        $this->population = $population;
    }

    /**
     * Template context.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $learners = repository::cohort($this->snapshot->id, $this->population);
        $weakest = repository::weakest_dimensions($this->snapshot->id);
        $answers = array_sum(array_column($learners, 'answers'));
        $correct = array_sum(array_column($learners, 'correct'));
        $withanswers = count(array_filter($learners, fn($l) => $l->answers > 0));
        $withweak = count(array_filter($learners, fn($l) => $l->weaktopics > 0));

        $pendingusers = repository::pending_refresh_users($this->course->id);
        $rows = [];
        foreach ($learners as $learner) {
            $dimension = $weakest[$learner->userid] ?? null;
            $pending = isset($pendingusers[(int) $learner->userid]);
            $rows[] = [
                'name' => fullname($learner),
                'url' => (new \moodle_url('/local/microlearning/profile.php',
                    ['id' => $this->course->id, 'userid' => $learner->userid]))->out(false),
                'simulated' => $learner->population === repository::POPULATION_SIMULATED,
                'answered' => "{$learner->topicsanswered} / {$learner->topics}",
                'correct' => $learner->answers ? knowledge_display::percent($learner->correct / $learner->answers) : '–',
                'correctwidth' => $learner->answers ? round(100 * $learner->correct / $learner->answers) : 0,
                'hascorrect' => $learner->answers > 0,
                'weak' => (int) $learner->weaktopics,
                'strong' => (int) $learner->strongtopics,
                'weakestskill' => $dimension ? knowledge_display::dimension_name($dimension->dimension) . ' ('
                    . knowledge_display::percent($dimension->meanobserved) . ')' : '–',
                'phase' => get_string('phase', 'local_microlearning', (object) [
                    'phase' => $learner->knowledgephase ?? '–', 'model' => s($learner->knowledgemodel ?? '–')]),
                'refreshed' => $learner->timerefreshed ? userdate($learner->timerefreshed,
                    get_string('strftimedatetimeshort', 'langconfig')) : '–',
                'pending' => (bool) $pending,
            ];
        }

        return [
            'tiles' => [
                ['label' => get_string('learners', 'local_microlearning'), 'value' => count($learners)],
                ['label' => get_string('tile:withanswers', 'local_microlearning'), 'value' => $withanswers],
                ['label' => get_string('tile:correct', 'local_microlearning'),
                    'value' => $answers ? knowledge_display::percent($correct / $answers) : '–',
                    'detail' => get_string('tile:correct_detail', 'local_microlearning',
                        (object) ['correct' => $correct, 'answers' => $answers])],
                ['label' => get_string('tile:withweak', 'local_microlearning'), 'value' => $withweak],
            ],
            'levels' => knowledge_display::segmented_levels(
                repository::cohort_level_counts($this->snapshot->id, $this->population)),
            'heatmap' => $this->heatmap(),
            'ramplegend' => knowledge_display::ramp_legend(),
            'hardest' => $this->hardest(),
            'learners' => $rows,
            'learnercount' => count($rows),
        ];
    }

    /**
     * Unit x dimension grid of the cohort's mean share of correct answers.
     *
     * @return array
     */
    protected function heatmap(): array {
        global $DB;
        // Row and column labels come from the course's KC structure (one learner's rows suffice).
        $any = $DB->get_field_select('local_microlearning_kcstate', 'MIN(userid)', 'snapshotid = ?', [$this->snapshot->id]);
        $states = $any ? repository::kc_states($this->snapshot->id, (int) $any) : [];
        usort($states, fn($a, $b) => [(int) $a->unitnumber, (int) $a->kcorder] <=> [(int) $b->unitnumber, (int) $b->kcorder]);
        $units = [];
        $dimensions = [];
        foreach ($states as $state) {
            $units[(int) $state->unitnumber] = $units[(int) $state->unitnumber] ?? $state->topic;
            $dimensions[$state->kcdimension] = true;
        }
        $means = [];
        foreach (repository::cohort_skill_means($this->snapshot->id, $this->population, self::MIN_COHORT) as $row) {
            $means[(int) $row->unitnumber][$row->kcdimension] = $row;
        }
        $rows = [];
        foreach ($units as $number => $name) {
            $row = ['name' => $name, 'cells' => []];
            foreach (array_keys($dimensions) as $dimension) {
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
        return ['columns' => array_map(fn($d) => ['name' => knowledge_display::dimension_name($d)],
            array_keys($dimensions)), 'rows' => $rows];
    }

    /**
     * Topics with the most learners at the weak level, as a meter list.
     *
     * @return array
     */
    protected function hardest(): array {
        $topics = repository::weak_kcs($this->snapshot->id, 10, $this->population);
        return array_map(fn($kc) => [
            'name' => $kc->kcname,
            'detail' => knowledge_display::dimension_name($kc->kcdimension) . ' · ' . ($kc->topic ?? ''),
            'width' => $kc->learners ? round(100 * $kc->concerns / $kc->learners) : 0,
            'figure' => get_string('hardest:figure', 'local_microlearning',
                (object) ['weak' => (int) $kc->concerns, 'learners' => (int) $kc->learners]),
            'mean' => knowledge_display::percent($kc->meanaccuracy),
        ], $topics);
    }
}
