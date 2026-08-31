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
 * Parser for the dimensional-analysis (factor-label) shorthand.
 *
 * Turns a source string such as
 *
 *     5 L x (1 mol / 22.4 L) x (46 g / 1 mol)
 *
 * or an explicitly delimited block
 *
 *     [da] 2.4 g H2 x (1 mol / 1.008 g) x (6.02E23 molecules / 1 mol) = ... [/da]
 *
 * into a structured tree the {@see renderer} lays out as stacked
 * fractions. Grammar (whitespace tolerant, newlines count as spaces):
 *
 *   expression := term ( SEP term )+ ( "=" quantity )?
 *   term       := "(" expression ")"        // pure grouping, flattened
 *               | "(" quantity "/" quantity ")"   // a conversion factor
 *               | quantity                        // the given quantity
 *   quantity   := value? unit? label?
 *   SEP        := " x " | " X " | "×" | "*" | "·" | "⋅" | "∙"
 *
 * Exactly one bare quantity (no "/") is allowed - it is the given
 * amount; every other term must be a "( a / b )" factor; there must be
 * at least one factor. Anything that does not fit returns null and the
 * caller leaves the text untouched.
 *
 * Detection limits (documented, deliberate): parentheses may nest one
 * level deep; a bare quantity may not contain the ASCII letter "x"; the
 * whole expression must sit within a single text node (do not split
 * factors across editor line breaks).
 *
 * @package    filter_dimanalysis
 * @copyright  2026 Moodle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class parser {
    /** @var string[] Multi-byte multiplicative separator characters. */
    private const SEP_CHARS = ['×', '*', '·', '⋅', '∙'];

    /**
     * @var string Character class (for use inside a /u pattern, negated)
     * of everything a bare quantity run may NOT contain.
     */
    private const NOT_BARE = '[^()=\/xX×*·⋅∙]';

    /**
     * @var string[] Units recognised when splitting a run with no space
     * after the number, e.g. "2.4gH2". Longest match wins, so order here
     * does not matter - the code sorts by length. Case sensitive.
     */
    private const KNOWN_UNITS = [
        'molecules', 'particles', 'formula', 'atoms', 'ions', 'items',
        'mmol', 'umol', 'µmol', 'mol',
        'kcal', 'cal', 'kJ', 'J',
        'mL', 'dL', 'uL', 'µL', 'L',
        'mg', 'ug', 'µg', 'ng', 'kg', 'g',
        'kPa', 'Pa', 'atm', 'torr', 'bar', 'mmHg',
        'mol/L', 'M', 'km', 'cm', 'mm', 'nm', 'pm', 'm',
        'min', 'ms', 'hr', 'h', 's',
        'K', 'eq', 'meq',
    ];

    /**
     * Locate every dimensional-analysis expression in a plain-text
     * string.
     *
     * @param string $text the text-node contents to scan.
     * @param bool $autodetect whether to detect bare (undelimited)
     *        expressions; when false only [da]...[/da] blocks are found.
     * @param bool $cancel whether unit cancellation should be marked by
     *        default (a "[da nocancel]" block always wins over this).
     * @return array<int, array{offset:int, length:int, tree:array}>
     *         byte offsets into $text, ordered and non-overlapping.
     */
    public static function find_all(string $text, bool $autodetect = true, bool $cancel = true): array {
        $results = [];

        // Explicit [da] ... [/da] blocks (optionally "[da nocancel]").
        if (preg_match_all('/\[da(\s+nocancel)?\]\s*(.*?)\s*\[\/da\]/isu', $text, $blocks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($blocks as $block) {
                $nocancel = trim($block[1][0]) !== '' || !$cancel;
                $tree = self::parse($block[2][0], $nocancel);
                if ($tree !== null) {
                    $results[] = ['offset' => $block[0][1], 'length' => strlen($block[0][0]), 'tree' => $tree];
                }
            }
        }

        if ($autodetect) {
            foreach (self::top_level_paren_groups($text) as [$open, $close]) {
                if (self::intersects($open, $close, $results)) {
                    continue;
                }
                $body = substr($text, $open + 1, $close - $open - 1);
                if (self::find_top_level($body, '/') === null) {
                    // Not a fraction - e.g. "(aq)" or "(see 3)".
                    continue;
                }
                [$start, $end] = self::expand_around($text, $open, $close);
                $raw = substr($text, $start, $end - $start);
                $trimmed = ltrim($raw);
                $offset = $start + (strlen($raw) - strlen($trimmed));
                $src = rtrim($trimmed);
                if ($src === '' || self::intersects($offset, $offset + strlen($src) - 1, $results)) {
                    continue;
                }
                $tree = self::parse($src, !$cancel);
                if ($tree === null) {
                    continue;
                }
                $results[] = ['offset' => $offset, 'length' => strlen($src), 'tree' => $tree];
            }
        }

        usort($results, static fn($a, $b) => $a['offset'] <=> $b['offset']);

        $final = [];
        $prevend = -1;
        foreach ($results as $result) {
            if ($result['offset'] <= $prevend) {
                continue;
            }
            $final[] = $result;
            $prevend = $result['offset'] + $result['length'] - 1;
        }
        return $final;
    }

    /**
     * Parse a single expression string into a tree, or null if it is not
     * a well-formed factor-label expression.
     *
     * @param string $expr the expression source (without any [da] wrapper).
     * @param bool $nocancel suppress cancellation marking for this expression.
     * @return array{nocancel:bool, source:string, given:array, factors:array<int, array{num:array, den:array}>, result:?array}|null
     */
    public static function parse(string $expr, bool $nocancel = false): ?array {
        $expr = trim(preg_replace('/\s+/u', ' ', $expr));
        if ($expr === '') {
            return null;
        }
        $source = $expr;

        // Split off a trailing "= result" at paren depth zero.
        $resultstr = null;
        $eqpos = self::find_top_level($expr, '=');
        if ($eqpos !== null) {
            $resultstr = substr($expr, $eqpos + 1);
            $expr = substr($expr, 0, $eqpos);
        }

        $flat = [];
        foreach (self::split_terms(trim($expr)) as $term) {
            self::flatten_term($term, $flat);
        }
        if (count($flat) < 2) {
            return null;
        }

        $given = null;
        $factors = [];
        foreach ($flat as $item) {
            if ($item['type'] === 'bare') {
                if ($given !== null) {
                    return null;
                }
                $given = self::parse_quantity($item['str']);
            } else {
                $num = self::parse_quantity($item['num']);
                $den = self::parse_quantity($item['den']);
                if ($num === null || $den === null) {
                    return null;
                }
                $factors[] = ['num' => $num, 'den' => $den];
            }
        }
        if ($given === null || !$factors) {
            return null;
        }

        $result = null;
        if ($resultstr !== null && trim($resultstr) !== '') {
            $result = self::parse_quantity($resultstr);
        }

        $tree = [
            'nocancel' => $nocancel,
            'source'   => $source,
            'given'    => $given,
            'factors'  => $factors,
            'result'   => $result,
        ];
        if (!$nocancel) {
            self::mark_cancellations($tree);
        }
        return $tree;
    }

    /**
     * Recursively reduce a term to zero or more "bare"/"factor" items,
     * flattening pure grouping parentheses like "(a x (b/c))".
     *
     * @param string $term
     * @param array<int, array{type:string, str?:string, num?:string, den?:string}> $out
     * @return void
     */
    private static function flatten_term(string $term, array &$out): void {
        $s = trim($term);
        while (self::is_wrapped($s)) {
            $s = trim(substr($s, 1, -1));
        }
        if ($s === '') {
            return;
        }

        $parts = self::split_terms($s);
        if (count($parts) > 1) {
            foreach ($parts as $part) {
                self::flatten_term($part, $out);
            }
            return;
        }

        $slash = self::find_top_level($s, '/');
        if ($slash !== null) {
            $out[] = [
                'type' => 'factor',
                'num'  => trim(substr($s, 0, $slash)),
                'den'  => trim(substr($s, $slash + 1)),
            ];
            return;
        }

        $out[] = ['type' => 'bare', 'str' => $s];
    }

    /**
     * Parse a "value? unit? label?" quantity slot.
     *
     * @param string $slot
     * @return array{value:string, unit:string, label:string, cancel:bool}|null
     */
    private static function parse_quantity(string $slot): ?array {
        $slot = trim($slot);
        if ($slot === '') {
            return null;
        }

        $value = '';
        $rest = $slot;
        $numrx = '/^([+-]?\d+(?:[.,]\d+)?(?:[eE][+-]?\d+)?(?:\s*[×xX*]\s*10\s*\^\s*[+-]?\d+)?)\s*(.*)$/su';
        if (preg_match($numrx, $slot, $m)) {
            $value = trim($m[1]);
            $rest = trim($m[2]);
        }

        $unit = '';
        $label = '';
        if ($rest !== '') {
            if (preg_match('/^(\S+)\s+(.*)$/su', $rest, $rm)) {
                $unit = $rm[1];
                $label = trim($rm[2]);
            } else if (($split = self::split_known_unit($rest)) !== null) {
                [$unit, $label] = $split;
            } else {
                $unit = $rest;
            }
        }

        if ($value === '' && $unit === '' && $label === '') {
            return null;
        }
        return ['value' => $value, 'unit' => $unit, 'label' => $label, 'cancel' => false];
    }

    /**
     * Split a spaceless run like "gH2" into [unit, label] using the
     * known-unit list, longest prefix first. Returns null if no known
     * unit is a prefix.
     *
     * @param string $run
     * @return array{0:string, 1:string}|null
     */
    private static function split_known_unit(string $run): ?array {
        $units = self::KNOWN_UNITS;
        usort($units, static fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($units as $unit) {
            if (str_starts_with($run, $unit)) {
                return [$unit, trim(substr($run, strlen($unit)))];
            }
        }
        return null;
    }

    /**
     * Mark, in place, each numerator quantity and denominator quantity
     * that cancel against one another. Numerator side = the given
     * quantity plus every factor numerator; denominator side = every
     * factor denominator. A "= result" never participates. Pairing is
     * greedy, left to right, one-to-one.
     *
     * @param array $tree passed by reference; 'cancel' flags are set.
     * @return void
     */
    private static function mark_cancellations(array &$tree): void {
        $dencount = count($tree['factors']);
        $denused = array_fill(0, $dencount, false);

        // Numerator slots as [kind, index]; 'given' has no index.
        $slots = [['given', -1]];
        foreach (array_keys($tree['factors']) as $k) {
            $slots[] = ['num', $k];
        }

        foreach ($slots as [$kind, $k]) {
            $num = $kind === 'given' ? $tree['given'] : $tree['factors'][$k]['num'];
            for ($i = 0; $i < $dencount; $i++) {
                if ($denused[$i]) {
                    continue;
                }
                if (self::cancels($num, $tree['factors'][$i]['den'])) {
                    if ($kind === 'given') {
                        $tree['given']['cancel'] = true;
                    } else {
                        $tree['factors'][$k]['num']['cancel'] = true;
                    }
                    $tree['factors'][$i]['den']['cancel'] = true;
                    $denused[$i] = true;
                    break;
                }
            }
        }
    }

    /**
     * Whether a numerator quantity and a denominator quantity cancel:
     * identical (case-sensitive) non-empty unit, and labels that either
     * match or where at least one side carries none.
     *
     * @param array $a
     * @param array $b
     * @return bool
     */
    private static function cancels(array $a, array $b): bool {
        if ($a['unit'] === '' || $b['unit'] === '' || $a['unit'] !== $b['unit']) {
            return false;
        }
        $la = strtolower(trim($a['label']));
        $lb = strtolower(trim($b['label']));
        return $la === '' || $lb === '' || $la === $lb;
    }

    // Low-level string helpers below: byte scans, ASCII delimiters only,
    // so UTF-8 multi-byte sequences are never mis-split.

    /**
     * Offset of the first occurrence of a single ASCII char at paren
     * depth zero, or null.
     *
     * @param string $s
     * @param string $needle single ASCII character.
     * @return int|null
     */
    private static function find_top_level(string $s, string $needle): ?int {
        $depth = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '(') {
                $depth++;
            } else if ($c === ')') {
                $depth = max(0, $depth - 1);
            } else if ($depth === 0 && $c === $needle) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Split a string on the multiplicative separator at paren depth
     * zero. Empty pieces are dropped.
     *
     * @param string $s
     * @return string[]
     */
    private static function split_terms(string $s): array {
        $terms = [];
        $depth = 0;
        $start = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '(') {
                $depth++;
                continue;
            }
            if ($c === ')') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($depth !== 0) {
                continue;
            }
            $seplen = self::separator_length_at($s, $i);
            if ($seplen > 0) {
                $terms[] = trim(substr($s, $start, $i - $start));
                $i += $seplen - 1;
                $start = $i + 1;
            }
        }
        $terms[] = trim(substr($s, $start));
        return array_values(array_filter($terms, static fn($t) => $t !== ''));
    }

    /**
     * If a multiplicative separator starts at byte offset $i, return its
     * byte length, else 0. ×, *, ·, ⋅, ∙ stand alone; an "x"/"X" counts
     * only when flanked by whitespace or a parenthesis on both sides, so
     * ") x (", ")x(", " x(" and "5 L x (" all separate but the "x" in a
     * word or unit never does.
     *
     * @param string $s
     * @param int $i
     * @return int
     */
    private static function separator_length_at(string $s, int $i): int {
        foreach (self::SEP_CHARS as $sep) {
            if (substr($s, $i, strlen($sep)) === $sep) {
                return strlen($sep);
            }
        }
        if (
            ($s[$i] === 'x' || $s[$i] === 'X')
                && $i > 0 && (ctype_space($s[$i - 1]) || $s[$i - 1] === ')')
                && isset($s[$i + 1]) && (ctype_space($s[$i + 1]) || $s[$i + 1] === '(')
        ) {
            return 1;
        }
        return 0;
    }

    /**
     * Whether the whole string is one balanced "( ... )" group.
     *
     * @param string $s
     * @return bool
     */
    private static function is_wrapped(string $s): bool {
        if (strlen($s) < 2 || $s[0] !== '(' || substr($s, -1) !== ')') {
            return false;
        }
        $depth = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === '(') {
                $depth++;
            } else if ($s[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i === $len - 1;
                }
            }
        }
        return false;
    }

    /**
     * All top-level (non-nested) "( ... )" groups in a string, as
     * [openoffset, closeoffset] byte pairs.
     *
     * @param string $text
     * @return array<int, array{0:int, 1:int}>
     */
    private static function top_level_paren_groups(string $text): array {
        $groups = [];
        $depth = 0;
        $open = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            if ($text[$i] === '(') {
                if ($depth === 0) {
                    $open = $i;
                }
                $depth++;
            } else if ($text[$i] === ')' && $depth > 0) {
                $depth--;
                if ($depth === 0) {
                    $groups[] = [$open, $i];
                }
            }
        }
        return $groups;
    }

    /**
     * Grow a [open, close] paren span outward across " SEP term"
     * sequences on both sides, then an optional trailing "= quantity".
     *
     * @param string $text
     * @param int $open byte offset of the anchor "(".
     * @param int $close byte offset of its matching ")".
     * @return array{0:int, 1:int} [start, endexclusive]
     */
    private static function expand_around(string $text, int $open, int $close): array {
        $start = $open;
        $end = $close + 1;

        for ($guard = 0; $guard < 50; $guard++) {
            $next = self::match_term_left($text, $start);
            if ($next === null || $next >= $start) {
                break;
            }
            $start = $next;
        }
        for ($guard = 0; $guard < 50; $guard++) {
            $next = self::match_term_right($text, $end);
            if ($next === null || $next <= $end) {
                break;
            }
            $end = $next;
        }
        if (preg_match('/^(\s*=\s*)([+-]?\d' . self::NOT_BARE . '*)/u', substr($text, $end), $m)) {
            $end += strlen($m[1]) + strlen(rtrim($m[2]));
        }
        return [$start, $end];
    }

    /**
     * If the text immediately before $start is " SEP term", return the
     * byte offset where that term begins, else null.
     *
     * @param string $text
     * @param int $start
     * @return int|null
     */
    private static function match_term_left(string $text, int $start): ?int {
        $head = substr($text, 0, $start);
        if (!preg_match('/(?:(?<=[\s)])[xX]\s*|\s*(?:×|\*|·|⋅|∙)\s*)$/u', $head, $m)) {
            return null;
        }
        $before = rtrim(substr($head, 0, strlen($head) - strlen($m[0])));
        if ($before === '') {
            return null;
        }
        if ($before[strlen($before) - 1] === ')') {
            return self::match_open_paren($before);
        }
        // A bare term drawn in by expansion must be a real quantity: it
        // starts with a (optionally signed) number, so sentence text like
        // "At STP: " in front of it is left alone.
        if (preg_match('/([+-]?\d' . self::NOT_BARE . '*)$/u', $before, $bm)) {
            if (rtrim($bm[1]) === '') {
                return null;
            }
            return strlen($before) - strlen($bm[1]);
        }
        return null;
    }

    /**
     * If the text starting at $end is " SEP term", return the byte
     * offset just past that term, else null.
     *
     * @param string $text
     * @param int $end
     * @return int|null
     */
    private static function match_term_right(string $text, int $end): ?int {
        $tail = substr($text, $end);
        if (!preg_match('/^(?:\s*[xX](?=[\s(])|\s*(?:×|\*|·|⋅|∙)\s*)/u', $tail, $m)) {
            return null;
        }
        // A bare "x" (no whitespace before it in the match) is only an
        // operator when it butts against the previous factor's ")", not
        // when it is the tail of a bare quantity like "6x".
        if (($m[0][0] === 'x' || $m[0][0] === 'X')) {
            $prev = $end > 0 ? $text[$end - 1] : '';
            if ($prev !== ')' && !ctype_space($prev)) {
                return null;
            }
        }
        $offset = strlen($m[0]);
        $rest = substr($tail, $offset);
        $lead = strlen($rest) - strlen(ltrim($rest));
        $rest = ltrim($rest);
        if ($rest === '') {
            return null;
        }
        if ($rest[0] === '(') {
            $rel = self::match_close_paren($rest);
            return $rel === null ? null : $end + $offset + $lead + $rel + 1;
        }
        // As on the left: only a number-led bare quantity extends the span.
        if (preg_match('/^([+-]?\d' . self::NOT_BARE . '*)/u', $rest, $bm)) {
            $run = rtrim($bm[1]);
            if ($run === '') {
                return null;
            }
            return $end + $offset + $lead + strlen($run);
        }
        return null;
    }

    /**
     * Byte offset of the "(" that matches the final ")" of $s.
     *
     * @param string $s a string ending in ")".
     * @return int|null
     */
    private static function match_open_paren(string $s): ?int {
        $depth = 0;
        for ($i = strlen($s) - 1; $i >= 0; $i--) {
            if ($s[$i] === ')') {
                $depth++;
            } else if ($s[$i] === '(') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * Byte offset of the ")" that matches the leading "(" of $s.
     *
     * @param string $s a string starting with "(".
     * @return int|null
     */
    private static function match_close_paren(string $s): ?int {
        $depth = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === '(') {
                $depth++;
            } else if ($s[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * Whether [s, e] (inclusive byte offsets) intersects any already
     * accepted result range.
     *
     * @param int $s
     * @param int $e
     * @param array<int, array{offset:int, length:int}> $results
     * @return bool
     */
    private static function intersects(int $s, int $e, array $results): bool {
        foreach ($results as $r) {
            $rs = $r['offset'];
            $re = $r['offset'] + $r['length'] - 1;
            if ($s <= $re && $e >= $rs) {
                return true;
            }
        }
        return false;
    }
}
