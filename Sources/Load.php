<?php

/**
 * This file has the hefty job of loading information for the forum.
 *
 * Simple Machines Forum (SMF)
 *
 * @package SMF
 * @author Simple Machines https://www.simplemachines.org
 * @copyright 2023 Simple Machines and individual contributors
 * @license https://www.simplemachines.org/about/smf/license.php BSD
 *
 * @version 3.0 Alpha 1
 */

use SMF\BBCodeParser;
use SMF\Board;
use SMF\BrowserDetector;
use SMF\Config;
use SMF\Lang;
use SMF\User;
use SMF\Utils;
use SMF\Cache\CacheApi;
use SMF\Db\DatabaseApi as Db;

if (!defined('SMF'))
	die('No direct access...');

/**
 * Load a theme, by ID.
 *
 * @param int $id_theme The ID of the theme to load
 * @param bool $initialize Whether or not to initialize a bunch of theme-related variables/settings
 */
function loadTheme($id_theme = 0, $initialize = true)
{
	global $settings, $options;

	if (empty($id_theme))
	{
		// The theme was specified by the board.
		if (!empty(Board::$info->theme))
			$id_theme = Board::$info->theme;
		// The theme is the forum's default.
		else
			$id_theme = Config::$modSettings['theme_guests'];

		// Sometimes the user can choose their own theme.
		if (!empty(Config::$modSettings['theme_allow']) || allowedTo('admin_forum'))
		{
			// The theme was specified by REQUEST.
			if (!empty($_REQUEST['theme']) && (allowedTo('admin_forum') || in_array($_REQUEST['theme'], explode(',', Config::$modSettings['knownThemes']))))
			{
				$id_theme = (int) $_REQUEST['theme'];
				$_SESSION['id_theme'] = $id_theme;
			}
			// The theme was specified by REQUEST... previously.
			elseif (!empty($_SESSION['id_theme']))
				$id_theme = (int) $_SESSION['id_theme'];
			// The theme is just the user's choice. (might use ?board=1;theme=0 to force board theme.)
			elseif (!empty(User::$me->theme))
				$id_theme = User::$me->theme;
		}

		// Verify the id_theme... no foul play.
		// Always allow the board specific theme, if they are overriding.
		if (!empty(Board::$info->theme) && Board::$info->override_theme)
			$id_theme = Board::$info->theme;
		elseif (!empty(Config::$modSettings['enableThemes']))
		{
			$themes = explode(',', Config::$modSettings['enableThemes']);
			if (!in_array($id_theme, $themes))
				$id_theme = Config::$modSettings['theme_guests'];
			else
				$id_theme = (int) $id_theme;
		}
	}

	// Allow mod authors the option to override the theme id for custom page themes
	call_integration_hook('integrate_pre_load_theme', array(&$id_theme));

	// We already load the basic stuff?
	if (empty($settings['theme_id']) || $settings['theme_id'] != $id_theme)
	{
		$member = empty(User::$me->id) ? -1 : User::$me->id;

		if (!empty(CacheApi::$enable) && CacheApi::$enable >= 2 && ($temp = CacheApi::get('theme_settings-' . $id_theme . ':' . $member, 60)) != null && time() - 60 > Config::$modSettings['settings_updated'])
		{
			$themeData = $temp;
			$flag = true;
		}
		elseif (($temp = CacheApi::get('theme_settings-' . $id_theme, 90)) != null && time() - 60 > Config::$modSettings['settings_updated'])
			$themeData = $temp + array($member => array());
		else
			$themeData = array(-1 => array(), 0 => array(), $member => array());

		if (empty($flag))
		{
			// Load variables from the current or default theme, global or this user's.
			$result = Db::$db->query('', '
				SELECT variable, value, id_member, id_theme
				FROM {db_prefix}themes
				WHERE id_member' . (empty($themeData[0]) ? ' IN (-1, 0, {int:id_member})' : ' = {int:id_member}') . '
					AND id_theme' . ($id_theme == 1 ? ' = {int:id_theme}' : ' IN ({int:id_theme}, 1)') . '
				ORDER BY id_theme asc',
				array(
					'id_theme' => $id_theme,
					'id_member' => $member,
				)
			);
			// Pick between $settings and $options depending on whose data it is.
			foreach (Db::$db->fetch_all($result) as $row)
			{
				// There are just things we shouldn't be able to change as members.
				if ($row['id_member'] != 0 && in_array($row['variable'], array('actual_theme_url', 'actual_images_url', 'base_theme_dir', 'base_theme_url', 'default_images_url', 'default_theme_dir', 'default_theme_url', 'default_template', 'images_url', 'number_recent_posts', 'smiley_sets_default', 'theme_dir', 'theme_id', 'theme_layers', 'theme_templates', 'theme_url')))
					continue;

				// If this is the theme_dir of the default theme, store it.
				if (in_array($row['variable'], array('theme_dir', 'theme_url', 'images_url')) && $row['id_theme'] == '1' && empty($row['id_member']))
					$themeData[0]['default_' . $row['variable']] = $row['value'];

				// If this isn't set yet, is a theme option, or is not the default theme..
				if (!isset($themeData[$row['id_member']][$row['variable']]) || $row['id_theme'] != '1')
					$themeData[$row['id_member']][$row['variable']] = substr($row['variable'], 0, 5) == 'show_' ? $row['value'] == '1' : $row['value'];
			}
			Db::$db->free_result($result);

			if (!empty($themeData[-1]))
				foreach ($themeData[-1] as $k => $v)
				{
					if (!isset($themeData[$member][$k]))
						$themeData[$member][$k] = $v;
				}

			if (!empty(CacheApi::$enable) && CacheApi::$enable >= 2)
				CacheApi::put('theme_settings-' . $id_theme . ':' . $member, $themeData, 60);
			// Only if we didn't already load that part of the cache...
			elseif (!isset($temp))
				CacheApi::put('theme_settings-' . $id_theme, array(-1 => $themeData[-1], 0 => $themeData[0]), 90);
		}

		$settings = $themeData[0];
		$options = $themeData[$member];

		$settings['theme_id'] = $id_theme;

		$settings['actual_theme_url'] = $settings['theme_url'];
		$settings['actual_images_url'] = $settings['images_url'];
		$settings['actual_theme_dir'] = $settings['theme_dir'];

		$settings['template_dirs'] = array();
		// This theme first.
		$settings['template_dirs'][] = $settings['theme_dir'];

		// Based on theme (if there is one).
		if (!empty($settings['base_theme_dir']))
			$settings['template_dirs'][] = $settings['base_theme_dir'];

		// Lastly the default theme.
		if ($settings['theme_dir'] != $settings['default_theme_dir'])
			$settings['template_dirs'][] = $settings['default_theme_dir'];
	}

	if (!$initialize)
		return;

	// Perhaps we've changed the agreement or privacy policy? Only redirect if:
	// 1. They're not a guest or admin
	// 2. This isn't called from SSI
	// 3. This isn't an XML request
	// 4. They're not trying to do any of the following actions:
	// 4a. View or accept the agreement and/or policy
	// 4b. Login or logout
	// 4c. Get a feed (RSS, ATOM, etc.)
	$agreement_actions = array(
		'agreement' => true,
		'acceptagreement' => true,
		'login2' => true,
		'logintfa' => true,
		'logout' => true,
		'pm' => array('sa' => array('popup')),
		'profile' => array('area' => array('popup', 'alerts_popup')),
		'xmlhttp' => true,
		'.xml' => true,
	);
	if (!empty(User::$me->id) && empty(User::$me->is_admin) && SMF != 'SSI' && !isset($_REQUEST['xml']) && !is_filtered_request($agreement_actions, 'action'))
	{
		require_once(Config::$sourcedir . '/Agreement.php');
		$can_accept_agreement = !empty(Config::$modSettings['requireAgreement']) && canRequireAgreement();
		$can_accept_privacy_policy = !empty(Config::$modSettings['requirePolicyAgreement']) && canRequirePrivacyPolicy();

		if ($can_accept_agreement || $can_accept_privacy_policy)
			redirectexit('action=agreement');
	}

	// Check to see if we're forcing SSL
	if (!empty(Config::$modSettings['force_ssl']) && empty(Config::$maintenance) &&
		!httpsOn() && SMF != 'SSI')
	{
		if (isset($_GET['sslRedirect']))
		{
			Lang::load('Errors');
			fatal_lang_error('login_ssl_required', false);
		}

		redirectexit(strtr($_SERVER['REQUEST_URL'], array('http://' => 'https://')) . (strpos($_SERVER['REQUEST_URL'], '?') > 0 ? ';' : '?') . 'sslRedirect');
	}

	// Check to see if they're accessing it from the wrong place.
	if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['SERVER_NAME']))
	{
		$detected_url = httpsOn() ? 'https://' : 'http://';
		$detected_url .= empty($_SERVER['HTTP_HOST']) ? $_SERVER['SERVER_NAME'] . (empty($_SERVER['SERVER_PORT']) || $_SERVER['SERVER_PORT'] == '80' ? '' : ':' . $_SERVER['SERVER_PORT']) : $_SERVER['HTTP_HOST'];
		$temp = preg_replace('~/' . basename(Config::$scripturl) . '(/.+)?$~', '', strtr(dirname($_SERVER['PHP_SELF']), '\\', '/'));
		if ($temp != '/')
			$detected_url .= $temp;
	}
	if (isset($detected_url) && $detected_url != Config::$boardurl)
	{
		// Try #1 - check if it's in a list of alias addresses.
		if (!empty(Config::$modSettings['forum_alias_urls']))
		{
			$aliases = explode(',', Config::$modSettings['forum_alias_urls']);

			foreach ($aliases as $alias)
			{
				// Rip off all the boring parts, spaces, etc.
				if ($detected_url == trim($alias) || strtr($detected_url, array('http://' => '', 'https://' => '')) == trim($alias))
					$do_fix = true;
			}
		}

		// Hmm... check #2 - is it just different by a www?  Send them to the correct place!!
		if (empty($do_fix) && strtr($detected_url, array('://' => '://www.')) == Config::$boardurl && (empty($_GET) || count($_GET) == 1) && SMF != 'SSI')
		{
			// Okay, this seems weird, but we don't want an endless loop - this will make $_GET not empty ;).
			if (empty($_GET))
				redirectexit('wwwRedirect');
			else
			{
				$k = key($_GET);
				$v = current($_GET);

				if ($k != 'wwwRedirect')
					redirectexit('wwwRedirect;' . $k . '=' . $v);
			}
		}

		// #3 is just a check for SSL...
		if (strtr($detected_url, array('https://' => 'http://')) == Config::$boardurl)
			$do_fix = true;

		// Okay, #4 - perhaps it's an IP address?  We're gonna want to use that one, then. (assuming it's the IP or something...)
		if (!empty($do_fix) || preg_match('~^http[s]?://(?:[\d\.:]+|\[[\d:]+\](?::\d+)?)(?:$|/)~', $detected_url) == 1)
		{
			// Caching is good ;).
			$oldurl = Config::$boardurl;

			// Fix Config::$boardurl and Config::$scripturl.
			Config::$boardurl = $detected_url;
			Config::$scripturl = strtr(Config::$scripturl, array($oldurl => Config::$boardurl));
			$_SERVER['REQUEST_URL'] = strtr($_SERVER['REQUEST_URL'], array($oldurl => Config::$boardurl));

			// Fix the theme urls...
			$settings['theme_url'] = strtr($settings['theme_url'], array($oldurl => Config::$boardurl));
			$settings['default_theme_url'] = strtr($settings['default_theme_url'], array($oldurl => Config::$boardurl));
			$settings['actual_theme_url'] = strtr($settings['actual_theme_url'], array($oldurl => Config::$boardurl));
			$settings['images_url'] = strtr($settings['images_url'], array($oldurl => Config::$boardurl));
			$settings['default_images_url'] = strtr($settings['default_images_url'], array($oldurl => Config::$boardurl));
			$settings['actual_images_url'] = strtr($settings['actual_images_url'], array($oldurl => Config::$boardurl));

			// And just a few mod settings :).
			Config::$modSettings['smileys_url'] = strtr(Config::$modSettings['smileys_url'], array($oldurl => Config::$boardurl));
			Config::$modSettings['avatar_url'] = strtr(Config::$modSettings['avatar_url'], array($oldurl => Config::$boardurl));
			Config::$modSettings['custom_avatar_url'] = strtr(Config::$modSettings['custom_avatar_url'], array($oldurl => Config::$boardurl));

			// Clean up after Board::load().
			if (isset(Board::$info->moderators))
			{
				foreach (Board::$info->moderators as $k => $dummy)
				{
					Board::$info->moderators[$k]['href'] = strtr($dummy['href'], array($oldurl => Config::$boardurl));
					Board::$info->moderators[$k]['link'] = strtr($dummy['link'], array('"' . $oldurl => '"' . Config::$boardurl));
				}
			}
			foreach (Utils::$context['linktree'] as $k => $dummy)
				Utils::$context['linktree'][$k]['url'] = strtr($dummy['url'], array($oldurl => Config::$boardurl));
		}
	}

	// Create User::$me if it is missing (e.g., an error very early in the login process).
	if (!isset(User::$me))
		User::load();

	// Determine the current smiley set.
	$smiley_sets_known = explode(',', Config::$modSettings['smiley_sets_known']);
	User::$me->smiley_set = (!in_array(User::$me->smiley_set, $smiley_sets_known) && User::$me->smiley_set != 'none') || empty(Config::$modSettings['smiley_sets_enable']) ? (!empty($settings['smiley_sets_default']) ? $settings['smiley_sets_default'] : Config::$modSettings['smiley_sets_default']) : User::$me->smiley_set;

	// Some basic information...
	if (!isset(Utils::$context['html_headers']))
		Utils::$context['html_headers'] = '';
	if (!isset(Utils::$context['javascript_files']))
		Utils::$context['javascript_files'] = array();
	if (!isset(Utils::$context['css_files']))
		Utils::$context['css_files'] = array();
	if (!isset(Utils::$context['css_header']))
		Utils::$context['css_header'] = array();
	if (!isset(Utils::$context['javascript_inline']))
		Utils::$context['javascript_inline'] = array('standard' => array(), 'defer' => array());
	if (!isset(Utils::$context['javascript_vars']))
		Utils::$context['javascript_vars'] = array();

	Utils::$context['login_url'] = Config::$scripturl . '?action=login2';
	Utils::$context['menu_separator'] = !empty($settings['use_image_buttons']) ? ' ' : ' | ';
	Utils::$context['session_var'] = $_SESSION['session_var'];
	Utils::$context['session_id'] = $_SESSION['session_value'];
	Utils::$context['forum_name'] = Config::$mbname;
	Utils::$context['forum_name_html_safe'] = Utils::htmlspecialchars(Utils::$context['forum_name']);
	Utils::$context['header_logo_url_html_safe'] = empty($settings['header_logo_url']) ? '' : Utils::htmlspecialchars($settings['header_logo_url']);
	Utils::$context['current_action'] = isset($_REQUEST['action']) ? Utils::htmlspecialchars($_REQUEST['action']) : null;
	Utils::$context['current_subaction'] = isset($_REQUEST['sa']) ? $_REQUEST['sa'] : null;
	Utils::$context['can_register'] = empty(Config::$modSettings['registration_method']) || Config::$modSettings['registration_method'] != 3;
	if (isset(Config::$modSettings['load_average']))
		Utils::$context['load_average'] = Config::$modSettings['load_average'];

	// Detect the browser. This is separated out because it's also used in attachment downloads
	BrowserDetector::call();

	// Set the top level linktree up.
	// Note that if we're dealing with certain very early errors (e.g., login) the linktree might not be set yet...
	if (empty(Utils::$context['linktree']))
		Utils::$context['linktree'] = array();
	array_unshift(Utils::$context['linktree'], array(
		'url' => Config::$scripturl,
		'name' => Utils::$context['forum_name_html_safe']
	));

	// This allows sticking some HTML on the page output - useful for controls.
	Utils::$context['insert_after_template'] = '';

	$simpleActions = array(
		'findmember',
		'helpadmin',
		'printpage',
	);

	// Parent action => array of areas
	$simpleAreas = array(
		'profile' => array('popup', 'alerts_popup',),
	);

	// Parent action => array of subactions
	$simpleSubActions = array(
		'pm' => array('popup',),
		'signup' => array('usernamecheck'),
	);

	// Extra params like ;preview ;js, etc.
	$extraParams = array(
		'preview',
		'splitjs',
	);

	// Actions that specifically uses XML output.
	$xmlActions = array(
		'quotefast',
		'jsmodify',
		'xmlhttp',
		'post2',
		'suggest',
		'stats',
		'notifytopic',
		'notifyboard',
	);

	call_integration_hook('integrate_simple_actions', array(&$simpleActions, &$simpleAreas, &$simpleSubActions, &$extraParams, &$xmlActions));

	Utils::$context['simple_action'] = in_array(Utils::$context['current_action'], $simpleActions) ||
		(isset($simpleAreas[Utils::$context['current_action']]) && isset($_REQUEST['area']) && in_array($_REQUEST['area'], $simpleAreas[Utils::$context['current_action']])) ||
		(isset($simpleSubActions[Utils::$context['current_action']]) && in_array(Utils::$context['current_subaction'], $simpleSubActions[Utils::$context['current_action']]));

	// See if theres any extra param to check.
	$requiresXML = false;
	foreach ($extraParams as $key => $extra)
		if (isset($_REQUEST[$extra]))
			$requiresXML = true;

	// Output is fully XML, so no need for the index template.
	if (isset($_REQUEST['xml']) && (in_array(Utils::$context['current_action'], $xmlActions) || $requiresXML))
	{
		Lang::load('index+Modifications');
		loadTemplate('Xml');
		Utils::$context['template_layers'] = array();
	}

	// These actions don't require the index template at all.
	elseif (!empty(Utils::$context['simple_action']))
	{
		Lang::load('index+Modifications');
		Utils::$context['template_layers'] = array();
	}

	else
	{
		// Custom templates to load, or just default?
		if (isset($settings['theme_templates']))
			$templates = explode(',', $settings['theme_templates']);
		else
			$templates = array('index');

		// Load each template...
		foreach ($templates as $template)
			loadTemplate($template);

		// ...and attempt to load their associated language files.
		$required_files = implode('+', array_merge($templates, array('Modifications')));
		Lang::load($required_files, '', false);

		// Custom template layers?
		if (isset($settings['theme_layers']))
			Utils::$context['template_layers'] = explode(',', $settings['theme_layers']);
		else
			Utils::$context['template_layers'] = array('html', 'body');
	}

	// Initialize the theme.
	loadSubTemplate('init', 'ignore');

	// Allow overriding the board wide time/number formats.
	if (empty(User::$profiles[User::$me->id]['time_format']) && !empty(Lang::$txt['time_format']))
		User::$me->time_format = Lang::$txt['time_format'];

	// Set the character set from the template.
	Utils::$context['character_set'] = empty(Config::$modSettings['global_character_set']) ? Lang::$txt['lang_character_set'] : Config::$modSettings['global_character_set'];
	Utils::$context['right_to_left'] = !empty(Lang::$txt['lang_rtl']);

	// Guests may still need a name.
	if (User::$me->is_guest && empty(User::$me->name))
		User::$me->name = Lang::$txt['guest_title'];

	// Any theme-related strings that need to be loaded?
	if (!empty($settings['require_theme_strings']))
		Lang::load('ThemeStrings', '', false);

	// Make a special URL for the language.
	$settings['lang_images_url'] = $settings['images_url'] . '/' . (!empty(Lang::$txt['image_lang']) ? Lang::$txt['image_lang'] : User::$me->language);

	// And of course, let's load the default CSS file.
	loadCSSFile('index.css', array('minimize' => true, 'order_pos' => 1), 'smf_index');

	// Here is my luvly Responsive CSS
	loadCSSFile('responsive.css', array('force_current' => false, 'validate' => true, 'minimize' => true, 'order_pos' => 9000), 'smf_responsive');

	if (Utils::$context['right_to_left'])
		loadCSSFile('rtl.css', array('order_pos' => 4000), 'smf_rtl');

	// We allow theme variants, because we're cool.
	Utils::$context['theme_variant'] = '';
	Utils::$context['theme_variant_url'] = '';
	if (!empty($settings['theme_variants']))
	{
		// Overriding - for previews and that ilk.
		if (!empty($_REQUEST['variant']))
			$_SESSION['id_variant'] = $_REQUEST['variant'];
		// User selection?
		if (empty($settings['disable_user_variant']) || allowedTo('admin_forum'))
			Utils::$context['theme_variant'] = !empty($_SESSION['id_variant']) && in_array($_SESSION['id_variant'], $settings['theme_variants']) ? $_SESSION['id_variant'] : (!empty($options['theme_variant']) && in_array($options['theme_variant'], $settings['theme_variants']) ? $options['theme_variant'] : '');
		// If not a user variant, select the default.
		if (Utils::$context['theme_variant'] == '' || !in_array(Utils::$context['theme_variant'], $settings['theme_variants']))
			Utils::$context['theme_variant'] = !empty($settings['default_variant']) && in_array($settings['default_variant'], $settings['theme_variants']) ? $settings['default_variant'] : $settings['theme_variants'][0];

		// Do this to keep things easier in the templates.
		Utils::$context['theme_variant'] = '_' . Utils::$context['theme_variant'];
		Utils::$context['theme_variant_url'] = Utils::$context['theme_variant'] . '/';

		if (!empty(Utils::$context['theme_variant']))
		{
			loadCSSFile('index' . Utils::$context['theme_variant'] . '.css', array('order_pos' => 300), 'smf_index' . Utils::$context['theme_variant']);
			if (Utils::$context['right_to_left'])
				loadCSSFile('rtl' . Utils::$context['theme_variant'] . '.css', array('order_pos' => 4200), 'smf_rtl' . Utils::$context['theme_variant']);
		}
	}

	// Let's be compatible with old themes!
	if (!function_exists('template_html_above') && in_array('html', Utils::$context['template_layers']))
		Utils::$context['template_layers'] = array('main');

	Utils::$context['tabindex'] = 1;

	// Compatibility.
	if (!isset($settings['theme_version']))
		Config::$modSettings['memberCount'] = Config::$modSettings['totalMembers'];

	// Default JS variables for use in every theme
	Utils::$context['javascript_vars'] = array(
		'smf_theme_url' => '"' . $settings['theme_url'] . '"',
		'smf_default_theme_url' => '"' . $settings['default_theme_url'] . '"',
		'smf_images_url' => '"' . $settings['images_url'] . '"',
		'smf_smileys_url' => '"' . Config::$modSettings['smileys_url'] . '"',
		'smf_smiley_sets' => '"' . Config::$modSettings['smiley_sets_known'] . '"',
		'smf_smiley_sets_default' => '"' . Config::$modSettings['smiley_sets_default'] . '"',
		'smf_avatars_url' => '"' . Config::$modSettings['avatar_url'] . '"',
		'smf_scripturl' => '"' . Config::$scripturl . '"',
		'smf_iso_case_folding' => Utils::$context['server']['iso_case_folding'] ? 'true' : 'false',
		'smf_charset' => '"' . Utils::$context['character_set'] . '"',
		'smf_session_id' => '"' . Utils::$context['session_id'] . '"',
		'smf_session_var' => '"' . Utils::$context['session_var'] . '"',
		'smf_member_id' => User::$me->id,
		'ajax_notification_text' => JavaScriptEscape(Lang::$txt['ajax_in_progress']),
		'help_popup_heading_text' => JavaScriptEscape(Lang::$txt['help_popup']),
		'banned_text' => JavaScriptEscape(sprintf(Lang::$txt['your_ban'], User::$me->name)),
		'smf_txt_expand' => JavaScriptEscape(Lang::$txt['code_expand']),
		'smf_txt_shrink' => JavaScriptEscape(Lang::$txt['code_shrink']),
		'smf_collapseAlt' => JavaScriptEscape(Lang::$txt['hide']),
		'smf_expandAlt' => JavaScriptEscape(Lang::$txt['show']),
		'smf_quote_expand' => !empty(Config::$modSettings['quote_expand']) ? Config::$modSettings['quote_expand'] : 'false',
		'allow_xhjr_credentials' => !empty(Config::$modSettings['allow_cors_credentials']) ? 'true' : 'false',
	);

	// Add the JQuery library to the list of files to load.
	$jQueryUrls = array ('cdn' => 'https://ajax.googleapis.com/ajax/libs/jquery/'. JQUERY_VERSION . '/jquery.min.js', 'jquery_cdn' => 'https://code.jquery.com/jquery-'. JQUERY_VERSION . '.min.js', 'microsoft_cdn' => 'https://ajax.aspnetcdn.com/ajax/jQuery/jquery-'. JQUERY_VERSION . '.min.js');

	if (isset(Config::$modSettings['jquery_source']) && array_key_exists(Config::$modSettings['jquery_source'], $jQueryUrls))
		loadJavaScriptFile($jQueryUrls[Config::$modSettings['jquery_source']], array('external' => true, 'seed' => false), 'smf_jquery');

	elseif (isset(Config::$modSettings['jquery_source']) && Config::$modSettings['jquery_source'] == 'local')
		loadJavaScriptFile('jquery-' . JQUERY_VERSION . '.min.js', array('seed' => false), 'smf_jquery');

	elseif (isset(Config::$modSettings['jquery_source'], Config::$modSettings['jquery_custom']) && Config::$modSettings['jquery_source'] == 'custom')
		loadJavaScriptFile(Config::$modSettings['jquery_custom'], array('external' => true, 'seed' => false), 'smf_jquery');

	// Fall back to the forum default
	else
		loadJavaScriptFile('https://ajax.googleapis.com/ajax/libs/jquery/' . JQUERY_VERSION . '/jquery.min.js', array('external' => true, 'seed' => false), 'smf_jquery');

	// Queue our JQuery plugins!
	loadJavaScriptFile('smf_jquery_plugins.js', array('minimize' => true), 'smf_jquery_plugins');
	if (!User::$me->is_guest)
	{
		loadJavaScriptFile('jquery.custom-scrollbar.js', array('minimize' => true), 'smf_jquery_scrollbar');
		loadCSSFile('jquery.custom-scrollbar.css', array('force_current' => false, 'validate' => true), 'smf_scrollbar');
	}

	// script.js and theme.js, always required, so always add them! Makes index.template.php cleaner and all.
	loadJavaScriptFile('script.js', array('defer' => false, 'minimize' => true), 'smf_script');
	loadJavaScriptFile('theme.js', array('minimize' => true), 'smf_theme');

	// And we should probably trigger the cron too.
	if (empty(Config::$modSettings['cron_is_real_cron']))
	{
		$ts = time();
		$ts -= $ts % 15;
		addInlineJavaScript('
	function triggerCron()
	{
		$.get(' . JavaScriptEscape(Config::$boardurl) . ' + "/cron.php?ts=' . $ts . '");
	}
	window.setTimeout(triggerCron, 1);', true);

		// Robots won't normally trigger cron.php, so for them run the scheduled tasks directly.
		if (BrowserDetector::isBrowser('possibly_robot') && (empty($modSettings['next_task_time']) || $modSettings['next_task_time'] < time() || (!empty($modSettings['mail_next_send']) && $modSettings['mail_next_send'] < time() && empty($modSettings['mail_queue_use_cron']))))
		{
			require_once($sourcedir . '/ScheduledTasks.php');

			// What to do, what to do?!
			if (empty($modSettings['next_task_time']) || $modSettings['next_task_time'] < time())
				AutoTask();
			else
				ReduceMailQueue();
		}
	}

	// Filter out the restricted boards from the linktree
	if (!User::$me->is_admin && !empty(Board::$info->id))
	{
		foreach (Utils::$context['linktree'] as $k => $element)
		{
			if (!empty($element['groups']) &&
				(count(array_intersect(User::$me->groups, $element['groups'])) == 0 ||
					(!empty(Config::$modSettings['deny_boards_access']) && count(array_intersect(User::$me->groups, $element['deny_groups'])) != 0)))
			{
				Utils::$context['linktree'][$k]['name'] = Lang::$txt['restricted_board'];
				Utils::$context['linktree'][$k]['extra_before'] = '<i>';
				Utils::$context['linktree'][$k]['extra_after'] = '</i>';
				unset(Utils::$context['linktree'][$k]['url']);
			}
		}
	}

	// Any files to include at this point?
	if (!empty(Config::$modSettings['integrate_theme_include']))
	{
		$theme_includes = explode(',', Config::$modSettings['integrate_theme_include']);
		foreach ($theme_includes as $include)
		{
			$include = strtr(trim($include), array('$boarddir' => Config::$boarddir, '$sourcedir' => Config::$sourcedir, '$themedir' => $settings['theme_dir']));
			if (file_exists($include))
				require_once($include);
		}
	}

	// Call load theme integration functions.
	call_integration_hook('integrate_load_theme');

	// We are ready to go.
	Utils::$context['theme_loaded'] = true;
}

