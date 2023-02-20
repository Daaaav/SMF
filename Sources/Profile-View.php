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

use SMF\BBCodeParser;
use SMF\Board;
use SMF\Config;
use SMF\Lang;
use SMF\User;
use SMF\Utils;
use SMF\Db\DatabaseApi as Db;

if (!defined('SMF'))
	die('No direct access...');

/**
 * View a summary.
 *
 * @param int $memID The ID of the member
 */
function summary($memID)
{
	// Attempt to load the member's profile data.
	if (!isset(User::$loaded[$memID]))
		fatal_lang_error('not_a_user', false, 404);

	User::$loaded[$memID]->format();

	// Set up the stuff and load the user.
	Utils::$context += array(
		'page_title' => sprintf(Lang::$txt['profile_of_username'], User::$loaded[$memID]->formatted['name']),
		'can_send_pm' => allowedTo('pm_send'),
		'can_have_buddy' => allowedTo('profile_extra_own') && !empty(Config::$modSettings['enable_buddylist']),
		'can_issue_warning' => allowedTo('issue_warning') && Config::$modSettings['warning_settings'][0] == 1,
		'can_view_warning' => (allowedTo('moderate_forum') || allowedTo('issue_warning') || allowedTo('view_warning_any') || (User::$me->is_owner && allowedTo('view_warning_own'))) && Config::$modSettings['warning_settings'][0] === '1'
	);

	Utils::$context['member'] = User::$loaded[$memID]->formatted;

	// Set a canonical URL for this page.
	Utils::$context['canonical_url'] = Config::$scripturl . '?action=profile;u=' . $memID;

	// Are there things we don't show?
	Utils::$context['disabled_fields'] = isset(Config::$modSettings['disabled_profile_fields']) ? array_flip(explode(',', Config::$modSettings['disabled_profile_fields'])) : array();
	// Menu tab
	Utils::$context[Utils::$context['profile_menu_name']]['tab_data'] = array(
		'title' => Lang::$txt['summary'],
		'icon_class' => 'main_icons profile_hd'
	);

	// See if they have broken any warning levels...
	list (Config::$modSettings['warning_enable'], Config::$modSettings['user_limit']) = explode(',', Config::$modSettings['warning_settings']);
	if (!empty(Config::$modSettings['warning_mute']) && Config::$modSettings['warning_mute'] <= Utils::$context['member']['warning'])
		Utils::$context['warning_status'] = Lang::$txt['profile_warning_is_muted'];
	elseif (!empty(Config::$modSettings['warning_moderate']) && Config::$modSettings['warning_moderate'] <= Utils::$context['member']['warning'])
		Utils::$context['warning_status'] = Lang::$txt['profile_warning_is_moderation'];
	elseif (!empty(Config::$modSettings['warning_watch']) && Config::$modSettings['warning_watch'] <= Utils::$context['member']['warning'])
		Utils::$context['warning_status'] = Lang::$txt['profile_warning_is_watch'];

	// They haven't even been registered for a full day!?
	$days_registered = (int) ((time() - User::$loaded[$memID]->date_registered) / (3600 * 24));
	if (empty(User::$loaded[$memID]->date_registered) || $days_registered < 1)
		Utils::$context['member']['posts_per_day'] = Lang::$txt['not_applicable'];
	else
		Utils::$context['member']['posts_per_day'] = Lang::numberFormat(Utils::$context['member']['real_posts'] / $days_registered, 3);

	// Set the age...
	if (empty(Utils::$context['member']['birth_date']) || substr(Utils::$context['member']['birth_date'], 0, 4) < 1002)
	{
		Utils::$context['member'] += array(
			'age' => Lang::$txt['not_applicable'],
			'today_is_birthday' => false
		);
	}
	else
	{
		list ($birth_year, $birth_month, $birth_day) = sscanf(Utils::$context['member']['birth_date'], '%d-%d-%d');
		$datearray = getdate(time());
		Utils::$context['member'] += array(
			'age' => $birth_year <= 1004 ? Lang::$txt['not_applicable'] : $datearray['year'] - $birth_year - (($datearray['mon'] > $birth_month || ($datearray['mon'] == $birth_month && $datearray['mday'] >= $birth_day)) ? 0 : 1),
			'today_is_birthday' => $datearray['mon'] == $birth_month && $datearray['mday'] == $birth_day && $birth_year > 1004
		);
	}

	if (allowedTo('moderate_forum'))
	{
		// Make sure it's a valid ip address; otherwise, don't bother...
		if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', User::$loaded[$memID]->formatted['ip']) == 1 && empty(Config::$modSettings['disableHostnameLookup']))
			Utils::$context['member']['hostname'] = host_from_ip(User::$loaded[$memID]->formatted['ip']);
		else
			Utils::$context['member']['hostname'] = '';

		Utils::$context['can_see_ip'] = true;
	}
	else
		Utils::$context['can_see_ip'] = false;

	// Are they hidden?
	Utils::$context['member']['is_hidden'] = empty(User::$loaded[$memID]->show_online);
	Utils::$context['member']['show_last_login'] = allowedTo('admin_forum') || !Utils::$context['member']['is_hidden'];

	if (!empty(Config::$modSettings['who_enabled']) && Utils::$context['member']['show_last_login'])
	{
		include_once(Config::$sourcedir . '/Who.php');
		$action = determineActions(User::$loaded[$memID]->url);

		if ($action !== false)
			Utils::$context['member']['action'] = $action;
	}

	// If the user is awaiting activation, and the viewer has permission - setup some activation context messages.
	if (Utils::$context['member']['is_activated'] % 10 != 1 && allowedTo('moderate_forum'))
	{
		Utils::$context['activate_type'] = Utils::$context['member']['is_activated'];
		// What should the link text be?
		Utils::$context['activate_link_text'] = in_array(Utils::$context['member']['is_activated'], array(3, 4, 5, 13, 14, 15)) ? Lang::$txt['account_approve'] : Lang::$txt['account_activate'];

		// Should we show a custom message?
		Utils::$context['activate_message'] = isset(Lang::$txt['account_activate_method_' . Utils::$context['member']['is_activated'] % 10]) ? Lang::$txt['account_activate_method_' . Utils::$context['member']['is_activated'] % 10] : Lang::$txt['account_not_activated'];

		// If they can be approved, we need to set up a token for them.
		Utils::$context['token_check'] = 'profile-aa' . $memID;
		createToken(Utils::$context['token_check'], 'get');

		// Puerile comment
		$type = in_array(Utils::$context['member']['is_activated'], array(3, 4, 5, 13, 14, 15)) ? 'approve' : 'activate';
		Utils::$context['activate_link'] = Config::$scripturl . '?action=admin;area=viewmembers;sa=browse;type=' . $type . ';' . Utils::$context['session_var'] . '=' . Utils::$context['session_id'] . ';' . Utils::$context[Utils::$context['token_check'] . '_token_var'] . '=' . Utils::$context[Utils::$context['token_check'] . '_token'];
	}

	// Is the signature even enabled on this forum?
	Utils::$context['signature_enabled'] = substr(Config::$modSettings['signature_settings'], 0, 1) == 1;

	// Prevent signature images from going outside the box.
	if (Utils::$context['signature_enabled'])
	{
		list ($sig_limits, $sig_bbc) = explode(':', Config::$modSettings['signature_settings']);
		$sig_limits = explode(',', $sig_limits);

		if (!empty($sig_limits[5]) || !empty($sig_limits[6]))
			addInlineCss('
	.signature img { ' . (!empty($sig_limits[5]) ? 'max-width: ' . (int) $sig_limits[5] . 'px; ' : '') . (!empty($sig_limits[6]) ? 'max-height: ' . (int) $sig_limits[6] . 'px; ' : '') . '}');
	}

	// How about, are they banned?
	Utils::$context['member']['bans'] = array();
	if (allowedTo('moderate_forum'))
	{
		// Can they edit the ban?
		Utils::$context['can_edit_ban'] = allowedTo('manage_bans');

		$ban_query = array();
		$ban_query_vars = array(
			'time' => time(),
		);
		$ban_query[] = 'id_member = ' . Utils::$context['member']['id'];
		$ban_query[] = ' {inet:ip} BETWEEN bi.ip_low and bi.ip_high';
		$ban_query_vars['ip'] = User::$loaded[$memID]->formatted['ip'];
		// Do we have a hostname already?
		if (!empty(Utils::$context['member']['hostname']))
		{
			$ban_query[] = '({string:hostname} LIKE hostname)';
			$ban_query_vars['hostname'] = Utils::$context['member']['hostname'];
		}
		// Check their email as well...
		if (strlen(Utils::$context['member']['email']) != 0)
		{
			$ban_query[] = '({string:email} LIKE bi.email_address)';
			$ban_query_vars['email'] = Utils::$context['member']['email'];
		}

		// So... are they banned?  Dying to know!
		$request = Db::$db->query('', '
			SELECT bg.id_ban_group, bg.name, bg.cannot_access, bg.cannot_post,
				bg.cannot_login, bg.reason
			FROM {db_prefix}ban_items AS bi
				INNER JOIN {db_prefix}ban_groups AS bg ON (bg.id_ban_group = bi.id_ban_group AND (bg.expire_time IS NULL OR bg.expire_time > {int:time}))
			WHERE (' . implode(' OR ', $ban_query) . ')',
			$ban_query_vars
		);
		while ($row = Db::$db->fetch_assoc($request))
		{
			// Work out what restrictions we actually have.
			$ban_restrictions = array();
			foreach (array('access', 'login', 'post') as $type)
				if ($row['cannot_' . $type])
					$ban_restrictions[] = Lang::$txt['ban_type_' . $type];

			// No actual ban in place?
			if (empty($ban_restrictions))
				continue;

			// Prepare the link for context.
			$ban_explanation = sprintf(Lang::$txt['user_cannot_due_to'], implode(', ', $ban_restrictions), '<a href="' . Config::$scripturl . '?action=admin;area=ban;sa=edit;bg=' . $row['id_ban_group'] . '">' . $row['name'] . '</a>');

			Utils::$context['member']['bans'][$row['id_ban_group']] = array(
				'reason' => empty($row['reason']) ? '' : '<br><br><strong>' . Lang::$txt['ban_reason'] . ':</strong> ' . $row['reason'],
				'cannot' => array(
					'access' => !empty($row['cannot_access']),
					'post' => !empty($row['cannot_post']),
					'login' => !empty($row['cannot_login']),
				),
				'explanation' => $ban_explanation,
			);
		}
		Db::$db->free_result($request);
	}
	loadCustomFields($memID);

	Utils::$context['print_custom_fields'] = array();

	// Any custom profile fields?
	if (!empty(Utils::$context['custom_fields']))
		foreach (Utils::$context['custom_fields'] as $custom)
			Utils::$context['print_custom_fields'][Utils::$context['cust_profile_fields_placement'][$custom['placement']]][] = $custom;

}

/**
 * Fetch the alerts a member currently has.
 *
 * @param int $memID The ID of the member.
 * @param mixed $to_fetch Alerts to fetch: true/false for all/unread, or a list of one or more IDs.
 * @param array $limit Maximum number of alerts to fetch (0 for no limit).
 * @param array $offset Number of alerts to skip for pagination. Ignored if $to_fetch is a list of IDs.
 * @param bool $with_avatar Whether to load the avatar of the alert sender.
 * @param bool $show_links Whether to show links in the constituent parts of the alert message.
 * @return array An array of information about the fetched alerts.
 */
function fetch_alerts($memID, $to_fetch = false, $limit = 0, $offset = 0, $with_avatar = false, $show_links = false)
{
	// Are we being asked for some specific alerts?
	$alertIDs = is_bool($to_fetch) ? array() : array_filter(array_map('intval', (array) $to_fetch));

	// Basic sanitation.
	$memID = (int) $memID;
	$unread = $to_fetch === false;

	if (empty($limit) || $limit > 1000)
		$limit = min(!empty(Config::$modSettings['alerts_per_page']) && (int) Config::$modSettings['alerts_per_page'] < 1000 ? (int) Config::$modSettings['alerts_per_page'] : 1000, 1000);

	$offset = !empty($alertIDs) ? 0 : max(0, (int) $offset);
	$with_avatar = !empty($with_avatar);
	$show_links = !empty($show_links);

	// Arrays we'll need.
	$alerts = array();
	$senders = array();
	$profiles = array();
	$profile_alerts = array();
	$possible_msgs = array();
	$possible_topics = array();

	// Get the basic alert info.
	$request = Db::$db->query('', '
		SELECT a.id_alert, a.alert_time, a.is_read, a.extra,
			a.content_type, a.content_id, a.content_action,
			mem.id_member AS sender_id, COALESCE(mem.real_name, a.member_name) AS sender_name' . ($with_avatar ? ',
			mem.email_address AS sender_email, mem.avatar AS sender_avatar, f.filename AS sender_filename' : '') . '
		FROM {db_prefix}user_alerts AS a
			LEFT JOIN {db_prefix}members AS mem ON (a.id_member_started = mem.id_member)' . ($with_avatar ? '
			LEFT JOIN {db_prefix}attachments AS f ON (mem.id_member = f.id_member)' : '') . '
		WHERE a.id_member = {int:id_member}' . ($unread ? '
			AND a.is_read = 0' : '') . (!empty($alertIDs) ? '
			AND a.id_alert IN ({array_int:alertIDs})' : '') . '
		ORDER BY id_alert DESC' . (!empty($limit) ? '
		LIMIT {int:limit}' : '') . (!empty($offset) ?'
		OFFSET {int:offset}' : ''),
		array(
			'id_member' => $memID,
			'alertIDs' => $alertIDs,
			'limit' => $limit,
			'offset' => $offset,
		)
	);
	while ($row = Db::$db->fetch_assoc($request))
	{
		$id_alert = array_shift($row);
		$row['time'] = timeformat($row['alert_time']);
		$row['extra'] = !empty($row['extra']) ? Utils::jsonDecode($row['extra'], true) : array();
		$alerts[$id_alert] = $row;

		if (!empty($row['sender_email']))
		{
			$senders[$row['sender_id']] = array(
				'email' => $row['sender_email'],
				'avatar' => $row['sender_avatar'],
				'filename' => $row['sender_filename'],
			);
		}

		if ($row['content_type'] == 'profile')
		{
			$profiles[] = $row['content_id'];
			$profile_alerts[] = $id_alert;
		}

		// For these types, we need to check whether they can actually see the content.
		if ($row['content_type'] == 'msg')
		{
			$alerts[$id_alert]['visible'] = false;
			$possible_msgs[$id_alert] = $row['content_id'];
		}
		elseif (in_array($row['content_type'], array('topic', 'board')))
		{
			$alerts[$id_alert]['visible'] = false;
			$possible_topics[$id_alert] = $row['content_id'];
		}
		// For the rest, they can always see it.
		else
			$alerts[$id_alert]['visible'] = true;

		// Are we showing multiple links or one big main link ?
		$alerts[$id_alert]['show_links'] = $show_links || (isset($row['extra']['show_links']) && $row['extra']['show_links']);

		// Set an appropriate icon.
		$alerts[$id_alert]['icon'] = set_alert_icon($alerts[$id_alert]);
	}
	Db::$db->free_result($request);

	// Look up member info of anyone we need it for.
	if (!empty($profiles))
		User::load($profiles, User::LOAD_BY_ID, 'minimal');

	// Get the senders' avatars.
	if ($with_avatar)
	{
		foreach ($senders as $sender_id => $sender)
			$senders[$sender_id]['avatar'] = User::setAvatarData($sender);

		Utils::$context['avatar_url'] = Config::$modSettings['avatar_url'];
	}

	// Now go through and actually make with the text.
	Lang::load('Alerts');

	// Some sprintf formats for generating links/strings.
	// 'required' is an array of keys in $alert['extra'] that should be used to generate the message, ordered to match the sprintf formats.
	// 'link' and 'text' are the sprintf formats that will be used when $alert['show_links'] is true or false, respectively.
	$formats['msg_msg'] = array(
		'required' => array('content_subject', 'topic', 'msg'),
		'link' => '<a href="{scripturl}?topic=%2$d.msg%3$d#msg%3$d">%1$s</a>',
		'text' => '<strong>%1$s</strong>',
	);
	$formats['topic_msg'] = array(
		'required' => array('content_subject', 'topic', 'topic_suffix'),
		'link' => '<a href="{scripturl}?topic=%2$d.%3$s">%1$s</a>',
		'text' => '<strong>%1$s</strong>',
	);
	$formats['board_msg'] = array(
		'required' => array('board_name', 'board'),
		'link' => '<a href="{scripturl}?board=%2$d.0">%1$s</a>',
		'text' => '<strong>%1$s</strong>',
	);
	$formats['profile_msg'] = array(
		'required' => array('user_name', 'user_id'),
		'link' => '<a href="{scripturl}?action=profile;u=%2$d">%1$s</a>',
		'text' => '<strong>%1$s</strong>',
	);

	// Hooks might want to do something snazzy around their own content types - including enforcing permissions if appropriate.
	call_integration_hook('integrate_fetch_alerts', array(&$alerts, &$formats));

	// Substitute Config::$scripturl into the link formats. (Done here to make life easier for hooked mods.)
	$formats = array_map(
		function ($format)
		{
			$format['link'] = str_replace('{scripturl}', Config::$scripturl, $format['link']);
			$format['text'] = str_replace('{scripturl}', Config::$scripturl, $format['text']);

			return $format;
		},
		$formats
	);

	// If we need to check board access, use the correct board access filter for the member in question.
	if ((!isset(User::$me->query_see_board) || User::$me->id != $memID) && (!empty($possible_msgs) || !empty($possible_topics)))
		$qb = User::buildQueryBoard($memID);
	else
		$qb['query_see_board'] = '{query_see_board}';

	// For anything that needs more info and/or wants us to check board or topic access, let's do that.
	if (!empty($possible_msgs))
	{
		$flipped_msgs = array();
		foreach ($possible_msgs as $id_alert => $id_msg)
		{
			if (!isset($flipped_msgs[$id_msg]))
				$flipped_msgs[$id_msg] = array();

			$flipped_msgs[$id_msg][] = $id_alert;
		}

		$request = Db::$db->query('', '
			SELECT m.id_msg, m.id_topic, m.subject, b.id_board, b.name AS board_name
			FROM {db_prefix}messages AS m
				INNER JOIN {db_prefix}boards AS b ON (m.id_board = b.id_board)
			WHERE ' . $qb['query_see_board'] . '
				AND m.id_msg IN ({array_int:msgs})
			ORDER BY m.id_msg',
			array(
				'msgs' => $possible_msgs,
			)
		);
		while ($row = Db::$db->fetch_assoc($request))
		{
			foreach ($flipped_msgs[$row['id_msg']] as $id_alert)
			{
				$alerts[$id_alert]['content_data'] = $row;
				$alerts[$id_alert]['visible'] = true;
			}
		}
		Db::$db->free_result($request);
	}
	if (!empty($possible_topics))
	{
		$flipped_topics = array();
		foreach ($possible_topics as $id_alert => $id_topic)
		{
			if (!isset($flipped_topics[$id_topic]))
				$flipped_topics[$id_topic] = array();

			$flipped_topics[$id_topic][] = $id_alert;
		}

		$request = Db::$db->query('', '
			SELECT m.id_msg, t.id_topic, m.subject, b.id_board, b.name AS board_name
			FROM {db_prefix}topics AS t
				INNER JOIN {db_prefix}messages AS m ON (t.id_first_msg = m.id_msg)
				INNER JOIN {db_prefix}boards AS b ON (t.id_board = b.id_board)
			WHERE ' . $qb['query_see_board'] . '
				AND t.id_topic IN ({array_int:topics})',
			array(
				'topics' => $possible_topics,
			)
		);
		while ($row = Db::$db->fetch_assoc($request))
		{
			foreach ($flipped_topics[$row['id_topic']] as $id_alert)
			{
				$alerts[$id_alert]['content_data'] = $row;
				$alerts[$id_alert]['visible'] = true;
			}
		}
		Db::$db->free_result($request);
	}

	// Now to go back through the alerts, reattach this extra information and then try to build the string out of it (if a hook didn't already)
	foreach ($alerts as $id_alert => $dummy)
	{
		// There's no point showing alerts for inaccessible content.
		if (!$alerts[$id_alert]['visible'])
		{
			unset($alerts[$id_alert]);
			continue;
		}
		else
			unset($alerts[$id_alert]['visible']);

		// Did a mod already take care of this one?
		if (!empty($alerts[$id_alert]['text']))
			continue;

		// For developer convenience.
		$alert = &$alerts[$id_alert];

		// The info in extra might outdated if the topic was moved, the message's subject was changed, etc.
		if (!empty($alert['content_data']))
		{
			$data = $alert['content_data'];

			// Make sure msg, topic, and board info are correct.
			$patterns = array();
			$replacements = array();
			foreach (array('msg', 'topic', 'board') as $item)
			{
				if (isset($data['id_' . $item]))
				{
					$separator = $item == 'msg' ? '=?' : '=';

					if (isset($alert['extra']['content_link']) && strpos($alert['extra']['content_link'], $item . $separator) !== false && strpos($alert['extra']['content_link'], $item . $separator . $data['id_' . $item]) === false)
					{
						$patterns[] = '/\b' . $item . $separator . '\d+/';
						$replacements[] = $item . $separator . $data['id_' . $item];
					}

					$alert['extra'][$item] = $data['id_' . $item];
				}
			}
			if (!empty($patterns))
				$alert['extra']['content_link'] = preg_replace($patterns, $replacements, $alert['extra']['content_link']);

			// Make sure the subject is correct.
			if (isset($data['subject']))
				$alert['extra']['content_subject'] = $data['subject'];

			// Keep track of this so we can use it below.
			if (isset($data['board_name']))
				$alert['extra']['board_name'] = $data['board_name'];

			unset($alert['content_data']);
		}

		// Do we want to link to the topic in general or the new messages specifically?
		if (isset($possible_topics[$id_alert]) && in_array($alert['content_action'], array('reply', 'topic', 'unapproved_reply')))
				$alert['extra']['topic_suffix'] = 'new;topicseen#new';
		elseif (isset($alert['extra']['topic']))
			$alert['extra']['topic_suffix'] = '0';

		// Make sure profile alerts have what they need.
		if (in_array($id_alert, $profile_alerts))
		{
			if (empty($alert['extra']['user_id']))
				$alert['extra']['user_id'] = $alert['content_id'];

			if (isset(User::$loaded[$alert['extra']['user_id']]))
				$alert['extra']['user_name'] = User::$loaded[$alert['extra']['user_id']]->name;
		}

		// If we loaded the sender's profile, we may as well use it.
		$sender_id = !empty($alert['sender_id']) ? $alert['sender_id'] : 0;
		if (isset(User::$loaded[$sender_id]))
			$alert['sender_name'] = User::$loaded[$sender_id]->name;

		// If requested, include the sender's avatar data.
		if ($with_avatar && !empty($senders[$sender_id]))
			$alert['sender'] = $senders[$sender_id];

		// Next, build the message strings.
		foreach ($formats as $msg_type => $format_info)
		{
			// Get the values to use in the formatted string, in the right order.
			$msg_values = array_replace(
				array_fill_keys($format_info['required'], ''),
				array_intersect_key($alert['extra'], array_flip($format_info['required']))
			);

			// Assuming all required values are present, build the message.
			if (!in_array('', $msg_values))
				$alert['extra'][$msg_type] = vsprintf($formats[$msg_type][$alert['show_links'] ? 'link' : 'text'], $msg_values);

			elseif (in_array($msg_type, array('msg_msg', 'topic_msg', 'board_msg')))
				$alert['extra'][$msg_type] = Lang::$txt[$msg_type == 'board_msg' ? 'board_na' : 'topic_na'];
			else
				$alert['extra'][$msg_type] = '(' . Lang::$txt['not_applicable'] . ')';
		}

		// Show the formatted time in alerts about subscriptions.
		if ($alert['content_type'] == 'paidsubs' && isset($alert['extra']['end_time']))
		{
			// If the subscription already expired, say so.
			if ($alert['extra']['end_time'] < time())
				$alert['content_action'] = 'expired';

			// Present a nicely formatted date.
			$alert['extra']['end_time'] = timeformat($alert['extra']['end_time']);
		}

		// Now set the main URL that this alert should take the user to.
		$alert['target_href'] = '';

		// Priority goes to explicitly specified links.
		if (isset($alert['extra']['content_link']))
			$alert['target_href'] = $alert['extra']['content_link'];

		elseif (isset($alert['extra']['report_link']))
			$alert['target_href'] = Config::$scripturl . $alert['extra']['report_link'];

		// Next, try determining the link based on the content action.
		if (empty($alert['target_href']) && in_array($alert['content_action'], array('register_approval', 'group_request', 'buddy_request')))
		{
			switch ($alert['content_action'])
			{
				case 'register_approval':
					$alert['target_href'] = Config::$scripturl . '?action=admin;area=viewmembers;sa=browse;type=approve';
					break;

				case 'group_request':
					$alert['target_href'] = Config::$scripturl . '?action=moderate;area=groups;sa=requests';
					break;

				case 'buddy_request':
					if (!empty($alert['id_member_started']))
						$alert['target_href'] = Config::$scripturl . '?action=profile;u=' . $alert['id_member_started'];
					break;

				default:
					break;
			}
		}

		// Or maybe we can determine the link based on the content type.
		if (empty($alert['target_href']) && in_array($alert['content_type'], array('msg', 'member', 'event')))
		{
			switch ($alert['content_type'])
			{
				case 'msg':
					if (!empty($alert['content_id']))
						$alert['target_href'] = Config::$scripturl . '?msg=' . $alert['content_id'];
					break;

				case 'member':
					if (!empty($alert['id_member_started']))
						$alert['target_href'] = Config::$scripturl . '?action=profile;u=' . $alert['id_member_started'];
					break;

				case 'event':
					if (!empty($alert['extra']['event_id']))
						$alert['target_href'] = Config::$scripturl . '?action=calendar;event=' . $alert['extra']['event_id'];
					break;

				default:
					break;
			}

		}

		// Finally, set this alert's text string.
		$string = 'alert_' . $alert['content_type'] . '_' . $alert['content_action'];

		// This kludge exists because the alert content_types prior to 2.1 RC3 were a bit haphazard.
		// This can be removed once all the translated language files have been updated.
		if (!isset(Lang::$txt[$string]))
		{
			if (strpos($alert['content_action'], 'unapproved_') === 0)
				$string = 'alert_' . $alert['content_action'];

			if ($alert['content_type'] === 'member' && in_array($alert['content_action'], array('report', 'report_reply')))
				$string = 'alert_profile_' . $alert['content_action'];

			if ($alert['content_type'] === 'member' && $alert['content_action'] === 'buddy_request')
				$string = 'alert_buddy_' . $alert['content_action'];
		}

		if (isset(Lang::$txt[$string]))
		{
			$substitutions = array(
				'{scripturl}' => Config::$scripturl,
				'{member_link}' => !empty($sender_id) && $alert['show_links'] ? '<a href="' . Config::$scripturl . '?action=profile;u=' . $sender_id . '">' . $alert['sender_name'] . '</a>' : '<strong>' . $alert['sender_name'] . '</strong>',
			);

			if (is_array($alert['extra']))
			{
				foreach ($alert['extra'] as $k => $v)
					$substitutions['{' . $k . '}'] = $v;
			}

			$alert['text'] = strtr(Lang::$txt[$string], $substitutions);
		}

		// Unset the reference variable to avoid any surprises in subsequent loops.
		unset($alert);
	}

	return $alerts;
}

/**
 * Shows all alerts for a member
 *
 * @param int $memID The ID of the member
 */
function showAlerts($memID)
{
	global $options;

	require_once(Config::$sourcedir . '/Profile-Modify.php');

	// Are we opening a specific alert? (i.e.: ?action=profile;area=showalerts;alert=12345)
	if (!empty($_REQUEST['alert']))
	{
		$alert_id = (int) $_REQUEST['alert'];
		$alerts = fetch_alerts($memID, $alert_id);
		$alert = array_pop($alerts);

		/*
		 * MOD AUTHORS:
		 * To control this redirect, use the 'integrate_fetch_alerts' hook to
		 * set the value of $alert['extra']['content_link'], which will become
		 * the value for $alert['target_href'].
		 */

		// In case it failed to determine this alert's link
		if (empty($alert['target_href']))
			redirectexit('action=profile;area=showalerts');

		// Mark the alert as read while we're at it.
		alert_mark($memID, $alert_id, 1);

		// Take the user to the content
		redirectexit($alert['target_href']);
	}

	// Prepare the pagination vars.
	$maxIndex = !empty(Config::$modSettings['alerts_per_page']) && (int) Config::$modSettings['alerts_per_page'] < 1000 ? min((int) Config::$modSettings['alerts_per_page'], 1000) : 25;
	Utils::$context['start'] = (int) isset($_REQUEST['start']) ? $_REQUEST['start'] : 0;
	$count = alert_count($memID);

	// Fix invalid 'start' offsets.
	if (Utils::$context['start'] > $count)
		Utils::$context['start'] = $count - ($count % $maxIndex);
	else
		Utils::$context['start'] = Utils::$context['start'] - (Utils::$context['start'] % $maxIndex);

	// Get the alerts.
	Utils::$context['alerts'] = fetch_alerts($memID, true, $maxIndex, Utils::$context['start'], true, true);
	$toMark = false;
	$action = '';

	//  Are we using checkboxes?
	Utils::$context['showCheckboxes'] = !empty($options['display_quick_mod']) && $options['display_quick_mod'] == 1;

	// Create the pagination.
	Utils::$context['pagination'] = constructPageIndex(Config::$scripturl . '?action=profile;area=showalerts;u=' . $memID, Utils::$context['start'], $count, $maxIndex, false);

	// Set some JavaScript for checking all alerts at once.
	if (Utils::$context['showCheckboxes'])
		addInlineJavaScript('
		$(function(){
			$(\'#select_all\').on(\'change\', function() {
				var checkboxes = $(\'ul.quickbuttons\').find(\':checkbox\');
				if($(this).prop(\'checked\')) {
					checkboxes.prop(\'checked\', true);
				}
				else {
					checkboxes.prop(\'checked\', false);
				}
			});
		});', true);

	// The quickbuttons
	foreach (Utils::$context['alerts'] as $id => $alert)
	{
		Utils::$context['alerts'][$id]['quickbuttons'] = array(
			'delete' => array(
				'label' => Lang::$txt['delete'],
				'href' => Config::$scripturl . '?action=profile;u=' . Utils::$context['id_member'] . ';area=showalerts;do=remove;aid=' . $id . ';' . Utils::$context['session_var'] . '=' . Utils::$context['session_id'] . (!empty(Utils::$context['start']) ? ';start=' . Utils::$context['start'] : ''),
				'class' => 'you_sure',
				'icon' => 'remove_button'
			),
			'mark' => array(
				'label' => $alert['is_read'] != 0 ? Lang::$txt['mark_unread'] : Lang::$txt['mark_read_short'],
				'href' => Config::$scripturl . '?action=profile;u=' . Utils::$context['id_member'] . ';area=showalerts;do=' . ($alert['is_read'] != 0 ? 'unread' : 'read') . ';aid=' . $id . ';' . Utils::$context['session_var'] . '=' . Utils::$context['session_id'] . (!empty(Utils::$context['start']) ? ';start=' . Utils::$context['start'] : ''),
				'icon' => $alert['is_read'] != 0 ? 'unread_button' : 'read_button',
			),
			'view' => array(
				'label' => Lang::$txt['view'],
				'href' => Config::$scripturl . '?action=profile;area=showalerts;alert=' . $id . ';',
				'icon' => 'move',
			),
			'quickmod' => array(
    			'class' => 'inline_mod_check',
				'content' => '<input type="checkbox" name="mark[' . $id . ']" value="' . $id . '">',
				'show' => Utils::$context['showCheckboxes']
			)
		);
	}

	// The Delete all unread link.
	Utils::$context['alert_purge_link'] = Config::$scripturl . '?action=profile;u=' . Utils::$context['id_member'] . ';area=showalerts;do=purge;' . Utils::$context['session_var'] . '=' . Utils::$context['session_id'] . (!empty(Utils::$context['start']) ? ';start=' . Utils::$context['start'] : '');

	// Set a nice message.
	if (!empty($_SESSION['update_message']))
	{
		Utils::$context['update_message'] = Lang::$txt['profile_updated_own'];
		unset($_SESSION['update_message']);
	}

	// Saving multiple changes?
	if (isset($_GET['save']) && !empty($_POST['mark']))
	{
		// Get the values.
		$toMark = array_map('intval', (array) $_POST['mark']);

		// Which action?
		$action = !empty($_POST['mark_as']) ? Utils::htmlspecialchars(Utils::htmlTrim($_POST['mark_as'])) : '';
	}

	// A single change.
	if (!empty($_GET['do']) && !empty($_GET['aid']))
	{
		$toMark = (int) $_GET['aid'];
		$action = Utils::htmlspecialchars(Utils::htmlTrim($_GET['do']));
	}
	// Delete all read alerts.
	elseif (!empty($_GET['do']) && $_GET['do'] === 'purge')
		$action = 'purge';

	// Save the changes.
	if (!empty($action) && (!empty($toMark) || $action === 'purge'))
	{
		checkSession('request');

		// Call it!
		if ($action == 'remove')
			alert_delete($toMark, $memID);

		elseif ($action == 'purge')
			alert_purge($memID);

		else
			alert_mark($memID, $toMark, $action == 'read' ? 1 : 0);

		// Set a nice update message.
		$_SESSION['update_message'] = true;

		// Redirect.
		redirectexit('action=profile;area=showalerts;u=' . $memID . (!empty(Utils::$context['start']) ? ';start=' . Utils::$context['start'] : ''));
	}
}

/**
 * Show all posts by a member
 *
 * @todo This function needs to be split up properly.
 *
 * @param int $memID The ID of the member
 */
function showPosts($memID)
{
	global $options;

	// Some initial context.
	Utils::$context['start'] = (int) $_REQUEST['start'];
	Utils::$context['current_member'] = $memID;

	// Create the tabs for the template.
	Utils::$context[Utils::$context['profile_menu_name']]['tab_data'] = array(
		'title' => Lang::$txt['showPosts'],
		'description' => Lang::$txt['showPosts_help'],
		'icon_class' => 'main_icons profile_hd',
		'tabs' => array(
			'messages' => array(
			),
			'topics' => array(
			),
			'unwatchedtopics' => array(
			),
			'attach' => array(
			),
		),
	);

	// Shortcut used to determine which Lang::$txt['show*'] string to use for the title, based on the SA
	$title = array(
		'attach' => 'Attachments',
		'topics' => 'Topics'
	);

	if (User::$me->is_owner)
		$title['unwatchedtopics'] = 'Unwatched';

	// Set the page title
	if (isset($_GET['sa']) && array_key_exists($_GET['sa'], $title))
		Utils::$context['page_title'] = Lang::$txt['show' . $title[$_GET['sa']]];
	else
		Utils::$context['page_title'] = Lang::$txt['showPosts'];

	Utils::$context['page_title'] .= ' - ' . User::$loaded[$memID]->name;

	// Is the load average too high to allow searching just now?
	if (!empty(Utils::$context['load_average']) && !empty(Config::$modSettings['loadavg_show_posts']) && Utils::$context['load_average'] >= Config::$modSettings['loadavg_show_posts'])
		fatal_lang_error('loadavg_show_posts_disabled', false);

	// If we're specifically dealing with attachments use that function!
	if (isset($_GET['sa']) && $_GET['sa'] == 'attach')
		return showAttachments($memID);
	// Instead, if we're dealing with unwatched topics (and the feature is enabled) use that other function.
	elseif (isset($_GET['sa']) && $_GET['sa'] == 'unwatchedtopics' && User::$me->is_owner)
		return showUnwatched($memID);

	// Are we just viewing topics?
	Utils::$context['is_topics'] = isset($_GET['sa']) && $_GET['sa'] == 'topics' ? true : false;

	// If just deleting a message, do it and then redirect back.
	if (isset($_GET['delete']) && !Utils::$context['is_topics'])
	{
		checkSession('get');

		// We need msg info for logging.
		$request = Db::$db->query('', '
			SELECT subject, id_member, id_topic, id_board
			FROM {db_prefix}messages
			WHERE id_msg = {int:id_msg}',
			array(
				'id_msg' => (int) $_GET['delete'],
			)
		);
		$info = Db::$db->fetch_row($request);
		Db::$db->free_result($request);

		// Trying to remove a message that doesn't exist.
		if (empty($info))
			redirectexit('action=profile;u=' . $memID . ';area=showposts;start=' . $_GET['start']);

		// We can be lazy, since removeMessage() will check the permissions for us.
		require_once(Config::$sourcedir . '/RemoveTopic.php');
		removeMessage((int) $_GET['delete']);

		// Add it to the mod log.
		if (allowedTo('delete_any') && (!allowedTo('delete_own') || $info[1] != User::$me->id))
			logAction('delete', array('topic' => $info[2], 'subject' => $info[0], 'member' => $info[1], 'board' => $info[3]));

		// Back to... where we are now ;).
		redirectexit('action=profile;u=' . $memID . ';area=showposts;start=' . $_GET['start']);
	}

	// Default to 10.
	if (empty($_REQUEST['viewscount']) || !is_numeric($_REQUEST['viewscount']))
		$_REQUEST['viewscount'] = '10';

	if (Utils::$context['is_topics'])
		$request = Db::$db->query('', '
			SELECT COUNT(*)
			FROM {db_prefix}topics AS t' . '
			WHERE {query_see_topic_board}
				AND t.id_member_started = {int:current_member}' . (!empty(Board::$info->id) ? '
				AND t.id_board = {int:board}' : '') . (!Config::$modSettings['postmod_active'] || User::$me->is_owner ? '' : '
				AND t.approved = {int:is_approved}'),
			array(
				'current_member' => $memID,
				'is_approved' => 1,
				'board' => Board::$info->id,
			)
		);
	else
		$request = Db::$db->query('', '
			SELECT COUNT(*)
			FROM {db_prefix}messages AS m' . (!Config::$modSettings['postmod_active'] || User::$me->is_owner ? '' : '
				INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)') . '
			WHERE {query_see_message_board} AND m.id_member = {int:current_member}' . (!empty(Board::$info->id) ? '
				AND m.id_board = {int:board}' : '') . (!Config::$modSettings['postmod_active'] || User::$me->is_owner ? '' : '
				AND m.approved = {int:is_approved}
				AND t.approved = {int:is_approved}'),
			array(
				'current_member' => $memID,
				'is_approved' => 1,
				'board' => Board::$info->id,
			)
		);
	list ($msgCount) = Db::$db->fetch_row($request);
	Db::$db->free_result($request);

	$request = Db::$db->query('', '
		SELECT MIN(id_msg), MAX(id_msg)
		FROM {db_prefix}messages AS m' . (!Config::$modSettings['postmod_active'] || User::$me->is_owner ? '' : '
			INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)') . '
		WHERE m.id_member = {int:current_member}' . (!empty(Board::$info->id) ? '
			AND m.id_board = {int:board}' : '') . (!Config::$modSettings['postmod_active'] || User::$me->is_owner ? '' : '
			AND m.approved = {int:is_approved}
			AND t.approved = {int:is_approved}'),
		array(
			'current_member' => $memID,
			'is_approved' => 1,
			'board' => Board::$info->id,
		)
	);
	list ($min_msg_member, $max_msg_member) = Db::$db->fetch_row($request);
	Db::$db->free_result($request);

	$range_limit = '';

	if (Utils::$context['is_topics'])
		$maxPerPage = empty(Config::$modSettings['disableCustomPerPage']) && !empty($options['topics_per_page']) ? $options['topics_per_page'] : Config::$modSettings['defaultMaxTopics'];
	else
		$maxPerPage = empty(Config::$modSettings['disableCustomPerPage']) && !empty($options['messages_per_page']) ? $options['messages_per_page'] : Config::$modSettings['defaultMaxMessages'];

	$maxIndex = $maxPerPage;

	// Make sure the starting place makes sense and construct our friend the page index.
	Utils::$context['page_index'] = constructPageIndex(Config::$scripturl . '?action=profile;u=' . $memID . ';area=showposts' . (Utils::$context['is_topics'] ? ';sa=topics' : '') . (!empty(Board::$info->id) ? ';board=' . Board::$info->id : ''), Utils::$context['start'], $msgCount, $maxIndex);
	Utils::$context['current_page'] = Utils::$context['start'] / $maxIndex;

	// Reverse the query if we're past 50% of the pages for better performance.
	$start = Utils::$context['start'];
	$reverse = $_REQUEST['start'] > $msgCount / 2;
	if ($reverse)
	{
		$maxIndex = $msgCount < Utils::$context['start'] + $maxPerPage + 1 && $msgCount > Utils::$context['start'] ? $msgCount - Utils::$context['start'] : $maxPerPage;
		$start = $msgCount < Utils::$context['start'] + $maxPerPage + 1 || $msgCount < Utils::$context['start'] + $maxPerPage ? 0 : $msgCount - Utils::$context['start'] - $maxPerPage;
	}

	// Guess the range of messages to be shown.
	if ($msgCount > 1000)
	{
		$margin = floor(($max_msg_member - $min_msg_member) * (($start + $maxPerPage) / $msgCount) + .1 * ($max_msg_member - $min_msg_member));
		// Make a bigger margin for topics only.
		if (Utils::$context['is_topics'])
		{
			$margin *= 5;
			$range_limit = $reverse ? 't.id_first_msg < ' . ($min_msg_member + $margin) : 't.id_first_msg > ' . ($max_msg_member - $margin);
		}
		else
			$range_limit = $reverse ? 'm.id_msg < ' . ($min_msg_member + $margin) : 'm.id_msg > ' . ($max_msg_member - $margin);
	}

	// Find this user's posts.  The left join on categories somehow makes this faster, weird as it looks.
	$looped = false;
	while (true)
	{
		if (Utils::$context['is_topics'])
		{
			$request = Db::$db->query('', '
				SELECT
					b.id_board, b.name AS bname, c.id_cat, c.name AS cname, t.id_member_started, t.id_first_msg, t.id_last_msg,
					t.approved, m.body, m.smileys_enabled, m.subject, m.poster_time, m.id_topic, m.id_msg
				FROM {db_prefix}topics AS t
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
					LEFT JOIN {db_prefix}categories AS c ON (c.id_cat = b.id_cat)
					INNER JOIN {db_prefix}messages AS m ON (m.id_msg = t.id_first_msg)
				WHERE t.id_member_started = {int:current_member}' . (!empty(Board::$info->id) ? '
					AND t.id_board = {int:board}' : '') . (empty($range_limit) ? '' : '
					AND ' . $range_limit) . '
					AND {query_see_board}' . (!Config::$modSettings['postmod_active'] || User::$me->is_owner ? '' : '
					AND t.approved = {int:is_approved} AND m.approved = {int:is_approved}') . '
				ORDER BY t.id_first_msg ' . ($reverse ? 'ASC' : 'DESC') . '
				LIMIT {int:start}, {int:max}',
				array(
					'current_member' => $memID,
					'is_approved' => 1,
					'board' => Board::$info->id,
					'start' => $start,
					'max' => $maxIndex,
				)
			);
		}
		else
		{
			$request = Db::$db->query('', '
				SELECT
					b.id_board, b.name AS bname, c.id_cat, c.name AS cname, m.id_topic, m.id_msg,
					t.id_member_started, t.id_first_msg, t.id_last_msg, m.body, m.smileys_enabled,
					m.subject, m.poster_time, m.approved
				FROM {db_prefix}messages AS m
					INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
					LEFT JOIN {db_prefix}categories AS c ON (c.id_cat = b.id_cat)
				WHERE m.id_member = {int:current_member}' . (!empty(Board::$info->id) ? '
					AND b.id_board = {int:board}' : '') . (empty($range_limit) ? '' : '
					AND ' . $range_limit) . '
					AND {query_see_board}' . (!Config::$modSettings['postmod_active'] || User::$me->is_owner ? '' : '
					AND t.approved = {int:is_approved} AND m.approved = {int:is_approved}') . '
				ORDER BY m.id_msg ' . ($reverse ? 'ASC' : 'DESC') . '
				LIMIT {int:start}, {int:max}',
				array(
					'current_member' => $memID,
					'is_approved' => 1,
					'board' => Board::$info->id,
					'start' => $start,
					'max' => $maxIndex,
				)
			);
		}

		// Make sure we quit this loop.
		if (Db::$db->num_rows($request) === $maxIndex || $looped || $range_limit == '')
			break;
		$looped = true;
		$range_limit = '';
	}

	// Start counting at the number of the first message displayed.
	$counter = $reverse ? Utils::$context['start'] + $maxIndex + 1 : Utils::$context['start'];
	Utils::$context['posts'] = array();
	$board_ids = array('own' => array(), 'any' => array());
	while ($row = Db::$db->fetch_assoc($request))
	{
		// Censor....
		Lang::censorText($row['body']);
		Lang::censorText($row['subject']);

		// Do the code.
		$row['body'] = BBCodeParser::load()->parse($row['body'], $row['smileys_enabled'], $row['id_msg']);

		// And the array...
		Utils::$context['posts'][$counter += $reverse ? -1 : 1] = array(
			'body' => $row['body'],
			'counter' => $counter,
			'category' => array(
				'name' => $row['cname'],
				'id' => $row['id_cat']
			),
			'board' => array(
				'name' => $row['bname'],
				'id' => $row['id_board']
			),
			'topic' => $row['id_topic'],
			'subject' => $row['subject'],
			'start' => 'msg' . $row['id_msg'],
			'time' => timeformat($row['poster_time']),
			'timestamp' => $row['poster_time'],
			'id' => $row['id_msg'],
			'can_reply' => false,
			'can_mark_notify' => !User::$me->is_guest,
			'can_delete' => false,
			'delete_possible' => ($row['id_first_msg'] != $row['id_msg'] || $row['id_last_msg'] == $row['id_msg']) && (empty(Config::$modSettings['edit_disable_time']) || $row['poster_time'] + Config::$modSettings['edit_disable_time'] * 60 >= time()),
			'approved' => $row['approved'],
			'css_class' => $row['approved'] ? 'windowbg' : 'approvebg',
		);

		if (User::$me->id == $row['id_member_started'])
			$board_ids['own'][$row['id_board']][] = $counter;
		$board_ids['any'][$row['id_board']][] = $counter;
	}
	Db::$db->free_result($request);

	// All posts were retrieved in reverse order, get them right again.
	if ($reverse)
		Utils::$context['posts'] = array_reverse(Utils::$context['posts'], true);

	// These are all the permissions that are different from board to board..
	if (Utils::$context['is_topics'])
		$permissions = array(
			'own' => array(
				'post_reply_own' => 'can_reply',
			),
			'any' => array(
				'post_reply_any' => 'can_reply',
			)
		);
	else
		$permissions = array(
			'own' => array(
				'post_reply_own' => 'can_reply',
				'delete_own' => 'can_delete',
			),
			'any' => array(
				'post_reply_any' => 'can_reply',
				'delete_any' => 'can_delete',
			)
		);

	// Create an array for the permissions.
	$boards_can = boardsAllowedTo(array_keys(iterator_to_array(
		new RecursiveIteratorIterator(new RecursiveArrayIterator($permissions)))
	), true, false);

	// For every permission in the own/any lists...
	foreach ($permissions as $type => $list)
	{
		foreach ($list as $permission => $allowed)
		{
			// Get the boards they can do this on...
			$boards = $boards_can[$permission];

			// Hmm, they can do it on all boards, can they?
			if (!empty($boards) && $boards[0] == 0)
				$boards = array_keys($board_ids[$type]);

			// Now go through each board they can do the permission on.
			foreach ($boards as $board_id)
			{
				// There aren't any posts displayed from this board.
				if (!isset($board_ids[$type][$board_id]))
					continue;

				// Set the permission to true ;).
				foreach ($board_ids[$type][$board_id] as $counter)
					Utils::$context['posts'][$counter][$allowed] = true;
			}
		}
	}

	// Clean up after posts that cannot be deleted and quoted.
	$quote_enabled = empty(Config::$modSettings['disabledBBC']) || !in_array('quote', explode(',', Config::$modSettings['disabledBBC']));
	foreach (Utils::$context['posts'] as $counter => $dummy)
	{
		Utils::$context['posts'][$counter]['can_delete'] &= Utils::$context['posts'][$counter]['delete_possible'];
		Utils::$context['posts'][$counter]['can_quote'] = Utils::$context['posts'][$counter]['can_reply'] && $quote_enabled;
	}

	// Allow last minute changes.
	call_integration_hook('integrate_profile_showPosts');

	foreach (Utils::$context['posts'] as $key => $post)
	{
		Utils::$context['posts'][$key]['quickbuttons'] = array(
			'reply' => array(
				'label' => Lang::$txt['reply'],
				'href' => Config::$scripturl.'?action=post;topic='.$post['topic'].'.'.$post['start'],
				'icon' => 'reply_button',
				'show' => $post['can_reply']
			),
			'quote' => array(
				'label' => Lang::$txt['quote_action'],
				'href' => Config::$scripturl.'?action=post;topic='.$post['topic'].'.'.$post['start'].';quote='.$post['id'],
				'icon' => 'quote',
				'show' => $post['can_quote']
			),
			'remove' => array(
				'label' => Lang::$txt['remove'],
				'href' => Config::$scripturl.'?action=deletemsg;msg='.$post['id'].';topic='.$post['topic'].';profile;u='.Utils::$context['member']['id'].';start='.Utils::$context['start'].';'.Utils::$context['session_var'].'='.Utils::$context['session_id'],
				'javascript' => 'data-confirm="'.Lang::$txt['remove_message'].'"',
				'class' => 'you_sure',
				'icon' => 'remove_button',
				'show' => $post['can_delete']
			)
		);
	}
}

/**
 * Show all the attachments belonging to a member.
 *
 * @param int $memID The ID of the member
 */
function showAttachments($memID)
{
	// OBEY permissions!
	$boardsAllowed = boardsAllowedTo('view_attachments');

	// Make sure we can't actually see anything...
	if (empty($boardsAllowed))
		$boardsAllowed = array(-1);

	require_once(Config::$sourcedir . '/Subs-List.php');

	// This is all the information required to list attachments.
	$listOptions = array(
		'id' => 'attachments',
		'width' => '100%',
		'items_per_page' => Config::$modSettings['defaultMaxListItems'],
		'no_items_label' => Lang::$txt['show_attachments_none'],
		'base_href' => Config::$scripturl . '?action=profile;area=showposts;sa=attach;u=' . $memID,
		'default_sort_col' => 'filename',
		'get_items' => array(
			'function' => 'list_getAttachments',
			'params' => array(
				$boardsAllowed,
				$memID,
			),
		),
		'get_count' => array(
			'function' => 'list_getNumAttachments',
			'params' => array(
				$boardsAllowed,
				$memID,
			),
		),
		'data_check' => array(
			'class' => function($data)
			{
				return $data['approved'] ? '' : 'approvebg';
			}
		),
		'columns' => array(
			'filename' => array(
				'header' => array(
					'value' => Lang::$txt['show_attach_filename'],
					'class' => 'lefttext',
					'style' => 'width: 25%;',
				),
				'data' => array(
					'sprintf' => array(
						'format' => '<a href="' . Config::$scripturl . '?action=dlattach;topic=%1$d.0;attach=%2$d">%3$s</a>%4$s',
						'params' => array(
							'topic' => true,
							'id' => true,
							'filename' => false,
							'awaiting_approval' => false,
						),
					),
				),
				'sort' => array(
					'default' => 'a.filename',
					'reverse' => 'a.filename DESC',
				),
			),
			'downloads' => array(
				'header' => array(
					'value' => Lang::$txt['show_attach_downloads'],
					'style' => 'width: 12%;',
				),
				'data' => array(
					'db' => 'downloads',
					'comma_format' => true,
				),
				'sort' => array(
					'default' => 'a.downloads',
					'reverse' => 'a.downloads DESC',
				),
			),
			'subject' => array(
				'header' => array(
					'value' => Lang::$txt['message'],
					'class' => 'lefttext',
					'style' => 'width: 30%;',
				),
				'data' => array(
					'sprintf' => array(
						'format' => '<a href="' . Config::$scripturl . '?msg=%1$d">%2$s</a>',
						'params' => array(
							'msg' => true,
							'subject' => false,
						),
					),
				),
				'sort' => array(
					'default' => 'm.subject',
					'reverse' => 'm.subject DESC',
				),
			),
			'posted' => array(
				'header' => array(
					'value' => Lang::$txt['show_attach_posted'],
					'class' => 'lefttext',
				),
				'data' => array(
					'db' => 'posted',
					'timeformat' => true,
				),
				'sort' => array(
					'default' => 'm.poster_time',
					'reverse' => 'm.poster_time DESC',
				),
			),
		),
	);

	// Create the request list.
	createList($listOptions);
}

/**
 * Get a list of attachments for a member. Callback for the list in showAttachments()
 *
 * @param int $start Which item to start with (for pagination purposes)
 * @param int $items_per_page How many items to show on each page
 * @param string $sort A string indicating how to sort the results
 * @param array $boardsAllowed An array containing the IDs of the boards they can see
 * @param int $memID The ID of the member
 * @return array An array of information about the attachments
 */
function list_getAttachments($start, $items_per_page, $sort, $boardsAllowed, $memID)
{
	// Retrieve some attachments.
	$request = Db::$db->query('', '
		SELECT a.id_attach, a.id_msg, a.filename, a.downloads, a.approved, m.id_msg, m.id_topic,
			m.id_board, m.poster_time, m.subject, b.name
		FROM {db_prefix}attachments AS a
			INNER JOIN {db_prefix}messages AS m ON (m.id_msg = a.id_msg)
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board AND {query_see_board})
		WHERE a.attachment_type = {int:attachment_type}
			AND a.id_msg != {int:no_message}
			AND m.id_member = {int:current_member}' . (!empty(Board::$info->id) ? '
			AND b.id_board = {int:board}' : '') . (!in_array(0, $boardsAllowed) ? '
			AND b.id_board IN ({array_int:boards_list})' : '') . (!Config::$modSettings['postmod_active'] || allowedTo('approve_posts') || User::$me->is_owner ? '' : '
			AND a.approved = {int:is_approved}') . '
		ORDER BY {raw:sort}
		LIMIT {int:offset}, {int:limit}',
		array(
			'boards_list' => $boardsAllowed,
			'attachment_type' => 0,
			'no_message' => 0,
			'current_member' => $memID,
			'is_approved' => 1,
			'board' => Board::$info->id,
			'sort' => $sort,
			'offset' => $start,
			'limit' => $items_per_page,
		)
	);
	$attachments = array();
	while ($row = Db::$db->fetch_assoc($request))
		$attachments[] = array(
			'id' => $row['id_attach'],
			'filename' => $row['filename'],
			'downloads' => $row['downloads'],
			'subject' => Lang::censorText($row['subject']),
			'posted' => $row['poster_time'],
			'msg' => $row['id_msg'],
			'topic' => $row['id_topic'],
			'board' => $row['id_board'],
			'board_name' => $row['name'],
			'approved' => $row['approved'],
			'awaiting_approval' => (empty($row['approved']) ? ' <em>(' . Lang::$txt['awaiting_approval'] . ')</em>' : ''),
		);

	Db::$db->free_result($request);

	return $attachments;
}

/**
 * Gets the total number of attachments for a member
 *
 * @param array $boardsAllowed An array of the IDs of the boards they can see
 * @param int $memID The ID of the member
 * @return int The number of attachments
 */
function list_getNumAttachments($boardsAllowed, $memID)
{
	// Get the total number of attachments they have posted.
	$request = Db::$db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}attachments AS a
			INNER JOIN {db_prefix}messages AS m ON (m.id_msg = a.id_msg)
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board AND {query_see_board})' . (!Config::$modSettings['postmod_active'] || User::$me->is_owner || allowedTo('approve_posts') ? '' : '
			INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)') . '
		WHERE a.attachment_type = {int:attachment_type}
			AND a.id_msg != {int:no_message}
			AND m.id_member = {int:current_member}' . (!empty(Board::$info->id) ? '
			AND b.id_board = {int:board}' : '') . (!in_array(0, $boardsAllowed) ? '
			AND b.id_board IN ({array_int:boards_list})' : '') . (!Config::$modSettings['postmod_active'] || User::$me->is_owner || allowedTo('approve_posts') ? '' : '
			AND m.approved = {int:is_approved}
			AND t.approved = {int:is_approved}'),
		array(
			'boards_list' => $boardsAllowed,
			'attachment_type' => 0,
			'no_message' => 0,
			'current_member' => $memID,
			'is_approved' => 1,
			'board' => Board::$info->id,
		)
	);
	list ($attachCount) = Db::$db->fetch_row($request);
	Db::$db->free_result($request);

	return $attachCount;
}

