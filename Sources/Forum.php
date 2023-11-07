<?php

/**
 * Simple Machines Forum (SMF)
 *
 * @package SMF
 * @author Simple Machines https://www.simplemachines.org
 * @copyright 2023 Simple Machines and individual contributors
 * @license https://www.simplemachines.org/about/smf/license.php BSD
 *
 * @version 3.0 Alpha 1
 */

namespace SMF;

use SMF\Db\DatabaseApi as Db;

/**
 * The root Forum class. Used when browsing the forum normally.
 *
 * This, as you have probably guessed, is the crux on which SMF functions.
 *
 * The most interesting part of this file for modification authors is the action
 * array. It is formatted as so:
 *
 *    'action-in-url' => array('Source-File.php', 'FunctionToCall'),
 *
 * Then, you can access the FunctionToCall() function from Source-File.php with
 * the URL index.php?action=action-in-url. Relatively simple, no?
 */
class Forum
{
	/**************************
	 * Public static properties
	 **************************/

	/**
	 * @var array
	 *
	 * This array defines what file to load and what function to call for each
	 * possible value of $_REQUEST['action'].
	 *
	 * When calling an autoloading class, the file can be left empty.
	 *
	 * Mod authors can add new actions to this via the integrate_actions hook.
	 */
	public static $actions = array(
		'agreement' => array('', 'SMF\\Actions\\Agreement::call'),
		'acceptagreement' => array('', 'SMF\\Actions\\AgreementAccept::call'),
		'activate' => array('', 'SMF\\Actions\\Activate::call'),
		'admin' => array('', 'SMF\\Actions\\Admin\\ACP::call'),
		'announce' => array('', 'SMF\\Actions\\Announce::call'),
		'attachapprove' => array('', 'SMF\\Actions\\AttachmentApprove::call'),
		'buddy' => array('', 'SMF\\Actions\\BuddyListToggle::call'),
		'calendar' => array('', 'SMF\\Actions\\Calendar::call'),
		'clock' => array('', 'SMF\\Actions\\Calendar::call'), // Deprecated; is now a sub-action
		'coppa' => array('', 'SMF\\Actions\\CoppaForm::call'),
		'credits' => array('', 'SMF\\Actions\\Credits::call'),
		'deletemsg' => array('', 'SMF\\Actions\\MsgDelete::call'),
		'dlattach' => array('', 'SMF\\Actions\\AttachmentDownload::call'),
		'editpoll' => array('', 'SMF\\Poll::edit'),
		'editpoll2' => array('', 'SMF\\Poll::edit2'),
		'findmember' => array('', 'SMF\\Actions\\FindMember::call'),
		'groups' => array('', 'SMF\\Actions\\Groups::call'),
		'help' => array('', 'SMF\\Actions\\Help::call'),
		'helpadmin' => array('', 'SMF\\Actions\\HelpAdmin::call'),
		'jsmodify' => array('', 'SMF\\Actions\\JavaScriptModify::call'),
		'jsoption' => array('', 'SMF\\Theme::setJavaScript'),
		'likes' => array('', 'SMF\\Actions\\Like::call'),
		'lock' => array('', 'SMF\\Topic::lock'),
		'lockvoting' => array('', 'SMF\\Poll::lock'),
		'login' => array('', 'SMF\\Actions\\Login::call'),
		'login2' => array('', 'SMF\\Actions\\Login2::call'),
		'logintfa' => array('', 'SMF\\Actions\\LoginTFA::call'),
		'logout' => array('', 'SMF\\Actions\\Logout::call'),
		'markasread' => array('', 'SMF\\Board::MarkRead'),
		'mergetopics' => array('', 'SMF\\Actions\\TopicMerge::call'),
		'mlist' => array('', 'SMF\\Actions\\Memberlist::call'),
		'moderate' => array('', 'SMF\\Actions\\Moderation\\Main::call'),
		'modifycat' => array('', 'SMF\\Actions\\Admin\\Boards::modifyCat'),
		'movetopic' => array('', 'SMF\\Actions\\TopicMove::call'),
		'movetopic2' => array('', 'SMF\\Actions\\TopicMove2::call'),
		'notifyannouncements' => array('', 'SMF\\Actions\\NotifyAnnouncements::call'),
		'notifyboard' => array('', 'SMF\\Actions\\NotifyBoard::call'),
		'notifytopic' => array('', 'SMF\\Actions\\NotifyTopic::call'),
		'pm' => array('', 'SMF\\Actions\\PersonalMessage::call'),
		'post' => array('', 'SMF\\Actions\\Post::call'),
		'post2' => array('', 'SMF\\Actions\\Post2::call'),
		'printpage' => array('', 'SMF\\Actions\\TopicPrint::call'),
		'profile' => array('', 'SMF\\Actions\\Profile\\Main::call'),
		'quotefast' => array('', 'SMF\\Actions\\QuoteFast::call'),
		'quickmod' => array('', 'SMF\\Actions\\QuickModeration::call'),
		'quickmod2' => array('', 'SMF\\Actions\\QuickModerationInTopic::call'),
		'recent' => array('', 'SMF\\Actions\\Recent::call'),
		'reminder' => array('', 'SMF\\Actions\\Reminder::call'),
		'removepoll' => array('', 'SMF\\Poll::remove'),
		'removetopic2' => array('', 'SMF\\Actions\\TopicRemove::call'),
		'reporttm' => array('', 'SMF\\Actions\\ReportToMod::call'),
		'requestmembers' => array('', 'SMF\\Actions\\RequestMembers::call'),
		'restoretopic' => array('', 'SMF\\Actions\\TopicRestore::call'),
		'search' => array('', 'SMF\\Actions\\Search::call'),
		'search2' => array('', 'SMF\\Actions\\Search2::call'),
		'sendactivation' => array('', 'SMF\\Actions\\SendActivation::call'),
		'signup' => array('', 'SMF\\Actions\\Register::call'),
		'signup2' => array('', 'SMF\\Actions\\Register2::call'),
		'smstats' => array('', 'SMF\\Actions\\SmStats::call'),
		'suggest' => array('', 'SMF\\Actions\\AutoSuggest::call'),
		'splittopics' => array('', 'SMF\\Actions\\TopicSplit::call'),
		'stats' => array('', 'SMF\\Actions\\Stats::call'),
		'sticky' => array('', 'SMF\\Topic::sticky'),
		'theme' => array('', 'SMF\\Theme::dispatch'),
		'trackip' => array('', 'SMF\\Actions\\TrackIP::call'),
		'about:unknown' => array('', 'SMF\\Actions\\Like::BookOfUnknown'),
		'unread' => array('', 'SMF\\Actions\\Unread::call'),
		'unreadreplies' => array('', 'SMF\\Actions\\UnreadReplies::call'),
		'uploadAttach' => array('', 'SMF\\Actions\\AttachmentUpload::call'),
		'verificationcode' => array('', 'SMF\\Actions\\VerificationCode::call'),
		'viewprofile' => array('', 'SMF\\Actions\\Profile\\Main::call'),
		'vote' => array('', 'SMF\\Poll::vote'),
		'viewquery' => array('', 'SMF\\Actions\\ViewQuery::call'),
		'viewsmfile' => array('', 'SMF\\Actions\\DisplayAdminFile::call'),
		'who' => array('', 'SMF\\Actions\\Who::call'),
		'.xml' => array('', 'SMF\\Actions\\Feed::call'),
		'xmlhttp' => array('', 'SMF\\Actions\\XmlHttp::call'),
	);