/**
 * Load a template - if the theme doesn't include it, use the default.
 * What this function does:
 *  - loads a template file with the name template_name from the current, default, or base theme.
 *  - detects a wrong default theme directory and tries to work around it.
 *
 * @uses template_include() to include the file.
 * @param string $template_name The name of the template to load
 * @param array|string $style_sheets The name of a single stylesheet or an array of names of stylesheets to load
 * @param bool $fatal If true, dies with an error message if the template cannot be found
 * @return boolean Whether or not the template was loaded
 */
function loadTemplate($template_name, $style_sheets = array(), $fatal = true)
{
	global $settings;

	// Do any style sheets first, cause we're easy with those.
	if (!empty($style_sheets))
	{
		if (!is_array($style_sheets))
			$style_sheets = array($style_sheets);

		foreach ($style_sheets as $sheet)
			loadCSSFile($sheet . '.css', array(), $sheet);
	}

	// No template to load?
	if ($template_name === false)
		return true;

	$loaded = false;
	foreach ($settings['template_dirs'] as $template_dir)
	{
		if (file_exists($template_dir . '/' . $template_name . '.template.php'))
		{
			$loaded = true;
			template_include($template_dir . '/' . $template_name . '.template.php', true);
			break;
		}
	}

	if ($loaded)
	{
		if (Config::$db_show_debug === true)
			Utils::$context['debug']['templates'][] = $template_name . ' (' . basename($template_dir) . ')';

		// If they have specified an initialization function for this template, go ahead and call it now.
		if (function_exists('template_' . $template_name . '_init'))
			call_user_func('template_' . $template_name . '_init');
	}
	// Hmmm... doesn't exist?!  I don't suppose the directory is wrong, is it?
	elseif (!file_exists($settings['default_theme_dir']) && file_exists(Config::$boarddir . '/Themes/default'))
	{
		$settings['default_theme_dir'] = Config::$boarddir . '/Themes/default';
		$settings['template_dirs'][] = $settings['default_theme_dir'];

		if (!empty(User::$me->is_admin) && !isset($_GET['th']))
		{
			Lang::load('Errors');
			echo '
<div class="alert errorbox">
	<a href="', Config::$scripturl . '?action=admin;area=theme;sa=list;' . Utils::$context['session_var'] . '=' . Utils::$context['session_id'], '" class="alert">', Lang::$txt['theme_dir_wrong'], '</a>
</div>';
		}

		loadTemplate($template_name);
	}
	// Cause an error otherwise.
	elseif ($template_name != 'Errors' && $template_name != 'index' && $fatal)
		fatal_lang_error('theme_template_error', 'template', array((string) $template_name));
	elseif ($fatal)
		die(log_error(sprintf(isset(Lang::$txt['theme_template_error']) ? Lang::$txt['theme_template_error'] : 'Unable to load Themes/default/%s.template.php!', (string) $template_name), 'template'));
	else
		return false;
}

