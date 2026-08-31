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
 * Unit tests for the dimensional-analysis renderer.
 *
 * @package    filter_dimanalysis
 * @category   test
 * @copyright  2026 Moodle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \filter_dimanalysis\local\renderer
 */
final class renderer_test extends \advanced_testcase {
    public function test_renders_track_with_role_and_aria_label(): void {
        $html = renderer::render(parser::parse('5 L x (1 mol / 22.4 L) x (46 g / 1 mol)'));

        $this->assertStringContainsString('class="filter-dimanalysis" role="img"', $html);
        $this->assertStringContainsString(
            'aria-label="5 L times 1 mol over 22.4 L times 46 g over 1 mol"',
            $html
        );
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString(
            '<span class="filter-dimanalysis-num">46 g</span>',
            $html
        );
        // A "×" between the given and each factor: given + 2 factors = 2.
        $this->assertSame(
            2,
            substr_count($html, '<span class="filter-dimanalysis-op">&#215;</span>')
        );
    }

    public function test_cancelled_units_are_wrapped_for_strike_through(): void {
        $html = renderer::render(parser::parse('5 L x (1 mol / 22.4 L) x (46 g / 1 mol)'));

        // Given L, factor-0 mol and L, factor-1 mol all cancel = 4 marks.
        $this->assertSame(4, substr_count($html, 'class="filter-dimanalysis-cancel"'));
    }

    public function test_nocancel_tree_has_no_strike_marks(): void {
        $html = renderer::render(parser::parse('5 L x (1 mol / 22.4 L)', true));

        $this->assertStringNotContainsString('filter-dimanalysis-cancel', $html);
    }

    public function test_scientific_notation_value_gets_a_superscript(): void {
        $html = renderer::render(parser::parse('2 mol x (6.02E23 molecules / 1 mol)'));

        $this->assertStringContainsString('10<sup>23</sup>', $html);
    }

    public function test_result_is_rendered_after_an_equals(): void {
        $html = renderer::render(parser::parse('2 mol x (58.44 g / 1 mol) = 116.9 g'));

        $this->assertStringContainsString(
            '<span class="filter-dimanalysis-op">=</span><span class="filter-dimanalysis-result">',
            $html
        );
        $this->assertStringContainsString('116.9 g', $html);
    }

    public function test_substance_label_uses_chemformula_when_available(): void {
        if (!class_exists('\\filter_chemformula\\local\\formatter')) {
            $this->markTestSkipped('filter_chemformula is not installed.');
        }
        $html = renderer::render(parser::parse('2.4 g H2 x (1 mol / 1.008 g)'));

        $this->assertStringContainsString('H<sub>2</sub>', $html);
    }
}
