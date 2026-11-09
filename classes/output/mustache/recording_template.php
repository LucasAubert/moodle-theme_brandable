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

namespace theme_brandable\output\mustache;

use theme_brandable\local\inspector\inspector;
use theme_brandable\local\inspector\markers;

/**
 * Wraps a compiled template to record its name and a sample of its context (page inspector, preview only), and
 * to surround its rendering with the template markers ({@see markers}).
 *
 * Nothing is generated: the wrapped template is the one core compiled.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_template extends \Mustache_Template {
    /** @var \Mustache_Template Wrapped template. */
    private \Mustache_Template $inner;

    /** @var string Template name. */
    private string $name;

    /**
     * Constructor.
     *
     * @param \Mustache_Engine $mustache Engine.
     * @param \Mustache_Template $inner Wrapped template.
     * @param string $name Template name.
     */
    public function __construct(\Mustache_Engine $mustache, \Mustache_Template $inner, string $name) {
        parent::__construct($mustache);
        $this->inner = $inner;
        $this->name = $name;
    }

    /**
     * Records the rendering, then renders the wrapped template.
     *
     * @param mixed $context Context.
     * @return string
     */
    public function render($context = []) {
        inspector::record($this->name, $context);
        $html = $this->inner->render($context);
        return markers::is_active() ? markers::wrap_output($this->name, $html) : $html;
    }

    // phpcs:disable moodle.NamingConventions.ValidFunctionName
    // Method name imposed by the Mustache_Template API.

    /**
     * Delegates to the wrapped template.
     *
     * @param \Mustache_Context $context Context.
     * @param string $indent Indentation.
     * @return string
     */
    public function renderInternal(\Mustache_Context $context, $indent = '') {
        return $this->inner->renderInternal($context, $indent);
    }
    // phpcs:enable
}