	/**
	 * @var array
	 *
	 * This array defines actions, sub-actions, and/or areas where user activity
	 * should not be logged. For example, if the user downloads an attachment
	 * via the dlattach action, that's not something we want to log.
	 *
	 * Array keys are actions. Array values are either:
	 *
	 *  - true, which means the action as a whole should not be logged.
	 *
	 *  - a multidimensional array indicating specific sub-actions or areas that
	 *    should not be logged.
	 *
	 *    For example, 'pm' => array('sa' => array('popup')) means that we won't
	 *    log visits to index.php?action=pm;sa=popup, but other sub-actions
	 *    like index.php?action=pm;sa=send will be logged.
	 */
	public static $unlogged_actions = array(
		'about:unknown' => true,
		'clock' => true,
		'dlattach' => true,
		'findmember' => true,
		'helpadmin' => true,
		'jsoption' => true,
		'likes' => true,
		'modifycat' => true,
		'pm' => array('sa' => array('popup')),
		'profile' => array('area' => array('popup', 'alerts_popup', 'download', 'dlattach')),
		'requestmembers' => true,
		'smstats' => true,
		'suggest' => true,
		'verificationcode' => true,
		'viewquery' => true,
		'viewsmfile' => true,
		'xmlhttp' => true,
		'.xml' => true,
	);

