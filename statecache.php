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
 * @package   qbehaviour_opaque
 * @copyright 2011 Antti Andreimann
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();


/**
 * Caches opaque states in the session.
 *
 * This is a singleton class, which is important, becuase we want one instance
 * per requests that does things with Opaque questions. That is how we ensure
 * we expire no longer required cache entries.
 *
 * @copyright 2011 Antti Andreimann
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_opaque_state_cache {
    const MAX_IDLE_LIFETIME = 2;

    /** @var array reference to where the data is acutally stored in the session. */
    protected $cache;

    /** @var qbehaviour_opaque_cache_manager singleton instance. */
    protected static $instance = null;

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

        $this->age_all_entries();

        // It would be better to do this at the end of the request, just before
        // the session is written out, rather than the next time this class is
        // created. However, I cannot currently find a way to hook into that
        // moment. Neither a destructor for this class, nor register_shutdown_function
        // works. They both happen after the session has been closed.
        $this->discard_old_entries();
    }

    /**
     * Get the cache manager instance associated with the current
     * user session or create a new one if it does not exist.
     *
     * @return qbehaviour_cache_manager the cache manager instance
     */
    public static function get() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Useful for debugging.
     *
     * For example add public function __destruct() { echo $this; } to this class
     * to output the cache state on any page that uses this cache.
     *
     * @return string representation of the state of the cache.
     */
    public function __toString() {
        $string = '';
        foreach ($this->cache as $state) {
            $string .= $state->cachekey . ' => [' . $state->questionsessionid . ', ' .
                    $state->sequencenumber . ', ' . $state->age . "]\n";
        }
        return $string;
    }

    /**
     * Load the cached state from the store.
     *
     * @param string $key a unique key for this cached entry
     * @return object|null On success, a cached opaque state,
     *      null if there was no usable cached state to return.
     */
    public function load($key) {
        if (!array_key_exists($key, $this->cache)) {
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
        if (array_key_exists($key, $this->cache)) {
            // If we already have some other state at this cache key, delete it.
            $this->delete($this->cache[$key]);
        }

        $state->cachekey = $key;
        $this->mark_fresh($state);
        $this->cache[$key] = $state;
    }

    /**
     * Delete the cached state, making sure that any remote session associated
     * with it is closed.
     *
     * @param object $state the state to remove.
     */
    public function delete($state) {
        // Try to stop any active question session.
        if (!empty($state->questionsessionid) && !empty($state->engine)) {
            try {
                $connection = new qbehaviour_opaque_connection($state->engine);
                $connection->stop($state->questionsessionid);
                $state->questionsessionid = null;
            } catch (SoapFault $e) {
                // ... but ignore any errors when doing so.
            }
        }

        // Remove from the cache, if it is there.
        if (!empty($state->cachekey)) {
            unset($this->cache[$state->cachekey]);
        }
    }

    /**
     * Reset the age of a cached entry to 0.
     * @param object $state a cache entry.
     */
    public function mark_fresh($state) {
        $state->timemodified = time();
        $state->age          = 0;
    }

    /**
     * Increase the age of all cache entries by one.
     */
    protected function age_all_entries() {
        foreach ($this->cache as $state) {
            $state->age += 1;
        }
    }

    /**
     * Discard any entries whose age is greater than MAX_IDLE_LIFETIME.
     */
    protected function discard_old_entries() {
        foreach ($this->cache as $state) {
            if ($state->age >= self::MAX_IDLE_LIFETIME) {
                $this->delete($state);
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
        if (!isset($SESSION->qtype_opaque_option_cache)) {
            $SESSION->qtype_opaque_option_cache = new stdClass();
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