/**
 * Show all the unwatched topics.
 *
 * @param int $memID The ID of the member
 */
function showUnwatched($memID)
{
	global $options;

	// Only the owner can see the list (if the function is enabled of course)
	if (User::$me->id != $memID)
		return;

	require_once(Config::$sourcedir . '/Subs-List.php');

	// And here they are: the topics you don't like
	$listOptions = array(
		'id' => 'unwatched_topics',
		'width' => '100%',
		'items_per_page' => (empty(Config::$modSettings['disableCustomPerPage']) && !empty($options['topics_per_page'])) ? $options['topics_per_page'] : Config::$modSettings['defaultMaxTopics'],
		'no_items_label' => Lang::$txt['unwatched_topics_none'],
		'base_href' => Config::$scripturl . '?action=profile;area=showposts;sa=unwatchedtopics;u=' . $memID,
		'default_sort_col' => 'started_on',
		'get_items' => array(
			'function' => 'list_getUnwatched',
			'params' => array(
				$memID,
			),
		),
		'get_count' => array(
			'function' => 'list_getNumUnwatched',
			'params' => array(
				$memID,
			),
		),
		'columns' => array(
			'subject' => array(
				'header' => array(
					'value' => Lang::$txt['subject'],
					'class' => 'lefttext',
					'style' => 'width: 30%;',
				),
				'data' => array(
					'sprintf' => array(
						'format' => '<a href="' . Config::$scripturl . '?topic=%1$d.0">%2$s</a>',
						'params' => array(
							'id_topic' => false,
							'subject' => false,
						),
					),
				),
				'sort' => array(
					'default' => 'm.subject',
					'reverse' => 'm.subject DESC',
				),
			),
			'started_by' => array(
				'header' => array(
					'value' => Lang::$txt['started_by'],
					'style' => 'width: 15%;',
				),
				'data' => array(
					'db' => 'started_by',
				),
				'sort' => array(
					'default' => 'mem.real_name',
					'reverse' => 'mem.real_name DESC',
				),
			),
			'started_on' => array(
				'header' => array(
					'value' => Lang::$txt['on'],
					'class' => 'lefttext',
					'style' => 'width: 20%;',
				),
				'data' => array(
					'db' => 'started_on',
					'timeformat' => true,
				),
				'sort' => array(
					'default' => 'm.poster_time',
					'reverse' => 'm.poster_time DESC',
				),
			),
			'last_post_by' => array(
				'header' => array(
					'value' => Lang::$txt['last_post'],
					'style' => 'width: 15%;',
				),
				'data' => array(
					'db' => 'last_post_by',
				),
				'sort' => array(
					'default' => 'mem.real_name',
					'reverse' => 'mem.real_name DESC',
				),
			),
			'last_post_on' => array(
				'header' => array(
					'value' => Lang::$txt['on'],
					'class' => 'lefttext',
					'style' => 'width: 20%;',
				),
				'data' => array(
					'db' => 'last_post_on',
					'timeformat' => true,
				),
				'sort' => array(
					'default' => 'm.poster_time',
					'reverse' => 'm.poster_time DESC',
				),
			),
		),
	);

	// Create the request list.
	createList($listOptions);

	Utils::$context['sub_template'] = 'show_list';
	Utils::$context['default_list'] = 'unwatched_topics';
}