	/**
	 * @var array
	 *
	 * Actions that guests are always allowed to do.
	 * This allows users to log in when guest access is disabled.
	 */
	public static $guest_access_actions = array(
		'coppa',
		'login',
		'login2',
		'logintfa',
		'reminder',
		'activate',
		'help',
		'helpadmin',
		'smstats',
		'verificationcode',
		'signup',
		'signup2',
	);

	/****************
	 * Public methods
	 ****************/

	/**
	 * Constructor
	 */
	public function __construct()
	{
		// If Config::$maintenance is set specifically to 2, then we're upgrading or something.
		if (!empty(Config::$maintenance) &&  2 === Config::$maintenance)
		{
			ErrorHandler::displayMaintenanceMessage();
		}

		// Initiate the database connection and define some database functions to use.
		Db::load();

		// Load the settings from the settings table, and perform operations like optimizing.
		Config::reloadModSettings();

		// Clean the request variables, add slashes, etc.
		QueryString::cleanRequest();

		// Seed the random generator.
		if (empty(Config::$modSettings['rand_seed']) || mt_rand(1, 250) == 69)
			Config::generateSeed();

		// If a Preflight is occurring, lets stop now.
		if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS')
		{
			Utils::sendHttpStatus(204);
			die;
		}

		// Check if compressed output is enabled, supported, and not already being done.
		if (!empty(Config::$modSettings['enableCompressedOutput']) && !headers_sent())
		{
			// If zlib is being used, turn off output compression.
			if (ini_get('zlib.output_compression') >= 1 || ini_get('output_handler') == 'ob_gzhandler')
				Config::$modSettings['enableCompressedOutput'] = '0';

			else
			{
				ob_end_clean();
				ob_start('ob_gzhandler');
			}
		}

		// Register an error handler.
		set_error_handler(__NAMESPACE__ . '\\ErrorHandler::call');

		// Start the session. (assuming it hasn't already been.)
		Session::load();

		// Why three different hooks? For historical reasons.
		// Allow modifying $actions easily.
		IntegrationHook::call('integrate_actions', array(&self::$actions));

		// Allow modifying $unlogged_actions easily.
		IntegrationHook::call('integrate_pre_log_stats', array(&self::$unlogged_actions));

		// Allow modifying $guest_access_actions easily.
		IntegrationHook::call('integrate_guest_actions', array(&self::$guest_access_actions));
	}

	/**
	 * This is the one that gets stuff done.
	 *
	 * Internally, this calls $this->main() to find out what function to call,
	 * then calls that function, and then calls obExit() in order to send
	 * results to the browser.
	 */
	public function execute()
	{
		// What function shall we execute? (done like this for memory's sake.)
		call_user_func($this->main());

		// Call obExit specially; we're coming from the main area ;).
		Utils::obExit(null, null, true);
	}

	/***********************
	 * Public static methods
	 ***********************/

	/**
	 * Display a message about the forum being in maintenance mode.
	 * - display a login screen with sub template 'maintenance'.
	 * - sends a 503 header, so search engines don't bother indexing while we're in maintenance mode.
	 */
	public static function inMaintenance()
	{
		Lang::load('Login');
		Theme::loadTemplate('Login');
		SecurityToken::create('login');

		// Send a 503 header, so search engines don't bother indexing while we're in maintenance mode.
		Utils::sendHttpStatus(503, 'Service Temporarily Unavailable');

		// Basic template stuff..
		Utils::$context['sub_template'] = 'maintenance';
		Utils::$context['title'] = Utils::htmlspecialchars(Config::$mtitle);
		Utils::$context['description'] = &Config::$mmessage;
		Utils::$context['page_title'] = Lang::$txt['maintain_mode'];
	}

	/******************
	 * Internal methods
	 ******************/

	/**
	 * The main dispatcher.
	 * This delegates to each area.
	 *
	 * @return array|string|void An array containing the file to include and name of function to call, the name of a function to call or dies with a fatal_lang_error if we couldn't find anything to do.
	 */
	protected function main()
	{
		// Special case: session keep-alive, output a transparent pixel.
		if (isset($_GET['action']) && $_GET['action'] == 'keepalive')
		{
			header('content-type: image/gif');
			die("\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\x00\x00\x00\x21\xF9\x04\x01\x00\x00\x00\x00\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3B");
		}

		// We should set our security headers now.
		Security::frameOptionsHeader();

		// Set our CORS policy.
		Security::corsPolicyHeader();

		// Load the user's cookie (or set as guest) and load their settings.
		User::load();

		// Load the current board's information.
		Board::load();

		// Load the current user's permissions.
		User::$me->loadPermissions();

		// Attachments don't require the entire theme to be loaded.
		if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'dlattach' && empty(Config::$maintenance))
		{
			BrowserDetector::call();
		}
		// Load the current theme.  (note that ?theme=1 will also work, may be used for guest theming.)
		else
		{
			Theme::load();
		}

