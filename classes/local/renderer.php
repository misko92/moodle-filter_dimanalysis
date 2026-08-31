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
 * Turns a parsed dimensional-analysis tree (see {@see parser}) into a
 * self-contained block of HTML: a given quantity, a train of stacked
 * fractions, and an optional "= result", laid out by styles.css.
 *
 * The output is a single element with role="img" and an aria-label
 * holding a literal linear reading of the source; the visual scaffold
 * inside it is aria-hidden. Only well-formed markup this class builds
 * itself is emitted, so the caller can splice it in with appendXML().
 *
 * @package    filter_dimanalysis
 * @copyright  2026 Moodle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer {
    /**
     * Render one parsed expression tree to HTML.
     *
     * @param array $tree a tree returned by {@see parser::parse()}.
     * @return string
     */
    public static function render(array $tree): string {
        $mult = '<span class="filter-dimanalysis-op">&#215;</span>';

        $parts = ['<span class="filter-dimanalysis-given">' . self::quantity($tree['given']) . '</span>'];
        foreach ($tree['factors'] as $factor) {
            $parts[] = '<span class="filter-dimanalysis-factor">'
                . '<span class="filter-dimanalysis-num">' . self::quantity($factor['num']) . '</span>'
                . '<span class="filter-dimanalysis-den">' . self::quantity($factor['den']) . '</span>'
                . '</span>';
        }
        $track = implode($mult, $parts);

        if ($tree['result'] !== null) {
            $track .= '<span class="filter-dimanalysis-op">=</span>'
                . '<span class="filter-dimanalysis-result">' . self::quantity($tree['result']) . '</span>';
        }

        return '<span class="filter-dimanalysis" role="img" aria-label="'
            . self::escape(self::aria_label($tree['source'])) . '">'
            . '<span class="filter-dimanalysis-track" aria-hidden="true">' . $track . '</span>'
            . '</span>';
    }

    /**
     * Render one quantity slot: an optional value, then the unit and
     * substance label, the unit/label pair wrapped in a strike-through
     * span when it cancels.
     *
     * @param array $q a quantity array from the parse tree.
     * @return string
     */
    private static function quantity(array $q): string {
        $out = '';
        if ($q['value'] !== '') {
            $out .= self::value($q['value']);
        }

        $unitlabel = self::escape($q['unit']);
        if ($q['label'] !== '') {
            $unitlabel .= ($unitlabel !== '' ? ' ' : '') . self::label($q['label']);
        }

        if ($unitlabel !== '') {
            if ($q['cancel']) {
                $unitlabel = '<span class="filter-dimanalysis-cancel">' . $unitlabel . '</span>';
            }
            $out .= ($out !== '' ? ' ' : '') . $unitlabel;
        }
        return $out;
    }

    /**
     * Render a numeric value, promoting "6.02E23" / "6.02x10^23" to
     * "6.02 × 10^23" with a real superscript.
     *
     * @param string $value
     * @return string
     */
    private static function value(string $value): string {
        $value = trim($value);
        if (
            preg_match('/^([+-]?\d+(?:[.,]\d+)?)[eE]([+-]?\d+)$/', $value, $m)
                || preg_match('/^([+-]?\d+(?:[.,]\d+)?)\s*[×xX*]\s*10\s*\^\s*([+-]?\d+)$/', $value, $m)
        ) {
            return self::escape($m[1]) . ' &#215; 10<sup>' . self::escape(ltrim($m[2], '+')) . '</sup>';
        }
        return self::escape($value);
    }

    /**
     * Render a substance label. If filter_chemformula is installed its
     * formatter is reused so "H2O" / "NaCl" get proper subscripts;
     * otherwise the label is just escaped.
     *
     * @param string $label
     * @return string
     */
    private static function label(string $label): string {
        if (class_exists('\\filter_chemformula\\local\\formatter')) {
            return \filter_chemformula\local\formatter::format($label);
        }
        return self::escape($label);
    }

    /**
     * Build the literal linear reading used as the aria-label: "/"
     * becomes "over", the separators become "times", "=" becomes
     * "equals", parentheses are dropped.
     *
     * @param string $source the trimmed source string.
     * @return string
     */
    private static function aria_label(string $source): string {
        $s = preg_replace('/\s*\/\s*/u', ' over ', $source);
        $s = preg_replace('/\s*=\s*/u', ' equals ', $s);
        $s = preg_replace('/(?:(?<=[\s)])[xX](?=[\s(])|\s*(?:×|\*|·|⋅|∙)\s*)/u', ' times ', $s);
        $s = str_replace(['(', ')'], '', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * HTML-escape a plain string (quotes included, for attribute use).
     *
     * @param string $text
     * @return string
     */
    private static function escape(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