/**
 * Gets information about unwatched (disregarded) topics. Callback for the list in show_unwatched
 *
 * @param int $start The item to start with (for pagination purposes)
 * @param int $items_per_page How many items to show on each page
 * @param string $sort A string indicating how to sort the results
 * @param int $memID The ID of the member
 * @return array An array of information about the unwatched topics
 */
function list_getUnwatched($start, $items_per_page, $sort, $memID)
{
	// Get the list of topics we can see
	$request = Db::$db->query('', '
		SELECT lt.id_topic
		FROM {db_prefix}log_topics as lt
			LEFT JOIN {db_prefix}topics as t ON (lt.id_topic = t.id_topic)
			LEFT JOIN {db_prefix}messages as m ON (t.id_first_msg = m.id_msg)' . (in_array($sort, array('mem.real_name', 'mem.real_name DESC', 'mem.poster_time', 'mem.poster_time DESC')) ? '
			LEFT JOIN {db_prefix}members as mem ON (m.id_member = mem.id_member)' : '') . '
		WHERE lt.id_member = {int:current_member}
			AND unwatched = 1
			AND {query_see_message_board}
		ORDER BY {raw:sort}
		LIMIT {int:offset}, {int:limit}',
		array(
			'current_member' => $memID,
			'sort' => $sort,
			'offset' => $start,
			'limit' => $items_per_page,
		)
	);

	$topics = array();
	while ($row = Db::$db->fetch_assoc($request))
		$topics[] = $row['id_topic'];

	Db::$db->free_result($request);

	// Any topics found?
	$topicsInfo = array();
	if (!empty($topics))
	{
		$request = Db::$db->query('', '
			SELECT mf.subject, mf.poster_time as started_on, COALESCE(memf.real_name, mf.poster_name) as started_by, ml.poster_time as last_post_on, COALESCE(meml.real_name, ml.poster_name) as last_post_by, t.id_topic
			FROM {db_prefix}topics AS t
				INNER JOIN {db_prefix}messages AS ml ON (ml.id_msg = t.id_last_msg)
				INNER JOIN {db_prefix}messages AS mf ON (mf.id_msg = t.id_first_msg)
				LEFT JOIN {db_prefix}members AS meml ON (meml.id_member = ml.id_member)
				LEFT JOIN {db_prefix}members AS memf ON (memf.id_member = mf.id_member)
			WHERE t.id_topic IN ({array_int:topics})',
			array(
				'topics' => $topics,
			)
		);
		while ($row = Db::$db->fetch_assoc($request))
			$topicsInfo[] = $row;
		Db::$db->free_result($request);
	}

	return $topicsInfo;
}

