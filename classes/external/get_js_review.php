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

namespace theme_brandable\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use theme_brandable\local\js\review;

/**
 * Returns the JavaScript modules of the draft to review before publishing (spec B13): source, source published before,
 * analysis, and whether each one is reviewed.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_js_review extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Returns the modules to review.
     *
     * @return array
     */
    public static function execute(): array {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);
        return self::export();
    }

    /**
     * Exports the modules of the draft to review.
     *
     * @return array
     */
    public static function export(): array {
        $modules = [];
        foreach (review::get_changed() as $module) {
            $report = json_decode((string) ($module['entry']['report'] ?? ''), true);
            $report = is_array($report) ? $report : [];
            $list = fn(string $name) => array_values(array_map(fn($item) => [
                'line' => (int) ($item['line'] ?? 0),
                'column' => (int) ($item['column'] ?? 0),
                'message' => (string) ($item['message'] ?? (is_scalar($item) ? $item : '')),
            ], array_filter((array) ($report[$name] ?? []), fn($item) => is_array($item) || is_scalar($item))));
            $modules[] = [
                'key' => $module['key'],
                'target' => $module['target'],
                'scopelabel' => $module['scope']->label(),
                'content' => $module['content'],
                'published' => (string) $module['published'],
                'isnew' => $module['published'] === null,
                'hash' => $module['hash'],
                'reviewed' => $module['reviewed'],
                // Without the signature of Brandable: no security review (it needs the signature, contract 12.6).
                'unsigned' => (string) ($module['entry']['signature'] ?? '') === '',
                'analyzer' => (string) ($report['analyzer'] ?? ''),
                'apis' => array_values(array_map('strval', array_filter((array) ($report['apis'] ?? []), 'is_scalar'))),
                'findings' => $list('findings'),
                'errors' => $list('errors'),
            ];
        }
        return [
            'modules' => $modules,
            'pending' => count(array_filter($modules, fn($module) => !$module['reviewed'])),
            'canaskbrandable' => $modules && review::can_ask_brandable(),
            'canreview' => review::can_review(),
            'downloadurl' => $modules ? (new \moodle_url('/theme/brandable/jsdownload.php', ['draft' => 1]))->out(false) : '',
        ];
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::structure();
    }

    /**
     * Describes the modules to review.
     *
     * @return external_single_structure
     */
    public static function structure(): external_single_structure {
        $item = fn(string $what) => new external_multiple_structure(new external_single_structure([
            'line' => new external_value(PARAM_INT, 'Line in the source, 0 if unknown'),
            'column' => new external_value(PARAM_INT, 'Column, 0 if unknown'),
            'message' => new external_value(PARAM_RAW, 'Message'),
        ]), $what);
        return new external_single_structure([
            'modules' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_RAW, 'Key of the variant (target and scope)'),
                'target' => new external_value(PARAM_RAW, 'Target (js/...)'),
                'scopelabel' => new external_value(PARAM_TEXT, 'Scope, human readable'),
                'content' => new external_value(PARAM_RAW, 'Source to review'),
                'published' => new external_value(PARAM_RAW, 'Source of the published version, empty when new'),
                'isnew' => new external_value(PARAM_BOOL, 'Whether the module is not in the published version'),
                'hash' => new external_value(PARAM_ALPHANUM, 'sha256 of the source'),
                'reviewed' => new external_value(PARAM_BOOL, 'Whether this exact source was reviewed'),
                'unsigned' => new external_value(PARAM_BOOL, 'Without the signature of Brandable (no security review)'),
                'analyzer' => new external_value(PARAM_RAW, 'Version of the analyzer of Brandable'),
                'apis' => new external_multiple_structure(new external_value(PARAM_RAW, 'API of b'), 'APIs used'),
                'findings' => $item('Findings of the analysis (not blocking)'),
                'errors' => $item('Errors of the analysis (the module never runs)'),
            ]), 'Modules of the draft changed since the published version'),
            'pending' => new external_value(PARAM_INT, 'Modules still to review'),
            'canaskbrandable' => new external_value(PARAM_BOOL, 'Whether the security review of Brandable is offered'),
            'canreview' => new external_value(PARAM_BOOL, 'Whether the user records reviews (managejs)'),
            'downloadurl' => new external_value(PARAM_RAW, 'Download of the JavaScript of the draft, empty if none'),
        ]);
    }
}
