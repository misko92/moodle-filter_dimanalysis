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

namespace filter_dimanalysis;

/**
 * Unit tests for the filter_dimanalysis DOM-based text filter.
 *
 * @package    filter_dimanalysis
 * @category   test
 * @copyright  2026 Moodle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \filter_dimanalysis\text_filter
 */
final class text_filter_test extends \advanced_testcase {
    /**
     * Build a filter instance. The filter never reads $this->context, so
     * a null context keeps this suite free of DB/session fixtures.
     *
     * @return text_filter
     */
    private function get_filter(): text_filter {
        return new text_filter(null, []);
    }

    public function test_renders_expression_and_keeps_surrounding_text(): void {
        $this->resetAfterTest();
        $out = $this->get_filter()->filter(
            '<p>At STP: 5 L x (1 mol / 22.4 L) x (46 g / 1 mol) of ethanol.</p>'
        );

        $this->assertStringContainsString('<p>At STP: ', $out);
        $this->assertStringContainsString(' of ethanol.</p>', $out);
        $this->assertStringContainsString('class="filter-dimanalysis" role="img"', $out);
        $this->assertStringContainsString(
            'aria-label="5 L times 1 mol over 22.4 L times 46 g over 1 mol"',
            $out
        );
    }

    public function test_explicit_block_is_consumed(): void {
        $this->resetAfterTest();
        $out = $this->get_filter()->filter('<p>[da] 2 mol x (58.44 g / 1 mol) [/da]</p>');

        $this->assertStringNotContainsString('[da]', $out);
        $this->assertStringContainsString('class="filter-dimanalysis-factor"', $out);
    }

    public function test_nocancel_block_has_no_strike_marks(): void {
        $this->resetAfterTest();
        $out = $this->get_filter()->filter('<p>[da nocancel] 5 L x (1 mol / 22.4 L) [/da]</p>');

        $this->assertStringNotContainsString('filter-dimanalysis-cancel', $out);
    }

    public function test_content_in_code_and_pre_is_untouched(): void {
        $this->resetAfterTest();
        $in = '<p>5 L x (1 mol / 22.4 L) x (46 g / 1 mol)</p>'
            . '<pre>5 L x (1 mol / 22.4 L) x (46 g / 1 mol)</pre>';
        $out = $this->get_filter()->filter($in);

        $this->assertStringContainsString('class="filter-dimanalysis"', $out);
        $this->assertStringContainsString(
            '<pre>5 L x (1 mol / 22.4 L) x (46 g / 1 mol)</pre>',
            $out
        );
    }

    public function test_plain_text_without_an_expression_is_returned_unchanged(): void {
        $this->resetAfterTest();
        $in = '<p>Use a 3/4 to 1/2 ratio (see p. 3).</p>';

        $this->assertSame($in, $this->get_filter()->filter($in));
    }

    public function test_autodetect_off_leaves_bare_expressions_alone(): void {
        $this->resetAfterTest();
        set_config('autodetect', 0, 'filter_dimanalysis');
        $in = '<p>5 L x (1 mol / 22.4 L) x (46 g / 1 mol)</p>';

        $this->assertSame($in, $this->get_filter()->filter($in));
    }
}