/**
 * Count the number of topics in the unwatched list
 *
 * @param int $memID The ID of the member
 * @return int The number of unwatched topics
 */
function list_getNumUnwatched($memID)
{
	// Get the total number of attachments they have posted.
	$request = Db::$db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}log_topics as lt
		LEFT JOIN {db_prefix}topics as t ON (lt.id_topic = t.id_topic)
		WHERE lt.id_member = {int:current_member}
			AND lt.unwatched = 1
			AND {query_see_topic_board}',
		array(
			'current_member' => $memID,
		)
	);
	list ($unwatchedCount) = Db::$db->fetch_row($request);
	Db::$db->free_result($request);

	return $unwatchedCount;
}

/**
 * Gets the user stats for display
 *
 * @param int $memID The ID of the member
 */
function statPanel($memID)
{
	Utils::$context['page_title'] = Lang::$txt['statPanel_showStats'] . ' ' . User::$loaded[$memID]->name;

	// Is the load average too high to allow searching just now?
	if (!empty(Utils::$context['load_average']) && !empty(Config::$modSettings['loadavg_userstats']) && Utils::$context['load_average'] >= Config::$modSettings['loadavg_userstats'])
		fatal_lang_error('loadavg_userstats_disabled', false);

	// General user statistics.
	Utils::$context['time_logged_in'] = User::$loaded[$memID]->time_logged_in;

	Utils::$context['num_posts'] = Lang::numberFormat(User::$loaded[$memID]->posts);

	// Menu tab
	Utils::$context[Utils::$context['profile_menu_name']]['tab_data'] = array(
		'title' => Lang::$txt['statPanel_generalStats'] . ' - ' . Utils::$context['member']['name'],
		'icon' => 'stats_info.png'
	);

	// Number of topics started and Number polls started
	$result = Db::$db->query('', '
		SELECT COUNT(*), COUNT( CASE WHEN id_poll != {int:no_poll} THEN 1 ELSE NULL END )
		FROM {db_prefix}topics
		WHERE id_member_started = {int:current_member}' . (!empty(Config::$modSettings['recycle_enable']) && Config::$modSettings['recycle_board'] > 0 ? '
			AND id_board != {int:recycle_board}' : ''),
		array(
			'current_member' => $memID,
			'recycle_board' => Config::$modSettings['recycle_board'],
			'no_poll' => 0,
		)
	);
	list (Utils::$context['num_topics'], Utils::$context['num_polls']) = Db::$db->fetch_row($result);
	Db::$db->free_result($result);

	// Number polls voted in.
	$result = Db::$db->query('distinct_poll_votes', '
		SELECT COUNT(DISTINCT id_poll)
		FROM {db_prefix}log_polls
		WHERE id_member = {int:current_member}',
		array(
			'current_member' => $memID,
		)
	);
	list (Utils::$context['num_votes']) = Db::$db->fetch_row($result);
	Db::$db->free_result($result);

	// Format the numbers...
	Utils::$context['num_topics'] = Lang::numberFormat(Utils::$context['num_topics']);
	Utils::$context['num_polls'] = Lang::numberFormat(Utils::$context['num_polls']);
	Utils::$context['num_votes'] = Lang::numberFormat(Utils::$context['num_votes']);

	// Grab the board this member posted in most often.
	$result = Db::$db->query('', '
		SELECT
			b.id_board, MAX(b.name) AS name, MAX(b.num_posts) AS num_posts, COUNT(*) AS message_count
		FROM {db_prefix}messages AS m
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
		WHERE m.id_member = {int:current_member}
			AND b.count_posts = {int:count_enabled}
			AND {query_see_board}
		GROUP BY b.id_board
		ORDER BY message_count DESC
		LIMIT 10',
		array(
			'current_member' => $memID,
			'count_enabled' => 0,
		)
	);
	Utils::$context['popular_boards'] = array();
	while ($row = Db::$db->fetch_assoc($result))
	{
		Utils::$context['popular_boards'][$row['id_board']] = array(
			'id' => $row['id_board'],
			'posts' => $row['message_count'],
			'href' => Config::$scripturl . '?board=' . $row['id_board'] . '.0',
			'link' => '<a href="' . Config::$scripturl . '?board=' . $row['id_board'] . '.0">' . $row['name'] . '</a>',
			'posts_percent' => User::$loaded[$memID]->posts == 0 ? 0 : ($row['message_count'] * 100) / User::$loaded[$memID]->posts,
			'total_posts' => $row['num_posts'],
			'total_posts_member' => User::$loaded[$memID]->posts,
		);
	}
	Db::$db->free_result($result);

	// Now get the 10 boards this user has most often participated in.
	$result = Db::$db->query('profile_board_stats', '
		SELECT
			b.id_board, MAX(b.name) AS name, b.num_posts, COUNT(*) AS message_count,
			CASE WHEN COUNT(*) > MAX(b.num_posts) THEN 1 ELSE COUNT(*) / MAX(b.num_posts) END * 100 AS percentage
		FROM {db_prefix}messages AS m
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
		WHERE m.id_member = {int:current_member}
			AND {query_see_board}
		GROUP BY b.id_board, b.num_posts
		ORDER BY percentage DESC
		LIMIT 10',
		array(
			'current_member' => $memID,
		)
	);
	Utils::$context['board_activity'] = array();
	while ($row = Db::$db->fetch_assoc($result))
	{
		Utils::$context['board_activity'][$row['id_board']] = array(
			'id' => $row['id_board'],
			'posts' => $row['message_count'],
			'href' => Config::$scripturl . '?board=' . $row['id_board'] . '.0',
			'link' => '<a href="' . Config::$scripturl . '?board=' . $row['id_board'] . '.0">' . $row['name'] . '</a>',
			'percent' => Lang::numberFormat((float) $row['percentage'], 2),
			'posts_percent' => (float) $row['percentage'],
			'total_posts' => $row['num_posts'],
		);
	}
	Db::$db->free_result($result);

	// Posting activity by time.
	$result = Db::$db->query('user_activity_by_time', '
		SELECT
			HOUR(FROM_UNIXTIME(poster_time + {int:time_offset})) AS hour,
			COUNT(*) AS post_count
		FROM (
			SELECT poster_time, id_msg
			FROM {db_prefix}messages WHERE id_member = {int:current_member}
			ORDER BY id_msg DESC
			LIMIT {int:max_messages}
		) a
		GROUP BY hour',
		array(
			'current_member' => $memID,
			'time_offset' => User::$me->time_offset * 3600,
			'max_messages' => 1001,
		)
	);
	$maxPosts = $realPosts = 0;
	Utils::$context['posts_by_time'] = array();
	while ($row = Db::$db->fetch_assoc($result))
	{
		// Cast as an integer to remove the leading 0.
		$row['hour'] = (int) $row['hour'];

		$maxPosts = max($row['post_count'], $maxPosts);
		$realPosts += $row['post_count'];

		Utils::$context['posts_by_time'][$row['hour']] = array(
			'hour' => $row['hour'],
			'hour_format' => stripos(User::$me->time_format, '%p') === false ? $row['hour'] : date('g a', mktime($row['hour'])),
			'posts' => $row['post_count'],
			'posts_percent' => 0,
			'is_last' => $row['hour'] == 23,
		);
	}
	Db::$db->free_result($result);

	if ($maxPosts > 0)
		for ($hour = 0; $hour < 24; $hour++)
		{
			if (!isset(Utils::$context['posts_by_time'][$hour]))
				Utils::$context['posts_by_time'][$hour] = array(
					'hour' => $hour,
					'hour_format' => stripos(User::$me->time_format, '%p') === false ? $hour : date('g a', mktime($hour)),
					'posts' => 0,
					'posts_percent' => 0,
					'relative_percent' => 0,
					'is_last' => $hour == 23,
				);
			else
			{
				Utils::$context['posts_by_time'][$hour]['posts_percent'] = round((Utils::$context['posts_by_time'][$hour]['posts'] * 100) / $realPosts);
				Utils::$context['posts_by_time'][$hour]['relative_percent'] = round((Utils::$context['posts_by_time'][$hour]['posts'] * 100) / $maxPosts);
			}
		}

	// Put it in the right order.
	ksort(Utils::$context['posts_by_time']);

	/**
	 * Adding new entries:
	 * 'key' => array(
	 * 		'text' => string, // The text that will be shown next to the entry.
	 * 		'url' => string, // OPTIONAL: The entry will be a url
	 * ),
	 *
	 * 'key' will be used to look up the language string as Lang::$txt['statPanel_' . $key].
	 * Make sure to add a new entry when writing your mod!
	 */
	Utils::$context['text_stats'] = array(
		'total_time_online' => array(
			'text' => Utils::$context['time_logged_in'],
		),
		'total_posts' => array(
			'text' => Utils::$context['num_posts'] . ' ' . Lang::$txt['statPanel_posts'],
			'url' => Config::$scripturl . '?action=profile;area=showposts;sa=messages;u=' . $memID
		),
		'total_topics' => array(
			'text' => Utils::$context['num_topics'] . ' ' . Lang::$txt['statPanel_topics'],
			'url' => Config::$scripturl . '?action=profile;area=showposts;sa=topics;u=' . $memID
		),
		'users_polls' => array(
			'text' => Utils::$context['num_polls'] . ' ' . Lang::$txt['statPanel_polls'],
		),
		'users_votes' => array(
			'text' => Utils::$context['num_votes'] . ' ' . Lang::$txt['statPanel_votes']
		)
	);

	// Custom stats (just add a template_layer to add it to the template!)
	call_integration_hook('integrate_profile_stats', array($memID, &Utils::$context['text_stats']));
}

