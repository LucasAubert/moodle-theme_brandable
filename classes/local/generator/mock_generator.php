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

use theme_brandable\local\assets\font_downloader;
use theme_brandable\local\assets\library;
use theme_brandable\local\assets\settings;
use theme_brandable\local\js\consent;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\scope;
use theme_brandable\local\overrides\template_source;

/**
 * Demo generator: deterministic changes picked from keywords of the prompt (English or French).
 * "ghost" adds a rule whose selector matches nothing, to try the verification. "ambiguous" asks a question; the answer
 * is read together with the prompt of the question (from the history).
 *
 * - a colour (#rrggbb or a colour name): primary colour (pre/brand-colors);
 * - dark / sombre: dark navbar (post/dark-navbar);
 * - rounded / arrondi: rounder corners (pre/rounded-corners);
 * - hero / bannière / banner: a hero banner template (theme_brandable/hero_banner) included at the top
 *   of the page header (core/full_header override).
 * - logo: the image of the library named in the prompt (else the latest one) as logo and compact logo (settings);
 * - favicon: same, as favicon (PNG, SVG or ICO);
 * - background / fond: same, as background of the page (SCSS with a marker of the library);
 * - montserrat / police / font: Montserrat for the headings, its file asked in fontRequests (fixture, no network);
 * - welcome / bienvenue: a welcome window at the first visit, a JavaScript module (js/welcome-modal, compiled by hand,
 *   signature of the right form only); needsJsActivation while the JavaScript is off (contract 12.3);
 * - broken / cassé: a JavaScript module failing at its start (js/broken-module), to try the errors of the preview and
 *   their repair (which drops it).
 * Anything else: a subtle accent (post/demo-accent).
 *
 * Scope: "this page" / "cette page", "this page type" / "ce type de page", "this course" / "ce cours",
 * "this activity" / "cette activité" limit the changes to the page shown in the preview (pre/ chunks stay
 * site-wide: a scoped colour or radius becomes post/ rules).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mock_generator extends sync_generator {
    /** @var scope Scope of the changes being generated. */
    private scope $scope;

    /** @var string[] Colour names (English and French) and their value. */
    private const COLOURS = [
        'navy' => '#1f3a5f', 'marine' => '#1f3a5f', 'blue' => '#1d4ed8', 'bleu' => '#1d4ed8',
        'red' => '#b42318', 'rouge' => '#b42318', 'green' => '#15803d', 'vert' => '#15803d',
        'purple' => '#6d28d9', 'violet' => '#6d28d9', 'orange' => '#c2410c', 'pink' => '#be185d',
        'rose' => '#be185d', 'black' => '#111827', 'noir' => '#111827', 'teal' => '#0f766e',
    ];

    #[\Override]
    public function generate(string $prompt, generation_context $context): generation_result {
        $text = \core_text::strtolower($prompt);
        $changes = [];
        $parts = [];
        $question = get_string('mock:question', 'theme_brandable');
        $last = array_slice($context->history, -1)[0] ?? null;
        if ($last !== null && $last['summary'] === $question) {
            // An answer: what is asked is the question's prompt, completed by the answer.
            $text = \core_text::strtolower($last['prompt']) . ' ' . $text;
            $parts[] = get_string('mock:answered', 'theme_brandable', $last['prompt']);
        } else if (preg_match('/\b(ambiguous|ambigu|ambiguë)\b/u', $text)) {
            return new generation_result($question, [], true);
        }
        $this->scope = $this->detect_scope($text, $context);
        $welcome = (bool) preg_match('/\b(welcome|bienvenue)\b/u', $text);
        $broken = (bool) preg_match('/\b(broken|cassé|casse)\b/u', $text);
        if (($welcome || $broken) && (!$context->jsenabled || $context->jsconsentversion !== consent::MOCK_VERSION)) {
            // The JavaScript is needed: nothing else is generated (contract 12.3, after the planning).
            return new generation_result(get_string('mock:needsjs', 'theme_brandable'), [], false, [], true);
        }
        if (!$this->scope->is_site()) {
            $parts[] = get_string('mock:scoped', 'theme_brandable', $this->scope->label());
        }

        $colour = null;
        if (preg_match('/#[0-9a-f]{6}\b/', $text, $matches)) {
            $colour = $matches[0];
        } else {
            foreach (self::COLOURS as $name => $value) {
                if (preg_match('/\b' . $name . '\b/u', $text)) {
                    $colour = $value;
                    break;
                }
            }
        }
        if ($colour !== null) {
            if ($this->scope->is_site()) {
                $changes[] = $this->scss('pre/brand-colors', "\$primary: {$colour};");
            } else {
                // Variables are site-wide: a scoped colour is applied with rules.
                $changes[] = $this->scss('post/brand-colors', ".btn-primary { background-color: {$colour}; "
                    . "border-color: {$colour}; }\na:not(.btn) { color: {$colour}; }");
            }
            $parts[] = get_string('mock:colour', 'theme_brandable', $colour);
        }

        if (preg_match('/\b(dark|sombre|nuit|night)\b/u', $text)) {
            $changes[] = $this->scss('post/dark-navbar', implode("\n", [
                '.navbar.fixed-top, .navbar { background-color: #111827 !important; border-bottom-color: #1f2937 !important; }',
                '.navbar .nav-link, .navbar .navbar-brand, .navbar .btn { color: #f9fafb !important; }',
            ]));
            $parts[] = get_string('mock:dark', 'theme_brandable');
        }

        if (preg_match('/\b(rounded|round|arrondi|arrondis|arrondie|arrondies)\b/u', $text)) {
            $changes[] = $this->scope->is_site()
                ? $this->scss(
                    'pre/rounded-corners',
                    "\$border-radius: .75rem;\n\$border-radius-sm: .5rem;\n\$border-radius-lg: 1rem;"
                )
                : $this->scss('post/rounded-corners', '.card, .btn, .form-control { border-radius: .75rem; }');
            $parts[] = get_string('mock:rounded', 'theme_brandable');
        }

        if (preg_match('/\b(hero|banner|bannière|banniere)\b/u', $text)) {
            $header = template_source::get_original('core/full_header');
            if ($header !== null) {
                $title = get_string('mock:herotitle', 'theme_brandable');
                // The banner partial is created with the scope of the header override that includes it.
                $changes[] = change::from_array(['type' => change::TYPE_TEMPLATE, 'target' => 'theme_brandable/hero_banner',
                    'scope' => $this->scope->to_array(),
                    'content' => '<section class="brandable-hero"><h2 class="brandable-hero-title">' . s($title)
                        . '</h2><p class="brandable-hero-text">{{#str}} sitehome, core {{/str}}</p></section>',
                    'deleted' => false, 'sourceHash' => null]);
                $changes[] = change::from_array(['type' => change::TYPE_TEMPLATE, 'target' => 'core/full_header',
                    'scope' => $this->scope->to_array(),
                    'content' => "{{> theme_brandable/hero_banner}}\n" . $header,
                    'deleted' => false, 'sourceHash' => template_source::hash($header)]);
                $changes[] = $this->scss('post/hero-banner', implode("\n", [
                    '.brandable-hero { margin: 1rem 0 1.5rem; padding: 2rem; border-radius: 1rem; color: #fff;',
                    '  background: linear-gradient(135deg, $primary, darken($primary, 15%)); }',
                    '.brandable-hero-title { margin: 0 0 .25rem; font-weight: 700; }',
                    '.brandable-hero-text { margin: 0; opacity: .9; }',
                ]));
                $parts[] = get_string('mock:hero', 'theme_brandable');
            }
        }

        // Images of the library: the one named in the prompt, else the latest one.
        $image = fn(array $types = []) => $this->image($text, $types);
        if (preg_match('/\b(logo|logos)\b/u', $text) && ($asset = $image())) {
            foreach (['logocompact', 'logo'] as $target) {
                $changes[] = change::from_array(['type' => change::TYPE_SETTING, 'target' => $target, 'content' => $asset->slug]);
            }
            $parts[] = get_string('mock:logo', 'theme_brandable', $asset->slug);
        }
        if (preg_match('/\bfavicon\b/u', $text) && ($asset = $image(settings::FAVICON_TYPES))) {
            $changes[] = change::from_array(['type' => change::TYPE_SETTING, 'target' => 'favicon', 'content' => $asset->slug]);
            $parts[] = get_string('mock:favicon', 'theme_brandable', $asset->slug);
        }
        if (preg_match('/\b(background|fond|arrière-plan)\b/u', $text) && ($asset = $image())) {
            $changes[] = $this->scss('post/background-image', "body {\n  background: brandable-asset(\"{$asset->slug}\")"
                . " center / cover fixed;\n}");
            $parts[] = get_string('mock:background', 'theme_brandable', $asset->slug);
        }

        $fontrequests = [];
        if (preg_match('/\b(montserrat|police|font)\b/u', $text)) {
            // A font of the catalogue: its chunk is written by Brandable, its files come from fontRequests (fixture).
            $slug = 'font-montserrat-latin-700-normal';
            $changes[] = $this->scss('post/font-montserrat', implode("\n", [
                '@font-face {',
                '  font-family: "Montserrat";',
                '  font-style: normal;',
                '  font-weight: 700;',
                '  font-display: swap;',
                "  src: brandable-asset(\"$slug\") format(\"woff2\");",
                '}',
                'h1, h2, h3 { font-family: "Montserrat", sans-serif; }',
            ]));
            $fontrequests[] = ['family' => 'Montserrat', 'weights' => [700], 'styles' => ['normal'], 'license' => 'OFL-1.1',
                'licenseUrl' => font_downloader::MOCK_SCHEME . '/fonts/montserrat/LICENSE',
                'files' => [['slug' => $slug, 'weight' => 700, 'style' => 'normal', 'subset' => 'latin',
                    'url' => font_downloader::MOCK_SCHEME . '/fonts/montserrat/latin-700-normal.woff2']]];
            $parts[] = get_string('mock:font', 'theme_brandable');
        }

        if ($welcome) {
            $changes[] = $this->welcome_module();
            $parts[] = get_string('mock:welcome', 'theme_brandable');
        }
        if ($broken) {
            $message = json_encode(get_string('mock:brokenerror', 'theme_brandable'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
            $changes[] = change::from_array([
                'type' => change::TYPE_JS,
                'target' => 'js/broken-module',
                'scope' => $this->scope->to_array(),
                'content' => "export default function () {\n  throw new Error({$message});\n}\n",
                'compiled' => 'function(){"use strict";throw new Error(' . $message . ')}',
                'report' => ['analyzer' => 'mock', 'apis' => [], 'findings' => [['line' => 2, 'column' => 3,
                    'message' => get_string('mock:brokenfinding', 'theme_brandable')]]],
                'signature' => 'v1.mockSignatureNotFromBrandable000',
                'deleted' => false,
                'sourceHash' => null,
            ]);
            $parts[] = get_string('mock:broken', 'theme_brandable');
        }

        if (!$changes) {
            $changes[] = $this->scss(
                'post/demo-accent',
                'a:not(.btn):not(.nav-link) { text-underline-offset: .2em; }' . "\n"
                . '.card { box-shadow: 0 1px 2px rgba(0, 0, 0, .06); }'
            );
            $parts[] = get_string('mock:accent', 'theme_brandable');
        }

        if (preg_match('/\b(ghost|fantôme|fantome)\b/u', $text)) {
            // Selector of no page: exercises the verification in the preview, then the repair (which drops it).
            $changes[] = $this->scss('post/ghost', '.brandable-ghost-element { outline: 2px dashed #dc2626; }');
            $parts[] = get_string('mock:ghost', 'theme_brandable');
        }

        return new generation_result(
            get_string('mock:summary', 'theme_brandable', implode(' ', $parts)),
            $changes,
            false,
            $fontrequests
        );
    }

    /**
     * Returns the image of the library named in the prompt, else the latest one (icons excluded unless allowed).
     *
     * @param string $text Prompt, lowercase.
     * @param string[] $types Types accepted, any image when empty.
     * @return \stdClass|null
     */
    private function image(string $text, array $types = []): ?\stdClass {
        $images = array_filter(library::get_all(), fn($asset) => $asset->kind === 'image'
            && ($types ? in_array($asset->mime, $types, true) : empty($asset->favicononly)));
        foreach ($images as $slug => $asset) {
            if (strpos($text, (string) $slug) !== false) {
                return $asset;
            }
        }
        return $images ? reset($images) : null;
    }

    /**
     * Builds the welcome window: a JavaScript module showing a modal at the first visit of each user.
     *
     * @return change
     */
    private function welcome_module(): change {
        $title = get_string('mock:welcometitle', 'theme_brandable');
        $body = get_string('mock:welcomebody', 'theme_brandable');
        $json = fn(string $text) => json_encode($text, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        return change::from_array([
            'type' => change::TYPE_JS,
            'target' => 'js/welcome-modal',
            'scope' => $this->scope->to_array(),
            'content' => "export default function (b) {\n  if (!b.once('welcome')) {\n    return;\n  }\n"
                . "  b.modal({title: {$json($title)}, body: {$json($body)}}).then((modal) => modal.show());\n}\n",
            'compiled' => 'function(e){"use strict";e.once("welcome")&&e.modal({title:' . $json($title) . ',body:'
                . $json($body) . '}).then(function(m){m.show()})}',
            'report' => ['analyzer' => 'mock', 'apis' => ['b.modal', 'b.once'], 'findings' => []],
            // Of the right form only: POST /js/review refuses it, as expected.
            'signature' => 'v1.mockSignatureNotFromBrandable000',
            'deleted' => false,
            'sourceHash' => null,
        ]);
    }

    /**
     * Builds an SCSS chunk change.
     *
     * @param string $target Chunk target.
     * @param string $content SCSS.
     * @return change
     */
    private function scss(string $target, string $content): change {
        return change::from_array(['type' => change::TYPE_SCSS, 'target' => $target, 'content' => $content,
            'deleted' => false, 'sourceHash' => null,
            'scope' => strpos($target, 'pre/') === 0 ? null : $this->scope->to_array()]);
    }

    /**
     * Picks the scope asked by the prompt, from the page shown in the preview.
     *
     * @param string $text Prompt, lowercase.
     * @param generation_context $context Context.
     * @return scope
     */
    private function detect_scope(string $text, generation_context $context): scope {
        $page = $context->page;
        $candidates = [
            [scope::CM, '/(cette activité|this activity)/u', $page['cmId'] ?? null],
            [scope::COURSE, '/(ce cours|this course)/u', $page['courseId'] ?? null],
            [scope::PAGETYPE, '/(ce type de page|this page type|this kind of page)/u', $page['pagetype'] ?? null],
            [scope::PAGE, '/(cette page|this page|uniquement ici|only here)/u', $page['pageKey'] ?? null],
        ];
        foreach ($candidates as [$level, $pattern, $value]) {
            if ($value !== null && $value !== '' && $value !== 'unknown' && preg_match($pattern, $text)) {
                return new scope($level, (string) $value);
            }
        }
        return new scope();
    }
}
