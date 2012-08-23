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
 * Defines functions that are used to apply historic hacks
 *
 * @package    qbehaviour
 * @subpackage opaque
 * @copyright  2006 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

/**
 * Hacks used to clean-up the HTML.
 * @param string $xhtml the HTML that came from the question engine.
 * @param object $opaquestate the current Opaque state.
 * @return string the cleaned-up HTML.
 */
function qbehaviour_opaque_hacks_filter_xhtml($xhtml, $opaquestate) {
    // TODO this is a nasty hack. Flash uses & as a separator in the FlashVars string,
    // so we have to replce the &amp;s with %26s in this one place only. So for now
    // do it with a regexp. Longer term, it might be better to changes the file.php urls
    // so they don't contain &s.
    $xhtml = preg_replace_callback(
            '/name="FlashVars" value="TheSound=[^"]+"/',
            create_function('$matches', 'return str_replace("&amp;", "%26", $matches[0]);'),
            $xhtml);

    // Another hack to take out the next button that most OM questions include,
    // but which does not work in Moodle. Actually, we remove any non-disabled
    // button, with an id containing _omact_ and the following script tag.
    if ($opaquestate->resultssequencenumber >= 0 || $opaquestate->questionended) {
        $xhtml = preg_replace(
                '|<input(?:(?!disabled=)[^>])*? id="[^"]*%%omact_[^"]*"(?:(?!disabled=)[^>])*?>' .
                '<script type="text/javascript">[^<]*</script>|', '', $xhtml);
    }

    // Process the links to TinyMCE that OpenMark now requires.
    if (strpos($xhtml, '%%TINYMCE%%') !== false) {
        global $CFG;
        require_once($CFG->libdir . '/editor/tinymce/lib.php');

        $tinymce = new tinymce_texteditor();
        $tinymceurl = new moodle_url('/lib/editor/tinymce/tiny_mce/' . $tinymce->version . '/tiny_mce_src.js');
        $settingsurl = new moodle_url('/question/behaviour/opaque/tinymcesettings.php');

        $replaces = array(
            'src="%%TINYMCE%%/tiny_mce_src.js' => 'src="' . $tinymceurl->out(),
            'src="%%TINYMCE%%/tiny_mce_settings.js' => 'src="' . $settingsurl->out(),
        );

        $xhtml = str_replace(array_keys($replaces), array_values($replaces), $xhtml);
    }

    return $xhtml;
}

/**
 * OpenMark relies on certain browser-specific class names to be present in the
 * HTML outside the question, in order to apply certian browser-specific layout
 * work-arounds. This function re-implements Om's browser sniffing rules. See
 * http://java.net/projects/openmark/sources/svn/content/trunk/src/util/misc/UserAgent.java
 * @return string class to add to the HTML.
 */
function qbehaviour_opaque_legacy_browser_type() {
    if (!array_key_exists('HTTP_USER_AGENT', $_SERVER)) {
        return 'unknown';
    }
    $useragent = $_SERVER['HTTP_USER_AGENT'];

    // Filter troublemakers
    if (strpos($useragent, 'KHTML') !== false) {
        return "khtml";
    }
    if (strpos($useragent, 'Opera') !== false) {
        return "opera";
    }

    // Check version of our two supported browsers
    $matches = array();
    if (preg_match('/"^.*rv:(\d+)\\.(\d+)\D.*$"/', $useragent, $matches)) {
        return 'gecko-' . $matches[1] . '-' . $matches[2];
    }
    if (preg_match('/^.*MSIE (\d+)\\.(\d+)\D.*Windows.*$/', $useragent, $matches)) {
        return 'winie-' . $matches[1]; // Major verison only
    }

    return '';
}


/**
 * Hacks used to clean-up bits of the response.
 * @param object $response the response.
 * @return the updated response.
 */
function qbehaviour_opaque_hacks_filter_response($response, $opaquestate) {
    $response->XHTML = qbehaviour_opaque_hacks_filter_xhtml($response->XHTML, $opaquestate);

    // Process the resources.
    // TODO remove this. Evil hack. IE cannot cope with : and other odd characters
    // in the name argument to window.open. Until we can deploy a fix to the
    // OpenMark servers, apply the fix to the JS code here.
    if (isset($response->resources)) {
        foreach ($response->resources as $key => $resource) {
            if ($resource->filename == 'script.js') {
                $response->resources[$key]->content = preg_replace(
                        '/(?<=' . preg_quote('window.open("", idprefix') . '|' .
                                preg_quote('window.open("",idprefix') . ')\+(?=\"\w+\"\+id,)/',
                        '.replace(/\W/g,"_")+', $resource->content);
            }
        }
    }

    // Another nasty hack pending a permanent fix to OpenMark.
    if (!empty($response->progressInfo)) {
        $response->progressInfo = str_replace(
                array('attempts', 'attempt'),
                array('tries', 'try'),
                $response->progressInfo);
    }

    return $response;
}

/**
 * Wrapper round $step->get_submitted_data() to work around an incompatibility
 * between OpenMark and the Moodle question engine.
 * @param question_attempt_step $step a step.
 * @return array approximately $step->get_submitted_data().
 */
function qbehaviour_opaque_fix_up_submitted_data(array $response, question_attempt_step $step) {
    // By default, OpenMark radio buttons get the name '_rg', whcih breaks
    // one of the assumptions of the qutesion engine, so we have to manually
    // include it when doing get_submitted_data.
    if ($step->has_qt_var('_rg')) {
        $response['_rg'] = $step->get_qt_var('_rg');
    }
    return $response;
}

/**
 * @param array $response response data.
 * @return bool whether this submission contains an Om button click.
 */
function qbehaviour_opaque_response_contains_om_action(array $response) {
    foreach ($response as $key => $ignored) {
        if (strpos($key, 'omact_') === 0) {
            return true;
        }
    }
}