/**
 * Load a sub-template.
 * What it does:
 * 	- loads the sub template specified by sub_template_name, which must be in an already-loaded template.
 *  - if ?debug is in the query string, shows administrators a marker after every sub template
 *	for debugging purposes.
 *
 * @todo get rid of reading $_REQUEST directly
 *
 * @param string $sub_template_name The name of the sub-template to load
 * @param bool $fatal Whether to die with an error if the sub-template can't be loaded
 */
function loadSubTemplate($sub_template_name, $fatal = false)
{
	if (Config::$db_show_debug === true)
		Utils::$context['debug']['sub_templates'][] = $sub_template_name;

	// Figure out what the template function is named.
	$theme_function = 'template_' . $sub_template_name;
	if (function_exists($theme_function))
		$theme_function();
	elseif ($fatal === false)
		fatal_lang_error('theme_template_error', 'template', array((string) $sub_template_name));
	elseif ($fatal !== 'ignore')
		die(log_error(sprintf(isset(Lang::$txt['theme_template_error']) ? Lang::$txt['theme_template_error'] : 'Unable to load the %s sub template!', (string) $sub_template_name), 'template'));

	// Are we showing debugging for templates?  Just make sure not to do it before the doctype...
	if (allowedTo('admin_forum') && isset($_REQUEST['debug']) && !in_array($sub_template_name, array('init', 'main_below')) && ob_get_length() > 0 && !isset($_REQUEST['xml']))
	{
		echo '
<div class="noticebox">---- ', $sub_template_name, ' ends ----</div>';
	}
}

