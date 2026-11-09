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

namespace theme_brandable\local\generator;

/**
 * Element targeted in the preview by the administrator (editor element picker).
 *
 * Contract (proposed to the backend): { html, ancestors: [{ tag, id, classes, data }], template, templates, label,
 * unstableIds }; the plugin also keeps a locator (to find the element again in the verification). The browser
 * removes personal data; the server checks the structure and the sizes, and removes email addresses again.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class selection {
    /** @var int Maximum length of the HTML, in characters. */
    public const MAX_HTML = 4096;
    /** @var int Maximum ancestors. */
    public const MAX_ANCESTORS = 40;
    /** @var int Maximum items of the lists (classes, data, templates, unstable ids). */
    public const MAX_ITEMS = 30;
    /** @var int Maximum length of the label. */
    public const MAX_LABEL = 500;

    /**
     * Normalises a selection sent by the editor.
     *
     * @param array $data Selection.
     * @return array
     */
    public static function clean(array $data): array {
        $ancestors = [];
        foreach (array_slice(is_array($data['ancestors'] ?? null) ? $data['ancestors'] : [], 0, self::MAX_ANCESTORS) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $datas = [];
            $attributes = is_array($item['data'] ?? null) ? $item['data'] : [];
            foreach (array_slice($attributes, 0, self::MAX_ITEMS, true) as $name => $value) {
                $datas[self::text((string) $name, 64)] = self::text((string) $value, 100);
            }
            $ancestors[] = [
                'tag' => self::text((string) ($item['tag'] ?? ''), 32),
                'id' => ($id = self::text((string) ($item['id'] ?? ''), 100)) !== '' ? $id : null,
                'classes' => self::texts($item['classes'] ?? [], 100),
                'data' => (object) $datas,
            ];
        }
        $html = self::scrub((string) ($data['html'] ?? ''));
        $html = \core_text::substr($html, 0, self::MAX_HTML);
        // Without template markers: template null and no templates (the fields are always present).
        $template = is_string($data['template'] ?? null) && $data['template'] !== ''
            ? self::text($data['template'], 255) : null;
        return [
            'html' => $html,
            'ancestors' => $ancestors,
            'template' => $template,
            'templates' => self::texts($data['templates'] ?? [], 255),
            'label' => self::text(self::scrub((string) ($data['label'] ?? '')), self::MAX_LABEL),
            'unstableIds' => self::texts($data['unstableIds'] ?? [], 100),
            'locator' => self::text((string) ($data['locator'] ?? ''), 1000),
        ];
    }

    /**
     * Returns the part of the selection sent to the generator (contract fields only).
     *
     * @param array $selection Clean selection.
     * @return array
     */
    public static function to_request(array $selection): array {
        return array_diff_key($selection, ['locator' => true]);
    }

    /**
     * Removes email addresses.
     *
     * @param string $text Text.
     * @return string
     */
    private static function scrub(string $text): string {
        return preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $text);
    }

    /**
     * Cleans a string list.
     *
     * @param mixed $values Values.
     * @param int $max Maximum length of each value.
     * @return string[]
     */
    private static function texts($values, int $max): array {
        $values = is_array($values) ? array_slice(array_values($values), 0, self::MAX_ITEMS) : [];
        return array_values(array_filter(array_map(
            fn($value) => is_scalar($value) ? self::text((string) $value, $max) : '',
            $values
        ), fn($value) => $value !== ''));
    }

    /**
     * Cleans a string (no control characters, bounded length).
     *
     * @param string $value Value.
     * @param int $max Maximum length.
     * @return string
     */
    private static function text(string $value, int $max): string {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
        return \core_text::substr($value, 0, $max);
    }
}