/**
 * Loads up the information for the "track user" section of the profile
 *
 * @param int $memID The ID of the member
 */
function tracking($memID)
{
	$subActions = array(
		'activity' => array('trackActivity', Lang::$txt['trackActivity'], 'moderate_forum'),
		'ip' => array('TrackIP', Lang::$txt['trackIP'], 'moderate_forum'),
		'edits' => array('trackEdits', Lang::$txt['trackEdits'], 'moderate_forum'),
		'groupreq' => array('trackGroupReq', Lang::$txt['trackGroupRequests'], 'approve_group_requests'),
		'logins' => array('TrackLogins', Lang::$txt['trackLogins'], 'moderate_forum'),
	);

	foreach ($subActions as $sa => $action)
	{
		if (!allowedTo($action[2]))
			unset($subActions[$sa]);
	}

	// Create the tabs for the template.
	Utils::$context[Utils::$context['profile_menu_name']]['tab_data'] = array(
		'title' => Lang::$txt['tracking'],
		'description' => Lang::$txt['tracking_description'],
		'icon_class' => 'main_icons profile_hd',
		'tabs' => array(
			'activity' => array(),
			'ip' => array(),
			'edits' => array(),
			'groupreq' => array(),
			'logins' => array(),
		),
	);

	// Moderation must be on to track edits.
	if (empty(Config::$modSettings['userlog_enabled']))
		unset(Utils::$context[Utils::$context['profile_menu_name']]['tab_data']['edits'], $subActions['edits']);

	// Group requests must be active to show it...
	if (empty(Config::$modSettings['show_group_membership']))
		unset(Utils::$context[Utils::$context['profile_menu_name']]['tab_data']['groupreq'], $subActions['groupreq']);

	if (empty($subActions))
		fatal_lang_error('no_access', false);

	$keys = array_keys($subActions);
	$default = array_shift($keys);
	Utils::$context['tracking_area'] = isset($_GET['sa']) && isset($subActions[$_GET['sa']]) ? $_GET['sa'] : $default;

	// Set a page title.
	Utils::$context['page_title'] = Lang::$txt['trackUser'] . ' - ' . $subActions[Utils::$context['tracking_area']][1] . ' - ' . User::$loaded[$memID]->name;

	// Pass on to the actual function.
	Utils::$context['sub_template'] = $subActions[Utils::$context['tracking_area']][0];
	$call = call_helper($subActions[Utils::$context['tracking_area']][0], true);

	if (!empty($call))
		call_user_func($call, $memID);
}

/**
 * Handles tracking a user's activity
 *
 * @param int $memID The ID of the member
 */
