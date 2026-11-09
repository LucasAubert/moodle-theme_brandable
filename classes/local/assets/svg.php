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

namespace theme_brandable\local\assets;

/**
 * SVG images of the library: sanitised (allowlist of svg-sanitize, no remote reference, no DOCTYPE nor entity) and
 * measured (viewBox).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class svg {
    /** @var bool Whether the autoloader of the library is registered. */
    private static bool $loaded = false;

    /**
     * Returns the sanitised SVG, null when the file is not an SVG image that can be kept.
     *
     * @param string $content File content.
     * @return string|null
     */
    public static function sanitize(string $content): ?string {
        // Entities (XXE, billion laughs) and DTDs are never accepted: refused before any parsing.
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $content) || !mb_check_encoding($content, 'UTF-8')) {
            return null;
        }
        self::load();
        $sanitizer = new \enshrined\svgSanitize\Sanitizer();
        $sanitizer->removeRemoteReferences(true);
        $sanitizer->removeXMLTag(false);
        $clean = $sanitizer->sanitize($content);
        if (!is_string($clean) || self::size($clean) === null) {
            return null;
        }
        return $clean;
    }

    /**
     * Returns the size of an SVG image: its viewBox, else its width and height in pixels.
     *
     * @param string $content Sanitised SVG.
     * @return int[]|null [width, height], null when unknown.
     */
    public static function size(string $content): ?array {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($content, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $loaded ? $document->documentElement : null;
        if (!$root || strtolower($root->localName) !== 'svg') {
            return null;
        }
        $viewbox = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));
        if (count($viewbox) === 4 && is_numeric($viewbox[2]) && is_numeric($viewbox[3])) {
            [$width, $height] = [(float) $viewbox[2], (float) $viewbox[3]];
        } else {
            $width = self::pixels($root->getAttribute('width'));
            $height = self::pixels($root->getAttribute('height'));
        }
        if (!$width || !$height || $width <= 0 || $height <= 0) {
            return null;
        }
        return [max(1, (int) round($width)), max(1, (int) round($height))];
    }

    /**
     * Reads a length in pixels (unit px or none).
     *
     * @param string $value Attribute value.
     * @return float|null
     */
    private static function pixels(string $value): ?float {
        return preg_match('/^\s*(\d+(?:\.\d+)?)\s*(px)?\s*$/', $value, $match) ? (float) $match[1] : null;
    }

    /**
     * Registers the autoloader of svg-sanitize (lib/svg-sanitize, see thirdpartylibs.xml).
     */
    private static function load(): void {
        if (self::$loaded) {
            return;
        }
        $base = dirname(__DIR__, 3) . '/lib/svg-sanitize/src/';
        spl_autoload_register(function (string $class) use ($base): void {
            $prefix = 'enshrined\\svgSanitize\\';
            if (strpos($class, $prefix) === 0) {
                $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_readable($file)) {
                    require_once($file);
                }
            }
        });
        self::$loaded = true;
    }
}
