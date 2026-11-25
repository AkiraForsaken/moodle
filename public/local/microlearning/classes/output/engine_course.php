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

use local_microlearning\local\engine_client;
use local_microlearning\local\repository;
use renderable;
use renderer_base;
use templatable;

/**
 * Engine dashboard, one course: readiness for training per learner population, the
 * estimator in charge for each population, the last training decision, snapshots and jobs.
 *
 * A trained model applies only to the population it was trained on; every other
 * population stays in Phase 1 (BKT), which is what the page shows.
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine_course implements renderable, templatable {
    /** @var int */
    protected $courseid;

    /** @var \stdClass|null the course, null when it was deleted in Moodle */
    protected $course;

    /** @var engine_client|null */
    protected $client;

    /**
     * @param int $courseid
     * @param \stdClass|null $course
     * @param engine_client|null $client null when no engine URL is configured
     */
    public function __construct(int $courseid, ?\stdClass $course, ?engine_client $client) {
        $this->courseid = $courseid;
        $this->course = $course;
        $this->client = $client;
    }

    public function export_for_template(renderer_base $output): array {
        $status = null;
        $jobs = [];
        $error = '';
        if ($this->client) {
            try {
                $status = $this->client->course_status($this->courseid);
                $jobs = $this->client->jobs($this->courseid, 10)['jobs'] ?? [];
            } catch (\moodle_exception $e) {
                $error = $e->a ?? $e->getMessage();
            }
        }
        $model = $status['active_model'] ?? [];
        $training = $status['training_population'] ?? null;
        $trainready = $training && !empty($status['readiness_by_population'][$training]['ready']);
        $trainblocked = $training ? ($status['readiness_by_population'][$training]['unmet'] ?? []) : [];
        $running = array_filter($jobs, fn($job) => in_array($job['state'] ?? '', ['queued', 'running']));

        return [
            'error' => $error,
            'hasstatus' => (bool) $status,
            'courseexists' => (bool) $this->course,
            'profilesurl' => (new \moodle_url('/local/microlearning/index.php', ['id' => $this->courseid]))->out(false),
            'populations' => $status ? $this->populations($status) : [],
            'decision' => $status ? $this->decision($output, $model) : null,
            'snapshots' => $this->snapshots(),
            'jobs' => engine_format::jobs($jobs, false),
            'hasjobs' => !empty($jobs),
            'pending' => engine_format::pending(repository::pending_refresh_summary($this->courseid)),
            'actionurl' => (new \moodle_url('/local/microlearning/enginecourse.php', ['id' => $this->courseid]))->out(false),
            'sesskey' => sesskey(),
            'canrebuild' => (bool) $this->client && (bool) $this->course && !$running,
            'cantrain' => (bool) $this->client && (bool) $this->course && $trainready && !$running,
            'trainingpopulation' => $training ? self::population($training) : '',
            'trainblocked' => array_map(fn($reason) => ['reason' => s($reason)], $trainblocked),
            'busy' => !empty($running),
        ];
    }

    /**
     * A learner population's display name; an unknown name is shown as it is.
     *
     * @param string $population
     * @return string
     */
    protected static function population(string $population): string {
        $manager = get_string_manager();
        return $manager->string_exists('population:' . $population, 'local_microlearning')
            ? get_string('population:' . $population, 'local_microlearning') : s($population);
    }

    /**
     * Readiness against each threshold and the estimator in charge, per population.
     *
     * @param array $status the engine's course status
     * @return array
     */
    protected function populations(array $status): array {
        $model = $status['active_model'] ?? [];
        $rows = [];
        foreach ($status['readiness_by_population'] ?? [] as $population => $ready) {
            $counts = $ready['counts'] ?? [];
            $limits = $ready['thresholds'] ?? [];
            $trained = ($model['population'] ?? null) === $population;
            $phase = $trained ? (int) ($model['phase'] ?? 1) : 1;
            $estimator = $phase === 3 ? strtoupper((string) ($model['deep_model'] ?? 'deep')) : 'BKT';
            $rows[] = [
                'name' => self::population($population),
                'ready' => !empty($ready['ready']),
                'phase' => $phase,
                'estimator' => $estimator,
                'phasetext' => get_string('engine:phase' . $phase, 'local_microlearning'),
                'meters' => [
                    $this->meter('engine:meter:learners', $counts['learners_with_answers'] ?? 0,
                        $limits['min_learners_with_answers'] ?? 0),
                    $this->meter('engine:meter:answers', $counts['answer_events'] ?? 0,
                        $limits['min_answer_events'] ?? 0),
                    $this->meter('engine:meter:coverage', round(100 * ($counts['kc_coverage'] ?? 0)),
                        round(100 * ($limits['min_kc_coverage'] ?? 0)), '%'),
                ],
            ];
        }
        return $rows;
    }

    /**
     * One progress bar: a count against its threshold.
     *
     * @param string $label string id
     * @param float $value
     * @param float $threshold
     * @param string $unit
     * @return array
     */
    protected function meter(string $label, $value, $threshold, string $unit = ''): array {
        $percent = $threshold > 0 ? (int) min(100, round(100 * $value / $threshold)) : 100;
        return [
            'label' => get_string($label, 'local_microlearning'),
            'value' => format_float($value, 0) . $unit,
            'threshold' => format_float($threshold, 0) . $unit,
            'percent' => $percent,
            'met' => $value >= $threshold,
        ];
    }

    /**
     * The last training decision: both AUCs on the same held-out answers, the margin, the outcome.
     *
     * @param renderer_base $output
     * @param array $model the engine's active-model summary
     * @return array|null
     */
    protected function decision(renderer_base $output, array $model): ?array {
        $gate = $model['gate'] ?? null;
        if (!$gate || $gate['deep_auc'] === null || $gate['bkt_auc'] === null) {
            return null;
        }
        $deep = round((float) $gate['deep_auc'], 4);
        $bkt = round((float) $gate['bkt_auc'], 4);
        $margin = (float) ($model['gate_margin'] ?? 0.01);
        $deepname = strtoupper((string) ($model['deep_model'] ?? 'deep'));

        $chart = new \core\chart_bar();
        $chart->set_horizontal(true);
        $series = new \core\chart_series(get_string('engine:auc', 'local_microlearning'), [$deep, $bkt]);
        $series->set_colors(['#2a78d6', '#7a7974']);
        $chart->add_series($series);
        $chart->set_labels([$deepname, 'BKT']);
        $chart->set_legend_options(['display' => false]);
        $axis = $chart->get_xaxis(0, true);
        $axis->set_min(0.5);
        $axis->set_max(1);
        $axis->set_label(get_string('engine:auc', 'local_microlearning'));

        return [
            'decided' => engine_format::date($model['decided_at'] ?? null),
            'population' => self::population((string) ($model['population'] ?? '')),
            'deepname' => $deepname,
            'deepauc' => format_float($deep, 4),
            'bktauc' => format_float($bkt, 4),
            'difference' => format_float($deep - $bkt, 4),
            'margin' => format_float($margin, 2),
            'targets' => (int) ($gate['n_targets'] ?? 0),
            'testlearners' => (int) ($gate['n_test_learners'] ?? 0),
            'promoted' => (int) ($model['phase'] ?? 1) === 3,
            'reason' => s((string) ($model['reason'] ?? '')),
            'chart' => $output->render($chart),
        ];
    }

    /**
     * The course's imported snapshots, newest first.
     *
     * @return array
     */
    protected function snapshots(): array {
        $format = get_string('strftimedatetimeshort', 'langconfig');
        return array_map(fn($snap) => [
            'id' => (int) $snap->id,
            'active' => (bool) $snap->active,
            'generated' => userdate($snap->timegenerated, $format),
            'imported' => userdate($snap->timeimported, $format),
            'cohort' => s((string) $snap->cohort),
            'version' => s((string) $snap->recommenderversion),
        ], repository::snapshot_history($this->courseid));
    }
}
