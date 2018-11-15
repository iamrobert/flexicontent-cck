<?php
/**
 * @version 1.5 stable $Id: router.php 1927 2014-06-27 10:40:12Z enjoyman@gmail.com $
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

defined( '_JEXEC' ) or die( 'Restricted access' );

/**
 * Create the segments of the SEF URL (segments are SEF URL parts seperated by '/')
 * $query variables that will not be unset will be automatically inserted into the URL
 * as /index/value/
 */
function FLEXIcontentBuildRoute(&$query)
{
	$segments = array();

	// If both 'start' and 'limitstart' variables are set then prefer 'limitstart' and also remove 'start' variable from URL
	if (isset($query['start']) && isset($query['limitstart']))
	{
		$query['start'] = $query['limitstart'];
		unset($query['start']);
	}

	$app = JFactory::getApplication();
	$params = JComponentHelper::getParams('com_flexicontent');
	$add_item_sef_segment = $params->get('add_item_sef_segment', 1);

	// 1. Get a menu item based on Itemid or currently active
	$menus = $app->getMenu();
	if (empty($query['Itemid']))
	{
		//  USE current Active ID it is now handled in route.php and also add a global config option whether to enable this
		//$menu = $menus->getActive();
		//$query['Itemid'] = $menu ? $menu->id : 0;
	}
	else
	{
		$menu = $menus->getItem($query['Itemid']);
	}


	// 2. Try to match the variables against the variables of the menu item
	if (!empty($menu))
	{
		$menuItem_matches = true;
		foreach($query as $index => $value)
		{
			// Skip URL query variable 'Itemid', since it does not exist PLUS we retrieve the menu item with the given Itemid
			if ($index === 'Itemid') continue;

			// Allow alternative display formats to match a given item
			if ($index === 'format') continue;
			if ($index === 'type') continue;

			// id and cid query variables can be contain 'slug', so we need to typecast them into integer
			$value = in_array($index, array('id', 'cid')) ? (int) $query[$index] : $query[$index];

			// Compare current query variable against the menu query variables
			if ($value != (isset($menu->query[$index]) ? $menu->query[$index] : null))
			{
				$menuItem_matches = false;
				break;
			}
		}

		// If exact menu match then unset ALL $query array, only the menu item segments will appear
		if ($menuItem_matches)
		{
			foreach($query as $index => $value)
			{
				// Do not unset option, Itemid and format variables, as these are needed / handled by JRoute
				if ($index === 'option' || $index === 'Itemid' || $index === 'format') continue;

				// Other variables to unset only if they match
				if ($index === 'type' && $value != (isset($menu->query[$index]) ? $menu->query[$index] : null)) continue;

				unset($query[$index]);
			}
			return $segments;  // $segments is empty we will get variables from menuItem (it has them)
		}
	}


	// 3. Handle known 'task'(s) formulating segments of SEF URL appropriately
	if (isset($query['task']))
	{
		if ($query['task'] === 'download')
		{
			$segments[] = $query['task'];
			$segments[] = $query['id'];
			$segments[] = $query['cid'];
			$segments[] = $query['fid'];
			unset($query['task']);	// task
			unset($query['id']);	// file
			unset($query['cid']);	// content
			unset($query['fid']);	// field
		}

		elseif ($query['task'] === 'weblink')
		{
			$segments[] = $query['task'];
			$segments[] = $query['fid'];
			$segments[] = $query['cid'];
			$segments[] = $query['ord'];
			unset($query['task']);	// task
			unset($query['fid']);	// field
			unset($query['cid']);	// content
			unset($query['ord']);	// value order
		}

		return $segments;
	}


	// 4. Handle known views formulating segments of SEF URL appropriately
	$mview = isset($menu->query['view']) ? $menu->query['view'] : null;
	$view  = isset($query['view']) ? $query['view'] : null;

	switch ($view)
	{
	case 'item':
		$mcid = !isset($menu->query['cid']) ? null : (int) $menu->query['cid'];
		$cid  = !isset($query['cid']) ? null : (int) $query['cid'];
		$mid = !isset($menu->query['id']) ? null : (int) $menu->query['id'];
		$id  = !isset($query['id']) ? null : (int) $query['id'];

		if ($mview === 'category' && !empty($menu->query['layout']))
		{
			// Add 'item' segment if using a category view menu item with a non-empty 'layout', needed since segment length has different meaning for such items
			$segments[] = 'item';
			$segments[] = isset($query['id']) ? $query['id'] : null;
		}

		elseif ($mview === 'item' && $mid === $id)
		{
			// Add no segments, even if CIDs of menu item is not an exact match
		}

		elseif ($mview !== 'category')
		{
			// Add 'item' segment if using non-category menu item, e.g. a 'flexicontent' (aka a directory) needs this
			$segments[] = 'item';
			$segments[] = @$query['id'];
		}
		
		// Menu item view is category
		else
		{
			/**
			 * We will make an item URL -with- CID ...
			 * cid EXISTs and cid/view URL variables to do not match those in the menu item
			 * IMPLY view = 'item' when count($segments) == 2
			 */
			if ($cid && $mcid !== $cid)
			{
				$segments[] = $query['cid'];
			}

			/**
			 * We will make an item URL -without- CID ...
			 * because cid is missing or matched cid/view URL variables matched those in menu item
			 */

			// EXPLICIT view ('item' be contained in the url), because according to configuration,
			// the 1-segment implies --category-- view so we need to add /item/ segment (1st segment is view)
			elseif ($add_item_sef_segment)
			{
				$segments[] = 'item';
			}

			// IMPLICIT view ('item' NOT contained in the url), because according to configuration,
			// the 1-segment implies --item-- view so we do not need to add /item/ segment (1st segment is view)
			else
			{
			}

			// ID is null for new item form, so check that this is set
			$segments[] = isset($query['id']) ? $query['id'] : null;
		}

		unset($query['view']);
		unset($query['cid']);
		unset($query['id']);
		break;

	case 'category':
		$mcid = !isset($menu->query['cid']) ? null : (int) $menu->query['cid'];
		$cid  = !isset($query['cid']) ? null : (int) $query['cid'];

		$mlayout = isset($menu->query['layout']) ? $menu->query['layout'] : null;
		$layout  = isset($query['layout']) ? $query['layout'] : null;
		$keep_view_layout = false;

		// Handle layout
		if ($layout)
		{
			switch ($layout)
			{
			case 'tags':
				$mtagid = isset($menu->query['tagid']) ? (int) $menu->query['tagid'] : null;
				$tagid  = isset($query['tagid']) ? (int) $query['tagid'] : null;

				if ($mview === $view && $mlayout === $layout)
				{
					if ($mtagid !== $tagid)
					{
						$segments[] = $query['tagid'];

						if ($cid && $mcid !== $cid)
						{
							$segments[] = $query['cid'];
						}

						unset($query['tagid']);
						unset($query['cid']);
					}
					else
					{
						if ($mtagid === $tagid)
						{
							unset($query['tagid']);
						}
						if ($mcid === $cid)
						{
							unset($query['cid']);
						}
					}
				}
				else
				{
					$segments[] = 'tagged';
					$segments[] = $query['tagid'];

					if ($cid && $mcid !== $cid)
					{
						$segments[] = $query['cid'];
					}

					unset($query['tagid']);
					unset($query['cid']);
				}
				break;

			case 'author':
				$mauthorid = isset($menu->query['authorid']) ? (int) $menu->query['authorid'] : null;
				$authorid  = isset($query['authorid']) ? (int) $query['authorid'] : null;

				if ($mview === $view && $mlayout === $layout)
				{
					if ($mauthorid !== $authorid)
					{
						$segments[] = $query['authorid'];
						if ($cid && $mcid !== $cid)
						{
							$segments[] = $query['cid'];
						}
						unset($query['authorid']);
						unset($query['cid']);
					}
					else
					{
						if ($mauthorid === $authorid)
						{
							unset($query['authorid']);
						}
						if ($mcid === $cid)
						{
							unset($query['cid']);
						}
					}
				}
				else
				{
					$segments[] = 'authored';
					$segments[] = $authorid;
					if ($cid && $mcid !== $cid)
					{
						$segments[] = $query['cid'];
					}
					unset($query['authorid']);
					unset($query['cid']);
				}
				break;

			case 'favs':
				if ($mview === $view && $mlayout === $layout)
				{
					if ($cid && $mcid !== $cid)
					{
						$segments[] = $query['cid'];
					}
					unset($query['cid']);
				}
				else
				{
					$segments[] = 'favoured';

					if ($cid && $mcid !== $cid)
					{
						$segments[] = $query['cid'];
					}

					unset($query['cid']);
				}
				break;

			case 'myitems':
				if ($mview === $view && $mlayout === $layout)
				{
					if ($cid && $mcid !== $cid)
					{
						$segments[] = $query['cid'];
					}

					unset($query['cid']);
				}

				else
				{
					$segments[] = 'myitems';

					if ($cid && $mcid !== $cid)
					{
						$segments[] = $query['cid'];
					}

					unset($query['cid']);
				}

				unset($query['authorid']);  // this should not be in the SEF URL even if it is set, as it is ignored (registered view effected by current user)
				break;

			case 'mcats':
				$mcids = isset($menu->query['cids']) ? $menu->query['cids'] : null;
				$cids  = isset($query['cids']) ? $query['cids'] : null;
				if (!$mcids) $mcids = array();
				if (!$cids)  $cids  = array();
				if ( !is_array($mcids) ) $mcids = explode(',', $mcids);
				if ( !is_array($cids) )  $cids  = explode(',', $cids);
				if ($mview==$view && $mlayout==$layout)
				{
					if (array_values($mcids) == array_values($cids))
					{
						unset($query['cids']);
					}
				}
				else
				{
					$segments[] = 'categories';
					$segments[] = is_array($cids) ? implode(',', $cids) : $cids;
					//if ($cid && $mcid!=$cid) $segments[] = $query['cid'];  // ignored
					unset($query['cids']);
				}
				//unset($query['cid']);
				break;

			default:  // Unhandled
				$keep_view_layout = true;
				break;
			}
		}

		// Handle adding category ID and view if not already handled above
		if (!$layout && !count($segments) && $cid && ($mview !== $view || $mcid !== $cid))
		{
			/**
			 * If adding explicit view /item/ is disabled for no-cid item URLs (thus they are 1-segment URLs),
			 * or the given menu item is not of category view, then ... we need to explicitly declare
			 * that this URL is a category URL, otherwise it will be wrongly interpreted as 'item' URL
			 */
			if (!$add_item_sef_segment && $mview === 'category')
			{
				$segments[] = 'category';
			}

			/**
			 * IMPLY view = 'category' when count($segments) == 1
			 * Check cid is set as it is optional, some category view layouts do not use category id
			 */
			if ($cid)
			{
				$segments[] = $query['cid'];
			}

			unset($query['cid']);
		}

		// 1. Unset 'layout' if not needed
		if (!$keep_view_layout)
		{
			unset($query['layout']);
		}

		// 2. Unset 'view' always, since this should be handled above in ALL cases
		unset($query['view']);

		// 3. Unset 'cid' if it matches the menu item, this may have be done above explicitely in every above case, otherwise it will remain in the URL
		if ($mcid === $cid)
		{
			unset($query['cid']);
		}
		break;

	case 'tags':
	case 'tag':  // legacy 'tags' view
		// EXPLICIT view (will be contained in the url)
		$segments[] = 'tag';
		$segments[] = isset($query['id']) ? $query['id'] : null;  // Required ...
		unset($query['view']);
		unset($query['id']);
		break;

	case 'flexicontent':    // (aka directory view)
		$mrootcat = !isset($menu->query['rootcat']) ? 1 : (int) $menu->query['rootcat'];
		$rootcat  = !isset($query['rootcat']) ? 1 : (int) $query['rootcat'];
		if ($mview !== $view || $mrootcat !== $rootcat)
		{
			// view/rootcat URL variables did not match add them as segments (but use 'catalog' as segment for view)
			$segments[] = 'catalog';
			$segments[] = isset($query['rootcat']) ? $query['rootcat'] : 1;
		}
		unset($query['view']);
		unset($query['rootcat']);
		break;

	case 'search':
	case 'favourites':    // legacy 'favourites' view
	case 'fileselement':
	default:
		// EXPLICIT view (will be contained in the url)
		if ($view)
		{
			$segments[] = $view;
		}
		unset($query['view']);

		// Set remaining variables "/variable/value/" pairs
		foreach ($query as $variable => $value)
		{
			if ( $variable!="option" || $variable!="Itemid") continue; // skip 'option' and 'Itemid' variables !
			$segments[] = $variable;
			$segments[] = $value;
			unset($query[$variable]);
		}
		/*if (isset($query['id'])) {
			$segments[] = 'id';
			$segments[] = $query['id'];
			unset($query['id']);
		}*/
		break;
	}

	// *******************************************************
	// We leave remaining $query variables untouched,
	// so that these variables will be appended to the SEF URL
	// *******************************************************

	return $segments;
}

