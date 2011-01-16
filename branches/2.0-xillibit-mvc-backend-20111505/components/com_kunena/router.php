<?php
/**
 * @version $Id$
 * Kunena Component
 * @package Kunena
 *
 * @Copyright (C) 2008 - 2010 Kunena Team All rights reserved
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.org
 *
 **/
defined( '_JEXEC' ) or die();

require_once JPATH_ADMINISTRATOR . '/components/com_kunena/api.php';

kimport('kunena.error');
kimport('kunena.forum.category.helper');
kimport('kunena.forum.topic.helper');

class KunenaRouter {
	static $catidcache = null;

	// List of reserved functions (if category name is one of these, use always catid)
	// Contains array of default variable=>value pairs, which can be removed from URI
	static $functions = array (
		'manage'=>array(),
		'who'=>array(),
		'announcement'=>array(),
		'poll'=>array(),
		'polls'=>array(),
		'stats'=>array(),
		'myprofile'=>array(),
		'userprofile'=>array(),
		'profile'=>array(),
		'moderateuser'=>array(),
		'userlist'=>array(),
		'post'=>array(),
		'view'=>array(),
		'help'=>array(),
		'showcat'=>array(),
		'listcat'=>array(),
		'review'=>array(),
		'rules'=>array(),
		'report'=>array(),
		'latest'=>array('do' => 'latest', 'page' => '1'),
		'search'=>array(),
		'advsearch'=>array(),
		'markthisread'=>array(),
		'subscribecat'=>array(),
		'unsubscribecat'=>array(),
		'karma'=>array(),
		'bulkactions'=>array(),
		'templatechooser'=>array(),
		'credits'=>array(),
		'json'=>array(),
		'rss'=>array(),
		'pdf'=>array(),
		'entrypage'=>array(),
		'thankyou'=>array(),
		// Deprecated functions:
		'fbprofile'=>array(),
		'mylatest'=>array(),
		'noreplies'=>array(),
		'subscriptions'=>array(),
		'favorites'=>array(),
		'userposts'=>array(),
		'unapproved'=>array(),
		'deleted'=>array(),
		'fb_pdf'=>array(),
		'article'=>array(),
	);

	function loadCategories() {
		if (self::$catidcache !== null)
			return; // Already loaded

		$categories = KunenaForumCategoryHelper::getCategories();
		self::$catidcache = array();
		foreach ($categories as $id=>$category) {
			self::$catidcache[$id] = self::stringURLSafe ( $category->name );
		}
	}

	function isCategoryConflict($catid, $catname) {
		$keys = array_keys(self::$catidcache, $catname);
		return count($keys) > 1;
	}

	function filterOutput($str) {
		return JString::trim ( preg_replace ( array ('/\s+/', '/[\$\&\+\,\/\:\;\=\?\@\'\"\<\>\#\%\{\}\|\\\^\~\[\]\`\.]/' ), array ('-', '' ), $str ) );
	}

	function stringURLSafe($str) {
		$kconfig =  KunenaFactory::getConfig ();
		if ($kconfig->sefutf8) {
			$str = self::filterOutput ( $str );
			return urlencode ( $str );
		}
		return JFilterOutput::stringURLSafe ( $str );
	}
	/**
	 * Build SEF URL
	 *
	 * All SEF URLs are formatted like this:
	 *
	 * http://site.com/menuitem/1-category-name/10-subject/[view]/[do]/[param1]-value1/[param2]-value2?param3=value3&param4=value4
	 *
	 * - If catid exists, category will always be in the first segment
	 * - If there is no catid, second segment for message will not be used (param-value: id-10)
	 * - [view] and [do] are the only parameters without value
	 * - all other segments (task, id, userid, page, sel) are using param-value format
	 *
	 * NOTE! Only major variables are using SEF segments
	 *
	 * @param $query
	 * @return segments
	 */
	function BuildRoute(&$query) {
		$parsevars = array ('task', 'id', 'userid', 'page', 'sel' );
		$segments = array ();

		$kconfig = KunenaFactory::getConfig ();
		if (! $kconfig->sef) {
			return $segments;
		}

		// Convert legacy functions to views
		if (isset ( $query ['func'] )) {
			$query ['view'] = $query ['func'];
		}
		unset ($query ['func']);

		if (isset ( $query ['Itemid'] ) && $query ['Itemid'] > 0) {
			// If we have Itemid, make sure that we remove identical parameters
			$app = JFactory::getApplication();
			$menu = $app->getMenu ();
			$menuitem = $menu->getItem ( $query ['Itemid'] );
			if ($menuitem) {
				// Remove variables with default values from URI
				if (!isset($query ['view'])) $defaults = array();
				else $defaults = self::$functions[$query ['view']];
				foreach ( $defaults as $var => $value ) {
					if (!isset ( $menuitem->query [$var] ) && isset ( $query [$var] ) && $value == $query [$var] ) {
						unset ( $query [$var] );
					}
				}
				// Remove variables that are the same as in menu item from URI
				foreach ( $menuitem->query as $var => $value ) {
					if ($var == 'Itemid' || $var == 'option')
						continue;
					if (isset ( $query [$var] ) && $value == $query [$var] ) {
						unset ( $query [$var] );
					}
				}
			}
		}

		$db = JFactory::getDBO ();
		jimport ( 'joomla.filter.output' );

		// We may have catid also in the menu item
		$catfound = isset ( $menuitem->query ['catid'] );
		// If we had identical catid in menuitem, this one will be skipped:
		if (isset ( $query ['catid'] )) {
			$catid = ( int ) $query ['catid'];
			if ($catid != 0) {
				$catfound = true;

				if (self::$catidcache === null)
					self::loadCategories ();
				if (isset ( self::$catidcache [$catid] )) {
					$suf = self::$catidcache [$catid];
				}
				if (empty ( $suf ))
					// If translated category name is empty, use catid: 123
					$segments [] = $query ['catid'];
				else if ($kconfig->sefcats && ! isset ( self::$functions[$suf] )) {
					// We want to remove catid: check that there are no conflicts between names
					if (self::isCategoryConflict($catid, $suf)) {
						$segments [] = $query ['catid'] . '-' . $suf;
					} else {
						$segments [] = $suf;
					}
				} else {
					// By default use 123-category_name
					$segments [] = $query ['catid'] . '-' . $suf;
				}
			}
			unset ( $query ['catid'] );
		}

		if ($catfound && isset ( $query ['id'] )) {
			$id = $query ['id'];
			$suf = self::stringURLSafe ( KunenaForumTopicHelper::get($id)->subject );
			if (empty ( $suf ))
				$segments [] = $query ['id'];
			else
				$segments [] = $query ['id'] . '-' . $suf;
			unset ( $query ['id'] );
		}

		if (isset ( $query ['view'] )) {
			if ($query ['view'] != 'showcat' && $query ['view'] != 'view' && ! ($query ['view'] == 'listcat' && $catfound)) {
				$segments [] = $query ['view'];
			}
			unset ( $query ['view'] );
		}

		if (isset ( $query ['do'] )) {
			$segments [] = $query ['do'];
			unset ( $query ['do'] );
		}

		foreach ( $parsevars as $var ) {
			if (isset ( $query [$var] )) {
				$segments [] = "{$var}-{$query[$var]}";
				unset ( $query [$var] );
			}
		}

		return $segments;
	}

