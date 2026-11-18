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