/**
 * Add a CSS file for output later
 *
 * @param string $fileName The name of the file to load
 * @param array $params An array of parameters
 * Keys are the following:
 * 	- ['external'] (true/false): define if the file is a externally located file. Needs to be set to true if you are loading an external file
 * 	- ['default_theme'] (true/false): force use of default theme url
 * 	- ['force_current'] (true/false): if this is false, we will attempt to load the file from the default theme if not found in the current theme
 *  - ['validate'] (true/false): if true script will validate the local file exists
 *  - ['rtl'] (string): additional file to load in RTL mode
 *  - ['seed'] (true/false/string): if true or null, use cache stale, false do not, or used a supplied string
 *  - ['minimize'] boolean to add your file to the main minimized file. Useful when you have a file thats loaded everywhere and for everyone.
 *  - ['order_pos'] int define the load order, when not define it's loaded in the middle, before index.css = -500, after index.css = 500, middle = 3000, end (i.e. after responsive.css) = 10000
 *  - ['attributes'] array extra attributes to add to the element
 * @param string $id An ID to stick on the end of the filename for caching purposes
 */
function loadCSSFile($fileName, $params = array(), $id = '')
{
	global $settings;

	if (empty(Utils::$context['css_files_order']))
		Utils::$context['css_files_order'] = array();

	$params['seed'] = (!array_key_exists('seed', $params) || (array_key_exists('seed', $params) && $params['seed'] === true)) ?
		(array_key_exists('browser_cache', Utils::$context) ? Utils::$context['browser_cache'] : '') :
		(is_string($params['seed']) ? '?' . ltrim($params['seed'], '?') : '');
	$params['force_current'] = isset($params['force_current']) ? $params['force_current'] : false;
	$themeRef = !empty($params['default_theme']) ? 'default_theme' : 'theme';
	$params['minimize'] = isset($params['minimize']) ? $params['minimize'] : true;
	$params['external'] = isset($params['external']) ? $params['external'] : false;
	$params['validate'] = isset($params['validate']) ? $params['validate'] : true;
	$params['order_pos'] = isset($params['order_pos']) ? (int) $params['order_pos'] : 3000;
	$params['attributes'] = isset($params['attributes']) ? $params['attributes'] : array();

	// Account for shorthand like admin.css?alp21 filenames
	$id = (empty($id) ? strtr(str_replace('.css', '', basename($fileName)), '?', '_') : $id) . '_css';

	$fileName = str_replace(pathinfo($fileName, PATHINFO_EXTENSION), strtok(pathinfo($fileName, PATHINFO_EXTENSION), '?'), $fileName);

	// Is this a local file?
	if (empty($params['external']))
	{
		// Are we validating the the file exists?
		if (!empty($params['validate']) && ($mtime = @filemtime($settings[$themeRef . '_dir'] . '/css/' . $fileName)) === false)
		{
			// Maybe the default theme has it?
			if ($themeRef === 'theme' && !$params['force_current'] && ($mtime = @filemtime($settings['default_theme_dir'] . '/css/' . $fileName) !== false))
			{
				$fileUrl = $settings['default_theme_url'] . '/css/' . $fileName;
				$filePath = $settings['default_theme_dir'] . '/css/' . $fileName;
			}
			else
			{
				$fileUrl = false;
				$filePath = false;
			}
		}
		else
		{
			$fileUrl = $settings[$themeRef . '_url'] . '/css/' . $fileName;
			$filePath = $settings[$themeRef . '_dir'] . '/css/' . $fileName;
			$mtime = @filemtime($filePath);
		}
	}
	// An external file doesn't have a filepath. Mock one for simplicity.
	else
	{
		$fileUrl = $fileName;
		$filePath = $fileName;

		// Always turn these off for external files.
		$params['minimize'] = false;
		$params['seed'] = false;
	}

	$mtime = empty($mtime) ? 0 : $mtime;

	// Add it to the array for use in the template
	if (!empty($fileName) && !empty($fileUrl))
	{
		// find a free number/position
		while (isset(Utils::$context['css_files_order'][$params['order_pos']]))
			$params['order_pos']++;
		Utils::$context['css_files_order'][$params['order_pos']] = $id;

		Utils::$context['css_files'][$id] = array('fileUrl' => $fileUrl, 'filePath' => $filePath, 'fileName' => $fileName, 'options' => $params, 'mtime' => $mtime);
	}

	if (!empty(Utils::$context['right_to_left']) && !empty($params['rtl']))
		loadCSSFile($params['rtl'], array_diff_key($params, array('rtl' => 0)));

	if ($mtime > Config::$modSettings['browser_cache'])
		Config::updateModSettings(array('browser_cache' => $mtime));
}

