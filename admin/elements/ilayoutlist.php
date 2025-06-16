<?php
/**
 * @version 1.5 stable $Id: ilayoutlist.php 967 2011-11-21 00:01:36Z ggppdk $
 * @package Joomla
 * @subpackage FLEXIcontent
 * @copyright (C) 2009 Emmanuel Danan - www.vistamedia.fr
 * @license GNU/GPL v2
 * 
 * FLEXIcontent is a derivative work of the excellent QuickFAQ component
 * @copyright (C) 2008 Christoph Lukes
 * see www.schlu.net for more information
 *
 * FLEXIcontent is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die('Restricted access');

// Load the helper classes
if (!defined('DS'))  define('DS',DIRECTORY_SEPARATOR);
require_once(JPATH_ROOT.DS.'components'.DS.'com_flexicontent'.DS.'classes'.DS.'flexicontent.helper.php');

jimport('cms.html.html');      // JHtml
jimport('cms.html.select');    // \Joomla\CMS\HTML\Helpers\Select

jimport('joomla.form.helper'); // \Joomla\CMS\Form\FormHelper
\Joomla\CMS\Form\FormHelper::loadFieldClass('list');   // \Joomla\CMS\Form\Field\ListField

/**
 * Renders an ilayoutlist element
 *
 * @package 	Joomla
 * @subpackage	FLEXIcontent
 * @since		1.0
 */
class JFormFieldIlayoutlist extends JFormFieldList
{
	/**
	 * The form field type.
	 *
	 * @var		string
	 * @since	1.6
	 */
	protected $type = 'Ilayoutlist';

	protected function getInput()
	{
		$node = & $this->element;
		$attributes = get_object_vars($node->attributes());
		$attributes = $attributes['@attributes'];
		
		$themes	= flexicontent_tmpl::getTemplates();
		$tmpls	= $themes->items ? $themes->items : array();
		
		$values = $this->value;
		if ( empty($values) ) {
			$values = array();
		}
		if ( !is_array($values) ) {
			$values = preg_split("/[\|,]/", $values);
		}
		
		$fieldname	= $this->name;
		$element_id = $this->id;

		// Field parameter (attributes)
		$attribs = '';
		
		$classes = 'use_select2_lib ';
		$classes .= @$attributes['required'] && @$attributes['required']!='false' ? ' required' : '';
		$classes .= @$attributes['class'] ? ' '.$attributes['class'] : '';
		$attribs = ' class="'.$classes.'" '.$attribs;
		
		$attribs .= ' style="float:left;" ';
		if (@$attributes['multiple']=='multiple' || @$attributes['multiple']=='true' ) {
			$attribs .= ' multiple="multiple" ';
			$attribs .= (@$attributes['size']) ? ' size="'.$attributes['size'].'" ' : ' size="6" ';
		} else {
			$attribs .= 'class="inputbox"';
		}
		
		if ($onchange = @$attributes['onchange']) {
			$attribs .= ' onchange="'.$onchange.'"';
		}
		
		// Field data (the templates)
		$lays = array();
		foreach ($tmpls as $tmpl) {
			$lays[] = $tmpl->name;
		}
		$lays = implode("','", $lays);
		
		if ($tmpls !== false) {
			foreach ($tmpls as $tmpl) {
				$layouts[] = JHTMLSelect::option($tmpl->name, $tmpl->name);
			}
		}
		
		return \Joomla\CMS\HTML\HTMLHelper::_('select.genericlist', $layouts, $fieldname, $attribs, 'value', 'text', $values, $element_id);
	}
}
?>
