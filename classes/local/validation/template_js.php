<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace theme_brandable\local\validation;

use Mustache_Engine;
use Mustache_Tokenizer;

/**
 * Executable code in template overrides: an override may keep the code of the original template, never add any.
 *
 * Compared with the original (filesystem) template, an override must not add or change:
 * - a {{#js}} block (found with the Mustache tokenizer, so delimiter changes do not hide it);
 * - a <script> element;
 * - an event handler attribute (on*);
 * - a javascript: URL in href, src, action, formaction, xlink:href or data (entities decoded).
 *
 * The HTML checks run on what the template can output: its text, with Mustache comments and {{#js}} blocks left
 * out and every variable replaced by a placeholder. A template without original (theme_brandable/*) may contain
 * none of them.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_js {
    /** @var string[] Attributes holding a URL that a browser can run. */
    private const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'xlink:href', 'data'];

    /**
     * Returns what an override adds compared with the original: descriptions such as "{{#js}} block", "onclick
     * attribute"; empty when it adds nothing executable.
     *
     * @param string|null $original Original template source, null if none.
     * @param string $source Override source (valid Mustache).
     * @return string[]
     */
    public static function find_added(?string $original, string $source): array {
        $before = $original !== null ? self::inventory($original) : ['js' => [], 'script' => [], 'event' => [], 'url' => []];
        $after = self::inventory($source);
        $labels = [
            'js' => fn($item) => '{{#js}} block',
            'script' => fn($item) => '<script> element',
            'event' => fn($item) => strtok($item, '=') . ' attribute',
            'url' => fn($item) => 'javascript: URL in ' . strtok($item, '='),
        ];
        $added = [];
        foreach ($labels as $kind => $label) {
            $remaining = array_count_values($before[$kind]);
            foreach ($after[$kind] as $item) {
                if (!empty($remaining[$item])) {
                    $remaining[$item]--;
                    continue;
                }
                $added[$label($item)] = true;
            }
        }
        return array_keys($added);
    }

    /**
     * Lists the executable code of a template.
     *
     * @param string $source Template source.
     * @return array ['js' => [...], 'script' => [...], 'event' => [...], 'url' => [...]], normalised strings.
     */
    private static function inventory(string $source): array {
        try {
            $tokens = (new Mustache_Engine())->getTokenizer()->scan($source);
        } catch (\Mustache_Exception $e) {
            // Invalid original: everything in the override counts as new (the override itself was parsed already).
            return ['js' => [], 'script' => [], 'event' => [], 'url' => []];
        }

        $js = [];
        $html = '';
        $stack = [];
        $jsstart = null;
        foreach ($tokens as $token) {
            $type = $token[Mustache_Tokenizer::TYPE];
            $insidejs = in_array('js', $stack, true);
            switch ($type) {
                case Mustache_Tokenizer::T_SECTION:
                case Mustache_Tokenizer::T_INVERTED:
                    $name = trim((string) $token[Mustache_Tokenizer::NAME]);
                    if ($name === 'js' && !$insidejs) {
                        $jsstart = $token[Mustache_Tokenizer::INDEX];
                    }
                    $stack[] = $name;
                    break;
                case Mustache_Tokenizer::T_END_SECTION:
                    $name = array_pop($stack);
                    if ($name === 'js' && !in_array('js', $stack, true) && $jsstart !== null) {
                        $js[] = self::normalise(substr($source, $jsstart, $token[Mustache_Tokenizer::INDEX] - $jsstart));
                        $jsstart = null;
                    }
                    break;
                case Mustache_Tokenizer::T_TEXT:
                    if (!$insidejs) {
                        $html .= $token[Mustache_Tokenizer::VALUE];
                    }
                    break;
                case Mustache_Tokenizer::T_ESCAPED:
                case Mustache_Tokenizer::T_UNESCAPED:
                case Mustache_Tokenizer::T_UNESCAPED_2:
                case Mustache_Tokenizer::T_PARTIAL:
                case Mustache_Tokenizer::T_PARENT:
                    if (!$insidejs) {
                        // Placeholder: keeps attributes apart, never forms "on" or "javascript:" with the text.
                        $html .= '{x}';
                    }
                    break;
                default:
                    // Comments, delimiter changes, pragmas, block variables: no output of their own.
                    break;
            }
        }

        $scripts = [];
        preg_match_all('~<script\b[^>]*>(.*?)(?:</script\s*>|$)~is', $html, $matches);
        foreach ($matches[0] as $script) {
            $scripts[] = self::normalise(strtolower($script));
        }

        $events = [];
        $urls = [];
        preg_match_all('~<[a-zA-Z][^>]*>?~s', $html, $tags);
        foreach ($tags[0] as $tag) {
            foreach (self::attributes($tag) as [$name, $value]) {
                $name = strtolower($name);
                if (strpos($name, 'on') === 0) {
                    $events[] = $name . '=' . self::normalise($value);
                } else if (in_array($name, self::URL_ATTRIBUTES, true)) {
                    // Browsers decode entities and ignore whitespace and control characters in the scheme.
                    $decoded = strtolower(preg_replace('~[\x00-\x20]+~', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5)));
                    if (strpos($decoded, 'javascript:') === 0) {
                        $urls[] = $name . '=' . $decoded;
                    }
                }
            }
        }
        return ['js' => $js, 'script' => $scripts, 'event' => $events, 'url' => $urls];
    }

    /**
     * Reads the attributes of a start tag in order, as a browser does (values in quotes are never attributes).
     *
     * @param string $tag Start tag, from "<".
     * @return array [[name, value]], value without quotes ('' when there is none).
     */
    private static function attributes(string $tag): array {
        $attributes = [];
        $offset = preg_match('~^<[a-zA-Z][^\s/>]*~', $tag, $match) ? strlen($match[0]) : strlen($tag);
        $pattern = '~\G[\s/]*([^\s"\'>/=]+)(?:\s*=\s*("[^"]*"?|\'[^\']*\'?|[^\s>]+))?~';
        while ($offset < strlen($tag) && preg_match($pattern, $tag, $match, 0, $offset)) {
            if ($match[0] === '') {
                break;
            }
            $attributes[] = [$match[1], trim($match[2] ?? '', '"\'')];
            $offset += strlen($match[0]);
        }
        return $attributes;
    }

    /**
     * Normalises code for comparison (whitespace only).
     *
     * @param string $code Code.
     * @return string
     */
    private static function normalise(string $code): string {
        return trim(preg_replace('~\s+~', ' ', $code));
    }
}
