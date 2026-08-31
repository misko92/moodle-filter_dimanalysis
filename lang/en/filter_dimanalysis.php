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

/**
 * Strings for component 'filter_dimanalysis', language 'en'.
 *
 * @package    filter_dimanalysis
 * @copyright  2026 Moodle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['autodetect'] = 'Automatic detection';
$string['autodetect_desc'] = 'When enabled, factor-label expressions such as <code>5 L x (1 mol / 22.4 L)</code> are formatted automatically wherever they appear in text. When disabled, only text wrapped in <code>[da] &hellip; [/da]</code> is formatted.';
$string['defaultcancel'] = 'Strike through cancelled units';
$string['defaultcancel_desc'] = 'Draw a diagonal line through a unit that appears in both a numerator and a denominator, the way it is struck out by hand. Write <code>[da nocancel] &hellip; [/da]</code> to suppress this for one expression.';
$string['filtername'] = 'Dimensional analysis formatting';
$string['privacy:metadata'] = 'The Dimensional analysis formatting filter does not store any personal data.';
