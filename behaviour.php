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
 * This behaviour specifically for use with the Opaque question type.
 *
 * @package   qbehaviour_opaque
 * @copyright 2010 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/opaque/connection.php');
require_once($CFG->dirroot . '/question/behaviour/opaque/connection.php');
require_once($CFG->dirroot . '/question/behaviour/opaque/statecache.php');
require_once($CFG->dirroot . '/question/behaviour/opaque/resourcecache.php');
require_once($CFG->dirroot . '/question/behaviour/opaque/legacy.php');
require_once($CFG->dirroot . '/question/behaviour/opaque/opaquestate.php');


/**
 * This behaviour is specifically for use with the Opaque question type.
 *
 * @copyright 2010 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_opaque extends question_behaviour {
    /** @var string */
    protected $preferredbehaviour;
    /** @var string */
    protected $questionsummary;

    public function __construct(question_attempt $qa, $preferredbehaviour) {
        parent::__construct($qa, $preferredbehaviour);
        $this->preferredbehaviour = $preferredbehaviour;
    }

    public function is_compatible_question(question_definition $question) {
        return $question instanceof qtype_opaque_question;
    }

    public function can_finish_during_attempt() {
        return true;
    }

    public function get_state_string($showcorrectness) {
        $state = $this->qa->get_state();
        $omstate = $this->qa->get_last_behaviour_var('_statestring');

        if ($state->is_finished()) {
            return $state->default_string($showcorrectness);

        } else if ($omstate) {
            return $omstate;

        } else {
            return get_string('notcomplete', 'qbehaviour_opaque');
        }
    }

    public function init_first_step(question_attempt_step $step, $variant) {
        global $USER;

        parent::init_first_step($step, $variant);

        // We no longer set the random seed as such (we pass a fixed conventional
        // value instead). Instead we pass the variant as OpenMark's 'attempt'
        // paramter.
        $step->set_behaviour_var('_randomseed', 123456789);
        $step->set_behaviour_var('_attempt', $variant);
        $step->set_behaviour_var('_userid', $USER->id);
        $step->set_behaviour_var('_language', current_language());
        $step->set_behaviour_var('_preferredbehaviour', $this->preferredbehaviour);

        $opaquestate = new qbehaviour_opaque_state($this->qa, $step);
        $step->set_behaviour_var('_statestring', $opaquestate->get_progress_info());

        // Remember the question summary.
        $this->questionsummary = html_to_text($opaquestate->get_xhtml(), 0, false);
    }

    public function adjust_display_options(question_display_options $options) {
        if (!$this->qa->has_marks()) {
            $options->correctness = false;
        }
        if ($this->qa->get_state()->is_finished()) {
            $options->readonly = true;
        }
    }

    public function get_question_summary() {
        return $this->questionsummary;
    }

    protected function is_same_response(question_attempt_step $pendingstep) {
        $newdata = $pendingstep->get_submitted_data();
        $newdata = qbehaviour_opaque_fix_up_submitted_data($newdata, $pendingstep);

        // If an omact_ button has been clicked, never treat this as a duplicate submission.
        if (qbehaviour_opaque_response_contains_om_action($newdata)) {
            return false;
        }

        $laststep = $this->qa->get_last_step();
        $olddata = $laststep->get_submitted_data();
        $olddata = qbehaviour_opaque_fix_up_submitted_data($olddata, $laststep);
        return question_utils::arrays_have_same_keys_and_values($newdata, $olddata);
    }

    public function summarise_action(question_attempt_step $step) {
        if ($step->has_behaviour_var('_randomseed')) {
            return $this->summarise_start($step);

        } else if ($step->has_behaviour_var('finish')) {
            return $this->summarise_finish($step);

        } else if ($step->has_behaviour_var('comment')) {
            return $this->summarise_manual_comment($step);

        } else if ($step->has_qt_var('omact_ok')) {
            // This is always a 'Try again' event in OpenMark. In this case we
            // use button label.
            return $step->get_qt_var('omact_ok');

        } else {
            $summary = $this->question->summarise_response(qbehaviour_opaque_state::submitted_data($step));
            if ($this->step_has_a_submitted_response($step)) {
                return get_string('submitted', 'question', $summary);
            } else {
                return get_string('saved', 'question', $summary);
            }
        }
    }

    public function process_action(question_attempt_pending_step $pendingstep) {
        if ($pendingstep->has_behaviour_var('finish')) {
            return $this->process_finish($pendingstep);

        } else if ($pendingstep->has_behaviour_var('comment')) {
            return $this->process_comment($pendingstep);

        } else if ($this->is_same_response($pendingstep) ||
                $this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;

        } else {
            return $this->process_remote_action($pendingstep);
        }
    }

    public function process_finish(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        // Try to get the question to stop.
        $result = $this->process_remote_action($pendingstep);

        if ($result == question_attempt::KEEP && !$pendingstep->get_state()->is_finished()) {
            // They tried to finish but the question is not finished, so all we
            // can do is to set the state to gave up. This lets the renderer
            // handle the review page appropriately.
            $pendingstep->set_state(question_state::$gaveup);
        }
        return $result;
    }

    public function process_remote_action(question_attempt_pending_step $pendingstep) {
        $opaquestate = new qbehaviour_opaque_state($this->qa, $pendingstep);

        if ($opaquestate->get_results_sequence_number() != $this->qa->get_num_steps()) {
            if ($opaquestate->get_progress_info() === 'Answer saved') {
                $pendingstep->set_state(question_state::$complete);
            } else {
                $pendingstep->set_state(question_state::$todo);
            }

            $pendingstep->set_behaviour_var('_statestring', $opaquestate->get_progress_info());

        } else {
            // Look for a score on the default axis.
            $pendingstep->set_fraction(0);
            $results = $opaquestate->get_results();
            foreach ($results->scores as $score) {
                if ($score->axis == '') {
                    $pendingstep->set_fraction($score->marks / $this->question->defaultmark);
                }
            }

            if ($results->attempts > 0) {
                $pendingstep->set_state(question_state::$gradedright);
            } else {
                $pendingstep->set_state(
                        question_state::graded_state_for_fraction($pendingstep->get_fraction()));
            }

            if (!empty($results->questionLine)) {
                $this->qa->set_question_summary(
                        $this->cleanup_results($results->questionLine));

                if (preg_match('~variant\s*=\s*(\d+)~i', $results->questionLine, $matches)) {
                    $pendingstep->set_new_variant_number($matches[1]);
                } else {
                    $pendingstep->set_new_variant_number(1);
                }
            }
            if (!empty($results->answerLine)) {
                $pendingstep->set_new_response_summary(
                        $this->cleanup_results($results->answerLine));
            }
            if (!empty($results->actionSummary)) {
                $pendingstep->set_behaviour_var('_actionsummary',
                        $this->cleanup_results($results->actionSummary));
            }
        }

        return question_attempt::KEEP;
    }

    protected function cleanup_results($line) {
        return preg_replace('/\\s+/', ' ', $line);
    }

    public function step_has_a_submitted_response($step) {
        if ($step->has_behaviour_var('finish')) {
            return false;

        } else if ($step->has_behaviour_var('comment')) {
            return false;

        } else {
            $foundomact = false;
            foreach (qbehaviour_opaque_state::submitted_data($step) as $name => $value) {
                if ($name === 'omact_ok') {
                    // This is a Try again state.
                    return false;
                }
                if (substr($name, 0, 6) === 'omact_') {
                    $foundomact = true;
                }
            }
            return $foundomact;
        }
    }
}