/**
 * Add a block of inline css code to be executed later
 *
 * - only use this if you have to, generally external css files are better, but for very small changes
 *   or for scripts that require help from PHP/whatever, this can be useful.
 * - all code added with this function is added to the same <style> tag so do make sure your css is valid!
 *
 * @param string $css Some css code
 * @return void|bool Adds the CSS to the Utils::$context['css_header'] array or returns if no CSS is specified
 */
function addInlineCss($css)
{
	// Gotta add something...
	if (empty($css))
		return false;

	Utils::$context['css_header'][] = $css;
}

/**
 * Add a Javascript file for output later
 *
 * @param string $fileName The name of the file to load
 * @param array $params An array of parameter info
 * Keys are the following:
 * 	- ['external'] (true/false): define if the file is a externally located file. Needs to be set to true if you are loading an external file
 * 	- ['default_theme'] (true/false): force use of default theme url
 * 	- ['defer'] (true/false): define if the file should load in <head> or before the closing <html> tag
 * 	- ['force_current'] (true/false): if this is false, we will attempt to load the file from the
 *	default theme if not found in the current theme
 *	- ['async'] (true/false): if the script should be loaded asynchronously (HTML5)
 *  - ['validate'] (true/false): if true script will validate the local file exists
 *  - ['seed'] (true/false/string): if true or null, use cache stale, false do not, or used a supplied string
 *  - ['minimize'] boolean to add your file to the main minimized file. Useful when you have a file thats loaded everywhere and for everyone.
 *  - ['attributes'] array extra attributes to add to the element
 *
 * @param string $id An ID to stick on the end of the filename
 */
