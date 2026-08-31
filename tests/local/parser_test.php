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

namespace filter_dimanalysis\local;

/**
 * Unit tests for the dimensional-analysis shorthand parser.
 *
 * @package    filter_dimanalysis
 * @category   test
 * @copyright  2026 Moodle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \filter_dimanalysis\local\parser
 */
final class parser_test extends \advanced_testcase {
    public function test_parses_a_three_factor_chain(): void {
        $tree = parser::parse('5 L x (1 mol / 22.4 L) x (46 g / 1 mol)');

        $this->assertNotNull($tree);
        $this->assertSame(
            ['value' => '5', 'unit' => 'L', 'label' => '', 'cancel' => true],
            $tree['given']
        );
        $this->assertCount(2, $tree['factors']);
        $this->assertSame(
            ['value' => '1', 'unit' => 'mol', 'label' => '', 'cancel' => true],
            $tree['factors'][0]['num']
        );
        $this->assertSame(
            ['value' => '22.4', 'unit' => 'L', 'label' => '', 'cancel' => true],
            $tree['factors'][0]['den']
        );
        $this->assertSame(
            ['value' => '46', 'unit' => 'g', 'label' => '', 'cancel' => false],
            $tree['factors'][1]['num']
        );
        $this->assertSame(
            ['value' => '1', 'unit' => 'mol', 'label' => '', 'cancel' => true],
            $tree['factors'][1]['den']
        );
        $this->assertNull($tree['result']);
    }

    public function test_flattens_grouping_parentheses_and_reads_labels(): void {
        $tree = parser::parse('(2.4 g H2 x (1 mol / 1.008 g)) x (6.02E23 molecules / 1 mol)');

        $this->assertNotNull($tree);
        $this->assertSame(
            ['value' => '2.4', 'unit' => 'g', 'label' => 'H2', 'cancel' => true],
            $tree['given']
        );
        $this->assertSame(
            ['value' => '6.02E23', 'unit' => 'molecules', 'label' => '', 'cancel' => false],
            $tree['factors'][1]['num']
        );
        $this->assertTrue($tree['factors'][0]['num']['cancel']);
        $this->assertTrue($tree['factors'][0]['den']['cancel']);
        $this->assertTrue($tree['factors'][1]['den']['cancel']);
    }

    public function test_x_separator_without_surrounding_spaces_against_parens(): void {
        // A ")x(" run is unambiguous, so it separates just like " x ".
        $tree = parser::parse('1200 s x (1 min / 60 s) x (1 hr / 60 min)x(1 day / 24 hr)');

        $this->assertNotNull($tree);
        $this->assertSame('1200', $tree['given']['value']);
        $this->assertCount(3, $tree['factors']);
        $this->assertSame('1', $tree['factors'][2]['num']['value']);
        $this->assertSame('day', $tree['factors'][2]['num']['unit']);
    }

    public function test_find_all_spans_a_glued_x_between_factors(): void {
        $text = 'time: 1200 s x (1 min / 60 s)x(1 hr / 60 min) elapsed';
        $matches = parser::find_all($text);

        $this->assertCount(1, $matches);
        $this->assertSame(
            '1200 s x (1 min / 60 s)x(1 hr / 60 min)',
            substr($text, $matches[0]['offset'], $matches[0]['length'])
        );
    }

    public function test_optional_result_after_equals(): void {
        $tree = parser::parse('2 mol x (58.44 g / 1 mol) = 116.9 g');

        $this->assertNotNull($tree);
        $this->assertSame(
            ['value' => '116.9', 'unit' => 'g', 'label' => '', 'cancel' => false],
            $tree['result']
        );
    }

    public function test_labelled_units_do_not_cross_cancel(): void {
        $tree = parser::parse('3 mol Na x (1 mol Cl / 1 mol Na)');

        // Units "mol Na" cancel "mol Na"; the "mol Cl" is left standing.
        $this->assertTrue($tree['given']['cancel']);
        $this->assertTrue($tree['factors'][0]['den']['cancel']);
        $this->assertFalse($tree['factors'][0]['num']['cancel']);
    }

    public function test_nocancel_suppresses_all_strike_marks(): void {
        $tree = parser::parse('5 L x (1 mol / 22.4 L)', true);

        $this->assertFalse($tree['given']['cancel']);
        $this->assertFalse($tree['factors'][0]['den']['cancel']);
    }

    public function test_rejects_non_expressions(): void {
        $this->assertNull(parser::parse(''));
        $this->assertNull(parser::parse('just some words'));
        $this->assertNull(parser::parse('(1 mol / 22.4 L)'));           // No given quantity.
        $this->assertNull(parser::parse('5 L x 3 mol'));                // No factor.
        $this->assertNull(parser::parse('5 L x 6 g x (1 mol / 22.4 L)')); // Two givens.
    }

    public function test_find_all_detects_expression_inside_prose(): void {
        $text = 'At STP: 5 L x (1 mol / 22.4 L) x (46 g / 1 mol) of ethanol.';
        $matches = parser::find_all($text);

        $this->assertCount(1, $matches);
        $this->assertSame(
            '5 L x (1 mol / 22.4 L) x (46 g / 1 mol)',
            substr($text, $matches[0]['offset'], $matches[0]['length'])
        );
    }

    public function test_find_all_reads_explicit_block_with_nocancel(): void {
        $matches = parser::find_all('Result: [da nocancel] 5 L x (1 mol / 22.4 L) [/da] done');

        $this->assertCount(1, $matches);
        $this->assertFalse($matches[0]['tree']['factors'][0]['den']['cancel']);
    }

    public function test_find_all_respects_autodetect_off(): void {
        $text = 'Bare: 5 L x (1 mol / 22.4 L) and block: [da] 2 mol x (18 g / 1 mol) [/da]';
        $matches = parser::find_all($text, false);

        // Only the [da] block is picked up; the bare expression is ignored.
        $this->assertCount(1, $matches);
        $this->assertSame('2', $matches[0]['tree']['given']['value']);
    }

    public function test_find_all_ignores_plain_fractions(): void {
        $this->assertSame([], parser::find_all('Mix in a 3/4 to 1/2 ratio, see note (p. 3).'));
    }
}
