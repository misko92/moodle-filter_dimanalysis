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

use filter_dimanalysis\local\parser;
use filter_dimanalysis\local\renderer;

/**
 * Filter main class for the filter_dimanalysis plugin.
 *
 * Detects factor-label ("railroad track") dimensional-analysis
 * expressions - e.g. "5 L x (1 mol / 22.4 L) x (46 g / 1 mol)" or an
 * explicit "[da] ... [/da]" block - and renders them as stacked
 * fractions with cancelled units struck through.
 *
 * Only text nodes are ever touched, walked via DOMDocument rather than
 * regular expressions against the raw HTML, so existing markup is never
 * disturbed and content inside <pre>, <code>, <script> or <style> is
 * left completely alone. An expression must sit within a single text
 * node - it will not be recognised if split across an element boundary.
 *
 * @package    filter_dimanalysis
 * @copyright  2026 Moodle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends \core_filters\text_filter {
    /** @var string[] Element tag names whose contents must never be touched. */
    private const SKIP_TAGS = ['pre', 'code', 'script', 'style'];

    /** @var ?bool Cached "automatic detection" setting for this instance. */
    private ?bool $autodetect = null;

    /** @var ?bool Cached "strike cancelled units" setting for this instance. */
    private ?bool $cancel = null;

    #[\Override]
    public function filter($text, array $options = []) {
        if (!is_string($text) || $text === '') {
            return $text;
        }

        // Every expression contains a "/" (the fraction bar); an explicit
        // block additionally contains "[da". If neither marker is present
        // there is nothing this filter can match.
        if (strpos($text, '/') === false && stripos($text, '[da') === false) {
            return $text;
        }

        return $this->apply_to_html($text);
    }

    /**
     * Whether undelimited expressions are detected (admin setting,
     * default on).
     *
     * @return bool
     */
    private function autodetect_enabled(): bool {
        if ($this->autodetect === null) {
            $value = get_config('filter_dimanalysis', 'autodetect');
            $this->autodetect = $value === false ? true : (bool) $value;
        }
        return $this->autodetect;
    }

    /**
     * Whether cancelled units are struck through by default (admin
     * setting, default on).
     *
     * @return bool
     */
    private function cancel_enabled(): bool {
        if ($this->cancel === null) {
            $value = get_config('filter_dimanalysis', 'defaultcancel');
            $this->cancel = $value === false ? true : (bool) $value;
        }
        return $this->cancel;
    }

    /**
     * Parse $html, replace expressions in its text nodes, and return the
     * (possibly) updated HTML. If nothing matched, the original string
     * is returned byte-for-byte.
     *
     * @param string $html
     * @return string
     */
    private function apply_to_html(string $html): string {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return $html;
        }

        if (!$this->process_children($doc, $body)) {
            return $html;
        }

        $result = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $result .= $doc->saveHTML($child);
        }
        return $result;
    }

    /**
     * Recursively walk the children of $node, replacing expressions in
     * any text-node descendant that is not inside {@see SKIP_TAGS}.
     *
     * @param \DOMDocument $doc
     * @param \DOMNode $node
     * @return bool whether any replacement was made in the subtree.
     */
    private function process_children(\DOMDocument $doc, \DOMNode $node): bool {
        $changed = false;
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                if ($this->replace_text_node($doc, $child)) {
                    $changed = true;
                }
            } else if ($child->nodeType === XML_ELEMENT_NODE) {
                if (in_array(strtolower($child->nodeName), self::SKIP_TAGS, true)) {
                    continue;
                }
                if ($this->process_children($doc, $child)) {
                    $changed = true;
                }
            }
        }
        return $changed;
    }

    /**
     * Replace the matched expressions in one text node with rendered
     * markup, leaving the surrounding text intact. Text nodes with no
     * match are left untouched.
     *
     * @param \DOMDocument $doc
     * @param \DOMText $textnode
     * @return bool whether the node was replaced.
     */
    private function replace_text_node(\DOMDocument $doc, \DOMText $textnode): bool {
        $text = $textnode->data;
        if (trim($text) === '') {
            return false;
        }

        $matches = parser::find_all($text, $this->autodetect_enabled(), $this->cancel_enabled());
        if (!$matches) {
            return false;
        }

        $fragment = $doc->createDocumentFragment();
        $cursor = 0;
        foreach ($matches as $match) {
            $before = substr($text, $cursor, $match['offset'] - $cursor);
            if ($before !== '') {
                $fragment->appendChild($doc->createTextNode($before));
            }

            $rendered = renderer::render($match['tree']);
            $piece = $doc->createDocumentFragment();
            if (@$piece->appendXML($rendered)) {
                $fragment->appendChild($piece);
            } else {
                // Should not happen (we control the markup) but never
                // drop content: fall back to the original source text.
                libxml_clear_errors();
                $fragment->appendChild(
                    $doc->createTextNode(substr($text, $match['offset'], $match['length']))
                );
            }
            $cursor = $match['offset'] + $match['length'];
        }

        $rest = substr($text, $cursor);
        if ($rest !== '') {
            $fragment->appendChild($doc->createTextNode($rest));
        }

        $textnode->parentNode->replaceChild($fragment, $textnode);
        return true;
    }
}