	function ParseRoute($segments) {
		$funcitems = array (array ('view' => 'showcat', 'var' => 'catid' ), array ('view' => 'view', 'var' => 'id' ) );
		$doitems = array ('view', 'do' );
		$counter = 0;
		$funcpos = $dopos = 0;
		$vars = array ();

		$kconfig =  KunenaFactory::getConfig ();

		// Get current menu item
		$app = JFactory::getApplication();
		$menu = $app->getMenu ();
		$active = $menu->getActive ();

		// Fill data from the menu item
		$menuquery = isset ( $active->query ) ? $active->query : array ();
		foreach ( $menuquery as $var => $value ) {
			if ($var == 'func') {
				// Convert legacy functions to views
				$var = 'view';
			}
			$vars [$var] = $value;
		}
		if (isset ( $vars ['view']) && $vars ['view'] == 'entrypage') {
			unset ( $vars ['view'] );
		}

		// Fix bug in Joomla 1.5.20 when using /components/kunena instead /component/kunena
		if (!$active && $segments[0] == 'kunena') array_shift ( $segments );
		while ( ($segment = array_shift ( $segments )) !== null ) {
			$seg = explode ( ':', $segment );
			$var = array_shift ( $seg );
			$value = array_shift ( $seg );

			// If SEF categories are allowed we need to translate category name to catid
			if ($kconfig->sefcats && $counter == 0 && ! is_numeric ( $var ) && ($value !== null || ! isset ( self::$functions[$var] ))) {
				$catname = strtr ( $segment, ':', '-' );

				$categories = KunenaForumCategoryHelper::getCategories();
				foreach ( $categories as $category ) {
					if ($catname == self::filterOutput ( $category->name ) || $catname == JFilterOutput::stringURLSafe ( $category->name )) {
						$var = $category->id;
						break;
					}
				}
			}

			if (empty ( $var ))
				continue; // Empty parameter

			if (is_numeric ( $var )) {
				// Numeric value is always category or id (in this order)
				$value = $var;
				if (!isset($vars ['catid']) || $vars ['catid'] < 1) {
					$var = 'catid';
				} else if (!isset($vars ['id']) || $vars ['id'] < 1) {
					$var = 'id';
				} else {
					// Unknown parameter, skip it
					continue;
				}
			} else if ($value === null) {
				// Variable must be either view or do
				$value = $var;
				if (isset ( self::$functions[$var] )) {
					$var = 'view';
				} else if (isset($vars ['view']) && !isset($vars ['do'])) {
					$var = 'do';
				} else {
					// Unknown parameter: continue
					if (isset($vars ['view'])) continue;
					// Oops: unknown view or non-existing category
					$var = 'view';
				}
			}
			$vars [$var] = $value;
			$counter++;
		}

		if (isset($vars['catid']) && (!isset($vars ['view']) || $vars ['view'] == 'listcat')) {
			// If we have catid, view cannot be listcat
			$vars ['view'] = 'showcat';
		}
		if (isset($vars['id']) && isset ( $vars ['view'] ) && $vars ['view'] == 'showcat') {
			// If we have id, view cannot be showcat
			$vars ['view'] = 'view';
		}
		// Check if we should use listcat instead of showcat
		if (isset ( $vars ['view'] ) && $vars ['view'] == 'showcat') {
			if (empty ( $vars ['catid'] )) {
				$parent = 0;
			} else {
				$parent = KunenaForumCategoryHelper::get($vars ['catid'])->parent_id;
			}
			if (! $parent)
				$vars ['view'] = 'listcat';
		}
		return $vars;
	}

}

function KunenaBuildRoute(&$query) {
	return KunenaRouter::BuildRoute ( $query );
}

function KunenaParseRoute($segments) {
	return KunenaRouter::ParseRoute ( $segments );
}