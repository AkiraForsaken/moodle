<?php
// This file is part of Moodle - http://moodle.org/

namespace local_microlearning\output;

/**
 * Shared presentation of knowledge data: level colours, heatmap cells, segmented bars.
 *
 * Colours were checked with the data-viz validator (OKLab Delta E, Machado CVD
 * simulation, WCAG contrast) on the light surface:
 *  - levels, in display order strong, developing, weak, started, not assessed:
 *    worst adjacent CVD Delta E 12.5, normal-vision 19.4. Strong..weak is a
 *    blue/red diverging pair around the thresholds; started and not assessed
 *    are neutral greys (no evidence, not a point on the scale). Three swatches
 *    sit below 3:1 contrast, so every level is always shown with its text
 *    label and count, never by colour alone.
 *  - heatmap ramp (share of correct answers in 5 bins): one blue hue, steps
 *    250..650, monotone lightness (min step 0.093), light end 2.06:1. Cell text
 *    is dark on the three light bins and white on the two dark ones (>= 4.4:1).
 *
 * @package    local_microlearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class knowledge_display {
    /** @var string[] Knowledge levels, best first (learner_model/knowledge_levels.py). */
    const LEVELS = ['strong', 'developing', 'weak', 'started', 'not_assessed'];

    /** @var string[] Level => colour. */
    const LEVEL_COLORS = [
        'strong' => '#1c5cab',
        'developing' => '#86b6ef',
        'weak' => '#e34948',
        'started' => '#a8a7a1',
        'not_assessed' => '#e6e5e0',
    ];

    /** @var string[] Sequential ramp for a share of correct answers, 5 bins of 20 %. */
    const RAMP = ['#86b6ef', '#5598e7', '#2a78d6', '#1c5cab', '#104281'];

    /** @var string[] Text colour on each ramp step. */
    const RAMP_TEXT = ['#0b0b0b', '#0b0b0b', '#0b0b0b', '#ffffff', '#ffffff'];

    /** @var string The learner's series. */
    const LEARNER_COLOR = '#2a78d6';

    /** @var string Context series (cohort average). */
    const CONTEXT_COLOR = '#a8a7a1';

    /** @var string Reference lines (pass mark). */
    const REFERENCE_COLOR = '#52514e';

    /**
     * @var string[] Categorical colours of the dimensions (skills), in fixed slot order:
     *   blue, orange, aqua, yellow, magenta, green, violet. Assigned by the dimension's
     *   position in alphabetical order, so a skill keeps its colour on every page.
     *   Validated (first four, light surface): CVD worst adjacent Delta E 9.1,
     *   normal-vision 22.9; aqua and yellow sit below 3:1 contrast, so every bar is
     *   named on its axis and the chart keeps its data table.
     */
    const DIMENSION_COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6'];

    /**
     * Readable name of a level.
     *
     * @param string|null $level
     * @return string
     */
    public static function level_name(?string $level): string {
        return get_string('level:' . ($level ?: 'not_assessed'), 'local_microlearning');
    }

    /**
     * Template data of a level badge.
     *
     * @param string|null $level
     * @return array
     */
    public static function level_badge(?string $level): array {
        $level = in_array($level, self::LEVELS) ? $level : 'not_assessed';
        return ['level' => $level, 'name' => self::level_name($level), 'color' => self::LEVEL_COLORS[$level]];
    }

    /**
     * A segmented bar of level counts: one block per level with its count, and a legend.
     *
     * @param array $counts level => count
     * @return array Template data: segments (with width %), legend, total.
     */
    public static function segmented_levels(array $counts): array {
        $total = array_sum(array_map('intval', $counts));
        $segments = [];
        $legend = [];
        foreach (self::LEVELS as $level) {
            $count = (int) ($counts[$level] ?? 0);
            $legend[] = self::level_badge($level) + ['count' => $count];
            if ($count && $total) {
                $share = 100 * $count / $total;
                $segments[] = self::level_badge($level) + [
                    'count' => $count,
                    'width' => round($share, 2),
                    'showcount' => $share >= 6,
                    'textcolor' => in_array($level, ['strong', 'weak']) ? '#ffffff' : '#0b0b0b',
                ];
            }
        }
        return ['segments' => $segments, 'legend' => $legend, 'total' => $total, 'hastotal' => $total > 0];
    }

    /**
     * Heatmap cell for a share of correct answers (null = nothing answered).
     *
     * @param float|null $share 0..1
     * @param string $detail Second line (e.g. "3/4 topics answered").
     * @param string $tooltip
     * @return array
     */
    public static function heat_cell(?float $share, string $detail, string $tooltip): array {
        if ($share === null) {
            return ['empty' => true, 'value' => '–', 'detail' => $detail, 'tooltip' => $tooltip,
                'background' => '', 'color' => ''];
        }
        $bin = min(4, max(0, (int) floor($share * 5)));
        return [
            'empty' => false,
            'value' => format_float(100 * $share, 0) . ' %',
            'detail' => $detail,
            'tooltip' => $tooltip,
            'background' => self::RAMP[$bin],
            'color' => self::RAMP_TEXT[$bin],
        ];
    }

    /**
     * The heatmap's colour legend.
     *
     * @return array
     */
    public static function ramp_legend(): array {
        $legend = [];
        foreach (self::RAMP as $i => $colour) {
            $legend[] = ['color' => $colour, 'label' => (20 * $i) . '–' . (20 * ($i + 1)) . ' %'];
        }
        return $legend;
    }

    /**
     * Colour of a dimension (skill), stable across pages.
     *
     * @param string $dimension
     * @param string[] $all Every dimension of the course.
     * @return string
     */
    public static function dimension_color(string $dimension, array $all): string {
        $sorted = array_values(array_unique($all));
        sort($sorted);
        $index = array_search($dimension, $sorted);
        return self::DIMENSION_COLORS[($index === false ? 0 : $index) % count(self::DIMENSION_COLORS)];
    }

    /**
     * Doughnut of topics by knowledge level.
     *
     * @param \renderer_base $output
     * @param array $counts level => count
     * @param string $label Series label.
     * @return string|null Chart HTML (null without any topic).
     */
    public static function level_doughnut(\renderer_base $output, array $counts, string $label): ?string {
        // Tried topics only: "not tried yet" is what is still ahead, not a level, and would
        // otherwise fill the chart early in a course. Pages state its count in words.
        $levels = array_values(array_filter(self::LEVELS,
            fn($level) => $level !== 'not_assessed' && (int) ($counts[$level] ?? 0) > 0));
        if (!$levels) {
            return null;
        }
        $series = new \core\chart_series($label, array_map(fn($level) => (int) $counts[$level], $levels));
        $series->set_colors(array_map(fn($level) => self::LEVEL_COLORS[$level], $levels));
        $chart = new \core\chart_pie();
        $chart->set_doughnut(true);
        $chart->add_series($series);
        $chart->set_labels(array_map([self::class, 'level_name'], $levels));
        $chart->set_legend_options(['position' => 'right']);
        return $output->render($chart);
    }

    /**
     * Stacked bars: topics of each unit by knowledge level.
     *
     * @param \renderer_base $output
     * @param array $units unitnumber => label
     * @param array $counts unitnumber => [level => count]
     * @param string $axislabel
     * @return string|null
     */
    public static function unit_level_chart(\renderer_base $output, array $units, array $counts, string $axislabel): ?string {
        if (!$units) {
            return null;
        }
        $chart = new \core\chart_bar();
        $chart->set_stacked(true);
        foreach (array_diff(self::LEVELS, ['not_assessed']) as $level) {
            $series = new \core\chart_series(self::level_name($level),
                array_map(fn($unit) => (int) ($counts[$unit][$level] ?? 0), array_keys($units)));
            $series->set_color(self::LEVEL_COLORS[$level]);
            $chart->add_series($series);
        }
        $chart->set_labels(array_values($units));
        $chart->set_legend_options(['position' => 'bottom']);
        $chart->get_yaxis(0, true)->set_label($axislabel);
        return $output->render($chart);
    }

    /**
     * Per unit: a progress bar of the topics tried (coloured by level) and still ahead (grey).
     *
     * @param array $units unitnumber => name
     * @param array $counts unitnumber => [level => count]
     * @return array Template rows.
     */
    public static function journey(array $units, array $counts): array {
        $rows = [];
        foreach ($units as $number => $name) {
            $unit = $counts[$number] ?? [];
            $topics = array_sum($unit);
            $tried = $topics - (int) ($unit['not_assessed'] ?? 0);
            $segments = [];
            foreach (array_diff(self::LEVELS, ['not_assessed']) as $level) {
                if (!empty($unit[$level]) && $topics) {
                    $segments[] = self::level_badge($level) + ['count' => (int) $unit[$level],
                        'width' => round(100 * $unit[$level] / $topics, 2)];
                }
            }
            $rows[] = [
                'number' => $number,
                'name' => $name,
                'segments' => $segments,
                'started' => $tried > 0,
                'complete' => $topics > 0 && $tried === $topics,
                'figure' => get_string('journeyrow', 'local_microlearning', (object) ['tried' => $tried, 'topics' => $topics]),
                'summary' => implode(', ', array_map(fn($s) => "{$s['name']}: {$s['count']}", $segments)),
            ];
        }
        return $rows;
    }

    /**
     * Legend of the levels with what each one means.
     *
     * @return array
     */
    public static function level_guide(): array {
        return array_map(fn($level) => self::level_badge($level) + [
            'desc' => get_string('leveldesc:' . $level, 'local_microlearning')], self::LEVELS);
    }

    /**
     * Horizontal bars of the share of correct answers per dimension, one colour per dimension.
     *
     * @param \renderer_base $output
     * @param string[] $dimensions In display order.
     * @param array $values dimension => share 0..1 or null
     * @param string $label Series label.
     * @param array|null $reference dimension => share, drawn as a grey second series (null: none).
     * @param string|null $referencelabel
     * @return string|null
     */
    public static function dimension_chart(\renderer_base $output, array $dimensions, array $values, string $label,
            ?array $reference = null, ?string $referencelabel = null): ?string {
        if (!array_filter($values, fn($v) => $v !== null)) {
            return null;
        }
        $series = new \core\chart_series($label,
            array_map(fn($d) => isset($values[$d]) && $values[$d] !== null ? round(100 * $values[$d]) : null, $dimensions));
        $series->set_colors(array_map(fn($d) => self::dimension_color($d, $dimensions), $dimensions));
        $chart = new \core\chart_bar();
        $chart->set_horizontal(true);
        $chart->add_series($series);
        if ($reference) {
            $context = new \core\chart_series($referencelabel ?? '',
                array_map(fn($d) => isset($reference[$d]) ? round(100 * $reference[$d]) : null, $dimensions));
            $context->set_color(self::CONTEXT_COLOR);
            $chart->add_series($context);
        } else {
            $chart->set_legend_options(['display' => false]);
        }
        $chart->set_labels(array_map([self::class, 'dimension_name'], $dimensions));
        $axis = $chart->get_xaxis(0, true);
        $axis->set_min(0);
        $axis->set_max(100);
        $axis->set_stepsize(20);
        $axis->set_label(get_string('chart:percentcorrect', 'local_microlearning'));
        return $output->render($chart);
    }

    /**
     * Readable dimension name ("grammar" -> "Grammar").
     *
     * @param string|null $dimension
     * @return string
     */
    public static function dimension_name(?string $dimension): string {
        return $dimension ? \core_text::strtotitle(str_replace('_', ' ', $dimension)) : '–';
    }

    /**
     * A share 0..1 as a whole percentage, or a dash.
     *
     * @param float|string|null $share
     * @return string
     */
    public static function percent($share): string {
        return $share === null || $share === '' ? '–' : format_float(100 * (float) $share, 0) . ' %';
    }
}