		// Check if the user should be disallowed access.
		User::$me->kickIfBanned();

		// If we are in a topic and don't have permission to approve it then duck out now.
		if (!empty(Topic::$topic_id) && empty(Board::$info->cur_topic_approved) && !User::$me->allowedTo('approve_posts') && (User::$me->id != Board::$info->cur_topic_starter || User::$me->is_guest))
		{
			ErrorHandler::fatalLang('not_a_topic', false);
		}

		// Don't log if this is an attachment, avatar, toggle of editor buttons, theme option, XML feed, popup, etc.
		if (!QueryString::isFilteredRequest(self::$unlogged_actions, 'action'))
		{
			// Log this user as online.
			User::$me->logOnline();

			// Track forum statistics and hits...?
			if (!empty(Config::$modSettings['hitStats']))
				Logging::trackStats(array('hits' => '+'));
		}

		// Make sure that our scheduled tasks have been running as intended
		Config::checkCron();

		// Is the forum in maintenance mode? (doesn't apply to administrators.)
		if (!empty(Config::$maintenance) && !User::$me->allowedTo('admin_forum'))
		{
			// You can only login.... otherwise, you're getting the "maintenance mode" display.
			if (isset($_REQUEST['action']) && (in_array($_REQUEST['action'], array('login2', 'logintfa', 'logout'))))
			{
				return self::$actions[$_REQUEST['action']][1];
			}
			// Don't even try it, sonny.
			else
			{
				return __CLASS__ . '::inMaintenance';
			}
		}
		// If guest access is off, a guest can only do one of the very few following actions.
		elseif (empty(Config::$modSettings['allow_guestAccess']) && User::$me->is_guest && (!isset($_REQUEST['action']) || !in_array($_REQUEST['action'], self::$guest_access_actions)))
		{
			User::$me->kickIfGuest(null, false);
		}
		elseif (empty($_REQUEST['action']))
		{
			// Action and board are both empty... BoardIndex! Unless someone else wants to do something different.
			if (empty(Board::$info->id) && empty(Topic::$topic_id))
			{
				if (!empty(Config::$modSettings['integrate_default_action']))
				{
					$defaultAction = explode(',', Config::$modSettings['integrate_default_action']);

					// Sorry, only one default action is needed.
					$defaultAction = $defaultAction[0];

					$call = Utils::getCallable($defaultAction);

					if (!empty($call))
						return $call;
				}

				// No default action huh? then go to our good old BoardIndex.
				else
				{
					return 'SMF\\Actions\\BoardIndex::call';
				}
			}

			// Topic is empty, and action is empty.... MessageIndex!
			elseif (empty(Topic::$topic_id))
			{
				return 'SMF\\Actions\\MessageIndex::call';
			}

			// Board is not empty... topic is not empty... action is empty.. Display!
			else
			{
				return 'SMF\\Actions\\Display::call';
			}
		}

		// Get the function and file to include - if it's not there, do the board index.
		if (!isset($_REQUEST['action']) || !isset(self::$actions[$_REQUEST['action']]))
		{
			// Catch the action with the theme?
			if (!empty(Theme::$current->settings['catch_action']))
			{
				return 'SMF\\Theme::wrapAction';
			}

			if (!empty(Config::$modSettings['integrate_fallback_action']))
			{
				$fallbackAction = explode(',', Config::$modSettings['integrate_fallback_action']);

				// Sorry, only one fallback action is needed.
				$fallbackAction = $fallbackAction[0];

				$call = Utils::getCallable($fallbackAction);

				if (!empty($call))
					return $call;
			}

			// No fallback action, huh?
			else
			{
				ErrorHandler::fatalLang('not_found', false, array(), 404);
			}
		}

		// Otherwise, it was set - so let's go to that action.
		if (!empty(self::$actions[$_REQUEST['action']][0]))
			require_once(Config::$sourcedir . '/' . self::$actions[$_REQUEST['action']][0]);

		// Do the right thing.
		return Utils::getCallable(self::$actions[$_REQUEST['action']][1]);
	}
}

?>