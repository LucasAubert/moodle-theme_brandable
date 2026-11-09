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

namespace theme_brandable\local\overrides;

/**
 * A change brought by a revision: creates, replaces or removes one override.
 *
 * Mirrors the generator contract: { type, target, content, deleted, sourceHash, moodleVersion, scope }.
 * The scope is part of the identity of an override: several overrides of a target can coexist with different
 * scopes.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class change {
    /** @var string Mustache template override. */
    public const TYPE_TEMPLATE = 'template';
    /** @var string SCSS chunk. */
    public const TYPE_SCSS = 'scss';
    /** @var string Generated JavaScript module (contract 12): source in content, compiled, report and signature. */
    public const TYPE_JS = 'js';

    /** @var string Setting of the theme taken from the library: logo, logocompact or favicon (contract 11.4). */
    public const TYPE_SETTING = 'setting';

    /** @var string[] Known types. */
    public const TYPES = [self::TYPE_TEMPLATE, self::TYPE_SCSS, self::TYPE_JS, self::TYPE_SETTING];

    /** @var string SCSS chunk target: pre/<slug> or post/<slug>, slug in kebab-case. */
    public const SCSS_TARGET_PATTERN = '~^(pre|post)/[a-z0-9]+(-[a-z0-9]+)*$~';

    /** @var string Template name part (after the component), subdirectories allowed. */
    public const TEMPLATE_NAME_PATTERN = '~^[a-zA-Z0-9_-]+(/[a-zA-Z0-9_-]+)*$~';

    /**
     * Constructor.
     *
     * @param string $type One of {@see self::TYPES}.
     * @param string $target Template name or SCSS chunk.
     * @param string|null $content Content, null when deleted.
     * @param bool $deleted Whether the change removes the override.
     * @param string|null $remotesourcehash sourceHash sent by the generator (templates), if any.
     * @param bool $hasremotesourcehash Whether the generator sent a sourceHash at all (null included).
     * @param scope|null $scope Scope, the site when null.
     * @param string|null $compiled JS: compiled function expression.
     * @param string|null $report JS: analysis report (JSON).
     * @param string|null $signature JS: signature of Brandable.
     */
    public function __construct(
        /** @var string Type. */
        public readonly string $type,
        /** @var string Target. */
        public readonly string $target,
        /** @var string|null Content. */
        public readonly ?string $content = null,
        /** @var bool Deleted. */
        public readonly bool $deleted = false,
        /** @var string|null Remote source hash. */
        public readonly ?string $remotesourcehash = null,
        /** @var bool Whether a remote source hash was provided. */
        public readonly bool $hasremotesourcehash = false,
        ?scope $scope = null,
        /** @var string|null Compiled function (js). */
        public readonly ?string $compiled = null,
        /** @var string|null Analysis report, JSON (js). */
        public readonly ?string $report = null,
        /** @var string|null Signature (js). */
        public readonly ?string $signature = null,
    ) {
        $this->scope = $scope ?? new scope();
    }

    /** @var scope Scope. */
    public readonly scope $scope;

    /**
     * Builds a change from the generator/external format.
     *
     * @param array $data Keys: type, target, content, deleted, sourceHash (optional), moodleVersion (ignored:
     *                    the plugin records its own version), scope (optional: { level, value }, site when absent);
     *                    js: compiled, report (object, or JSON), signature.
     * @return self
     */
    public static function from_array(array $data): self {
        return new self(
            (string) ($data['type'] ?? ''),
            trim((string) ($data['target'] ?? '')),
            isset($data['content']) ? (string) $data['content'] : null,
            !empty($data['deleted']),
            isset($data['sourceHash']) ? (string) $data['sourceHash'] : null,
            array_key_exists('sourceHash', $data),
            scope::from_array($data['scope'] ?? null),
            isset($data['compiled']) ? (string) $data['compiled'] : null,
            !isset($data['report']) ? null
                : (is_string($data['report']) ? $data['report'] : json_encode($data['report'], JSON_UNESCAPED_SLASHES)),
            isset($data['signature']) ? (string) $data['signature'] : null,
        );
    }

    /**
     * Returns the key identifying the override this change applies to (type, target and scope).
     *
     * @return string
     */
    public function key(): string {
        return $this->type . ':' . resolver::entry_key($this->target, $this->scope);
    }
}
