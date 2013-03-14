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
 * Defines the qbehaviour_opaque_connection class.
 *
 * @package   qbehaviour_opaque
 * @copyright 2011 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();


// In config.php, you can set
// $CFG->qtype_opaque_soap_class = 'qtype_opaque_soap_client_with_logging';
// To log every SOAP call in huge detail. Lots are writted to moodledata/temp.

/**
 * Wraps the SOAP connection to the question engine, exposing the methods used
 * when processing question attempts.
 *
 * @copyright 2011 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_opaque_connection extends qtype_opaque_connection {

    /**
     * @param string $secret the secret string for this question engine.
     * @param int $userid the id of the user attempting this question.
     * @return string the passkey that needs to be sent to the quetion engine to
     *      show that we are allowed to start a question session for this user.
     */
    protected function generate_passkey($userid) {
        return md5($this->passkeysalt . $userid);
    }

    /**
     * @param string $remoteid identifies the question.
     * @param string $remoteversion identifies the specific version of the quetsion.
     * @param aray $data feeds into the initialParams.
     * @param question_display_options|null $options controls how the question is displayed.
     * @return object and Opaque StartReturn structure.
     */
    public function start($remoteid, $remoteversion, $data, $cachedresources, $options = null) {

        $initialparams = array(
            'randomseed' => $data['-_randomseed'],
            'userid' => $data['-_userid'],
            'language' => $data['-_language'],
            'passKey' => $this->generate_passkey($data['-_userid']),
            'preferredbehaviour' => $data['-_preferredbehaviour'],
        );

        if (!is_null($options)) {
            $initialparams['display_readonly'] = (int) $options->readonly;
            $initialparams['display_marks'] = (int) $options->marks;
            $initialparams['display_markdp'] = (int) $options->markdp;
            $initialparams['display_correctness'] = (int) $options->correctness;
            $initialparams['display_feedback'] = (int) $options->feedback;
            $initialparams['display_generalfeedback'] = (int) $options->generalfeedback;
        }

        return $this->soapclient->start($remoteid, $remoteversion, $this->question_base_url(),
                array_keys($initialparams), array_values($initialparams), $cachedresources);
    }

    /**
     * @param string $questionsessionid the question session.
     * @param array $respones the post date to process.
     */
    public function process($questionsessionid, $response) {
        return $this->soapclient->process($questionsessionid,
                array_keys($response), array_values($response));
    }

    /**
     * @param string $questionsessionid the question session to stop.
     */
    public function stop($questionsessionid) {
        $this->soapclient->stop($questionsessionid);
    }
}
