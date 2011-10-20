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
 * Defines the renderer for the Opaque behaviour.
 *
 * @package    qbehaviour
 * @subpackage opaque
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();


/**
 * Renderer for outputting parts of a question when the actual behaviour
 * used is not available.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_opaque_renderer extends qbehaviour_renderer {
    public function controls(question_attempt $qa, question_display_options $options) {
        if ($qa->get_state()->is_gave_up()) {
            return html_writer::tag('div', get_string('notcompletedmessage', 'qtype_opaque'),
                    array('class' => 'question_aborted'));
        }

        try {
            $opaquestate = qtype_opaque_update_state($qa, null, $options);
        } catch (SoapFault $sf) {
            return $this->soap_fault($sf);
        }

        $question = $qa->get_question();
        $resourcecache = new qtype_opaque_resource_cache($question->engineid,
        $question->remoteid, $question->remoteversion);

        if (!empty($opaquestate->cssfilename) &&
                $resourcecache->file_in_cache($opaquestate->cssfilename)) {
            $javascript = html_writer::script('
                    YUI.use("node", function(Y) {
                        Y.one("head").append(Y.Node.create(\'<link type="text/css" rel="stylesheet" href="' .
                                $resourcecache->file_url($opaquestate->cssfilename) . '" />\'));
                    })');
        }

        return html_writer::tag('div', $javascript . $opaquestate->xhtml,
                array('class' => qtype_opaque_browser_type()));
    }

    protected function soap_fault(SoapFault $sf) {
        $a = new stdClass();
        $a->faultcode = $sf->faultcode;
        $a->faultstring = $sf->getMessage();
        return html_writer::tag('div', get_string('errorconnecting', 'qtype_opaque') .
                html_writer::tag('pre', get_string('soapfault', 'qtype_opaque', $a),
                        array('class' => 'notifytiny')),
                array('class' => 'opaqueerror'));
    }
}
