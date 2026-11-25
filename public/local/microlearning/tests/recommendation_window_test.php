<?php
// This file is part of Moodle - http://moodle.org/

namespace local_microlearning;

use local_microlearning\local\recommendation_window;

/**
 * The block's slot rules agree with the pipeline's (recommender.ranking.window).
 *
 * tests/fixtures/recommendation_window.json is written by the Python test suite
 * (python -m tests.window_fixture) from the Python implementation.
 *
 * @package    local_microlearning
 * @covers     \local_microlearning\local\recommendation_window
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recommendation_window_test extends \advanced_testcase {
    public function test_select_matches_the_shared_fixture(): void {
        $cases = json_decode(file_get_contents(__DIR__ . '/fixtures/recommendation_window.json'), true)['cases'];
        $this->assertGreaterThan(20, count($cases));
        foreach ($cases as $case) {
            $this->assertSame($case['expected'], recommendation_window::select(
                $case['queue'], $case['done'], $case['top_n'], $case['max_remediation']), $case['name']);
        }
    }
}
