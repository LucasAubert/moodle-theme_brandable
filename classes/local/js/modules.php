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

namespace theme_brandable\local\js;

use theme_brandable\local\assets\library;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\context;
use theme_brandable\local\overrides\page_context;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\preview;

/**
 * Generated JavaScript modules (contract 12, spec B5 to B8): whether they run on a page, which ones, and the file of
 * the modules of a revision (an AMD module returning the compiled functions, never evaluated by the plugin).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modules {
    /** @var string Target of a module. */
    public const TARGET_PATTERN = '~^js/[a-z0-9]+(?:-[a-z0-9]+)*$~';

    /** @var int Most modules in a revision (a target counts once, all scopes, contract 12.3). */
    public const MAX_MODULES = 6;

    /** @var int Longest source. */
    public const MAX_CONTENT = 20000;

    /** @var int Longest compiled function. */
    public const MAX_COMPILED = 10000;

    /** @var string Signature of Brandable (contract 12.3: v1.<HMAC-SHA256 base64url>). */
    public const SIGNATURE_PATTERN = '~^v[0-9]{1,3}\.[A-Za-z0-9_-]{16,90}$~';

    /** @var string Literal template name given to b.render in a source (contract 12.4: the name is literal). */
    public const RENDER_PATTERN = '~\.render\(\s*([\'"\x60])([a-z][a-z0-9_]*/[A-Za-z0-9_/-]+)\1~';

    /** @var string Setting: generated JavaScript enabled (with the consent, spec B1, B3). */
    public const ENABLED_CONFIG = 'brandable_js';

    /** @var string Setting: version of the consent text accepted. */
    public const CONSENT_CONFIG = 'brandable_jsconsentversion';

    /** @var string Setting: kill switch (spec B2). */
    public const SUSPENDED_CONFIG = 'brandable_jssuspended';

    /** @var string Setting: key of the opaque identifiers of the users (b.storage). */
    public const USERKEY_CONFIG = 'brandable_jsuserkey';

    /** @var string What replaces personal data in the errors sent to a repair. */
    public const MASK = '[masqué]';

    /** @var string URL parameter turning the modules off on the page, for the administrators (spec B2). */
    public const NOJS_PARAM = 'brandable_nojs';

    /**
     * Whether the generated JavaScript is enabled on the site (setting with its consent).
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('theme_brandable', self::ENABLED_CONFIG);
    }

    /**
     * Whether the kill switch is on.
     *
     * @return bool
     */
    public static function is_suspended(): bool {
        return (bool) get_config('theme_brandable', self::SUSPENDED_CONFIG);
    }

    /**
     * Whether modules run on a page: enabled, not suspended, not turned off by an administrator for the page, never on
     * the administration pages nor on the pages of Brandable (editor, settings), and the draft only in the preview
     * session.
     *
     * @param \moodle_page $page Page.
     * @return bool
     */
    public static function run_on(\moodle_page $page): bool {
        if (during_initial_install() || !self::is_enabled() || self::is_suspended()) {
            return false;
        }
        $path = $page->has_set_url() ? $page->url->get_path() : '';
        if (
            $page->pagelayout === 'admin' || strpos((string) $page->pagetype, 'admin-') === 0
                || strpos($path, '/admin/') !== false || strpos($path, '/theme/brandable/') !== false
        ) {
            return false;
        }
        if (
            optional_param(self::NOJS_PARAM, 0, PARAM_BOOL) && has_capability(
                'theme/brandable:managejs',
                \context_system::instance()
            )
        ) {
            return false;
        }
        // The draft runs only in the preview (published revision otherwise).
        return context::get_active_revision() === revision_manager::get_published() || preview::is_active();
    }

    /**
     * Returns the modules of a page: for each target, the variant of the most precise scope, by target in alphabetical
     * order.
     *
     * @param array $map Resolved map.
     * @param page_context $page Page.
     * @return array[] Entries of the map.
     */
    public static function select(array $map, page_context $page): array {
        $targets = array_unique(array_column($map[change::TYPE_JS] ?? [], 'target'));
        sort($targets);
        $selected = [];
        foreach ($targets as $target) {
            $entry = resolver::select(resolver::get_variants($map, change::TYPE_JS, $target), $page);
            if ($entry !== null && !self::has_errors($entry)) {
                $selected[] = $entry;
            }
        }
        return $selected;
    }

    /**
     * Returns the data of the runtime for a page (spec section 3): file of the modules, variants to run in order, b.page
     * and the opaque identifier of the user. Nothing else.
     *
     * @param \moodle_page $page Page.
     * @param page_context $pagecontext Scope context of the page.
     * @param string|null $pagekey Page key (bp-*).
     * @return array|null Null when no module applies to the page.
     */
    public static function page_config(\moodle_page $page, page_context $pagecontext, ?string $pagekey): ?array {
        $selected = self::select(context::get_active_map(), $pagecontext);
        if (!$selected) {
            return null;
        }
        $revisionid = context::get_active_revision();
        $url = new \moodle_url('/theme/brandable/js.php', ['rev' => $revisionid, 'h' => self::bundle_hash($revisionid)]);
        return [
            'url' => $url->out(false),
            'modules' => array_map(fn(array $entry) => [
                'key' => resolver::entry_key($entry['target'], resolver::entry_scope($entry)),
                'target' => $entry['target'],
            ], $selected),
            'page' => [
                'pagetype' => (string) $page->pagetype,
                'pageKey' => (string) $pagekey,
                'courseId' => $pagecontext->courseid,
                'cmId' => $pagecontext->cmid,
                'lang' => current_language(),
            ],
            'user' => self::user_key(),
            'preview' => preview::is_active(),
        ];
    }

    /**
     * Whether the analysis report of a module has errors (it then never runs, spec B12).
     *
     * @param array $entry Entry of the map.
     * @return bool
     */
    public static function has_errors(array $entry): bool {
        $report = json_decode((string) ($entry['report'] ?? ''), true);
        return !empty($report['errors']);
    }

    /**
     * Returns the templates a source renders with b.render (literal names, contract 12.4).
     *
     * @param string $content Source of a module.
     * @return string[]
     */
    public static function rendered_templates(string $content): array {
        preg_match_all(self::RENDER_PATTERN, $content, $matches);
        return array_values(array_unique($matches[2]));
    }

    /**
     * Returns the file of the modules of a revision: an AMD module returning the compiled functions by target. The
     * compiled code is inserted as it is (never changed nor evaluated here); markers of the library become URLs.
     *
     * @param int $revisionid Revision.
     * @return string JavaScript.
     */
    public static function bundle(int $revisionid): string {
        $functions = [];
        $entries = resolver::get_map($revisionid)[change::TYPE_JS] ?? [];
        foreach ($entries as $key => $entry) {
            $compiled = (string) ($entry['compiled'] ?? '');
            if ($compiled === '' || self::has_errors($entry)) {
                continue;
            }
            // One function per variant: the page receives the keys of its variants.
            $functions[] = json_encode((string) $key) . ': ' . self::resolve_markers($compiled);
        }
        return "define([], function() {\n\"use strict\";\nreturn {\n" . implode(",\n", $functions) . "\n};\n});\n";
    }

    /**
     * Returns the hash of the file of the modules of a revision (part of its URL).
     *
     * @param int $revisionid Revision.
     * @return string
     */
    public static function bundle_hash(int $revisionid): string {
        return substr(sha1(self::bundle($revisionid)), 0, 16);
    }

    /**
     * Replaces the markers of the library in JavaScript code (literal URLs, contract 12.3): characters that could end
     * a string literal are encoded.
     *
     * @param string $code Code.
     * @return string
     */
    public static function resolve_markers(string $code): string {
        if (strpos($code, 'brandable-asset') === false) {
            return $code;
        }
        $urls = \theme_brandable\local\assets\markers::urls();
        return preg_replace_callback(library::TEMPLATE_MARKER, function (array $match) use ($urls): string {
            return strtr($urls[$match[1]] ?? '', ['"' => '%22', "'" => '%27', "\x60" => '%60', '\\' => '%5C', "\n" => '',
                "\r" => '', '<' => '%3C', '>' => '%3E']);
        }, $code);
    }

    /**
     * Cleans the message of an error of a module before it is sent to a repair (spec B11, contract 12.8): one line
     * without tags, emails, runs of 4 digits or more and the name of the current user (full name, first name, last
     * name, as whole words of 3 letters at least) replaced by [masqué], then 500 characters at most.
     *
     * The message is the one thrown by the module: it may have been built from the page. This masks what is most
     * likely personal; it cannot guarantee that nothing of the page is left.
     *
     * @param string $message Message of the error.
     * @param \stdClass|null $user User whose name is masked (the current one when null).
     * @return string
     */
    public static function clean_error(string $message, ?\stdClass $user = null): string {
        global $USER;
        $user = $user ?? $USER;
        $mask = self::MASK;
        $text = trim((string) preg_replace('/\s+/u', ' ', clean_param($message, PARAM_NOTAGS)));
        $text = (string) preg_replace('/[^\s@<>"\'(),;:]+@[^\s@<>"\'(),;:]+\.[^\s@<>"\'(),;:]+/u', $mask, $text);
        $text = (string) preg_replace('/\d{4,}/u', $mask, $text);
        // The longest name first: the full name, then its parts.
        $names = [];
        if (!empty($user->id) && !isguestuser($user)) {
            $names = array_merge([fullname($user)], [(string) ($user->firstname ?? ''), (string) ($user->lastname ?? '')]);
        }
        // Whole words only (never inside a word), of 3 letters at least (not a short word such as "Al").
        $names = array_filter(array_map('trim', $names), fn($name) => preg_match_all('/\p{L}/u', $name) >= 3);
        usort($names, fn($a, $b) => \core_text::strlen($b) <=> \core_text::strlen($a));
        foreach (array_unique($names) as $name) {
            $text = (string) preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu', $mask, $text);
        }
        return \core_text::substr($text !== '' ? $text : 'Error', 0, 500);
    }

    /**
     * Returns the opaque identifier of the current user for b.storage (HMAC by a key of the site: the same in every
     * page, never the id itself).
     *
     * @return string
     */
    public static function user_key(): string {
        global $USER;
        $key = (string) get_config('theme_brandable', self::USERKEY_CONFIG);
        if ($key === '') {
            $key = random_string(32);
            set_config(self::USERKEY_CONFIG, $key, 'theme_brandable');
        }
        return substr(hash_hmac('sha256', (string) (int) ($USER->id ?? 0), $key), 0, 16);
    }
}