function loadJavaScriptFile($fileName, $params = array(), $id = '')
{
	global $settings;

	$params['seed'] = (!array_key_exists('seed', $params) || (array_key_exists('seed', $params) && $params['seed'] === true)) ?
		(array_key_exists('browser_cache', Utils::$context) ? Utils::$context['browser_cache'] : '') :
		(is_string($params['seed']) ? '?' . ltrim($params['seed'], '?') : '');
	$params['force_current'] = isset($params['force_current']) ? $params['force_current'] : false;
	$themeRef = !empty($params['default_theme']) ? 'default_theme' : 'theme';
	$params['async'] = isset($params['async']) ? $params['async'] : false;
	$params['defer'] = isset($params['defer']) ? $params['defer'] : false;
	$params['minimize'] = isset($params['minimize']) ? $params['minimize'] : false;
	$params['external'] = isset($params['external']) ? $params['external'] : false;
	$params['validate'] = isset($params['validate']) ? $params['validate'] : true;
	$params['attributes'] = isset($params['attributes']) ? $params['attributes'] : array();

	// Account for shorthand like admin.js?alp21 filenames
	$id = (empty($id) ? strtr(str_replace('.js', '', basename($fileName)), '?', '_') : $id) . '_js';
	$fileName = str_replace(pathinfo($fileName, PATHINFO_EXTENSION), strtok(pathinfo($fileName, PATHINFO_EXTENSION), '?'), $fileName);

	// Is this a local file?
	if (empty($params['external']))
	{
		// Are we validating it exists on disk?
		if (!empty($params['validate']) && ($mtime = @filemtime($settings[$themeRef . '_dir'] . '/scripts/' . $fileName)) === false)
		{
			// Can't find it in this theme, how about the default?
			if ($themeRef === 'theme' && !$params['force_current'] && ($mtime = @filemtime($settings['default_theme_dir'] . '/scripts/' . $fileName)) !== false)
			{
				$fileUrl = $settings['default_theme_url'] . '/scripts/' . $fileName;
				$filePath = $settings['default_theme_dir'] . '/scripts/' . $fileName;
			}
			else
			{
				$fileUrl = false;
				$filePath = false;
			}
		}
		else
		{
			$fileUrl = $settings[$themeRef . '_url'] . '/scripts/' . $fileName;
			$filePath = $settings[$themeRef . '_dir'] . '/scripts/' . $fileName;
			$mtime = @filemtime($filePath);
		}
	}
	// An external file doesn't have a filepath. Mock one for simplicity.
	else
	{
		$fileUrl = $fileName;
		$filePath = $fileName;

		// Always turn these off for external files.
		$params['minimize'] = false;
		$params['seed'] = false;
	}

	$mtime = empty($mtime) ? 0 : $mtime;

	// Add it to the array for use in the template
	if (!empty($fileName) && !empty($fileUrl))
		Utils::$context['javascript_files'][$id] = array('fileUrl' => $fileUrl, 'filePath' => $filePath, 'fileName' => $fileName, 'options' => $params, 'mtime' => $mtime);

	if ($mtime > Config::$modSettings['browser_cache'])
		Config::updateModSettings(array('browser_cache' => $mtime));
}

