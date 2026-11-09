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

use core_component;
use theme_brandable\local\assets\markers;
use theme_brandable\local\assets\settings;
use theme_brandable\local\js\modules;
use Mustache_Engine;
use Mustache_Tokenizer;
use stdClass;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\scope;
use theme_brandable\local\overrides\template_source;

/**
 * Validates changes before a revision is written.
 *
 * - Structure: known type, valid target, content present unless deleted, no duplicate.
 * - JavaScript (contract 12.3, spec B6): js/<slug> target, sizes, compiled function and signature of Brandable, at most
 *   MAX_MODULES modules, templates given to b.render available. The code is never analysed nor run here.
 * - Template targets: an override of any component must match an existing filesystem template; new
 *   templates can only be created under theme_brandable/ (and never over Brandable's own UI templates).
 * - Mustache: the template must tokenize, parse and compile (to a string, never executed); its partials
 *   must exist (filesystem, or override in the candidate revision or in the draft), and so must its static
 *   {{#str}} strings.
 * - SCSS: each chunk must parse, then the whole theme must compile with the candidate chunks.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validator {
    /** @var string Only templates under this prefix can be created (they exist in the database only). */
    public const CREATABLE_PREFIX = 'theme_brandable/';

    /**
     * Validates changes against the draft overrides and returns the records to store.
     *
     * @param change[] $changes Changes.
     * @param array $basemap Resolved map of the draft.
     * @return stdClass[] Change records (without revisionid / sortorder).
     * @throws validation_exception
     */
    public static function validate(array $changes, array $basemap): array {
        $errors = [];
        $records = [];
        $seen = [];

        if (empty($changes)) {
            throw new validation_exception([['target' => '', 'message' => get_string('error:nochanges', 'theme_brandable')]]);
        }

        foreach ($changes as $change) {
            $error = self::check_structure($change, $basemap);
            if ($error === null && isset($seen[$change->key()])) {
                $error = get_string('error:duplicatetarget', 'theme_brandable');
            }
            $seen[$change->key()] = true;
            if ($error !== null) {
                $errors[] = ['target' => $change->target, 'message' => $error];
                continue;
            }
            $records[] = self::to_record($change);
        }
        if ($errors) {
            throw new validation_exception($errors);
        }

        $candidate = resolver::apply($basemap, $records, 0);
        $errors = array_merge(self::check_templates($records, $candidate), self::check_js($records, $candidate));

        $scsstargets = [];
        foreach ($records as $record) {
            if ($record->type === change::TYPE_SCSS) {
                $scsstargets[] = $record->target;
                if (!$record->deleted && ($error = scss_compiler::check_syntax($record->content)) !== null) {
                    $errors[] = ['target' => $record->target, 'message' => $error];
                }
            }
        }
        // Compile the whole theme only when the chunks parse on their own (compiling takes seconds).
        if ($scsstargets && !$errors) {
            [$scsserrors, $selectors] = scss_compiler::check_theme_and_extract($candidate, $scsstargets);
            $errors = array_merge($errors, $scsserrors);
            // Selectors of the compiled chunks, for the verification in the preview.
            foreach ($records as $record) {
                if ($record->type === change::TYPE_SCSS && !$record->deleted) {
                    $key = resolver::entry_key($record->target, scope::from_columns($record->scopelevel, $record->scopevalue));
                    $record->selectors = json_encode($selectors[$key] ?? []);
                    $error = \theme_brandable\local\colour_modes::check_selectors($record->target, $selectors[$key] ?? []);
                    if ($error !== null) {
                        $errors[] = ['target' => $record->target, 'message' => $error];
                    }
                }
            }
        }

        if ($errors) {
            throw new validation_exception($errors);
        }
        return $records;
    }

    /**
     * Checks the type, target and content of a change.
     *
     * @param change $change Change.
     * @param array $basemap Resolved map of the draft.
     * @return string|null Error message.
     */
    private static function check_structure(change $change, array $basemap): ?string {
        if (!in_array($change->type, change::TYPES, true)) {
            return get_string('error:unknowntype', 'theme_brandable', s($change->type));
        }
        if ($change->type === change::TYPE_TEMPLATE) {
            if (!self::is_valid_template_name($change->target)) {
                return get_string('error:invalidtemplatename', 'theme_brandable');
            }
            $ondisk = template_source::get_original($change->target) !== null;
            if (strpos($change->target, self::CREATABLE_PREFIX) === 0) {
                // New templates only: existing theme_brandable files are Brandable's own UI (editor, banner).
                if ($ondisk) {
                    return get_string('error:protectedtemplate', 'theme_brandable');
                }
            } else if (!$ondisk) {
                return get_string('error:templatenotfound', 'theme_brandable', self::CREATABLE_PREFIX);
            }
        }
        if ($change->type === change::TYPE_SCSS && !preg_match(change::SCSS_TARGET_PATTERN, $change->target)) {
            return get_string('error:invalidscsstarget', 'theme_brandable');
        }
        if ($change->type === change::TYPE_SETTING) {
            // Logo, compact logo, favicon: the whole site, an image of the library (contract 11.4).
            if (!in_array($change->target, settings::TARGETS, true)) {
                return get_string('error:settingtarget', 'theme_brandable');
            }
            if (!$change->scope->is_site()) {
                return get_string('error:settingscope', 'theme_brandable');
            }
            if (!$change->deleted && ($error = settings::check($change->target, trim((string) $change->content))) !== null) {
                return $error;
            }
        }
        if ($change->type === change::TYPE_JS && ($error = self::check_js_structure($change)) !== null) {
            return $error;
        }
        if (
            in_array($change->type, [change::TYPE_SCSS, change::TYPE_TEMPLATE, change::TYPE_JS], true) && !$change->deleted
                && (!markers::well_formed($change->type, (string) $change->content)
                    || !markers::well_formed($change->type, (string) $change->compiled))
        ) {
            return get_string('error:assetmarker', 'theme_brandable');
        }
        if (($error = $change->scope->validate()) !== null) {
            return $error;
        }
        if ($change->type === change::TYPE_SCSS && strpos($change->target, 'pre/') === 0 && !$change->scope->is_site()) {
            // SCSS variables are global: a pre/ chunk cannot be limited to some pages.
            return get_string('error:prescope', 'theme_brandable');
        }
        if ($change->deleted) {
            if (!isset($basemap[$change->type][resolver::entry_key($change->target, $change->scope)])) {
                return get_string('error:nothingtodelete', 'theme_brandable');
            }
        } else if ($change->content === null || trim($change->content) === '') {
            return get_string('error:emptycontent', 'theme_brandable');
        }
        return null;
    }

    /**
     * Checks a JavaScript module on its own (contract 12.3): target, sizes, compiled function, signature, report.
     *
     * @param change $change Change of type js.
     * @return string|null Error message.
     */
    private static function check_js_structure(change $change): ?string {
        if (!preg_match(modules::TARGET_PATTERN, $change->target)) {
            return get_string('error:jstarget', 'theme_brandable');
        }
        if ($change->deleted) {
            return null;
        }
        if (\core_text::strlen((string) $change->content) > modules::MAX_CONTENT) {
            return get_string('error:jscontent', 'theme_brandable', modules::MAX_CONTENT);
        }
        $compiled = (string) $change->compiled;
        if (
            $compiled === '' || \core_text::strlen($compiled) > modules::MAX_COMPILED
                || !preg_match('~^function\s*\(~', $compiled)
        ) {
            return get_string('error:jscompiled', 'theme_brandable', modules::MAX_COMPILED);
        }
        // Without signature: a module restored from the cloud and changed out of Brandable (js_unsigned, contract
        // 13.4), shown as unsigned; a signature given has the form of Brandable.
        if ((string) $change->signature !== '' && !preg_match(modules::SIGNATURE_PATTERN, (string) $change->signature)) {
            return get_string('error:jssignature', 'theme_brandable');
        }
        if ($change->report !== null && !is_array(json_decode($change->report, true))) {
            return get_string('error:jsreport', 'theme_brandable');
        }
        return null;
    }

    /**
     * Checks the JavaScript modules of the candidate revision: at most MAX_MODULES (a target counts once, every scope),
     * and the templates rendered by b.render of the changed modules available (filesystem, or created in the revision).
     *
     * @param stdClass[] $records Change records of the candidate revision.
     * @param array $candidate Candidate resolved map.
     * @return array Errors.
     */
    private static function check_js(array $records, array $candidate): array {
        $errors = [];
        $changed = array_filter($records, fn($record) => $record->type === change::TYPE_JS);
        if (!$changed) {
            return [];
        }
        $targets = array_unique(array_column($candidate[change::TYPE_JS] ?? [], 'target'));
        if (count($targets) > modules::MAX_MODULES) {
            $errors[] = ['target' => reset($changed)->target,
                'message' => get_string('error:jsmaxmodules', 'theme_brandable', modules::MAX_MODULES)];
        }
        foreach ($changed as $record) {
            if ($record->deleted) {
                continue;
            }
            foreach (modules::rendered_templates((string) $record->content) as $template) {
                if (
                    template_source::get_original($template) === null
                        && !isset($candidate[change::TYPE_TEMPLATE][$template])
                ) {
                    $errors[] = ['target' => $record->target,
                        'message' => get_string('error:jstemplate', 'theme_brandable', s($template))];
                    break;
                }
            }
        }
        return $errors;
    }

    /**
     * Whether a template name is component/name with an existing component.
     *
     * @param string $name Template name.
     * @return bool
     */
    private static function is_valid_template_name(string $name): bool {
        if (strpos($name, '/') === false || strlen($name) > 255) {
            return false;
        }
        [$component, $template] = explode('/', $name, 2);
        if (clean_param($component, PARAM_COMPONENT) !== $component || !preg_match(change::TEMPLATE_NAME_PATTERN, $template)) {
            return false;
        }
        return $component === 'core' || core_component::get_component_directory($component) !== null;
    }

    /**
     * Builds the record stored for a change. The source hash is recomputed from the filesystem and is
     * authoritative; a different hash sent by the generator flags the change for review (not blocking).
     *
     * @param change $change Change.
     * @return stdClass
     */
    private static function to_record(change $change): stdClass {
        $sourcehash = null;
        $needsreview = false;
        if ($change->type === change::TYPE_TEMPLATE) {
            $sourcehash = template_source::get_original_hash($change->target);
            $needsreview = $change->hasremotesourcehash && $change->remotesourcehash !== $sourcehash;
        }
        $content = $change->deleted ? null : $change->content;
        $js = [];
        if ($change->type === change::TYPE_JS) {
            $report = $change->deleted ? null : $change->report;
            $analyzer = $report === null ? null : (json_decode($report, true)['analyzer'] ?? null);
            $js = [
                'compiled' => $change->deleted ? null : $change->compiled,
                'report' => $report,
                'signature' => $change->deleted ? null : $change->signature,
                'jsanalyzer' => is_scalar($analyzer) ? \core_text::substr((string) $analyzer, 0, 20) : null,
            ];
        }
        return (object) ([
            'type' => $change->type,
            'target' => $change->target,
            'scopelevel' => $change->scope->level,
            'scopevalue' => $change->scope->value,
            'content' => $content,
            'deleted' => (int) $change->deleted,
            'contenthash' => $content === null ? null : hash('sha256', $content),
            'sourcehash' => $sourcehash,
            'remotesourcehash' => $change->remotesourcehash,
            'needsreview' => (int) $needsreview,
            'moodleversion' => template_source::get_moodle_version(),
        ] + $js);
    }

    /**
     * Checks the changed templates, and that every partial used by the candidate overrides exists.
     *
     * Partials can be provided by the filesystem or by an override, either in the revision being created
     * or in the draft it builds upon. When the revision removes overrides, all the remaining overrides are
     * checked so that removing a theme_brandable/* template still used as a partial is refused.
     *
     * @param stdClass[] $records Change records of the candidate revision.
     * @param array $candidate Candidate resolved map.
     * @return array Errors.
     */
    private static function check_templates(array $records, array $candidate): array {
        $errors = [];
        $tocheck = [];
        $changed = [];
        $removes = false;
        foreach ($records as $record) {
            if ($record->type !== change::TYPE_TEMPLATE) {
                continue;
            }
            if ($record->deleted) {
                $removes = true;
                continue;
            }
            $scope = scope::from_columns($record->scopelevel, $record->scopevalue);
            $tocheck[resolver::entry_key($record->target, $scope)] = true;
            $changed[resolver::entry_key($record->target, $scope)] = true;
        }
        if ($removes) {
            $tocheck += array_fill_keys(array_keys($candidate[change::TYPE_TEMPLATE]), true);
        }

        foreach (array_keys($tocheck) as $key) {
            $entry = $candidate[change::TYPE_TEMPLATE][$key];
            $target = $entry['target'];
            try {
                $tree = self::parse_template($entry['content']);
            } catch (\Mustache_Exception $e) {
                $errors[] = ['target' => $target, 'message' => get_string('error:mustache', 'theme_brandable', $e->getMessage())];
                continue;
            }
            // No executable code beyond the original's (only the overrides of this change set: older ones stay).
            $added = isset($changed[$key])
                ? template_js::find_added(template_source::get_original($target), $entry['content']) : [];
            if ($added) {
                // Always in English: written for the generator (sent back with the repair request).
                $message = get_string_manager()->get_string('error:templatejs', 'theme_brandable', implode(', ', $added), 'en');
                $errors[] = ['target' => $target, 'message' => $message];
                continue;
            }
            $error = self::check_strings($entry['content']);
            if ($error !== null) {
                $errors[] = ['target' => $target, 'message' => $error];
                continue;
            }
            foreach (self::get_partials($tree) as $partial) {
                if (!self::partial_available($partial, resolver::entry_scope($entry), $candidate)) {
                    $message = get_string('error:unknownpartial', 'theme_brandable', s($partial));
                    $errors[] = ['target' => $target, 'message' => $message];
                    break;
                }
            }
        }
        return $errors;
    }

    /**
     * Whether a partial is available wherever the including override applies: a filesystem template, or a
     * database-only override (theme_brandable/*) that is site-wide or has the same scope as the includer.
     *
     * @param string $partial Partial name.
     * @param scope $scope Scope of the including override.
     * @param array $candidate Candidate resolved map.
     * @return bool
     */
    private static function partial_available(string $partial, scope $scope, array $candidate): bool {
        if (template_source::get_original($partial) !== null) {
            return true;
        }
        $templates = $candidate[change::TYPE_TEMPLATE];
        return isset($templates[$partial]) || isset($templates[resolver::entry_key($partial, $scope)]);
    }

    /**
     * Tokenizes, parses and compiles a template; the compiler only produces PHP source as a string
     * here, nothing is evaluated.
     *
     * @param string $source Template source.
     * @return array Parse tree.
     * @throws \Mustache_Exception
     */
    private static function parse_template(string $source): array {
        $engine = new Mustache_Engine(['pragmas' => [Mustache_Engine::PRAGMA_BLOCKS]]);
        $parser = $engine->getParser();
        $parser->setPragmas($engine->getPragmas());
        $tree = $parser->parse($engine->getTokenizer()->scan($source));
        $compiler = $engine->getCompiler();
        $compiler->setPragmas($engine->getPragmas());
        $compiler->compile($source, $tree, 'Brandable_Validation', true);
        return $tree;
    }

    /**
     * Checks that the static strings used by {{#str}} and {{#cleanstr}} exist.
     *
     * An unknown string identifier makes the page fail in developer debug mode (and shows [[id]] otherwise).
     * Identifiers or components built from variables are not checked.
     *
     * @param string $source Template source.
     * @return string|null Error message.
     */
    private static function check_strings(string $source): ?string {
        preg_match_all(
            '~\{\{\s*#\s*(?:str|cleanstr)\s*\}\}\s*([^,{}\s]+)\s*(?:,\s*([^,{}\s]+))?~',
            $source,
            $matches,
            PREG_SET_ORDER
        );
        $manager = get_string_manager();
        foreach ($matches as $match) {
            $identifier = $match[1];
            $component = ($match[2] ?? '') !== '' ? $match[2] : 'core';
            if (!$manager->string_exists($identifier, $component)) {
                return get_string('error:unknownstring', 'theme_brandable', "{$identifier}, {$component}");
            }
        }
        return null;
    }

    /**
     * Collects the partials ({{> name}}) and parents ({{< name}}) referenced by a parse tree.
     *
     * @param array $nodes Parse tree nodes.
     * @return string[]
     */
    private static function get_partials(array $nodes): array {
        $partials = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = $node[Mustache_Tokenizer::TYPE] ?? null;
            if ($type === Mustache_Tokenizer::T_PARTIAL || $type === Mustache_Tokenizer::T_PARENT) {
                $partials[] = $node[Mustache_Tokenizer::NAME];
            }
            if (!empty($node[Mustache_Tokenizer::NODES])) {
                $partials = array_merge($partials, self::get_partials($node[Mustache_Tokenizer::NODES]));
            }
        }
        return array_values(array_unique($partials));
    }
}
