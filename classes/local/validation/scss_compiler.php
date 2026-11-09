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

use core_scss;
use theme_brandable\local\overrides\context;
use theme_brandable\local\overrides\scss;
use theme_config;

/**
 * Compiles the Brandable theme SCSS with a given set of overrides, reporting errors per chunk.
 *
 * Same pipeline as theme_config::get_css_content_from_scss() (pre-SCSS, main SCSS, extra SCSS, through
 * core_scss), but errors are thrown instead of being turned into a debugging message.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scss_compiler {
    /**
     * Checks the syntax of a single chunk.
     *
     * @param string $code SCSS code.
     * @return string|null Error message.
     */
    public static function check_syntax(string $code): ?string {
        try {
            (new \ScssPhp\ScssPhp\Parser(null))->parse($code);
        } catch (\ScssPhp\ScssPhp\Exception\SassException $e) {
            return get_string('error:scss', 'theme_brandable', self::clean_message($e->getMessage()));
        }
        return null;
    }

    /**
     * Compiles the theme with a candidate map and returns the errors, attributed to chunks when possible.
     *
     * @param array $map Candidate resolved map.
     * @param string[] $changedtargets SCSS targets changed by the candidate revision.
     * @return array List of ['target' => string, 'message' => string].
     */
    public static function check_theme(array $map, array $changedtargets): array {
        return self::check_theme_and_extract($map, $changedtargets)[0];
    }

    /**
     * Compiles the theme with a candidate map; returns the errors and the selectors of each chunk.
     *
     * @param array $map Candidate resolved map.
     * @param string[] $changedtargets SCSS targets changed by the candidate revision.
     * @return array [errors (see check_theme()), selectors by chunk key (see extract_selectors())]
     */
    public static function check_theme_and_extract(array $map, array $changedtargets): array {
        scss::$sentinels = true;
        try {
            $css = self::compile($map);
        } catch (\Throwable $e) {
            $message = self::clean_message($e->getMessage());
            $source = context::with_map($map, fn() => self::get_source(self::load_theme()));
            [$target, $line] = self::attribute_error($message, $source);
            if ($target !== null) {
                // Report the line within the chunk rather than within the whole compiled source.
                $message = preg_replace('/line: \d+, column:/', "line: {$line}, column:", $message);
            } else if (count($changedtargets) === 1) {
                $target = reset($changedtargets);
            }
            return [[[
                'target' => $target ?? '',
                'message' => get_string('error:scss', 'theme_brandable', $message),
            ]], []];
        } finally {
            scss::$sentinels = false;
        }
        return [[], self::extract_selectors($css)];
    }

    /**
     * Extracts the selectors of each Brandable chunk from compiled CSS (scope wrapper and nesting resolved).
     *
     * Each chunk is preceded by a sentinel rule carrying its key (scss::$sentinels): the rules of a chunk are
     * those between its sentinel and the next one. @-rules (media queries...) are looked into; keyframe steps are
     * ignored.
     *
     * @param string $css Compiled CSS (not minified), with sentinels.
     * @return array Selectors (strings) by chunk key.
     */
    public static function extract_selectors(string $css): array {
        // Keys are targets and scope values: no quotes in them (see change and scope validation).
        $sentinel = '~\\.' . preg_quote(scss::SENTINEL, '~') . '\\[data-key="([^"]*)"\\]\\s*\\{[^}]*\\}~';
        $parts = preg_split($sentinel, $css, -1, PREG_SPLIT_DELIM_CAPTURE);
        $selectors = [];
        // Parts alternate: text, key, text, key, text...
        for ($i = 1; $i < count($parts); $i += 2) {
            $key = $parts[$i];
            if ($key === '-end') {
                continue;
            }
            $block = preg_replace('~/\*.*?\*/~s', '', $parts[$i + 1] ?? '');
            $found = [];
            preg_match_all('~([^{};]+)\{~', $block, $matches);
            foreach ($matches[1] as $prelude) {
                $prelude = trim($prelude);
                if ($prelude === '' || $prelude[0] === '@') {
                    continue;
                }
                foreach (explode(',', $prelude) as $selector) {
                    $selector = trim(preg_replace('~\s+~', ' ', $selector));
                    if ($selector !== '' && !preg_match('~^(from|to|[\d.]+%)$~', $selector)) {
                        $found[$selector] = true;
                    }
                }
            }
            $selectors[$key] = array_merge($selectors[$key] ?? [], array_keys($found));
        }
        return $selectors;
    }

    /**
     * Compiles the theme CSS with the given overrides.
     *
     * @param array $map Resolved map.
     * @return string CSS.
     * @throws \Throwable On compilation errors.
     */
    public static function compile(array $map): string {
        return context::with_map($map, function () {
            $theme = self::load_theme();
            [$paths, $scss] = $theme->get_scss_property();

            raise_memory_limit(MEMORY_EXTRA);
            \core_php_time_limit::raise(300);

            $compiler = new core_scss();
            $compiler->prepend_raw_scss($theme->get_pre_scss_code());
            if (is_string($scss)) {
                $compiler->set_file($scss);
            } else {
                $compiler->append_raw_scss($scss($theme));
                $compiler->setImportPaths($paths);
            }
            $compiler->append_raw_scss($theme->get_extra_scss_code());
            return $compiler->to_css();
        });
    }

    /**
     * Loads a fresh Brandable theme config.
     *
     * @return theme_config
     */
    private static function load_theme(): theme_config {
        return theme_config::load('brandable');
    }

    /**
     * Rebuilds the source string that core_scss::to_css() compiles (when the main SCSS is a callback).
     *
     * @param theme_config $theme Theme.
     * @return string|null
     */
    private static function get_source(theme_config $theme): ?string {
        [, $scss] = $theme->get_scss_property();
        if (is_string($scss)) {
            return null;
        }
        return $theme->get_pre_scss_code() . $scss($theme) . ';' . $theme->get_extra_scss_code();
    }

    /**
     * Finds the chunk containing the line an error points to.
     *
     * scssphp reports errors in the compiled string as "line: N, column: M" (errors in imported files
     * read "<path> on line N" and are not attributed).
     *
     * @param string $message Error message.
     * @param string|null $source Compiled source.
     * @return array [chunk target or null when the error is not in a Brandable chunk, line within the chunk].
     */
    private static function attribute_error(string $message, ?string $source): array {
        if ($source === null || !preg_match('/line: (\d+), column: \d+/', $message, $matches)) {
            return [null, 0];
        }
        $lines = explode("\n", $source);
        $errorline = (int) $matches[1];
        $target = null;
        $start = 0;
        for ($i = 0; $i < $errorline && $i < count($lines); $i++) {
            // The marker may follow the sentinel rule of the chunk (validation).
            $line = preg_replace('~^\\.' . preg_quote(scss::SENTINEL, '~') . '\\[[^\\]]*\\] \\{[^}]*\\} ~', '', $lines[$i]);
            if (strpos($line, scss::MARKER_START) === 0) {
                $target = strtok(strtok(substr($line, strlen(scss::MARKER_START)), ' '), '@');
                $start = $i + 1;
            } else if (strpos($line, scss::MARKER_END) === 0) {
                $target = null;
            }
        }
        return [$target, $errorline - $start];
    }

    /**
     * Keeps the first line of a compiler message (drops the call stack).
     *
     * @param string $message Message.
     * @return string
     */
    private static function clean_message(string $message): string {
        return trim(strtok($message, "\n"));
    }
}
