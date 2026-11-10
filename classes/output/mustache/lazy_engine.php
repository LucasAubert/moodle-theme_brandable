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

use core\output\renderer_base;
use theme_brandable\local\inspector\inspector;

/**
 * Placeholder engine injected into renderers: on first use, has core build the real engine of the
 * renderer, switches it to the Brandable loader and delegates to it (see {@see injector}).
 *
 * The real engine then replaces this object in the renderer, so only the first render_from_template()
 * call of a renderer goes through the delegation. In preview, the page inspector keeps this object in
 * place to wrap the loaded templates ({@see recording_template}). Every public method of Mustache_Engine is delegated
 * (checked by a unit test). The parent constructor is deliberately not called.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lazy_engine extends \core\output\mustache_engine {
    // phpcs:disable moodle.NamingConventions.ValidFunctionName
    // Method names are imposed by the Mustache_Engine API this class overrides.

    /** @var \Mustache_Engine|null The real engine, once built. */
    private ?\Mustache_Engine $engine = null;

    /** @var renderer_base Renderer owning this engine. */
    private renderer_base $renderer;

    /**
     * Constructor. The parent constructor is not called on purpose: nothing is built until first use.
     *
     * @param renderer_base $renderer Renderer owning this engine.
     */
    public function __construct(renderer_base $renderer) {
        $this->renderer = $renderer;
    }

    /**
     * Returns the real engine, building it on first call.
     *
     * @return \Mustache_Engine
     */
    private function engine(): \Mustache_Engine {
        if ($this->engine === null) {
            $this->engine = injector::build($this->renderer, $this);
        }
        return $this->engine;
    }

    /**
     * Delegates to the real engine.
     *
     * @param mixed $template See \Mustache_Engine::render().
     * @param mixed $context See \Mustache_Engine::render().
     * @return mixed
     */
    public function render($template, $context = []) {
        return $this->engine()->render($template, $context);
    }

    /**
     * Delegates to the real engine.
     *
     * @return mixed
     */
    public function getEscape() {
        return $this->engine()->getEscape();
    }

    /**
     * Delegates to the real engine.
     *
     * @return mixed
     */
    public function getEntityFlags() {
        return $this->engine()->getEntityFlags();
    }

    /**
     * Delegates to the real engine.
     *
     * @return mixed
     */
    public function getCharset() {
        return $this->engine()->getCharset();
    }

    /**
     * Delegates to the real engine (Mustache 3, Moodle 5.2 and later; never called before).
     *
     * @return mixed
     */
    public function getDoubleRenderLambdas() {
        return $this->engine()->getDoubleRenderLambdas();
    }

    /**
     * Delegates to the real engine (Mustache 3, Moodle 5.2 and later; never called before).
     *
     * @return mixed
     */
    public function getBuggyPropertyShadowing() {
        return $this->engine()->getBuggyPropertyShadowing();
    }

    /**
     * Delegates to the real engine (Mustache 3, Moodle 5.2 and later; never called before).
     *
     * @return mixed
     */
    public function getOptions() {
        return $this->engine()->getOptions();
    }

    /**
     * Delegates to the real engine.
     *
     * @return mixed
     */
    public function getPragmas() {
        return $this->engine()->getPragmas();
    }

    /**
     * Delegates to the real engine.
     *
     * @param \Mustache_Loader $loader See \Mustache_Engine::setLoader().
     * @return mixed
     */
    public function setLoader(\Mustache_Loader $loader) {
        return $this->engine()->setLoader($loader);
    }

    /**
     * Delegates to the real engine.
     *
     * @return mixed
     */
    public function getLoader() {
        return $this->engine()->getLoader();
    }

    /**
     * Delegates to the real engine.
     *
     * @param \Mustache_Loader $partialsloader See \Mustache_Engine::setPartialsLoader().
     * @return mixed
     */
    public function setPartialsLoader(\Mustache_Loader $partialsloader) {
        return $this->engine()->setPartialsLoader($partialsloader);
    }

    /**
     * Delegates to the real engine.
     *
     * @return mixed
     */
    public function getPartialsLoader() {
        return $this->engine()->getPartialsLoader();
    }

    /**
     * Delegates to the real engine.
     *
     * @param array $partials See \Mustache_Engine::setPartials().
     * @return mixed
     */
    public function setPartials(array $partials = []) {
        return $this->engine()->setPartials($partials);
    }

    /**
     * Delegates to the real engine.
     *
     * @param mixed $helpers See \Mustache_Engine::setHelpers().
     * @return mixed
     */
    public function setHelpers($helpers) {
        return $this->engine()->setHelpers($helpers);
    }

    /**
     * Delegates to the real engine.
     *
     * @return mixed
     */
    public function getHelpers() {
        return $this->engine()->getHelpers();
    }

    /**
     * Delegates to the real engine.
     *
     * @param mixed $name See \Mustache_Engine::addHelper().
     * @param mixed $helper See \Mustache_Engine::addHelper().
     * @return mixed
     */
    public function addHelper($name, $helper) {
        return $this->engine()->addHelper($name, $helper);
    }

    /**
     * Delegates to the real engine.
     *
     * @param mixed $name See \Mustache_Engine::getHelper().
     * @return mixed
     */
    public function getHelper($name) {
        return $this->engine()->getHelper($name);
    }

    /**
     * Delegates to the real engine.
     *
     * @param mixed $name See \Mustache_Engine::hasHelper().
     * @return mixed
     */
    public function hasHelper($name) {
        return $this->engine()->hasHelper($name);
    }

    /**
     * Delegates to the real engine.
     *
     * @param mixed $name See \Mustache_Engine::removeHelper().
     * @return mixed
     */
    public function removeHelper($name) {
        return $this->engine()->removeHelper($name);
    }

    /**
     * Delegates to the real engine.
     *
     * @param mixed $logger See \Mustache_Engine::setLogger().
     * @return mixed
     */
    public function setLogger($logger = null) {
        return $this->engine()->setLogger($logger);
    }

    /**
     * Delegates to the real engine.
     *
     * @return mixed
     */
    public function getLogger() {
        return $this->engine()->getLogger();
    }

    /**
     * Delegates to the real engine.
     *
     * @param \Mustache_Tokenizer $tokenizer See \Mustache_Engine::setTokenizer().
     * @return mixed
     */
    public function setTokenizer(\Mustache_Tokenizer $tokenizer) {
        return $this->engine()->setTokenizer($tokenizer);
    }

    /**
     * Delegates to the real engine.
     *
     * @return mixed
     */
    public function getTokenizer() {
        return $this->engine()->getTokenizer();
    }

    /**
     * Delegates to the real engine.
     *
     * @param \Mustache_Parser $parser See \Mustache_Engine::setParser().
     * @return mixed
     */
    public function setParser(\Mustache_Parser $parser) {
        return $this->engine()->setParser($parser);
    }

    /**
     * Delegates to the real engine.
     *
     * @return mixed
     */
    public function getParser() {
        return $this->engine()->getParser();
    }

    /**
     * Delegates to the real engine.
     *
     * @param \Mustache_Compiler $compiler See \Mustache_Engine::setCompiler().
     * @return mixed
     */
    public function setCompiler(\Mustache_Compiler $compiler) {
        return $this->engine()->setCompiler($compiler);
    }

    /**
     * Delegates to the real engine.
     *
     * @return mixed
     */
    public function getCompiler() {
        return $this->engine()->getCompiler();
    }

    /**
     * Delegates to the real engine.
     *
     * @param \Mustache_Cache $cache See \Mustache_Engine::setCache().
     * @return mixed
     */
    public function setCache(\Mustache_Cache $cache) {
        return $this->engine()->setCache($cache);
    }

    /**
     * Delegates to the real engine.
     *
     * @return mixed
     */
    public function getCache() {
        return $this->engine()->getCache();
    }

    /**
     * Delegates to the real engine.
     *
     * @param mixed $source See \Mustache_Engine::getTemplateClassName().
     * @return mixed
     */
    public function getTemplateClassName($source) {
        return $this->engine()->getTemplateClassName($source);
    }

    /**
     * Delegates to the real engine.
     *
     * @param mixed $name See \Mustache_Engine::loadTemplate().
     * @return mixed
     */
    public function loadTemplate($name) {
        $template = $this->engine()->loadTemplate($name);
        if (inspector::is_active()) {
            // Page inspector (preview only): record the context each time the template is rendered.
            return new recording_template($this->engine(), $template, $name);
        }
        return $template;
    }

    /**
     * Delegates to the real engine.
     *
     * @param mixed $name See \Mustache_Engine::loadPartial().
     * @return mixed
     */
    public function loadPartial($name) {
        return $this->engine()->loadPartial($name);
    }

    /**
     * Delegates to the real engine.
     *
     * @param mixed $source See \Mustache_Engine::loadLambda().
     * @param mixed $delims See \Mustache_Engine::loadLambda().
     * @return mixed
     */
    public function loadLambda($source, $delims = null) {
        return $this->engine()->loadLambda($source, $delims);
    }
    // phpcs:enable
}
