<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Editors-xtd.fccat
 *
 * @copyright   Copyright (C) 2005 - 2020 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

/**
 * Editor Article buton
 *
 * @since  1.5
 */
class PlgButtonFccat extends \Joomla\CMS\Plugin\CMSPlugin
{
	/**
	 * Load the language file on instantiation.
	 *
	 * @var    boolean
	 * @since  3.1
	 */
	protected $autoloadLanguage = true;

	/**
	 * Display the button
	 *
	 * @param   string  $name  The name of the button to add
	 *
	 * @return  \Joomla\CMS\Object\CMSObject  The button options as \Joomla\CMS\Object\CMSObject
	 *
	 * @since   1.5
	 */
	public function onDisplay($name)
	{
		/**
		 * Our elements view already filters records according to user's view access levels
		 */

		$link = 'index.php?option=com_flexicontent&amp;view=fccategoryelement&amp;layout=default&amp;isxtdbtn=1&amp;tmpl=component&amp;'
			. \Joomla\CMS\Session\Session::getFormToken() . '=1&amp;editor=' . $name;

		$button = new \Joomla\CMS\Object\CMSObject;
		$button->modal   = true;
		$button->icon    = 'add-category';
		/*$button->iconSVG = '<svg version="1.1" id="Capa_1" x="0px" y="0px" viewBox="0 0 490 490" style="enable-background:new 0 0 490 490;" xml:space="preserve">
			<g>
				<g>
					<rect x="0.1" width="489.9" height="87.9"/>
					<path d="M0,112.4V490h490V112.4H0z M116.7,429.3H60.3v-64.9h56.4C116.7,364.4,116.7,429.3,116.7,429.3z M116.7,327.4H60.3v-64.9
						h56.4C116.7,262.5,116.7,327.4,116.7,327.4z M116.7,225.9H60.3v-65.3h56.4C116.7,160.6,116.7,225.9,116.7,225.9z M429.7,401.3
						h-245c-7,0-12.4-5.4-12.4-12.4s5.4-12.4,12.4-12.4h244.9c7,0,12.4,5.4,12.4,12.4S436.7,401.3,429.7,401.3z M429.7,307.6h-245
						c-7,0-12.4-5.4-12.4-12.4s5.4-12.4,12.4-12.4h244.9c7,0,12.4,5.4,12.4,12.4S436.7,307.6,429.7,307.6z M429.7,213.5h-245
						c-7,0-12.4-5.4-12.4-12.4s5.4-12.4,12.4-12.4h244.9c7,0,12.4,5.4,12.4,12.4S436.7,213.5,429.7,213.5z"/>
				</g>
			</g>
		</svg>';*/
		/*$button->iconSVG = '
			<svg version="1.1" id="Capa_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px"
				 viewBox="0 0 297.114 297.114" style="enable-background:new 0 0 297.114 297.114;" xml:space="preserve">
				<g>
					<path d="M247.869,56.499L193.586,2.197C192.179,0.791,190.271,0,188.282,0H54.549c-4.143,0-7.5,3.357-7.5,7.5v282.114
						c0,4.143,3.357,7.5,7.5,7.5h188.016c4.143,0,7.5-3.357,7.5-7.5V61.802C250.065,59.813,249.275,57.906,247.869,56.499z
						 M224.462,54.302h-28.681v-28.69L224.462,54.302z M62.049,282.114V15h118.732v46.802c0,4.143,3.357,7.5,7.5,7.5h46.783v212.813
						H62.049z"/>
					<path d="M211.228,94.039h-78.34c-4.143,0-7.5,3.357-7.5,7.5s3.357,7.5,7.5,7.5h78.34c4.143,0,7.5-3.357,7.5-7.5
						S215.371,94.039,211.228,94.039z"/>
					<path d="M101.553,94.039h-8.167v-8.173c0-4.143-3.357-7.5-7.5-7.5s-7.5,3.357-7.5,7.5v15.673c0,4.143,3.357,7.5,7.5,7.5h15.667
						c4.143,0,7.5-3.357,7.5-7.5S105.696,94.039,101.553,94.039z"/>
					<path d="M211.228,141.057h-78.34c-4.143,0-7.5,3.357-7.5,7.5c0,4.143,3.357,7.5,7.5,7.5h78.34c4.143,0,7.5-3.357,7.5-7.5
						C218.728,144.414,215.371,141.057,211.228,141.057z"/>
					<path d="M101.553,141.057h-8.167v-8.172c0-4.143-3.357-7.5-7.5-7.5s-7.5,3.357-7.5,7.5v15.672c0,4.143,3.357,7.5,7.5,7.5h15.667
						c4.143,0,7.5-3.357,7.5-7.5C109.053,144.414,105.696,141.057,101.553,141.057z"/>
					<path d="M211.228,188.075h-78.34c-4.143,0-7.5,3.357-7.5,7.5c0,4.143,3.357,7.5,7.5,7.5h78.34c4.143,0,7.5-3.357,7.5-7.5
						C218.728,191.433,215.371,188.075,211.228,188.075z"/>
					<path d="M101.553,188.075h-8.167v-8.172c0-4.143-3.357-7.5-7.5-7.5s-7.5,3.357-7.5,7.5v15.672c0,4.143,3.357,7.5,7.5,7.5h15.667
						c4.143,0,7.5-3.357,7.5-7.5C109.053,191.433,105.696,188.075,101.553,188.075z"/>
					<path d="M211.228,235.094h-78.34c-4.143,0-7.5,3.357-7.5,7.5c0,4.143,3.357,7.5,7.5,7.5h78.34c4.143,0,7.5-3.357,7.5-7.5
						C218.728,238.451,215.371,235.094,211.228,235.094z"/>
					<path d="M101.553,235.094h-8.167v-8.173c0-4.143-3.357-7.5-7.5-7.5s-7.5,3.357-7.5,7.5v15.673c0,4.143,3.357,7.5,7.5,7.5h15.667
						c4.143,0,7.5-3.357,7.5-7.5C109.053,238.451,105.696,235.094,101.553,235.094z"/>
				</g>
			</svg>';*/
		$button->iconSVG = '
		<svg version="1.1" id="Capa_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px"
			 viewBox="0 0 489 489" style="enable-background:new 0 0 489 489;" xml:space="preserve">
			<g>
				<g>
					<path d="M166.55,0H20.85C9.45,0,0.05,9.4,0.05,20.8v146.7c0,11.4,9.4,20.8,20.8,20.8h145.7c11.4,0,19.8-9.4,20.8-19.8V20.8
						C187.35,9.4,177.95,0,166.55,0z M145.75,147.7H40.65V40.6h105.1V147.7z"/>
					<path d="M273.65,61.4h194.5c11.4,0,20.8-9.4,20.8-20.8s-9.4-20.8-20.8-20.8h-194.5c-11.4,0-20.8,9.4-20.8,20.8
						C252.85,52,262.25,61.4,273.65,61.4z"/>
					<path d="M468.25,125.9h-194.6c-11.4,0-20.8,9.4-20.8,20.8c0,11.4,9.4,20.8,20.8,20.8h194.5c11.4,0,20.8-9.4,20.8-20.8
						C489.05,135.3,479.65,125.9,468.25,125.9z"/>
					<path d="M166.55,300.7H20.85c-11.4,0-20.8,9.4-20.8,20.8v146.7c0,11.4,9.4,20.8,20.8,20.8h145.7c11.4,0,19.8-9.4,20.8-19.8V321.5
						C187.35,310,177.95,300.7,166.55,300.7z M145.75,448.4H40.65V341.3h105.1V448.4z"/>
					<path d="M468.25,320.4h-194.6c-11.4,0-20.8,9.4-20.8,20.8s9.4,20.8,20.8,20.8h194.5c11.4,0,20.8-9.4,20.8-20.8
						C489.05,329.8,479.65,320.4,468.25,320.4z"/>
					<path d="M468.25,426.6h-194.6c-11.4,0-20.8,9.4-20.8,20.8s9.4,20.8,20.8,20.8h194.5c11.4,0,20.8-9.4,20.8-20.8
						C489.05,435.9,479.65,426.6,468.25,426.6z"/>
				</g>
			</g>
		</svg>';

		$button->class   = 'btn';
		$button->link    = $link;
		$button->text    = \Joomla\CMS\Language\Text::_('PLG_EDITORS-XTD_FCCAT_BUTTON_FCCAT');
		$button->name    = FLEXI_J40GE ? 'fccat' : 'list';
		$button->options = "{handler: 'iframe', size: {x: 800, y: 500}}";

		return $button;
	}
}