function trackActivity($memID)
{
	// Verify if the user has sufficient permissions.
	isAllowedTo('moderate_forum');

	Utils::$context['last_ip'] = User::$loaded[$memID]->ip;
	if (Utils::$context['last_ip'] != User::$loaded[$memID]->ip2)
		Utils::$context['last_ip2'] = User::$loaded[$memID]->ip2;
	Utils::$context['member']['name'] = User::$loaded[$memID]->name;

	// Set the options for the list component.
	$listOptions = array(
		'id' => 'track_user_list',
		'title' => Lang::$txt['errors_by'] . ' ' . Utils::$context['member']['name'],
		'items_per_page' => Config::$modSettings['defaultMaxListItems'],
		'no_items_label' => Lang::$txt['no_errors_from_user'],
		'base_href' => Config::$scripturl . '?action=profile;area=tracking;sa=user;u=' . $memID,
		'default_sort_col' => 'date',
		'get_items' => array(
			'function' => 'list_getUserErrors',
			'params' => array(
				'le.id_member = {int:current_member}',
				array('current_member' => $memID),
			),
		),
		'get_count' => array(
			'function' => 'list_getUserErrorCount',
			'params' => array(
				'id_member = {int:current_member}',
				array('current_member' => $memID),
			),
		),
		'columns' => array(
			'ip_address' => array(
				'header' => array(
					'value' => Lang::$txt['ip_address'],
				),
				'data' => array(
					'sprintf' => array(
						'format' => '<a href="' . Config::$scripturl . '?action=profile;area=tracking;sa=ip;searchip=%1$s;u=' . $memID . '">%1$s</a>',
						'params' => array(
							'ip' => false,
						),
					),
				),
				'sort' => array(
					'default' => 'le.ip',
					'reverse' => 'le.ip DESC',
				),
			),
			'message' => array(
				'header' => array(
					'value' => Lang::$txt['message'],
				),
				'data' => array(
					'sprintf' => array(
						'format' => '%1$s<br><a href="%2$s">%2$s</a>',
						'params' => array(
							'message' => false,
							'url' => false,
						),
					),
				),
			),
			'date' => array(
				'header' => array(
					'value' => Lang::$txt['date'],
				),
				'data' => array(
					'db' => 'time',
				),
				'sort' => array(
					'default' => 'le.id_error DESC',
					'reverse' => 'le.id_error',
				),
			),
		),
		'additional_rows' => array(
			array(
				'position' => 'after_title',
				'value' => Lang::$txt['errors_desc'],
			),
		),
	);

	// Create the list for viewing.
	require_once(Config::$sourcedir . '/Subs-List.php');
	createList($listOptions);

	// @todo cache this
	// If this is a big forum, or a large posting user, let's limit the search.
	if (Config::$modSettings['totalMessages'] > 50000 && User::$loaded[$memID]->posts > 500)
	{
		$request = Db::$db->query('', '
			SELECT MAX(id_msg)
			FROM {db_prefix}messages AS m
			WHERE m.id_member = {int:current_member}',
			array(
				'current_member' => $memID,
			)
		);
		list ($max_msg_member) = Db::$db->fetch_row($request);
		Db::$db->free_result($request);

		// There's no point worrying ourselves with messages made yonks ago, just get recent ones!
		$min_msg_member = max(0, $max_msg_member - User::$loaded[$memID]->posts * 3);
	}

	// Default to at least the ones we know about.
	$ips = array(
		User::$loaded[$memID]->ip,
		User::$loaded[$memID]->ip2,
	);

	// @todo cache this
	// Get all IP addresses this user has used for his messages.
	$request = Db::$db->query('', '
		SELECT poster_ip
		FROM {db_prefix}messages
		WHERE id_member = {int:current_member}
		' . (isset($min_msg_member) ? '
			AND id_msg >= {int:min_msg_member} AND id_msg <= {int:max_msg_member}' : '') . '
		GROUP BY poster_ip',
		array(
			'current_member' => $memID,
			'min_msg_member' => !empty($min_msg_member) ? $min_msg_member : 0,
			'max_msg_member' => !empty($max_msg_member) ? $max_msg_member : 0,
		)
	);
	Utils::$context['ips'] = array();
	while ($row = Db::$db->fetch_assoc($request))
	{
		Utils::$context['ips'][] = '<a href="' . Config::$scripturl . '?action=profile;area=tracking;sa=ip;searchip=' . inet_dtop($row['poster_ip']) . ';u=' . $memID . '">' . inet_dtop($row['poster_ip']) . '</a>';
		$ips[] = inet_dtop($row['poster_ip']);
	}
	Db::$db->free_result($request);

	// Now also get the IP addresses from the error messages.
	$request = Db::$db->query('', '
		SELECT COUNT(*) AS error_count, ip
		FROM {db_prefix}log_errors
		WHERE id_member = {int:current_member}
		GROUP BY ip',
		array(
			'current_member' => $memID,
		)
	);
	Utils::$context['error_ips'] = array();
	while ($row = Db::$db->fetch_assoc($request))
	{
		$row['ip'] = inet_dtop($row['ip']);
		Utils::$context['error_ips'][] = '<a href="' . Config::$scripturl . '?action=profile;area=tracking;sa=ip;searchip=' . $row['ip'] . ';u=' . $memID . '">' . $row['ip'] . '</a>';
		$ips[] = $row['ip'];
	}
	Db::$db->free_result($request);

	// Find other users that might use the same IP.
	$ips = array_unique($ips);
	Utils::$context['members_in_range'] = array();
	if (!empty($ips))
	{
		// Get member ID's which are in messages...
		$request = Db::$db->query('', '
			SELECT DISTINCT mem.id_member
			FROM {db_prefix}messages AS m
				INNER JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
			WHERE m.poster_ip IN ({array_inet:ip_list})
				AND mem.id_member != {int:current_member}',
			array(
				'current_member' => $memID,
				'ip_list' => $ips,
			)
		);
		$message_members = array();
		while ($row = Db::$db->fetch_assoc($request))
			$message_members[] = $row['id_member'];
		Db::$db->free_result($request);

		// Fetch their names, cause of the GROUP BY doesn't like giving us that normally.
		if (!empty($message_members))
		{
			$request = Db::$db->query('', '
				SELECT id_member, real_name
				FROM {db_prefix}members
				WHERE id_member IN ({array_int:message_members})',
				array(
					'message_members' => $message_members,
					'ip_list' => $ips,
				)
			);
			while ($row = Db::$db->fetch_assoc($request))
				Utils::$context['members_in_range'][$row['id_member']] = '<a href="' . Config::$scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['real_name'] . '</a>';
			Db::$db->free_result($request);
		}

		$request = Db::$db->query('', '
			SELECT id_member, real_name
			FROM {db_prefix}members
			WHERE id_member != {int:current_member}
				AND member_ip IN ({array_inet:ip_list})',
			array(
				'current_member' => $memID,
				'ip_list' => $ips,
			)
		);
		while ($row = Db::$db->fetch_assoc($request))
			Utils::$context['members_in_range'][$row['id_member']] = '<a href="' . Config::$scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['real_name'] . '</a>';
		Db::$db->free_result($request);
	}
}

/**
 * Get the number of user errors
 *
 * @param string $where A query to limit which errors are counted
 * @param array $where_vars The parameters for $where
 * @return int Number of user errors
 */
function list_getUserErrorCount($where, $where_vars = array())
{
	$request = Db::$db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}log_errors
		WHERE ' . $where,
		$where_vars
	);
	list ($count) = Db::$db->fetch_row($request);
	Db::$db->free_result($request);

	return (int) $count;
}

/**
 * Gets all of the errors generated by a user's actions. Callback for the list in track_activity
 *
 * @param int $start Which item to start with (for pagination purposes)
 * @param int $items_per_page How many items to show on each page
 * @param string $sort A string indicating how to sort the results
 * @param string $where A query indicating how to filter the results (eg 'id_member={int:id_member}')
 * @param array $where_vars An array of parameters for $where
 * @return array An array of information about the error messages
 */
function list_getUserErrors($start, $items_per_page, $sort, $where, $where_vars = array())
{
	// Get a list of error messages from this ip (range).
	$request = Db::$db->query('', '
		SELECT
			le.log_time, le.ip, le.url, le.message, COALESCE(mem.id_member, 0) AS id_member,
			COALESCE(mem.real_name, {string:guest_title}) AS display_name, mem.member_name
		FROM {db_prefix}log_errors AS le
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = le.id_member)
		WHERE ' . $where . '
		ORDER BY {raw:sort}
		LIMIT {int:start}, {int:max}',
		array_merge($where_vars, array(
			'guest_title' => Lang::$txt['guest_title'],
			'sort' => $sort,
			'start' => $start,
			'max' => $items_per_page,
		))
	);
	$error_messages = array();
	while ($row = Db::$db->fetch_assoc($request))
		$error_messages[] = array(
			'ip' => inet_dtop($row['ip']),
			'member_link' => $row['id_member'] > 0 ? '<a href="' . Config::$scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['display_name'] . '</a>' : $row['display_name'],
			'message' => strtr($row['message'], array('&lt;span class=&quot;remove&quot;&gt;' => '', '&lt;/span&gt;' => '')),
			'url' => $row['url'],
			'time' => timeformat($row['log_time']),
			'timestamp' => $row['log_time'],
		);
	Db::$db->free_result($request);

	return $error_messages;
}

/**
 * Gets the number of posts made from a particular IP
 *
 * @param string $where A query indicating which posts to count
 * @param array $where_vars The parameters for $where
 * @return int Count of messages matching the IP
 */
function list_getIPMessageCount($where, $where_vars = array())
{
	$request = Db::$db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}messages AS m
		WHERE {query_see_message_board} AND ' . $where,
		$where_vars
	);
	list ($count) = Db::$db->fetch_row($request);
	Db::$db->free_result($request);

	return (int) $count;
}

/**
 * Gets all the posts made from a particular IP
 *
 * @param int $start Which item to start with (for pagination purposes)
 * @param int $items_per_page How many items to show on each page
 * @param string $sort A string indicating how to sort the results
 * @param string $where A query to filter which posts are returned
 * @param array $where_vars An array of parameters for $where
 * @return array An array containing information about the posts
 */
function list_getIPMessages($start, $items_per_page, $sort, $where, $where_vars = array())
{

	// Get all the messages fitting this where clause.
	$request = Db::$db->query('', '
		SELECT
			m.id_msg, m.poster_ip, COALESCE(mem.real_name, m.poster_name) AS display_name, mem.id_member,
			m.subject, m.poster_time, m.id_topic, m.id_board
		FROM {db_prefix}messages AS m
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
		WHERE {query_see_message_board} AND ' . $where . '
		ORDER BY {raw:sort}
		LIMIT {int:start}, {int:max}',
		array_merge($where_vars, array(
			'sort' => $sort,
			'start' => $start,
			'max' => $items_per_page,
		))
	);
	$messages = array();
	while ($row = Db::$db->fetch_assoc($request))
		$messages[] = array(
			'ip' => inet_dtop($row['poster_ip']),
			'member_link' => empty($row['id_member']) ? $row['display_name'] : '<a href="' . Config::$scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['display_name'] . '</a>',
			'board' => array(
				'id' => $row['id_board'],
				'href' => Config::$scripturl . '?board=' . $row['id_board']
			),
			'topic' => $row['id_topic'],
			'id' => $row['id_msg'],
			'subject' => $row['subject'],
			'time' => timeformat($row['poster_time']),
			'timestamp' => $row['poster_time']
		);
	Db::$db->free_result($request);

	return $messages;
}

/**
 * Handles tracking a particular IP address
 *
 * @param int $memID The ID of a member whose IP we want to track
 */
function TrackIP($memID = 0)
{
	global $options;

	// Can the user do this?
	isAllowedTo('moderate_forum');

	if ($memID == 0)
	{
		Utils::$context['ip'] = ip2range(User::$me->ip);
		loadTemplate('Profile');
		Lang::load('Profile');
		Utils::$context['sub_template'] = 'trackIP';
		Utils::$context['page_title'] = Lang::$txt['profile'];
		Utils::$context['base_url'] = Config::$scripturl . '?action=trackip';
	}
	else
	{
		Utils::$context['ip'] = ip2range(User::$loaded[$memID]->ip);
		Utils::$context['base_url'] = Config::$scripturl . '?action=profile;area=tracking;sa=ip;u=' . $memID;
	}

	// Searching?
	if (isset($_REQUEST['searchip']))
		Utils::$context['ip'] = ip2range(trim($_REQUEST['searchip']));

	if (count(Utils::$context['ip']) !== 2)
		fatal_lang_error('invalid_tracking_ip', false);

	$ip_string = array('{inet:ip_address_low}', '{inet:ip_address_high}');
	$fields = array(
		'ip_address_low' => Utils::$context['ip']['low'],
		'ip_address_high' => Utils::$context['ip']['high'],
	);

	$ip_var = Utils::$context['ip'];

	if (Utils::$context['ip']['low'] !== Utils::$context['ip']['high'])
		Utils::$context['ip'] = Utils::$context['ip']['low'] . '-' . Utils::$context['ip']['high'];
	else
		Utils::$context['ip'] = Utils::$context['ip']['low'];

	if (empty(Utils::$context['tracking_area']))
		Utils::$context['page_title'] = Lang::$txt['trackIP'] . ' - ' . Utils::$context['ip'];

	$request = Db::$db->query('', '
		SELECT id_member, real_name AS display_name, member_ip
		FROM {db_prefix}members
		WHERE member_ip >= ' . $ip_string[0] . ' and member_ip <= ' . $ip_string[1],
		$fields
	);
	Utils::$context['ips'] = array();
	while ($row = Db::$db->fetch_assoc($request))
		Utils::$context['ips'][inet_dtop($row['member_ip'])][] = '<a href="' . Config::$scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['display_name'] . '</a>';
	Db::$db->free_result($request);

	ksort(Utils::$context['ips']);

	// For messages we use the "messages per page" option
	$maxPerPage = empty(Config::$modSettings['disableCustomPerPage']) && !empty($options['messages_per_page']) ? $options['messages_per_page'] : Config::$modSettings['defaultMaxMessages'];

	// Gonna want this for the list.
	require_once(Config::$sourcedir . '/Subs-List.php');

	// Start with the user messages.
	$listOptions = array(
		'id' => 'track_message_list',
		'title' => Lang::$txt['messages_from_ip'] . ' ' . Utils::$context['ip'],
		'start_var_name' => 'messageStart',
		'items_per_page' => $maxPerPage,
		'no_items_label' => Lang::$txt['no_messages_from_ip'],
		'base_href' => Utils::$context['base_url'] . ';searchip=' . Utils::$context['ip'],
		'default_sort_col' => 'date',
		'get_items' => array(
			'function' => 'list_getIPMessages',
			'params' => array(
				'm.poster_ip >= ' . $ip_string[0] . ' and m.poster_ip <= ' . $ip_string[1],
				$fields,
			),
		),
		'get_count' => array(
			'function' => 'list_getIPMessageCount',
			'params' => array(
				'm.poster_ip >= ' . $ip_string[0] . ' and m.poster_ip <= ' . $ip_string[1],
				$fields,
			),
		),
		'columns' => array(
			'ip_address' => array(
				'header' => array(
					'value' => Lang::$txt['ip_address'],
				),
				'data' => array(
					'sprintf' => array(
						'format' => '<a href="' . Utils::$context['base_url'] . ';searchip=%1$s">%1$s</a>',
						'params' => array(
							'ip' => false,
						),
					),
				),
				'sort' => array(
					'default' => 'm.poster_ip',
					'reverse' => 'm.poster_ip DESC',
				),
			),
			'poster' => array(
				'header' => array(
					'value' => Lang::$txt['poster'],
				),
				'data' => array(
					'db' => 'member_link',
				),
			),
			'subject' => array(
				'header' => array(
					'value' => Lang::$txt['subject'],
				),
				'data' => array(
					'sprintf' => array(
						'format' => '<a href="' . Config::$scripturl . '?topic=%1$s.msg%2$s#msg%2$s" rel="nofollow">%3$s</a>',
						'params' => array(
							'topic' => false,
							'id' => false,
							'subject' => false,
						),
					),
				),
			),
			'date' => array(
				'header' => array(
					'value' => Lang::$txt['date'],
				),
				'data' => array(
					'db' => 'time',
				),
				'sort' => array(
					'default' => 'm.id_msg DESC',
					'reverse' => 'm.id_msg',
				),
			),
		),
		'additional_rows' => array(
			array(
				'position' => 'after_title',
				'value' => Lang::$txt['messages_from_ip_desc'],
			),
		),
	);

	// Create the messages list.
	createList($listOptions);

	// Set the options for the error lists.
	$listOptions = array(
		'id' => 'track_user_list',
		'title' => Lang::$txt['errors_from_ip'] . ' ' . Utils::$context['ip'],
		'start_var_name' => 'errorStart',
		'items_per_page' => Config::$modSettings['defaultMaxListItems'],
		'no_items_label' => Lang::$txt['no_errors_from_ip'],
		'base_href' => Utils::$context['base_url'] . ';searchip=' . Utils::$context['ip'],
		'default_sort_col' => 'date2',
		'get_items' => array(
			'function' => 'list_getUserErrors',
			'params' => array(
				'le.ip >= ' . $ip_string[0] . ' and le.ip <= ' . $ip_string[1],
				$fields,
			),
		),
		'get_count' => array(
			'function' => 'list_getUserErrorCount',
			'params' => array(
				'ip >= ' . $ip_string[0] . ' and ip <= ' . $ip_string[1],
				$fields,
			),
		),
		'columns' => array(
			'ip_address2' => array(
				'header' => array(
					'value' => Lang::$txt['ip_address'],
				),
				'data' => array(
					'sprintf' => array(
						'format' => '<a href="' . Utils::$context['base_url'] . ';searchip=%1$s">%1$s</a>',
						'params' => array(
							'ip' => false,
						),
					),
				),
				'sort' => array(
					'default' => 'le.ip',
					'reverse' => 'le.ip DESC',
				),
			),
			'display_name' => array(
				'header' => array(
					'value' => Lang::$txt['display_name'],
				),
				'data' => array(
					'db' => 'member_link',
				),
			),
			'message' => array(
				'header' => array(
					'value' => Lang::$txt['message'],
				),
				'data' => array(
					'sprintf' => array(
						'format' => '%1$s<br><a href="%2$s">%2$s</a>',
						'params' => array(
							'message' => false,
							'url' => false,
						),
					),
					'class' => 'word_break',
				),
			),
			'date2' => array(
				'header' => array(
					'value' => Lang::$txt['date'],
				),
				'data' => array(
					'db' => 'time',
				),
				'sort' => array(
					'default' => 'le.id_error DESC',
					'reverse' => 'le.id_error',
				),
			),
		),
		'additional_rows' => array(
			array(
				'position' => 'after_title',
				'value' => Lang::$txt['errors_from_ip_desc'],
			),
		),
	);

	// Create the error list.
	createList($listOptions);

	// Allow 3rd party integrations to add in their own lists or whatever.
	Utils::$context['additional_track_lists'] = array();
	call_integration_hook('integrate_profile_trackip', array($ip_string, $ip_var));

	Utils::$context['single_ip'] = ($ip_var['low'] === $ip_var['high']);
	if (Utils::$context['single_ip'])
	{
		Utils::$context['whois_servers'] = array(
			'apnic' => array(
				'name' => Lang::$txt['whois_apnic'],
				'url' => 'https://wq.apnic.net/apnic-bin/whois.pl?searchtext=' . Utils::$context['ip'],
			),
			'arin' => array(
				'name' => Lang::$txt['whois_arin'],
				'url' => 'https://whois.arin.net/rest/ip/' . Utils::$context['ip'],
			),
			'lacnic' => array(
				'name' => Lang::$txt['whois_lacnic'],
				'url' => 'https://lacnic.net/cgi-bin/lacnic/whois?query=' . Utils::$context['ip'],
			),
			'ripe' => array(
				'name' => Lang::$txt['whois_ripe'],
				'url' => 'https://apps.db.ripe.net/search/query.html?searchtext=' . Utils::$context['ip'],
			),
		);
	}
}

