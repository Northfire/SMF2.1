<?php

/**
 * Handles reported posts and moderation comments.
 *
 * Simple Machines Forum (SMF)
 *
 * @package SMF
 * @author Simple Machines http://www.simplemachines.org
 * @copyright 2014 Simple Machines and individual contributors
 * @license http://www.simplemachines.org/about/smf/license.php BSD
 *
 * @version 2.1 Alpha 1
 */

if (!defined('SMF'))
	die('No direct access...');

/**
 * Sets and call a function based on the given subaction.
 * It requires the moderate_forum permission.
 *
 * @uses ModerationCenter template.
 * @uses ModerationCenter language file.
 *
 */
function ReportedPosts()
{
	global $txt, $context, $scripturl, $user_info, $smcFunc;
	global $sourcedir;

	loadLanguage('ModerationCenter');
	loadTemplate('ReportedPosts');

	// We need this little rough gem.
	require_once($sourcedir . '/Subs-ReportedPosts.php');

	// Set up the comforting bits...
	$context['page_title'] = $txt['mc_reported_posts'];
	$context['sub_template'] = 'reported_posts';

	// This comes under the umbrella of moderating posts.
	if ($user_info['mod_cache']['bq'] == '0=1')
		isAllowedTo('moderate_forum');

	$sub_actions = array(
		'show' => 'ShowReports', // Both open and closed reports
		'handle' => 'HandleReport', // Deals with closing/opening reports.
		'disregard' => 'DisregardReport',
		'details' => 'ReportDetails', // Shows a single report and its comments.
		'handlecomment' => 'AddComment', // CRUD actions for moderator comments.
	);

	// Go ahead and add your own sub-actions.
	call_integration_hook('integrate_reported_posts', array(&$sub_actions));

	// By default we call the open sub-action.
	if (isset($_REQUEST['sa']) && isset($sub_actions[$_REQUEST['sa']]))
		$context['sub_action'] = $smcFunc['htmltrim']($smcFunc['htmlspecialchars']($_REQUEST['sa']), ENT_QUOTES);

	else
		$context['sub_action'] = 'show';

	// Call the function!
	$sub_actions[$context['sub_action']]();
}

/**
 * Shows all open or closed reported posts.
 * It requires the moderate_forum permission.
 *
 * @uses ModerationCenter template.
 * @uses ModerationCenter language file.
 *
 */
function ShowReports()
{
	global $context, $txt, $scripturl;

	// Put the open and closed options into tabs, because we can...
	$context[$context['moderation_menu_name']]['tab_data'] = array(
		'title' => $txt['mc_reported_posts'],
		'help' => '',
		'description' => $txt['mc_reported_posts_desc'],
		'tabs' => array(
			'show' => array($txt['mc_reportedp_active']),
			'show;closed' => array($txt['mc_reportedp_closed']),
		),
	);

	// Showing closed or open ones? regardless, turn this to an integer for better handling.
	$context['view_closed'] = (int) isset($_GET['closed']);

	// Call the right template.
	$context['sub_template'] = 'reported_posts';
	$context['start'] = (int) isset($_GET['start']) ? $_GET['start'] : 0;

	// Before anything, we need to know just how many reports do we have.
	$context['total_reports'] = countReports($context['view_closed']);

	// So, that means we can have pagination, yes?
	$context['page_index'] = constructPageIndex($scripturl . '?action=moderate;area=reports;sa=show' . ($context['view_closed'] ? ';closed' : ''), $context['start'], $context['total_reports'], 10);

	// Get the reposts at once!
	$context['reports'] = getReports($context['view_closed']);
}

