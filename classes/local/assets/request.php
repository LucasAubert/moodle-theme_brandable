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
 * The library in POST /generations (field assets, contract 11.2, spec A12): metadata of every image, fonts by family,
 * thumbnails of the images attached to the prompt, plus the current logo when the prompt is about the logo (4 at most).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class request {
    /** @var int Most thumbnails per request (contract 11.2). */
    public const MAX_THUMBNAILS = 4;

    /** @var string A prompt about the logo (or its colours): the current logo is sent with it. */
    public const LOGO_WORDS = '/\b(logos?|logotypes?|blasons?|embl[eè]mes?|emblems?)\b/iu';

    /**
     * Returns the assets field of a generation request.
     *
     * @param string[] $attached Slugs of the images attached to the prompt.
     * @param string $prompt Prompt.
     * @param array $draftmap Resolved map of the draft (its logo setting, if any).
     * @return array
     */
    public static function assets(array $attached, string $prompt, array $draftmap): array {
        $library = library::get_all();
        $thumbnails = self::thumbnails($attached, $prompt, $draftmap, $library);
        $images = [];
        $families = [];
        foreach ($library as $slug => $asset) {
            if ($asset->kind === 'image') {
                $image = ['slug' => $slug, 'mime' => $asset->mime, 'width' => (int) $asset->width,
                    'height' => (int) $asset->height, 'alt' => (string) $asset->alt];
                if (isset($thumbnails[$slug])) {
                    $image['thumbnail'] = $thumbnails[$slug];
                }
                $images[] = $image;
                continue;
            }
            $key = \core_text::strtolower($asset->family);
            $families[$key] ??= ['mime' => 'font/woff2', 'family' => $asset->family, 'files' => []];
            if ($asset->origin === 'google' && $asset->licence) {
                $families[$key]['license'] = $asset->licence;
            }
            $file = ['slug' => $slug, 'weight' => (int) $asset->weight, 'style' => $asset->style];
            if ((string) $asset->subset !== '') {
                $file['subset'] = $asset->subset;
            }
            $families[$key]['files'][] = $file;
        }
        $assets = $images;
        foreach ($families as $family) {
            usort($family['files'], fn($a, $b) => [$a['weight'], $a['style'], $a['subset'] ?? '']
                <=> [$b['weight'], $b['style'], $b['subset'] ?? '']);
            $assets[] = $family;
        }
        return $assets;
    }

    /**
     * Returns the thumbnails to send (data URL by slug).
     *
     * @param string[] $attached Attached slugs.
     * @param string $prompt Prompt.
     * @param array $draftmap Resolved map of the draft.
     * @param \stdClass[] $library Assets by slug.
     * @return array
     */
    private static function thumbnails(array $attached, string $prompt, array $draftmap, array &$library): array {
        $slugs = array_values(array_unique(array_filter($attached, fn($slug) => ($library[$slug]->kind ?? '') === 'image')));
        $slugs = array_slice($slugs, 0, self::MAX_THUMBNAILS);
        $thumbnails = [];
        foreach ($slugs as $slug) {
            $data = self::data_url($library[$slug]);
            if ($data !== null) {
                $thumbnails[$slug] = $data;
            }
        }
        if (count($thumbnails) >= self::MAX_THUMBNAILS || !preg_match(self::LOGO_WORDS, $prompt)) {
            return $thumbnails;
        }
        // The current logo: the setting of the draft, else the logo set in Moodle.
        foreach (['logo', 'logocompact'] as $target) {
            $asset = settings::get_asset($target, $draftmap);
            if ($asset !== null) {
                if (!isset($thumbnails[$asset->slug]) && ($data = self::data_url($asset)) !== null) {
                    $thumbnails[$asset->slug] = $data;
                }
                return $thumbnails;
            }
        }
        $logo = self::moodle_logo();
        if ($logo !== null && ($data = self::data_url($logo)) !== null) {
            $library[$logo->slug] = $logo;
            $thumbnails[$logo->slug] = $data;
        }
        return $thumbnails;
    }

    /**
     * Returns the thumbnail of an asset as a data URL (contract 11.2), null when it has none.
     *
     * @param \stdClass $asset Asset.
     * @return string|null
     */
    public static function data_url(\stdClass $asset): ?string {
        $file = library::get_file($asset, 'thumbnail');
        if (!$file || $file->get_filesize() > file_checker::THUMBNAIL_BYTES) {
            return null;
        }
        $content = $file->get_content();
        $mime = file_checker::sniff($content);
        return in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)
            ? 'data:' . $mime . ';base64,' . base64_encode($content) : null;
    }

    /**
     * Returns the logo set in Moodle (core_admin logo, else compact logo) as an asset of the library: copied once (same
     * content, same asset), so that Brandable can designate it like any image. core_admin is not changed.
     *
     * @return \stdClass|null Null when Moodle has no logo, or it cannot be added (library full, unreadable image).
     */
    private static function moodle_logo(): ?\stdClass {
        global $DB;
        $fs = get_file_storage();
        foreach (['logo', 'logocompact'] as $area) {
            $files = $fs->get_area_files(\context_system::instance()->id, 'core_admin', $area, 0, 'id', false);
            $file = $files ? reset($files) : null;
            if (!$file) {
                continue;
            }
            $path = make_request_directory() . '/' . clean_param($file->get_filename(), PARAM_FILE);
            $file->copy_content_to($path);
            try {
                $checked = file_checker::check_image($path);
                $existing = $DB->get_record(library::TABLE, ['contenthash' => sha1($checked['files']['optimized']['content'])]);
                return $existing ?: library::add($checked, ['filename' => 'logo-moodle.png', 'origin' => 'moodle',
                    'alt' => get_string('asset:moodlelogo', 'theme_brandable')]);
            } catch (\moodle_exception $e) {
                continue;
            }
        }
        return null;
    }

    /**
     * Returns the slugs of the images of the library attached to a prompt, from their ids.
     *
     * @param int[] $ids Asset ids.
     * @return string[]
     */
    public static function slugs(array $ids): array {
        $slugs = [];
        foreach (library::get_all() as $slug => $asset) {
            if ($asset->kind === 'image' && in_array((int) $asset->id, array_map('intval', $ids), true)) {
                $slugs[] = $slug;
            }
        }
        return array_slice($slugs, 0, self::MAX_THUMBNAILS);
    }
}
