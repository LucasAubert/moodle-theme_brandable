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

use theme_brandable\local\overrides\change;

/**
 * Result of a generation: a summary and the changes to apply, or a question for the administrator.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generation_result {
    /**
     * Constructor.
     *
     * @param string $summary Summary shown in the chat (the question when $needsclarification).
     * @param change[] $changes Changes.
     * @param bool $needsclarification Whether the request was too ambiguous: no changes, the summary is a question.
     * @param array $fontrequests Fonts of the catalogue to download before applying the changes (contract 11.6).
     * @param bool $needsjsactivation Whether the request needs JavaScript, not available on the site (contract 12.3):
     *                                no changes, the summary explains it.
     * @param array|null $pluginupgrade Capabilities missing in the plugin ({capabilities, url}, contract 15.3): no
     *                                  changes, the summary explains it.
     */
    public function __construct(
        /** @var string Summary shown in the chat. */
        public readonly string $summary,
        /** @var change[] Changes. */
        public readonly array $changes,
        /** @var bool Whether the summary is a question for the administrator. */
        public readonly bool $needsclarification = false,
        /** @var array Fonts of the catalogue to download before applying the changes (result.fontRequests). */
        public readonly array $fontrequests = [],
        /** @var bool Whether the request needs the generated JavaScript to be turned on. */
        public readonly bool $needsjsactivation = false,
        /** @var array|null Capabilities the plugin lacks for the request: ['capabilities' => string[], 'url' => string]. */
        public readonly ?array $pluginupgrade = null,
    ) {
    }

    /**
     * Builds a result from the contract format: { summary, changes: [{ type, target, content, deleted, sourceHash, ... }],
     * needsClarification, needsJsActivation, needsPluginUpgrade }.
     *
     * @param array $data Decoded result.
     * @return self
     */
    public static function from_array(array $data): self {
        $changes = [];
        foreach ($data['changes'] ?? [] as $item) {
            if (is_array($item)) {
                $changes[] = change::from_array($item);
            }
        }
        $fonts = is_array($data['fontRequests'] ?? null) ? array_values($data['fontRequests']) : [];
        $upgrade = is_array($data['needsPluginUpgrade'] ?? null) ? $data['needsPluginUpgrade'] : null;
        return new self(
            (string) ($data['summary'] ?? ''),
            $changes,
            !empty($data['needsClarification']),
            $fonts,
            !empty($data['needsJsActivation']),
            $upgrade === null ? null : [
                'capabilities' => array_values(array_filter(array_map('strval', (array) ($upgrade['capabilities'] ?? [])))),
                'url' => preg_match('~^https?://~i', (string) ($upgrade['url'] ?? '')) ? (string) $upgrade['url'] : '',
            ]
        );
    }

    /**
     * Exports the result in the contract format.
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'summary' => $this->summary,
            'changes' => array_map(fn(change $change) => [
                'type' => $change->type,
                'target' => $change->target,
                'content' => (string) $change->content,
                'deleted' => $change->deleted,
                'sourceHash' => $change->remotesourcehash,
                'scope' => $change->scope->to_array(),
            ] + ($change->type === change::TYPE_JS ? [
                // Generated JavaScript (contract 12.3): kept as delivered.
                'compiled' => $change->compiled,
                'report' => $change->report === null ? null : json_decode($change->report, true),
                'signature' => $change->signature,
            ] : []), $this->changes),
            'needsClarification' => $this->needsclarification,
            'fontRequests' => $this->fontrequests,
            'needsJsActivation' => $this->needsjsactivation,
        ] + ($this->pluginupgrade !== null ? ['needsPluginUpgrade' => $this->pluginupgrade] : []);
    }
}