function ReportDetails()
{
	global $user_info, $context, $sourcedir, $scripturl, $txt;
	global $smcFunc;

	// Have to at least give us something to work with.
	if (empty($_REQUEST['report']))
		fatal_lang_error('mc_no_modreport_specified');

	// Integers only please
	$_REQUEST['report'] = (int) $_REQUEST['report'];

	// Get the report details.
	$report = getReportDetails($_REQUEST['report']);

	if(!$report)
		fatal_lang_error('mc_no_modreport_found');

	// If they are adding a comment then... add a comment.
	if (isset($_POST['add_comment']) && !empty($_POST['mod_comment']))
	{
		checkSession();

		$newComment = trim($smcFunc['htmlspecialchars']($_POST['mod_comment']));

		// In it goes.
		if (!empty($newComment))
		{
			$smcFunc['db_insert']('',
				'{db_prefix}log_comments',
				array(
					'id_member' => 'int', 'member_name' => 'string', 'comment_type' => 'string', 'recipient_name' => 'string',
					'id_notice' => 'int', 'body' => 'string', 'log_time' => 'int',
				),
				array(
					$user_info['id'], $user_info['name'], 'reportc', '',
					$_REQUEST['report'], $newComment, time(),
				),
				array('id_comment')
			);
			$last_comment = $smcFunc['db_insert_id']('{db_prefix}log_comments', 'id_comment');

			// And get ready to notify people.
			$smcFunc['db_insert']('insert',
				'{db_prefix}background_tasks',
				array('task_file' => 'string', 'task_class' => 'string', 'task_data' => 'string', 'claimed_time' => 'int'),
				array('$sourcedir/tasks/MsgReportReply-Notify.php', 'MsgReportReply_Notify_Background', serialize(array(
					'report_id' => $_REQUEST['report'],
					'comment_id' => $last_comment,
					'msg_id' => $row['id_msg'],
					'topic_id' => $row['id_topic'],
					'board_id' => $row['id_board'],
					'sender_id' => $user_info['id'],
					'sender_name' => $user_info['name'],
					'time' => time(),
				)), 0),
				array('id_task')
			);

			// Redirect to prevent double submission.
			redirectexit($scripturl . '?action=moderate;area=reports;report=' . $_REQUEST['report']);
		}
	}

	$context['report'] = array(
		'id' => $row['id_report'],
		'topic_id' => $row['id_topic'],
		'board_id' => $row['id_board'],
		'message_id' => $row['id_msg'],
		'message_href' => $scripturl . '?msg=' . $row['id_msg'],
		'message_link' => '<a href="' . $scripturl . '?msg=' . $row['id_msg'] . '">' . $row['subject'] . '</a>',
		'report_href' => $scripturl . '?action=moderate;area=reports;report=' . $row['id_report'],
		'author' => array(
			'id' => $row['id_author'],
			'name' => $row['author_name'],
			'link' => $row['id_author'] ? '<a href="' . $scripturl . '?action=profile;u=' . $row['id_author'] . '">' . $row['author_name'] . '</a>' : $row['author_name'],
			'href' => $scripturl . '?action=profile;u=' . $row['id_author'],
		),
		'comments' => array(),
		'mod_comments' => array(),
		'time_started' => timeformat($row['time_started']),
		'last_updated' => timeformat($row['time_updated']),
		'subject' => $row['subject'],
		'body' => parse_bbc($row['body']),
		'num_reports' => $row['num_reports'],
		'closed' => $row['closed'],
		'ignore' => $row['ignore_all']
	);

	// So what bad things do the reporters have to say about it?
	$request = $smcFunc['db_query']('', '
		SELECT lrc.id_comment, lrc.id_report, lrc.time_sent, lrc.comment, lrc.member_ip,
			IFNULL(mem.id_member, 0) AS id_member, IFNULL(mem.real_name, lrc.membername) AS reporter
		FROM {db_prefix}log_reported_comments AS lrc
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lrc.id_member)
		WHERE lrc.id_report = {int:id_report}',
		array(
			'id_report' => $context['report']['id'],
		)
	);
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		$context['report']['comments'][] = array(
			'id' => $row['id_comment'],
			'message' => strtr($row['comment'], array("\n" => '<br>')),
			'time' => timeformat($row['time_sent']),
			'member' => array(
				'id' => $row['id_member'],
				'name' => empty($row['reporter']) ? $txt['guest'] : $row['reporter'],
				'link' => $row['id_member'] ? '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['reporter'] . '</a>' : (empty($row['reporter']) ? $txt['guest'] : $row['reporter']),
				'href' => $row['id_member'] ? $scripturl . '?action=profile;u=' . $row['id_member'] : '',
				'ip' => !empty($row['member_ip']) && allowedTo('moderate_forum') ? '<a href="' . $scripturl . '?action=trackip;searchip=' . $row['member_ip'] . '">' . $row['member_ip'] . '</a>' : '',
			),
		);
	}
	$smcFunc['db_free_result']($request);

	// Hang about old chap, any comments from moderators on this one?
	$request = $smcFunc['db_query']('', '
		SELECT lc.id_comment, lc.id_notice, lc.log_time, lc.body,
			IFNULL(mem.id_member, 0) AS id_member, IFNULL(mem.real_name, lc.member_name) AS moderator
		FROM {db_prefix}log_comments AS lc
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lc.id_member)
		WHERE lc.id_notice = {int:id_report}
			AND lc.comment_type = {literal:reportc}',
		array(
			'id_report' => $context['report']['id'],
		)
	);
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		$context['report']['mod_comments'][] = array(
			'id' => $row['id_comment'],
			'message' => parse_bbc($row['body']),
			'time' => timeformat($row['log_time']),
			'can_edit' => allowedTo('admin_forum') || (($user_info['id'] == $row['id_member']) && allowedTo('moderate_forum')),
			'member' => array(
				'id' => $row['id_member'],
				'name' => $row['moderator'],
				'link' => $row['id_member'] ? '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['moderator'] . '</a>' : $row['moderator'],
				'href' => $scripturl . '?action=profile;u=' . $row['id_member'],
			),
		);
	}
	$smcFunc['db_free_result']($request);

	// What have the other moderators done to this message?
	require_once($sourcedir . '/Modlog.php');
	require_once($sourcedir . '/Subs-List.php');
	loadLanguage('Modlog');

	// This is all the information from the moderation log.
	$listOptions = array(
		'id' => 'moderation_actions_list',
		'title' => $txt['mc_modreport_modactions'],
		'items_per_page' => 15,
		'no_items_label' => $txt['modlog_no_entries_found'],
		'base_href' => $scripturl . '?action=moderate;area=reports;report=' . $context['report']['id'],
		'default_sort_col' => 'time',
		'get_items' => array(
			'function' => 'list_getModLogEntries',
			'params' => array(
				'lm.id_topic = {int:id_topic}',
				array('id_topic' => $context['report']['topic_id']),
				1,
			),
		),
		'get_count' => array(
			'function' => 'list_getModLogEntryCount',
			'params' => array(
				'lm.id_topic = {int:id_topic}',
				array('id_topic' => $context['report']['topic_id']),
				1,
			),
		),
		// This assumes we are viewing by user.
		'columns' => array(
			'action' => array(
				'header' => array(
					'value' => $txt['modlog_action'],
				),
				'data' => array(
					'db' => 'action_text',
					'class' => 'smalltext',
				),
				'sort' => array(
					'default' => 'lm.action',
					'reverse' => 'lm.action DESC',
				),
			),
			'time' => array(
				'header' => array(
					'value' => $txt['modlog_date'],
				),
				'data' => array(
					'db' => 'time',
					'class' => 'smalltext',
				),
				'sort' => array(
					'default' => 'lm.log_time',
					'reverse' => 'lm.log_time DESC',
				),
			),
			'moderator' => array(
				'header' => array(
					'value' => $txt['modlog_member'],
				),
				'data' => array(
					'db' => 'moderator_link',
					'class' => 'smalltext',
				),
				'sort' => array(
					'default' => 'mem.real_name',
					'reverse' => 'mem.real_name DESC',
				),
			),
			'position' => array(
				'header' => array(
					'value' => $txt['modlog_position'],
				),
				'data' => array(
					'db' => 'position',
					'class' => 'smalltext',
				),
				'sort' => array(
					'default' => 'mg.group_name',
					'reverse' => 'mg.group_name DESC',
				),
			),
			'ip' => array(
				'header' => array(
					'value' => $txt['modlog_ip'],
				),
				'data' => array(
					'db' => 'ip',
					'class' => 'smalltext',
				),
				'sort' => array(
					'default' => 'lm.ip',
					'reverse' => 'lm.ip DESC',
				),
			),
		),
	);

	// Create the watched user list.
	createList($listOptions);

	// Make sure to get the correct tab selected.
	if ($context['report']['closed'])
		$context[$context['moderation_menu_name']]['current_subsection'] = 'closed';

	// Finally we are done :P
	$context['page_title'] = sprintf($txt['mc_viewmodreport'], $context['report']['subject'], $context['report']['author']['name']);
	$context['sub_template'] = 'viewmodreport';
}
?>