/**
 * Tracks a user's logins.
 *
 * @param int $memID The ID of the member
 */
function TrackLogins($memID = 0)
{
	// Gonna want this for the list.
	require_once(Config::$sourcedir . '/Subs-List.php');

	if ($memID == 0)
		Utils::$context['base_url'] = Config::$scripturl . '?action=trackip';
	else
		Utils::$context['base_url'] = Config::$scripturl . '?action=profile;area=tracking;sa=ip;u=' . $memID;

	// Start with the user messages.
	$listOptions = array(
		'id' => 'track_logins_list',
		'title' => Lang::$txt['trackLogins'],
		'no_items_label' => Lang::$txt['trackLogins_none_found'],
		'base_href' => Utils::$context['base_url'],
		'get_items' => array(
			'function' => 'list_getLogins',
			'params' => array(
				'id_member = {int:current_member}',
				array('current_member' => $memID),
			),
		),
		'get_count' => array(
			'function' => 'list_getLoginCount',
			'params' => array(
				'id_member = {int:current_member}',
				array('current_member' => $memID),
			),
		),
		'columns' => array(
			'time' => array(
				'header' => array(
					'value' => Lang::$txt['date'],
				),
				'data' => array(
					'db' => 'time',
				),
			),
			'ip' => array(
				'header' => array(
					'value' => Lang::$txt['ip_address'],
				),
				'data' => array(
					'sprintf' => array(
						'format' => '<a href="' . Utils::$context['base_url'] . ';searchip=%1$s">%1$s</a> (<a href="' . Utils::$context['base_url'] . ';searchip=%2$s">%2$s</a>) ',
						'params' => array(
							'ip' => false,
							'ip2' => false
						),
					),
				),
			),
		),
		'additional_rows' => array(
			array(
				'position' => 'after_title',
				'value' => Lang::$txt['trackLogins_desc'],
			),
		),
	);

	// Create the messages list.
	createList($listOptions);

	Utils::$context['sub_template'] = 'show_list';
	Utils::$context['default_list'] = 'track_logins_list';
}

/**
 * Finds the total number of tracked logins for a particular user
 *
 * @param string $where A query to limit which logins are counted
 * @param array $where_vars An array of parameters for $where
 * @return int count of messages matching the IP
 */
function list_getLoginCount($where, $where_vars = array())
{
	$request = Db::$db->query('', '
		SELECT COUNT(*) AS message_count
		FROM {db_prefix}member_logins
		WHERE id_member = {int:id_member}',
		array(
			'id_member' => $where_vars['current_member'],
		)
	);
	list ($count) = Db::$db->fetch_row($request);
	Db::$db->free_result($request);

	return (int) $count;
}

/**
 * Callback for the list in trackLogins.
 *
 * @param int $start Which item to start with (not used here)
 * @param int $items_per_page How many items to show on each page (not used here)
 * @param string $sort A string indicating
 * @param string $where A query to filter results (not used here)
 * @param array $where_vars An array of parameters for $where. Only 'current_member' (the ID of the member) is used here
 * @return array An array of information about user logins
 */
function list_getLogins($start, $items_per_page, $sort, $where, $where_vars = array())
{
	$request = Db::$db->query('', '
		SELECT time, ip, ip2
		FROM {db_prefix}member_logins
		WHERE id_member = {int:id_member}
		ORDER BY time DESC',
		array(
			'id_member' => $where_vars['current_member'],
		)
	);
	$logins = array();
	while ($row = Db::$db->fetch_assoc($request))
		$logins[] = array(
			'time' => timeformat($row['time']),
			'ip' => inet_dtop($row['ip']),
			'ip2' => inet_dtop($row['ip2']),
		);
	Db::$db->free_result($request);

	return $logins;
}

/**
 * Tracks a user's profile edits
 *
 * @param int $memID The ID of the member
 */
function trackEdits($memID)
{
	require_once(Config::$sourcedir . '/Subs-List.php');

	// Get the names of any custom fields.
	$request = Db::$db->query('', '
		SELECT col_name, field_name, bbc
		FROM {db_prefix}custom_fields',
		array(
		)
	);
	Utils::$context['custom_field_titles'] = array();
	while ($row = Db::$db->fetch_assoc($request))
		Utils::$context['custom_field_titles']['customfield_' . $row['col_name']] = array(
			'title' => $row['field_name'],
			'parse_bbc' => $row['bbc'],
		);
	Db::$db->free_result($request);

	// Set the options for the error lists.
	$listOptions = array(
		'id' => 'edit_list',
		'title' => Lang::$txt['trackEdits'],
		'items_per_page' => Config::$modSettings['defaultMaxListItems'],
		'no_items_label' => Lang::$txt['trackEdit_no_edits'],
		'base_href' => Config::$scripturl . '?action=profile;area=tracking;sa=edits;u=' . $memID,
		'default_sort_col' => 'time',
		'get_items' => array(
			'function' => 'list_getProfileEdits',
			'params' => array(
				$memID,
			),
		),
		'get_count' => array(
			'function' => 'list_getProfileEditCount',
			'params' => array(
				$memID,
			),
		),
		'columns' => array(
			'action' => array(
				'header' => array(
					'value' => Lang::$txt['trackEdit_action'],
				),
				'data' => array(
					'db' => 'action_text',
				),
			),
			'before' => array(
				'header' => array(
					'value' => Lang::$txt['trackEdit_before'],
				),
				'data' => array(
					'db' => 'before',
				),
			),
			'after' => array(
				'header' => array(
					'value' => Lang::$txt['trackEdit_after'],
				),
				'data' => array(
					'db' => 'after',
				),
			),
			'time' => array(
				'header' => array(
					'value' => Lang::$txt['date'],
				),
				'data' => array(
					'db' => 'time',
				),
				'sort' => array(
					'default' => 'id_action DESC',
					'reverse' => 'id_action',
				),
			),
			'applicator' => array(
				'header' => array(
					'value' => Lang::$txt['trackEdit_applicator'],
				),
				'data' => array(
					'db' => 'member_link',
				),
			),
		),
	);

	// Create the error list.
	createList($listOptions);

	Utils::$context['sub_template'] = 'show_list';
	Utils::$context['default_list'] = 'edit_list';
}

/**
 * How many edits?
 *
 * @param int $memID The ID of the member
 * @return int The number of profile edits
 */
function list_getProfileEditCount($memID)
{
	$request = Db::$db->query('', '
		SELECT COUNT(*) AS edit_count
		FROM {db_prefix}log_actions
		WHERE id_log = {int:log_type}
			AND id_member = {int:owner}',
		array(
			'log_type' => 2,
			'owner' => $memID,
		)
	);
	list ($edit_count) = Db::$db->fetch_row($request);
	Db::$db->free_result($request);

	return (int) $edit_count;
}

/**
 * Loads up information about a user's profile edits. Callback for the list in trackEdits()
 *
 * @param int $start Which item to start with (for pagination purposes)
 * @param int $items_per_page How many items to show on each page
 * @param string $sort A string indicating how to sort the results
 * @param int $memID The ID of the member
 * @return array An array of information about the profile edits
 */
function list_getProfileEdits($start, $items_per_page, $sort, $memID)
{
	// Get a list of error messages from this ip (range).
	$request = Db::$db->query('', '
		SELECT
			id_action, id_member, ip, log_time, action, extra
		FROM {db_prefix}log_actions
		WHERE id_log = {int:log_type}
			AND id_member = {int:owner}
		ORDER BY {raw:sort}
		LIMIT {int:start}, {int:max}',
		array(
			'log_type' => 2,
			'owner' => $memID,
			'sort' => $sort,
			'start' => $start,
			'max' => $items_per_page,
		)
	);
	$edits = array();
	$members = array();
	while ($row = Db::$db->fetch_assoc($request))
	{
		$extra = Utils::jsonDecode($row['extra'], true);
		if (!empty($extra['applicator']))
			$members[] = $extra['applicator'];

		// Work out what the name of the action is.
		if (isset(Lang::$txt['trackEdit_action_' . $row['action']]))
			$action_text = Lang::$txt['trackEdit_action_' . $row['action']];
		elseif (isset(Lang::$txt[$row['action']]))
			$action_text = Lang::$txt[$row['action']];
		// Custom field?
		elseif (isset(Utils::$context['custom_field_titles'][$row['action']]))
			$action_text = Utils::$context['custom_field_titles'][$row['action']]['title'];
		else
			$action_text = $row['action'];

		// Parse BBC?
		$parse_bbc = isset(Utils::$context['custom_field_titles'][$row['action']]) && Utils::$context['custom_field_titles'][$row['action']]['parse_bbc'] ? true : false;

		$edits[] = array(
			'id' => $row['id_action'],
			'ip' => inet_dtop($row['ip']),
			'id_member' => !empty($extra['applicator']) ? $extra['applicator'] : 0,
			'member_link' => Lang::$txt['trackEdit_deleted_member'],
			'action' => $row['action'],
			'action_text' => $action_text,
			'before' => !empty($extra['previous']) ? ($parse_bbc ? BBCodeParser::load()->parse($extra['previous']) : $extra['previous']) : '',
			'after' => !empty($extra['new']) ? ($parse_bbc ? BBCodeParser::load()->parse($extra['new']) : $extra['new']) : '',
			'time' => timeformat($row['log_time']),
		);
	}
	Db::$db->free_result($request);

	// Get any member names.
	if (!empty($members))
	{
		$request = Db::$db->query('', '
			SELECT
				id_member, real_name
			FROM {db_prefix}members
			WHERE id_member IN ({array_int:members})',
			array(
				'members' => $members,
			)
		);
		$members = array();
		while ($row = Db::$db->fetch_assoc($request))
			$members[$row['id_member']] = $row['real_name'];
		Db::$db->free_result($request);

		foreach ($edits as $key => $value)
			if (isset($members[$value['id_member']]))
				$edits[$key]['member_link'] = '<a href="' . Config::$scripturl . '?action=profile;u=' . $value['id_member'] . '">' . $members[$value['id_member']] . '</a>';
	}

	return $edits;
}

/**
 * Display the history of group requests made by the user whose profile we are viewing.
 *
 * @param int $memID The ID of the member
 */
function trackGroupReq($memID)
{
	require_once(Config::$sourcedir . '/Subs-List.php');

	// Set the options for the error lists.
	$listOptions = array(
		'id' => 'request_list',
		'title' => sprintf(Lang::$txt['trackGroupRequests_title'], Utils::$context['member']['name']),
		'items_per_page' => Config::$modSettings['defaultMaxListItems'],
		'no_items_label' => Lang::$txt['requested_none'],
		'base_href' => Config::$scripturl . '?action=profile;area=tracking;sa=groupreq;u=' . $memID,
		'default_sort_col' => 'time_applied',
		'get_items' => array(
			'function' => 'list_getGroupRequests',
			'params' => array(
				$memID,
			),
		),
		'get_count' => array(
			'function' => 'list_getGroupRequestsCount',
			'params' => array(
				$memID,
			),
		),
		'columns' => array(
			'group' => array(
				'header' => array(
					'value' => Lang::$txt['requested_group'],
				),
				'data' => array(
					'db' => 'group_name',
				),
			),
			'group_reason' => array(
				'header' => array(
					'value' => Lang::$txt['requested_group_reason'],
				),
				'data' => array(
					'db' => 'group_reason',
				),
			),
			'time_applied' => array(
				'header' => array(
					'value' => Lang::$txt['requested_group_time'],
				),
				'data' => array(
					'db' => 'time_applied',
					'timeformat' => true,
				),
				'sort' => array(
					'default' => 'time_applied DESC',
					'reverse' => 'time_applied',
				),
			),
			'outcome' => array(
				'header' => array(
					'value' => Lang::$txt['requested_group_outcome'],
				),
				'data' => array(
					'db' => 'outcome',
				),
			),
		),
	);

	// Create the error list.
	createList($listOptions);

	Utils::$context['sub_template'] = 'show_list';
	Utils::$context['default_list'] = 'request_list';
}

/**
 * How many edits?
 *
 * @param int $memID The ID of the member
 * @return int The number of profile edits
 */
function list_getGroupRequestsCount($memID)
{
	$request = Db::$db->query('', '
		SELECT COUNT(*) AS req_count
		FROM {db_prefix}log_group_requests AS lgr
		WHERE id_member = {int:memID}
			AND ' . (User::$me->mod_cache['gq'] == '1=1' ? User::$me->mod_cache['gq'] : 'lgr.' . User::$me->mod_cache['gq']),
		array(
			'memID' => $memID,
		)
	);
	list ($report_count) = Db::$db->fetch_row($request);
	Db::$db->free_result($request);

	return (int) $report_count;
}

/**
 * Loads up information about a user's group requests. Callback for the list in trackGroupReq()
 *
 * @param int $start Which item to start with (for pagination purposes)
 * @param int $items_per_page How many items to show on each page
 * @param string $sort A string indicating how to sort the results
 * @param int $memID The ID of the member
 * @return array An array of information about the user's group requests
 */
function list_getGroupRequests($start, $items_per_page, $sort, $memID)
{
	$groupreq = array();

	$request = Db::$db->query('', '
		SELECT
			lgr.id_group, mg.group_name, mg.online_color, lgr.time_applied, lgr.reason, lgr.status,
			ma.id_member AS id_member_acted, COALESCE(ma.member_name, lgr.member_name_acted) AS act_name, lgr.time_acted, lgr.act_reason
		FROM {db_prefix}log_group_requests AS lgr
			LEFT JOIN {db_prefix}members AS ma ON (lgr.id_member_acted = ma.id_member)
			INNER JOIN {db_prefix}membergroups AS mg ON (lgr.id_group = mg.id_group)
		WHERE lgr.id_member = {int:memID}
			AND ' . (User::$me->mod_cache['gq'] == '1=1' ? User::$me->mod_cache['gq'] : 'lgr.' . User::$me->mod_cache['gq']) . '
		ORDER BY {raw:sort}
		LIMIT {int:start}, {int:max}',
		array(
			'memID' => $memID,
			'sort' => $sort,
			'start' => $start,
			'max' => $items_per_page,
		)
	);
	while ($row = Db::$db->fetch_assoc($request))
	{
		$this_req = array(
			'group_name' => empty($row['online_color']) ? $row['group_name'] : '<span style="color:' . $row['online_color'] . '">' . $row['group_name'] . '</span>',
			'group_reason' => $row['reason'],
			'time_applied' => $row['time_applied'],
		);
		switch ($row['status'])
		{
			case 0:
				$this_req['outcome'] = Lang::$txt['outcome_pending'];
				break;
			case 1:
				$member_link = empty($row['id_member_acted']) ? $row['act_name'] : '<a href="' . Config::$scripturl . '?action=profile;u=' . $row['id_member_acted'] . '">' . $row['act_name'] . '</a>';
				$this_req['outcome'] = sprintf(Lang::$txt['outcome_approved'], $member_link, timeformat($row['time_acted']));
				break;
			case 2:
				$member_link = empty($row['id_member_acted']) ? $row['act_name'] : '<a href="' . Config::$scripturl . '?action=profile;u=' . $row['id_member_acted'] . '">' . $row['act_name'] . '</a>';
				$this_req['outcome'] = sprintf(!empty($row['act_reason']) ? Lang::$txt['outcome_refused_reason'] : Lang::$txt['outcome_refused'], $member_link, timeformat($row['time_acted']), $row['act_reason']);
				break;
		}

		$groupreq[] = $this_req;
	}
	Db::$db->free_result($request);

	return $groupreq;
}

