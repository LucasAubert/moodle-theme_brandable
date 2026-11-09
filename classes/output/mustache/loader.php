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
use theme_brandable\local\overrides\context;

/**
 * Mustache loader serving the Brandable overrides first (draft in preview, published otherwise),
 * then falling back on the standard filesystem resolution (theme chain, then component).
 *
 * Partials go through this loader too: core never sets a dedicated partials loader.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class loader extends \core\output\mustache_filesystem_loader {
    /** @var bool Whether this loader serves partials (their source is wrapped in the template markers). */
    private bool $partials;

    /**
     * Constructor.
     *
     * @param bool $partials Whether this loader serves partials.
     */
    public function __construct(bool $partials = false) {
        parent::__construct();
        $this->partials = $partials;
    }

    /**
     * Loads a template by name.
     *
     * @param string $name Template name (component/name).
     * @return string Template source.
     */
    public function load($name) {
        global $CFG;

        inspector::record($name);
        $override = context::get_template($name);
        // Markers of the library become the URLs of the assets (contract 11.3).
        $override = $override === null ? null : \theme_brandable\local\assets\markers::template($override);
        if ($override === null) {
            $source = parent::load($name);
        } else if (!empty($CFG->debugtemplateinfo)) {
            $source = "<!-- template(PHP, brandable): $name -->" . $override . "<!-- /template(PHP, brandable): $name -->";
        } else {
            $source = $override;
        }
        // Partials cannot be intercepted when rendered: their source carries the markers.
        return $this->partials && markers::is_active() ? markers::wrap_source($name, $source) : $source;
    }
}
