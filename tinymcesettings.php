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
 * Hack to display TinyMCE the way OpenMark wants.
 *
 * @package    qbehaviour
 * @subpackage opaque
 * @copyright  2012 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


define('NO_DEBUG_DISPLAY', true);
define('ABORT_AFTER_CONFIG', true);
require(dirname(__FILE__) . '/../../../config.php');


$height = min_optional_param('h', 100, 'INT');
$width = min_optional_param('w', 100, 'INT');
$type = min_optional_param('t', 'sup,sub', 'RAW');
$elements = min_optional_param('e', 60, 'RAW');
$isenabled = min_optional_param('ro', 60, 'RAW');
$editorselector = min_optional_param('es', 60, 'SAFEDIR');

if ($isenabled === 'true') {
    $readonly = 'readonly: false';
} else {
    $readonly = 'readonly: true';
}

$elements = json_encode($elements);

switch ($type) {
    case 'sup':
        $validelements = '-sup';
        break;

    case 'sub':
        $validelements = '-sub';
        break;

    case 'sup,sub':
        $validelements = '-sup,-sub';

    default:
        throw new coding_exception('Unknow editor type ' . $type);
}

?>
tinymce.PluginManager.load('supsub', '<?php echo $CFG->wwwroot; ?>/lib/editor/supsub/supsub_plugin.js');

tinyMCE.init({
    // General options
    apply_source_formatting: true,
    content_css: "<?php echo $CFG->wwwroot; ?>/lib/editor/supsub/extra.css,<?php echo $CFG->wwwroot; ?>/question/behaviour/opaque/tinymce.css",
    directionality: "ltr",
    document_base_url: "<?php echo $CFG->wwwroot . '/'; ?>",
    editor_selector: "<?php echo $editorselector; ?>",
    elements: <?php echo $elements; ?>,
    entity_encoding: "raw",
    forced_root_block: false,
    force_br_newlines: true,
    force_p_newlines: false,
    height: <?php echo $height; ?>,
    language: "en",
    mode: "textareas",
    nowrap: true,
    plugins: "-supsub",
    <?php echo $readonly; ?>,
    relative_urls: false,
    remove_script_host: false,
    skin: "o2k7",
    skin_variant: "silver",
    theme:  "advanced",
    theme_advanced_layout_manager: "simple",
    theme_advanced_toolbar_align: "left",
    theme_advanced_buttons1: "<?php echo $type; ?>",
    theme_advanced_buttons2: "",
    theme_advanced_buttons3: "",
    theme_advanced_resize_horizontal: true,
    theme_advanced_resizing:  true,
    theme_advanced_resizing_min_height: 30,
    theme_advanced_toolbar_location: "top",
    theme_advanced_statusbar_location: "none",
    valid_elements: "<?php echo $validelements; ?>",
    valid_children: "body[sup|sub|#text],sup[#text],sub[#text]",
    width: <?php echo $width; ?>
});
