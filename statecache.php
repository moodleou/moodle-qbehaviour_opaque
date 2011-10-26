<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Defines the qbehaviour_opaque_state_cache class.
 *
 * @package    qbehaviour
 * @subpackage opaque
 * @copyright  2011 Antti Andreimann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

/**
 * Caches opaque states in the session.
 *
 * @copyright  2011 Antti Andreimann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class qbehaviour_opaque_state_cache {
    /** @var array reference to where the data is acutally stored in the session. */
    protected $cache;

    /** @var qbehaviour_opaque_cache_manager singleton instance. */
    protected static $manager;

    /**
     * Constructor.
     */
    protected function __construct() {
        global $SESSION;

        if (!isset($SESSION->qtype_opaque_state_cache) ||
                !is_array($SESSION->qtype_opaque_state_cache)) {
            $SESSION->qtype_opaque_state_cache = array();
        }

        $this->cache = &$SESSION->qtype_opaque_state_cache;
    }

    /**
     * Get the cache manager instance associated with the current
     * user session or create a new one if it does not exist.
     * 
     * @return qbehaviour_cache_manager the cache manager instance
     */
    public static function get() {
        if (empty($class->manager)) {
            $class->manager = new self();
        }

        return $class->manager;
    }

    /**
     * Load the cached state from the store.
     *
     * @param string $key a unique key for this cached entry
     * @return object|null On success, a cached opaque state,
     *      null if there was no usable cached state to return.
     */
    public function load($key) {
        if (!isset($this->cache[$key])) {
            return null;
        }

        return $this->cache[$key];
    }

    /**
     * Save or update the cached state
     *
     * @param string $key a unique key for this cached entry
     * @param object $state the opaque state to save
     */
    public function save($key, $state) {
        $this->cache[$key] = $state;
    }

    /**
     * Delete the cached state
     *
     * @param string $key a unique key of the cached entry to delete
     */
    public function delete($key) {
        unset($this->cache[$key]);
    }

    /**
     * 
     */
    public function discard_old() {
        if (count($this->cache) <= 1) {
            return;
        }

        $timenow = time();
        foreach ($this->cache as $key => $state) {
            if ($state->timemodified < $timenow - 10) {
                unset($this->cache[$key]);
            }
        }
    }

    /**
     * Cache the last used set of question display options.
     * @param question_display_options $options
     */
    public function set_last_used_options($options) {
        global $SESSION;
        if (is_null($options)) {
            return;
        }
        $SESSION->qtype_opaque_option_cache->readonly = $options->readonly;
        $SESSION->qtype_opaque_option_cache->marks = $options->marks;
        $SESSION->qtype_opaque_option_cache->markdp = $options->markdp;
        $SESSION->qtype_opaque_option_cache->correctness = $options->correctness;
        $SESSION->qtype_opaque_option_cache->feedback = $options->feedback;
        $SESSION->qtype_opaque_option_cache->generalfeedback = $options->generalfeedback;
    }

    /**
     * @return object the last used set of question display options.
     */
    public function get_last_used_options() {
        global $SESSION;
        if (!empty($SESSION->qtype_opaque_option_cache)) {
            return $SESSION->qtype_opaque_option_cache;
        } else {
            return null;
        }
    }
}
