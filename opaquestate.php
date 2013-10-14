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
 * Defines the qbehaviour_opaque_state class.
 *
 * @package   qbehaviour_opaque
 * @copyright 2006 The Open University, 2011 Antti Andreimann
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/opaque/enginemanager.php');


/**
 * Stores active OPAQUE question session and caches associated results
 *
 * @copyright 2011 Antti Andreimann based on code from The Open University.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_opaque_state {

    /**
     * @var object The actual data about the state. We use a stdClass because
     * this gets cached in the session, and there is a problem caching real
     * classes there.
     */
    protected $state;

    /** @var qbehaviour_opaque_resource_cache */
    protected $resourcecache;

    /** @var qbehaviour_opaque_cache_manager */
    protected $statecache;

    /** @var qbehaviour_opaque_connection */
    protected $connection;

    /** @var array tokens that should be replaced in the HTML. */
    protected $replaces;

    /**
     * Create represenation of the current state of the question in the remote
     * system, either by finding the state in the cache and then updating it
     * if necessray, or by calling the remote server to create a new state.
     *
     * @param question_attempt $qa the question attempt to do processing for.
     * @param question_attempt_step $pendingstep (optional) a pending step that
     *      is currently being processed.
     * @param question_display_options $options (optional) display options to
     *      pass on to the question engine.
     */
    public function __construct(question_attempt $qa, question_attempt_step $pendingstep = null,
            question_display_options $options = null) {

        $firststep = $this->find_step(0, $qa, $pendingstep);
        if (is_null($firststep) || !$firststep->has_behaviour_var('_randomseed')) {
            throw new coding_exception(
                    'First step of the question attempt not properly initialised.');
        }

        $targetseq = $qa->get_num_steps() - 1;
        if (!is_null($pendingstep)) {
            $targetseq += 1;
        }

        $this->statecache = qbehaviour_opaque_state_cache::get();
        $this->state = $this->statecache->load(
                $this->calculate_cache_key($qa->get_question()->id, $firststep));

        if (is_null($options)) {
            $options = $this->statecache->get_last_used_options();
        }

        if (!$this->is_valid($qa, $firststep, $targetseq, $options)) {
            $this->invalidate();
        }

        if (empty($this->state)) {
            $this->create_new_state($qa, $firststep, $options);
        } else {
            // Make sure the name prefix is correct. (We don't know the $qa id
            // when start is called, but we get told it later.
            $this->state->nameprefix = $qa->get_field_prefix();
        }

        try {
            $this->update($qa, $pendingstep, $targetseq, $options);
        } catch (SoapFault $sf) {
            $this->invalidate();
            throw $sf;
        }
    }

    /**
     * Invalidate the cached state, cleaning up any related resources.
     */
    public function invalidate() {
        $this->statecache->delete($this->state);
        $this->state = null;
    }

    /**
     * Create a new state and store it in cache.
     *
     * @param question_attempt $qa the question attempt to use
     * @param question_attempt_step $firststep the first step, includes
     * @param question_display_options $options display options in use.
     * @return mixed a stdClass with attributes for holding the cached state
     */
    protected function create_new_state($qa, $firststep, $options) {
        $question = $qa->get_question();

        // Information about the question being attempts.
        $this->state = new stdClass();
        $this->state->engineid      = $question->engineid;
        $this->state->remoteid      = $question->remoteid;
        $this->state->remoteversion = $question->remoteversion;
        $this->state->options       = $this->make_option_string($options);
        $this->state->randomseed    = $firststep->get_behaviour_var('_randomseed');
        $this->state->nameprefix    = $qa->get_field_prefix();
        $this->state->engine        = qtype_opaque_engine_manager::get()->load($question->engineid);

        // Set up the fields where we will store data sent back by the remote engine.
        $this->state->questionended         = false;
        $this->state->sequencenumber        = -1;
        $this->state->resultssequencenumber = -1;
        $this->state->xhtml                 = null;
        $this->state->questionsessionid     = null;
        $this->state->results               = null;
        $this->state->cssfilename           = null;
        $this->state->progressinfo          = null;

        // Having reloaded the engine definition, we need to re-connect.
        $this->connection = null;

        $this->statecache->save(
                $this->calculate_cache_key($question->id, $firststep), $this->state);
        $this->statecache->set_last_used_options($options);
    }

    /**
     * Update opaque state to match the question attempt by sending
     * user data in the pending step to the engine for processing.
     * If engine session has not been started yet, start is called,
     * if the step can not be processed by the current session (eg. it's
     * out of sequence), the session will be restarted and the entire
     * history played back.
     *
     * @param question_attempt $qa the question attempt to use
     * @param question_attempt_step $pendingstep (optional) if we are in
     *      the process of adding a new step to the end of the question_attempt,
     *      this is it.
     * @param question_display_options $options (optional) display options to
     *      pass on to the question engine
     */
    public function update($qa, $pendingstep = null, $targetseq, $options = null) {
        // If this state has never been started, start it now.
        if ($this->state->sequencenumber < 0) {
            $firststep = $this->find_step(0, $qa, $pendingstep);
            $this->start_question_session($firststep, $options);
        }

        // Now play back the user input.
        while ($this->state->sequencenumber < $targetseq) {
            if (!CLI_SCRIPT) {
                set_time_limit($this->state->engine->timeout + 30); // Prevent PHP time-outs.
            }
            // For slower engines and longer sequences, it is concievable that we
            // could hit the browser connection timeout. However, it is not acceptable
            // to do any output here, so there is noting we can do about that.
            // Browser time-outs tend to be about 5 minutes.

            $step = $this->find_step($this->state->sequencenumber + 1, $qa, $pendingstep);
            $this->process_next_step($step);

            if ($this->state->questionended) {
                $this->state->sequencenumber = $targetseq;
                break;
            }
        }

        $this->statecache->mark_fresh($this->state);
    }

    /**
     * Start a question session and cache the results.
     * @param question_attempt_step $step the first step of the qa being processed.
     * @param question_display_options $options display options in use.
     */
    public function start_question_session($step, $options) {
        $resourcecache = $this->get_resource_cache();

        $startreturn = $this->get_connection()->start(
                $this->state->remoteid, $this->state->remoteversion,
                $step->get_all_data(), $resourcecache->list_cached_resources(),
                $options);

        $this->extract_stuff_from_response($startreturn, $resourcecache);
        $this->state->sequencenumber++;
    }

    /**
     * Take first unprocessed step and send it to the engine.
     * @param question_attempt_step $step the next step of the qa being processed.
     */
    public function process_next_step($step) {
        $resourcecache = $this->get_resource_cache();

        $processreturn = $this->get_connection()->process(
                $this->state->questionsessionid, self::submitted_data($step));

        if (!empty($processreturn->results)) {
            $this->state->results = $processreturn->results;
            $this->state->resultssequencenumber = $this->state->sequencenumber + 1;
        }

        if ($processreturn->questionEnd) {
            $this->state->questionended = true;
            unset($this->state->questionsessionid);
            return;
        }

        $this->extract_stuff_from_response($processreturn, $resourcecache);
        $this->state->sequencenumber++;
    }

    /**
     * Calculate a hash code that should not change during the lifetime
     * of a question attempt and allows us to find the matching
     * cache entries without relying on the database ID.
     *
     * @param int $questionid the question being attempted
     * @param question_attempt_step $firststep the first step of the attempt.
     * @return string a unique hash code for the question attempt.
     *
     */
    protected function calculate_cache_key($questionid, $firststep) {
        return md5(implode('|', array(
            $questionid,
            $firststep->get_behaviour_var('_randomseed'),
            $firststep->get_behaviour_var('_userid'),
            $firststep->get_behaviour_var('_language'),
            $firststep->get_behaviour_var('_preferredbehaviour'),
        )));
    }

    /**
     * Convert question_display_options into a string that can be used in cache keys.
     * @param question_display_options $options the set of options.
     */
    protected function make_option_string($options) {
        if (is_null($options)) {
            return '';
        }

        return implode('|', array(
            (int) $options->readonly,
            (int) $options->marks,
            (int) $options->markdp,
            (int) $options->correctness,
            (int) $options->feedback,
            (int) $options->generalfeedback,
        ));
    }

    /**
     * Get a step from $qa, as if $pendingstep had already been added at the end
     * of the list, if it is not null.
     *
     * @param int $seq the step number to get.
     * @param question_attempt $qa the question attempt.
     * @param question_attempt_step|null $pendingstep (optional) the new step
     *      that is about ot be added to the question attempt.
     * @return question_attempt_step the requested step.
     */
    protected function find_step($seq, question_attempt $qa, $pendingstep) {
        if ($seq < $qa->get_num_steps()) {
            return $qa->get_step($seq);
        }
        if ($seq == $qa->get_num_steps() && !is_null($pendingstep)) {
            return $pendingstep;
        }
        throw new coding_exception('Sequence number ' . $seq . ' out of range.');
    }

    /**
     * Get all the sumbitted data from a quesiton_attempt_step.
     * @param question_attempt_step $step the step.
     * @return array the response data.
     */
    public static function submitted_data(question_attempt_step $step) {
        $response = $step->get_submitted_data();
        return qbehaviour_opaque_fix_up_submitted_data($response, $step);
    }

    /**
     * Get a properly filtered question XHTML.
     * @return string the HTML with %% tokens replaced.
     */
    public function get_xhtml() {
        $replaces = $this->get_replaces();
        return str_replace(array_keys($replaces), $replaces, $this->state->xhtml);
    }

    /**
     * @return object results the results information, if any have been returned yet.
     */
    public function get_results() {
        return $this->state->results;
    }

    /**
     * @return string the name of the CSS file in the resource cache for this attempt.
     */
    public function get_css_filename() {
        return $this->state->cssfilename;
    }

    /**
     * @return string the progress info from the remote system.
     */
    public function get_progress_info() {
        return $this->state->progressinfo;
    }

    /**
     * @return the sequence number of the step where the remote system most
     * recently sent back results.
     */
    public function get_results_sequence_number() {
        return $this->state->resultssequencenumber;
    }

    /**
     * Get resource cache associated with the current opaque state
     *
     * @return qbehaviour_opaque_resource_cache
     */
    protected function get_resource_cache() {
        if (empty($this->resourcecache)) {
            $this->resourcecache = new qbehaviour_opaque_resource_cache(
                    $this->state->engineid, $this->state->remoteid,
                    $this->state->remoteversion);
        }

        return $this->resourcecache;
    }

    /**
     * Get connection to the correct question engine
     *
     * @return qbehaviour_opaque_connection the connection
     */
    protected function get_connection() {
        if (empty($this->connection)) {
            $this->connection = new qbehaviour_opaque_connection($this->state->engine);
        }

        return $this->connection;
    }

    /**
     * Check if the cached state is valid for a question attempt.
     * @param question_attempt $qa the question attempt to use
     * @param int $targetseq the sequence number we want to be at.
     * @return true if cached state is valid, false otherwise
     */
    protected function is_valid($qa, $firststep, $targetseq, $options) {
        $question = $qa->get_question();

        return !empty($this->state) &&
                $this->state->engineid       == $question->engineid &&
                $this->state->remoteid       == $question->remoteid &&
                $this->state->remoteversion  == $question->remoteversion &&
                $this->state->randomseed     == $firststep->get_behaviour_var('_randomseed') &&
                $this->state->options        == $this->make_option_string($options) &&
                $this->state->sequencenumber <= $targetseq;
    }

    /**
     * Pulls out the fields common to StartResponse and ProcessResponse.
     *
     * @param object $response a StartResponse or ProcessResponse.
     * @param object $resourcecache the resource cache for this question.
     */
    protected function extract_stuff_from_response($response,
            qbehaviour_opaque_resource_cache $resourcecache) {

        // Apply OpenMark hacks.
        $response = qbehaviour_opaque_hacks_filter_response($response, $this->state);

        $this->state->xhtml = $response->XHTML;

        // Record the session id.
        if (!empty($response->questionSession)) {
            $this->state->questionsessionid = $response->questionSession;
        }

        // Process the CSS.
        if (!empty($response->CSS)) {
            $this->state->cssfilename = $resourcecache->stylesheet_filename(
                    $this->state->questionsessionid);

            $replaces = $this->get_replaces();
            unset($replaces['%%IDPREFIX%%']); // Cannot be used in CSS.
            $resourcecache->cache_file($this->state->cssfilename, 'text/css;charset=UTF-8',
                    str_replace(array_keys($replaces), $replaces, $response->CSS));
        }

        // Process the resources.
        if (isset($response->resources)) {
            $resourcecache->cache_resources($response->resources);
        }

        // Save the progress info.
        if (isset($response->progressInfo)) {
            $this->state->progressinfo = str_replace(array_keys($replaces), $replaces, $response->progressInfo);
        }

        return true;
    }

    /**
     * Create a map of replacements that must be applied to
     * question xhtml, CSS and header.
     *
     * @return array a map of replacements
     */
    protected function get_replaces() {
        if (!empty($this->replaces)) {
            return $this->replaces;
        }

        $this->replaces = array(
            '%%RESOURCES%%' => $this->get_resource_cache()->file_url(''),
            '%%IDPREFIX%%'  => $this->state->nameprefix,
            '%%%%'          => '%%'
        );

        $strings = array('lTRYAGAIN', 'lGIVEUP', 'lNEXTQUESTION', 'lENTERANSWER',
                'lCLEAR', 'lTRY', 'lTRIES');
        foreach ($strings as $string) {
            $this->replaces["%%$string%%"] = get_string($string, 'qbehaviour_opaque');
        }

        return $this->replaces;
    }
}