/**
 * Add a Javascript variable for output later (for feeding text strings and similar to JS)
 * Cleaner and easier (for modders) than to use the function below.
 *
 * @param string $key The key for this variable
 * @param string $value The value
 * @param bool $escape Whether or not to escape the value
 */
function addJavaScriptVar($key, $value, $escape = false)
{
	// Variable name must be a valid string.
	if (!is_string($key) || $key === '' || is_numeric($key))
		return;

	// Take care of escaping the value for JavaScript?
	if (!empty($escape))
	{
		switch (gettype($value)) {
			// Illegal.
			case 'resource':
				break;

			// Convert PHP objects to arrays before processing.
			case 'object':
				$value = (array) $value;
				// no break

			// Apply JavaScriptEscape() to any strings in the array.
			case 'array':
				$replacements = array();
				array_walk_recursive(
					$value,
					function($v, $k) use (&$replacements)
					{
						if (is_string($v))
							$replacements[json_encode($v)] = JavaScriptEscape($v, true);
					}
				);
				$value = strtr(json_encode($value), $replacements);
				break;

			case 'string':
				$value = JavaScriptEscape($value);
				break;

			default:
				$value = json_encode($value);
				break;
		}
	}

	// At this point, value should contain suitably escaped JavaScript code.
	// If it obviously doesn't, declare the var with an undefined value.
	if (!is_string($value) && !is_numeric($value))
		$value = null;

	Utils::$context['javascript_vars'][$key] = $value;
}

/**
 * Add a block of inline Javascript code to be executed later
 *
 * - only use this if you have to, generally external JS files are better, but for very small scripts
 *   or for scripts that require help from PHP/whatever, this can be useful.
 * - all code added with this function is added to the same <script> tag so do make sure your JS is clean!
 *
 * @param string $javascript Some JS code
 * @param bool $defer Whether the script should load in <head> or before the closing <html> tag
 * @return void|bool Adds the code to one of the Utils::$context['javascript_inline'] arrays or returns if no JS was specified
 */
function addInlineJavaScript($javascript, $defer = false)
{
	if (empty($javascript))
		return false;

	Utils::$context['javascript_inline'][($defer === true ? 'defer' : 'standard')][] = $javascript;
}

/**
 * Load the template/language file using require
 * 	- loads the template or language file specified by filename.
 * 	- uses eval unless disableTemplateEval is enabled.
 * 	- outputs a parse error if the file did not exist or contained errors.
 * 	- attempts to detect the error and line, and show detailed information.
 *
 * @param string $filename The name of the file to include
 * @param bool $once If true only includes the file once (like include_once)
 */