/**
 * Construct a proper URL request from the SEF url, we try to reverse what FLEXIcontentBuildRoute() DID
 */
function FLEXIcontentParseRoute($segments)
{
	$vars = array();

	$params = JComponentHelper::getParams('com_flexicontent');
	$add_item_sef_segment = $params->get('add_item_sef_segment', 1);

	// Get the active menu item
	$menu = JFactory::getApplication()->getMenu()->getActive();

	// Count route segments
	$count = count($segments);


	/**
	 * 1. Cases that TASK is explicitly provided
	 */

	// 'download' task
	if ($segments[0] === 'download')
	{
		$vars['task'] = 'download';
		$vars['id']   = isset($segments[1]) ? $segments[1] : null;
		$vars['cid']  = isset($segments[2]) ? $segments[2] : null;
		$vars['fid']  = isset($segments[3]) ? $segments[3] : null;

		return $vars;
	}

	// 'weblink' task
	if ($segments[0] === 'weblink')
	{
		$vars['task'] = 'weblink';
		$vars['fid']  = isset($segments[1]) ? $segments[1] : null;
		$vars['cid']  = isset($segments[2]) ? $segments[2] : null;
		$vars['ord']  = isset($segments[3]) ? $segments[3] : null;

		return $vars;
	}


	/**
	 * 2. Cases that VIEW is explicitly provided
	 */

	// 'item' view
	if ($segments[0] === 'item' || $segments[0] === 'items')
	{
		$vars['view'] = 'item';

		// No cid provided
		if ($count === 2)
		{
			if (@$menu->query['view']=='category' && @$menu->query['cid'])
			{
				$vars['cid'] = (int)$menu->query['cid'];
			}
			$vars['id'] = $segments[1];
		}

		// Also cid provided
		elseif ($count === 3)
		{
			$vars['cid'] = $segments[1];
			$vars['id'] = $segments[2];
		}

		return $vars;
	}

	// 'category' view
	if ($segments[0] === 'category')
	{
		$vars['view'] = 'category';
		$vars['cid']  = isset($segments[1]) ? $segments[1] : null;  // it is optional, some category view layouts do not use category id

		return $vars;
	}

	// 'tags' view
	if ($segments[0] === 'tag' || $segments[0] === 'tags')
	{
		$vars['view'] = 'tags';
		$vars['id'] = $segments[1];

		return $vars;
	}

	// 'tags' via category view
	if ($segments[0] === 'tagged')
	{
		$vars['view'] = 'category';
		$vars['layout'] = 'tags';
		$vars['tagid'] = $segments[1];
		$vars['cid'] = isset($segments[2]) ? $segments[2] : null;  // it is optional

		return $vars;
	}

	// 'author' via category view
	if ($segments[0] === 'authored')
	{
		$vars['view'] = 'category';
		$vars['layout'] = 'author';
		$vars['authorid'] = $segments[1];
		$vars['cid'] = isset($segments[2]) ? $segments[2] : null;  // it is optional

		return $vars;
	}

	// 'favourites' via category view
	if ($segments[0] === 'favoured')
	{
		$vars['view'] = 'category';
		$vars['layout'] = 'favs';
		$vars['cid'] = isset($segments[1]) ? $segments[1] : null;  // it is optional

		return $vars;
	}

	// 'favourites' via category view
	if ($segments[0] === 'myitems')
	{
		$vars['view'] = 'category';
		$vars['layout'] = 'myitems';
		$vars['cid'] = isset($segments[1]) ? $segments[1] : null;  // it is optional

		return $vars;
	}

	// 'mcats' via category view
	if ($segments[0] === 'categories')
	{
		$vars['view'] = 'category';
		$vars['layout'] = 'mcats';
		$vars['cids'] = isset($segments[1]) ? $segments[1] : null;  // it is optional

		return $vars;
	}

	// 'flexicontent' view (aka directory view)
	if ($segments[0] === 'catalog')
	{
		$vars['view'] = 'flexicontent';
		$vars['rootcat'] = $segments[1];

		return $vars;
	}

	// VIEWs with no extra variables
	$view_only = array('favourites'=>1, 'fileselement'=>1, 'itemelement'=>1, 'search'=>1);

	if (isset($view_only[$segments[0]]))
	{
		$vars['view'] = $segments[0];

		return $vars;
	}


	/**
	 * 3. Cases that current menu item is a category-layout menu, do not use any segment 'length' rules (case 4)
	 */

	$mview   = isset($menu->query['view']) ? $menu->query['view'] : null;
	$mlayout = isset($menu->query['layout']) ? $menu->query['layout'] : null;

	if ($mview === 'category' && $mlayout)
	{
		switch ($mlayout)
		{
			case 'tags':
				$vars['view']   = 'category';
				$vars['layout'] = 'tags';
				$vars['tagid']  = $segments[0];
				$vars['cid']    = isset($segments[1]) ? $segments[1] : null;  // it is optional

				return $vars;

			case 'author':
				$vars['view']     = 'category';
				$vars['layout']   = 'author';
				$vars['authorid'] = $segments[0];
				$vars['cid']      = isset($segments[1]) ? $segments[1] : null;  // it is optional

				return $vars;

			case 'favs':
				$vars['view']   = 'category';
				$vars['layout'] = 'favs';
				$vars['cid']    = isset($segments[0]) ? $segments[0] : null;  // it is optional

				return $vars;

			case 'myitems':
				$vars['view']   = 'category';
				$vars['layout'] = 'myitems';
				$vars['cid']    = isset($segments[0]) ? $segments[0] : null;  // it is optional

				return $vars;
		}
	}


	/**
	 * 4. Cases that VIEW is not provided (instead it is implied by length of segments)
	 */

	// Count is integer aka safe to use swith to compare with zero ...
	switch ($count)
	{
		// SEGMENT LENGTH 0: is 'flexicontent' view (aka directory view)
		case 0:
			$vars['view'] = 'flexicontent';

			return $vars;

		/**
		 * SEGMENT LENGTH 1
		 *  Is 'category' view (default), or 'item' view depending on configuration
		 *  The 1 segment item URLs are possible only if (e.g.) menu item is a matching 'category' menu item,
		 *  in this case the category URLs become explicit with /category/ segment after the category menu item
		 *  so that they are not 1 segment of length ... BUT detect bad 1-segments URL (Segments must be integer prefixed)
		 */
		case 1:
			/**
			 * First check for bad URLs, THIS is needed for bad URLs with invalid MENU ALIAS segments,
			 * that are routed to FLEXIcontent because the PARENT menu is FLEXIcontent MENU ITEM
			 */
			$element_id_0 = (int) $segments[0];

			if (!$element_id_0)
			{
				// Force article error page
				$vars['view'] = 'item';
				$vars['cid']  = '0';
				$vars['id']   = '0';

				return $vars;
			}

			$is_item_view = !$add_item_sef_segment && in_array($mview, array('category'));

			if ($is_item_view)
			{
				$vars['view'] = 'item';
				$vars['cid']  = $menu->query['cid'];
				$vars['id']   = $segments[0];

				return $vars;
			}

			// Finally assume category view
			$vars['view'] = 'category';
			$vars['cid']  = $segments[0];

			return $vars;

		/**
		 * SEGMENT LENGTH 2
		 *  Is 'item' view, BUT detect bad 2-segments URL (Segments must be integer prefixed)
		 */
		case 2:
			/**
			 * Check for bad URLs, THIS is needed for bad URLs with invalid MENU ALIAS segments,
			 * that are routed to FLEXIcontent because the PARENT menu is FLEXIcontent MENU ITEM
			 */
			$element_id_0 = (int) $segments[0];
			$element_id_1 = (int) $segments[1];

			if (!$element_id_0 || !$element_id_1)
			{
				// Force article error page
				$vars['view'] = 'item';
				$vars['cid']  = '0';
				$vars['id']   = '0';

				return $vars;
			}

			$vars['view'] = 'item';
			$vars['cid'] 	= $segments[0];
			$vars['id'] 	= $segments[1];

			return $vars;


		/**
		 * SEGMENT LENGTH > 2
		 *  Explicit view at segment 0, and handle segments 1 and higher, as "/variable/value/" pairs
		 */
		default:
			static $flexi_views = null;

			/**
			 * 0. Find out component views that exist on disk
			 */
			if ($flexi_views === null)
			{
				$flexi_view_paths = glob(JPATH_SITE . DS . 'components' . DS . 'com_flexicontent' . DS . 'views' . DS . '[a-zA-Z_0-9]*', GLOB_ONLYDIR);

				foreach ($flexi_view_paths as $flexi_view_path)
				{
					$flexi_view = basename($flexi_view_path);

					if ($flexi_view !== '.' && $flexi_view !== '..')
					{
						$flexi_views[$flexi_view] = 1;
					}
				}
			}

			/**
			 * 1. First segment is an explicitely provided view
			 */
			$explicit_view = $segments[0];

			// Check view exists on disk and throw non found view error if not
			if (!isset($flexi_views[$explicit_view]))
			{
				// Make sure our language file has been loaded
				JFactory::getLanguage()->load('com_flexicontent', JPATH_SITE, 'en-GB', true);
				JFactory::getLanguage()->load('com_flexicontent', JPATH_SITE, null, true);

				throw new Exception(JText::sprintf('FLEXI_REQUESTED_CONTENT_OR_VIEW_NOT_FOUND', $explicit_view), 404);
			}

			$vars['view'] = $segments[0];

			/**
			 * 2. Consider remaining segments as "/variable/value/" pairs
			 */
			for ($i=1; $i < count($segments); $i = $i + 2)
			{
				$vars[$segments[$i]] = $segments[$i];
			}

			return $vars;
	}
}
