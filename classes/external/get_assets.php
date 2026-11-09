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
use stdClass;
use theme_brandable\local\assets\library;

/**
 * Lists the library of images and fonts for the editor panel.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_assets extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Lists the library.
     *
     * @return array
     */
    public static function execute(): array {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);
        return self::export_library();
    }

    /**
     * Exports the library.
     *
     * @return array
     */
    public static function export_library(): array {
        $usage = library::get_usage();
        $assets = [];
        foreach (library::get_all() as $slug => $record) {
            $assets[] = self::export_asset($record, $usage[$slug] ?? []);
        }
        return ['assets' => $assets, 'count' => library::count_units(), 'max' => library::MAX_ASSETS,
            'revision' => library::get_revision()];
    }

    /**
     * Exports an asset.
     *
     * @param stdClass $record Asset.
     * @param string[] $usage Where it is used (published, session, previous).
     * @return array
     */
    public static function export_asset(stdClass $record, array $usage): array {
        $thumbnail = library::thumbnail_url($record);
        return [
            'id' => (int) $record->id,
            'slug' => $record->slug,
            'kind' => $record->kind,
            'mime' => $record->mime,
            'width' => (int) $record->width,
            'height' => (int) $record->height,
            'alt' => (string) $record->alt,
            'family' => (string) $record->family,
            'weight' => (int) $record->weight,
            'style' => (string) $record->style,
            'subset' => (string) ($record->subset ?? ''),
            'origin' => $record->origin,
            'licence' => (string) $record->licence,
            'favicononly' => (bool) $record->favicononly,
            'url' => library::url($record)->out(false),
            'thumbnailurl' => $thumbnail ? $thumbnail->out(false) : '',
            'usage' => array_values($usage),
            'timecreated' => (int) $record->timecreated,
        ];
    }

    /**
     * Describes an asset.
     *
     * @return external_single_structure
     */
    public static function asset_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Id'),
            'slug' => new external_value(PARAM_ALPHANUMEXT, 'Slug'),
            'kind' => new external_value(PARAM_ALPHA, 'image or font'),
            'mime' => new external_value(PARAM_RAW, 'Type of the file served'),
            'width' => new external_value(PARAM_INT, 'Width (images)'),
            'height' => new external_value(PARAM_INT, 'Height (images)'),
            'alt' => new external_value(PARAM_TEXT, 'Alternative text'),
            'family' => new external_value(PARAM_TEXT, 'Font family'),
            'weight' => new external_value(PARAM_INT, 'Font weight'),
            'style' => new external_value(PARAM_ALPHA, 'Font style'),
            'subset' => new external_value(PARAM_ALPHANUMEXT, 'Subset of a font file of the catalogue'),
            'origin' => new external_value(PARAM_ALPHA, 'upload, google or import'),
            'licence' => new external_value(PARAM_TEXT, 'Licence of a font'),
            'favicononly' => new external_value(PARAM_BOOL, 'Icon only usable as favicon'),
            'url' => new external_value(PARAM_URL, 'URL of the file served'),
            'thumbnailurl' => new external_value(PARAM_URL, 'URL of the thumbnail, empty when none'),
            'usage' => new external_multiple_structure(new external_value(PARAM_ALPHA, 'published, session or previous')),
            'timecreated' => new external_value(PARAM_INT, 'Added on'),
        ]);
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::library_structure();
    }

    /**
     * Describes the library.
     *
     * @return external_single_structure
     */
    public static function library_structure(): external_single_structure {
        return new external_single_structure([
            'assets' => new external_multiple_structure(self::asset_structure()),
            'count' => new external_value(PARAM_INT, 'Assets in the sense of the limit: images, plus font families'),
            'max' => new external_value(PARAM_INT, 'Largest library'),
            'revision' => new external_value(PARAM_INT, 'Revision of the library'),
        ]);
    }
}