function template_include($filename, $once = false)
{
	static $templates = array();

	// We want to be able to figure out any errors...
	@ini_set('track_errors', '1');

	// Don't include the file more than once, if $once is true.
	if ($once && in_array($filename, $templates))
		return;
	// Add this file to the include list, whether $once is true or not.
	else
		$templates[] = $filename;

	$file_found = file_exists($filename);

	if ($once && $file_found)
		require_once($filename);
	elseif ($file_found)
		require($filename);

	if ($file_found !== true)
	{
		ob_end_clean();
		if (!empty(Config::$modSettings['enableCompressedOutput']))
			@ob_start('ob_gzhandler');
		else
			ob_start();

		if (isset($_GET['debug']))
			header('content-type: application/xhtml+xml; charset=' . (empty(Utils::$context['character_set']) ? 'ISO-8859-1' : Utils::$context['character_set']));

		// Don't cache error pages!!
		header('expires: Mon, 26 Jul 1997 05:00:00 GMT');
		header('last-modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
		header('cache-control: no-cache');

		if (!isset(Lang::$txt['template_parse_error']))
		{
			Lang::$txt['template_parse_error'] = 'Template Parse Error!';
			Lang::$txt['template_parse_error_message'] = 'It seems something has gone sour on the forum with the template system.  This problem should only be temporary, so please come back later and try again.  If you continue to see this message, please contact the administrator.<br><br>You can also try <a href="javascript:location.reload();">refreshing this page</a>.';
			Lang::$txt['template_parse_error_details'] = 'There was a problem loading the <pre><strong>%1$s</strong></pre> template or language file.  Please check the syntax and try again - remember, single quotes (<pre>\'</pre>) often have to be escaped with a slash (<pre>\\</pre>).  To see more specific error information from PHP, try <a href="%2$s%1$s" class="extern">accessing the file directly</a>.<br><br>You may want to try to <a href="javascript:location.reload();">refresh this page</a> or <a href="%3$s?theme=1">use the default theme</a>.';
			Lang::$txt['template_parse_errmsg'] = 'Unfortunately more information is not available at this time as to exactly what is wrong.';
		}

		// First, let's get the doctype and language information out of the way.
		echo '<!DOCTYPE html>
<html', !empty(Utils::$context['right_to_left']) ? ' dir="rtl"' : '', '>
	<head>';
		if (isset(Utils::$context['character_set']))
			echo '
		<meta charset="', Utils::$context['character_set'], '">';

		if (!empty(Config::$maintenance) && !allowedTo('admin_forum'))
			echo '
		<title>', Config::$mtitle, '</title>
	</head>
	<body>
		<h3>', Config::$mtitle, '</h3>
		', Config::$mmessage, '
	</body>
</html>';
		elseif (!allowedTo('admin_forum'))
			echo '
		<title>', Lang::$txt['template_parse_error'], '</title>
	</head>
	<body>
		<h3>', Lang::$txt['template_parse_error'], '</h3>
		', Lang::$txt['template_parse_error_message'], '
	</body>
</html>';
		else
		{
			$error = fetch_web_data(Config::$boardurl . strtr($filename, array(Config::$boarddir => '', strtr(Config::$boarddir, '\\', '/') => '')));
			$error_array = error_get_last();
			if (empty($error) && ini_get('track_errors') && !empty($error_array))
				$error = $error_array['message'];
			if (empty($error))
				$error = Lang::$txt['template_parse_errmsg'];

			$error = strtr($error, array('<b>' => '<strong>', '</b>' => '</strong>'));

			echo '
		<title>', Lang::$txt['template_parse_error'], '</title>
	</head>
	<body>
		<h3>', Lang::$txt['template_parse_error'], '</h3>
		', sprintf(Lang::$txt['template_parse_error_details'], strtr($filename, array(Config::$boarddir => '', strtr(Config::$boarddir, '\\', '/') => '')), Config::$boardurl, Config::$scripturl);

			if (!empty($error))
				echo '
		<hr>

		<div style="margin: 0 20px;"><pre>', strtr(strtr($error, array('<strong>' . Config::$boarddir => '<strong>...', '<strong>' . strtr(Config::$boarddir, '\\', '/') => '<strong>...')), '\\', '/'), '</pre></div>';

			// I know, I know... this is VERY COMPLICATED.  Still, it's good.
			if (preg_match('~ <strong>(\d+)</strong><br( /)?' . '>$~i', $error, $match) != 0)
			{
				$data = file($filename);
				$data2 = BBCodeParser::highlightPhpCode(implode('', $data));
				$data2 = preg_split('~\<br( /)?\>~', $data2);

				// Fix the PHP code stuff...
				if (!BrowserDetector::isBrowser('gecko'))
					$data2 = str_replace("\t", '<span style="white-space: pre;">' . "\t" . '</span>', $data2);
				else
					$data2 = str_replace('<pre style="display: inline;">' . "\t" . '</pre>', "\t", $data2);

				// Now we get to work around a bug in PHP where it doesn't escape <br>s!
				$j = -1;
				foreach ($data as $line)
				{
					$j++;

					if (substr_count($line, '<br>') == 0)
						continue;

					$n = substr_count($line, '<br>');
					for ($i = 0; $i < $n; $i++)
					{
						$data2[$j] .= '&lt;br /&gt;' . $data2[$j + $i + 1];
						unset($data2[$j + $i + 1]);
					}
					$j += $n;
				}
				$data2 = array_values($data2);
				array_unshift($data2, '');

				echo '
		<div style="margin: 2ex 20px; width: 96%; overflow: auto;"><pre style="margin: 0;">';

				// Figure out what the color coding was before...
				$line = max($match[1] - 9, 1);
				$last_line = '';
				for ($line2 = $line - 1; $line2 > 1; $line2--)
					if (strpos($data2[$line2], '<') !== false)
					{
						if (preg_match('~(<[^/>]+>)[^<]*$~', $data2[$line2], $color_match) != 0)
							$last_line = $color_match[1];
						break;
					}

				// Show the relevant lines...
				for ($n = min($match[1] + 4, count($data2) + 1); $line <= $n; $line++)
				{
					if ($line == $match[1])
						echo '</pre><div style="background-color: #ffb0b5;"><pre style="margin: 0;">';

					echo '<span style="color: black;">', sprintf('%' . strlen($n) . 's', $line), ':</span> ';
					if (isset($data2[$line]) && $data2[$line] != '')
						echo substr($data2[$line], 0, 2) == '</' ? preg_replace('~^</[^>]+>~', '', $data2[$line]) : $last_line . $data2[$line];

					if (isset($data2[$line]) && preg_match('~(<[^/>]+>)[^<]*$~', $data2[$line], $color_match) != 0)
					{
						$last_line = $color_match[1];
						echo '</', substr($last_line, 1, 4), '>';
					}
					elseif ($last_line != '' && strpos($data2[$line], '<') !== false)
						$last_line = '';
					elseif ($last_line != '' && $data2[$line] != '')
						echo '</', substr($last_line, 1, 4), '>';

					if ($line == $match[1])
						echo '</pre></div><pre style="margin: 0;">';
					else
						echo "\n";
				}

				echo '</pre></div>';
			}

			echo '
	</body>
</html>';
		}

		die;
	}
}

?>