/**
 * Shows which permissions a user has
 *
 * @param int $memID The ID of the member
 */
function showPermissions($memID)
{
	// Verify if the user has sufficient permissions.
	isAllowedTo('manage_permissions');

	Lang::load('ManagePermissions');
	Lang::load('Admin');
	loadTemplate('ManageMembers');

	// Load all the permission profiles.
	require_once(Config::$sourcedir . '/ManagePermissions.php');
	loadPermissionProfiles();

	Utils::$context['member']['id'] = $memID;
	Utils::$context['member']['name'] = User::$loaded[$memID]->name;

	Utils::$context['page_title'] = Lang::$txt['showPermissions'];
	Board::$info->id = empty(Board::$info->id) ? 0 : (int) Board::$info->id;
	Utils::$context['board'] = Board::$info->id;

	// Determine which groups this user is in.
	$curGroups = User::$loaded[$memID]->groups;

	// Load a list of boards for the jump box - except the defaults.
	$request = Db::$db->query('order_by_board_order', '
		SELECT b.id_board, b.name, b.id_profile, b.member_groups, COALESCE(mods.id_member, modgs.id_group, 0) AS is_mod
		FROM {db_prefix}boards AS b
			LEFT JOIN {db_prefix}moderators AS mods ON (mods.id_board = b.id_board AND mods.id_member = {int:current_member})
			LEFT JOIN {db_prefix}moderator_groups AS modgs ON (modgs.id_board = b.id_board AND modgs.id_group IN ({array_int:current_groups}))
		WHERE {query_see_board}',
		array(
			'current_member' => $memID,
			'current_groups' => $curGroups,
		)
	);
	Utils::$context['boards'] = array();
	Utils::$context['no_access_boards'] = array();
	while ($row = Db::$db->fetch_assoc($request))
	{
		if ((count(array_intersect($curGroups, explode(',', $row['member_groups']))) === 0) && !$row['is_mod']
		&& (!empty(Config::$modSettings['board_manager_groups']) && count(array_intersect($curGroups, explode(',', Config::$modSettings['board_manager_groups']))) === 0))
			Utils::$context['no_access_boards'][] = array(
				'id' => $row['id_board'],
				'name' => $row['name'],
				'is_last' => false,
			);
		elseif ($row['id_profile'] != 1 || $row['is_mod'])
			Utils::$context['boards'][$row['id_board']] = array(
				'id' => $row['id_board'],
				'name' => $row['name'],
				'selected' => Board::$info->id == $row['id_board'],
				'profile' => $row['id_profile'],
				'profile_name' => Utils::$context['profiles'][$row['id_profile']]['name'],
			);
	}
	Db::$db->free_result($request);

	Board::sort(Utils::$context['boards']);

	if (!empty(Utils::$context['no_access_boards']))
		Utils::$context['no_access_boards'][count(Utils::$context['no_access_boards']) - 1]['is_last'] = true;

	Utils::$context['member']['permissions'] = array(
		'general' => array(),
		'board' => array()
	);

	// If you're an admin we know you can do everything, we might as well leave.
	Utils::$context['member']['has_all_permissions'] = in_array(1, $curGroups);
	if (Utils::$context['member']['has_all_permissions'])
		return;

	$denied = array();

	// Get all general permissions.
	$result = Db::$db->query('', '
		SELECT p.permission, p.add_deny, mg.group_name, p.id_group
		FROM {db_prefix}permissions AS p
			LEFT JOIN {db_prefix}membergroups AS mg ON (mg.id_group = p.id_group)
		WHERE p.id_group IN ({array_int:group_list})
		ORDER BY p.add_deny DESC, p.permission, mg.min_posts, CASE WHEN mg.id_group < {int:newbie_group} THEN mg.id_group ELSE 4 END, mg.group_name',
		array(
			'group_list' => $curGroups,
			'newbie_group' => 4,
		)
	);
	while ($row = Db::$db->fetch_assoc($result))
	{
		// We don't know about this permission, it doesn't exist :P.
		if (!isset(Lang::$txt['permissionname_' . $row['permission']]))
			continue;

		if (empty($row['add_deny']))
			$denied[] = $row['permission'];

		// Permissions that end with _own or _any consist of two parts.
		if (in_array(substr($row['permission'], -4), array('_own', '_any')) && isset(Lang::$txt['permissionname_' . substr($row['permission'], 0, -4)]))
			$name = Lang::$txt['permissionname_' . substr($row['permission'], 0, -4)] . ' - ' . Lang::$txt['permissionname_' . $row['permission']];
		else
			$name = Lang::$txt['permissionname_' . $row['permission']];

		// Add this permission if it doesn't exist yet.
		if (!isset(Utils::$context['member']['permissions']['general'][$row['permission']]))
			Utils::$context['member']['permissions']['general'][$row['permission']] = array(
				'id' => $row['permission'],
				'groups' => array(
					'allowed' => array(),
					'denied' => array()
				),
				'name' => $name,
				'is_denied' => false,
				'is_global' => true,
			);

		// Add the membergroup to either the denied or the allowed groups.
		Utils::$context['member']['permissions']['general'][$row['permission']]['groups'][empty($row['add_deny']) ? 'denied' : 'allowed'][] = $row['id_group'] == 0 ? Lang::$txt['membergroups_members'] : $row['group_name'];

		// Once denied is always denied.
		Utils::$context['member']['permissions']['general'][$row['permission']]['is_denied'] |= empty($row['add_deny']);
	}
	Db::$db->free_result($result);

	$request = Db::$db->query('', '
		SELECT
			bp.add_deny, bp.permission, bp.id_group, mg.group_name' . (empty(Board::$info->id) ? '' : ',
			b.id_profile, CASE WHEN (mods.id_member IS NULL AND modgs.id_group IS NULL) THEN 0 ELSE 1 END AS is_moderator') . '
		FROM {db_prefix}board_permissions AS bp' . (empty(Board::$info->id) ? '' : '
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = {int:current_board})
			LEFT JOIN {db_prefix}moderators AS mods ON (mods.id_board = b.id_board AND mods.id_member = {int:current_member})
			LEFT JOIN {db_prefix}moderator_groups AS modgs ON (modgs.id_board = b.id_board AND modgs.id_group IN ({array_int:group_list}))') . '
			LEFT JOIN {db_prefix}membergroups AS mg ON (mg.id_group = bp.id_group)
		WHERE bp.id_profile = {raw:current_profile}
			AND bp.id_group IN ({array_int:group_list}' . (empty(Board::$info->id) ? ')' : ', {int:moderator_group})
			AND (mods.id_member IS NOT NULL OR modgs.id_group IS NOT NULL OR bp.id_group != {int:moderator_group})'),
		array(
			'current_board' => Board::$info->id,
			'group_list' => $curGroups,
			'current_member' => $memID,
			'current_profile' => empty(Board::$info->id) ? '1' : 'b.id_profile',
			'moderator_group' => 3,
		)
	);

	while ($row = Db::$db->fetch_assoc($request))
	{
		// We don't know about this permission, it doesn't exist :P.
		if (!isset(Lang::$txt['permissionname_' . $row['permission']]))
			continue;

		// The name of the permission using the format 'permission name' - 'own/any topic/event/etc.'.
		if (in_array(substr($row['permission'], -4), array('_own', '_any')) && isset(Lang::$txt['permissionname_' . substr($row['permission'], 0, -4)]))
			$name = Lang::$txt['permissionname_' . substr($row['permission'], 0, -4)] . ' - ' . Lang::$txt['permissionname_' . $row['permission']];
		else
			$name = Lang::$txt['permissionname_' . $row['permission']];

		// Create the structure for this permission.
		if (!isset(Utils::$context['member']['permissions']['board'][$row['permission']]))
			Utils::$context['member']['permissions']['board'][$row['permission']] = array(
				'id' => $row['permission'],
				'groups' => array(
					'allowed' => array(),
					'denied' => array()
				),
				'name' => $name,
				'is_denied' => false,
				'is_global' => empty(Board::$info->id),
			);

		Utils::$context['member']['permissions']['board'][$row['permission']]['groups'][empty($row['add_deny']) ? 'denied' : 'allowed'][$row['id_group']] = $row['id_group'] == 0 ? Lang::$txt['membergroups_members'] : $row['group_name'];

		Utils::$context['member']['permissions']['board'][$row['permission']]['is_denied'] |= empty($row['add_deny']);
	}
	Db::$db->free_result($request);
}

/**
 * View a member's warnings
 *
 * @param int $memID The ID of the member
 */
function viewWarning($memID)
{
	// Firstly, can we actually even be here?
	if (!(User::$me->is_owner && allowedTo('view_warning_own')) && !allowedTo('view_warning_any') && !allowedTo('issue_warning') && !allowedTo('moderate_forum'))
		fatal_lang_error('no_access', false);

	// Make sure things which are disabled stay disabled.
	Config::$modSettings['warning_watch'] = !empty(Config::$modSettings['warning_watch']) ? Config::$modSettings['warning_watch'] : 110;
	Config::$modSettings['warning_moderate'] = !empty(Config::$modSettings['warning_moderate']) && !empty(Config::$modSettings['postmod_active']) ? Config::$modSettings['warning_moderate'] : 110;
	Config::$modSettings['warning_mute'] = !empty(Config::$modSettings['warning_mute']) ? Config::$modSettings['warning_mute'] : 110;

	// Let's use a generic list to get all the current warnings, and use the issue warnings grab-a-granny thing.
	require_once(Config::$sourcedir . '/Subs-List.php');
	require_once(Config::$sourcedir . '/Profile-Actions.php');

	$listOptions = array(
		'id' => 'view_warnings',
		'title' => Lang::$txt['profile_viewwarning_previous_warnings'],
		'items_per_page' => Config::$modSettings['defaultMaxListItems'],
		'no_items_label' => Lang::$txt['profile_viewwarning_no_warnings'],
		'base_href' => Config::$scripturl . '?action=profile;area=viewwarning;sa=user;u=' . $memID,
		'default_sort_col' => 'log_time',
		'get_items' => array(
			'function' => 'list_getUserWarnings',
			'params' => array(
				$memID,
			),
		),
		'get_count' => array(
			'function' => 'list_getUserWarningCount',
			'params' => array(
				$memID,
			),
		),
		'columns' => array(
			'log_time' => array(
				'header' => array(
					'value' => Lang::$txt['profile_warning_previous_time'],
				),
				'data' => array(
					'db' => 'time',
				),
				'sort' => array(
					'default' => 'lc.log_time DESC',
					'reverse' => 'lc.log_time',
				),
			),
			'reason' => array(
				'header' => array(
					'value' => Lang::$txt['profile_warning_previous_reason'],
					'style' => 'width: 50%;',
				),
				'data' => array(
					'db' => 'reason',
				),
			),
			'level' => array(
				'header' => array(
					'value' => Lang::$txt['profile_warning_previous_level'],
				),
				'data' => array(
					'db' => 'counter',
				),
				'sort' => array(
					'default' => 'lc.counter DESC',
					'reverse' => 'lc.counter',
				),
			),
		),
		'additional_rows' => array(
			array(
				'position' => 'after_title',
				'value' => Lang::$txt['profile_viewwarning_desc'],
				'class' => 'smalltext',
				'style' => 'padding: 2ex;',
			),
		),
	);

	// Create the list for viewing.
	require_once(Config::$sourcedir . '/Subs-List.php');
	createList($listOptions);

	// Create some common text bits for the template.
	Utils::$context['level_effects'] = array(
		0 => '',
		Config::$modSettings['warning_watch'] => Lang::$txt['profile_warning_effect_own_watched'],
		Config::$modSettings['warning_moderate'] => Lang::$txt['profile_warning_effect_own_moderated'],
		Config::$modSettings['warning_mute'] => Lang::$txt['profile_warning_effect_own_muted'],
	);
	Utils::$context['current_level'] = 0;
	foreach (Utils::$context['level_effects'] as $limit => $dummy)
		if (Utils::$context['member']['warning'] >= $limit)
			Utils::$context['current_level'] = $limit;
}

/**
 * Sets the icon for a fetched alert.
 *
 * @param array The alert that we want to set an icon for.
 */
function set_alert_icon($alert)
{
	global $settings;

	switch ($alert['content_type'])
	{
		case 'topic':
		case 'board':
			{
				switch ($alert['content_action'])
				{
					case 'reply':
					case 'topic':
						$class = 'main_icons posts';
						break;

					case 'move':
						$src = $settings['images_url'] . '/post/moved.png';
						break;

					case 'remove':
						$class = 'main_icons delete';
						break;

					case 'lock':
					case 'unlock':
						$class = 'main_icons lock';
						break;

					case 'sticky':
					case 'unsticky':
						$class = 'main_icons sticky';
						break;

					case 'split':
						$class = 'main_icons split_button';
						break;

					case 'merge':
						$class = 'main_icons merge';
						break;

					case 'unapproved_topic':
					case 'unapproved_post':
						$class = 'main_icons post_moderation_moderate';
						break;

					default:
						$class = 'main_icons posts';
						break;
				}
			}
			break;

		case 'msg':
			{
				switch ($alert['content_action'])
				{
					case 'like':
						$class = 'main_icons like';
						break;

					case 'mention':
						$class = 'main_icons im_on';
						break;

					case 'quote':
						$class = 'main_icons quote';
						break;

					case 'unapproved_attachment':
						$class = 'main_icons post_moderation_attach';
						break;

					case 'report':
					case 'report_reply':
						$class = 'main_icons post_moderation_moderate';
						break;

					default:
						$class = 'main_icons posts';
						break;
				}
			}
			break;

		case 'member':
			{
				switch ($alert['content_action'])
				{
					case 'register_standard':
					case 'register_approval':
					case 'register_activation':
						$class = 'main_icons members';
						break;

					case 'report':
					case 'report_reply':
						$class = 'main_icons members_watched';
						break;

					case 'buddy_request':
						$class = 'main_icons people';
						break;

					case 'group_request':
						$class = 'main_icons members_request';
						break;

					default:
						$class = 'main_icons members';
						break;
				}
			}
			break;

		case 'groupr':
			$class = 'main_icons members_request';
			break;

		case 'event':
			$class = 'main_icons calendar';
			break;

		case 'paidsubs':
			$class = 'main_icons paid';
			break;

		case 'birthday':
			$src = $settings['images_url'] . '/cake.png';
			break;

		default:
			$class = 'main_icons alerts';
			break;
	}

	if (isset($class))
		return '<span class="alert_icon ' . $class . '"></span>';
	elseif (isset($src))
		return '<img class="alert_icon" src="' . $src . '">';
	else
		return '';
}

?>