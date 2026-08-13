<?php

use HScript\Mail\Mailer;

use HScript\Util\StringHelper;

use HScript\Template\View;

// Message

// from = uID / to = array()
// params: re / cat / attn / lang / group / full_email
// mGroup is the canonical root Msg.mID of a conversation. Legacy rows can have
// mGroup=0; their root is still resolved through MBox.bRe below.
function messageSend($from, $frommail, $to, $params, $topic, $text)
{
	global $db, $_cfg;
	$usr = array('uLang' => '', 'uLogin' => '', 'uMail' => '');
	if (!$frommail and !($usr = opReadUser($from)))
		return 'user_not_found';
	if (!is_array($params)) $params = array();
	$params['lang'] = $params['lang'] ?? '';
	$params['re'] = $params['re'] ?? 0;
	$params['attn'] = $params['attn'] ?? 0;
	$params['group'] = $params['group'] ?? 0;
	$params['full_email'] = $params['full_email'] ?? false;
	if ($frommail and !validMail($frommail))
		return 'mail_wrong';
	if (!is_array($to)) 
		$to = array($to);
	for ($i = count($to) - 1; $i >= 0; $i--)
		if (!($u = trim($to[$i])))
			unset($to[$i]);
		else
			$to[$i] = $u;
	if (count($to) < 1)
		return 'to_empty';
	if (!$topic)
		return 'topic_empty';
	if (!$text)
		return 'text_empty';
	$lang = StringHelper::exValue($params['lang'], $usr['uLang']);
	$wrusrs = array();
	$users = array();
	foreach ($to as $u)
	{
		if (StringHelper::textPos('mailto:', $u) == 0)
		{
			$u = trim(StringHelper::textSubStr($u, 7, 60)); // e-mail
			$a = $db->fetch1Row($db->select('Users LEFT JOIN AddInfo ON auID=uID', '*', 'uMail=?', array($u)));
			if (!$a and validMail($u))
				$a = array('uID' => 0, 'uMail' => $u, 'aName' => 'User', 'uLang' => $lang, 'aNoMail' => 0);
		}
		else
		{
			$a = $db->fetch1Row($db->select('Users LEFT JOIN AddInfo ON auID=uID', '*', 'uLogin=? and uState=1', array($u)));
		}
		if (!$a)
			$wrusrs[] = $u;
		else
			$users[] = $a;
	}
	if ($wrusrs)
	{
		View::setPage('wrusrs', asStr($wrusrs, ', '));
		return 'to_wrong';
	}
	$mid = $db->insert('Msg', array(
		'muID' => $from,
		'mTS' => timeToStamp(),
		'mMail' => $frommail,
		'mAttn' => $params['attn'],
		'mTopic' => $topic,
		'mText' => $text,
		'mLang' => $lang,
		'mGroup' => $params['group'],
		'mTo' => asStr($to, HS2_NL),
		'mToCnt' => count($to)
	));
	if (!$mid)
		return 'message_save_failed';
	if (intval($params['group']) < 1)
	{
		$thread_group = intval($params['re']) > 0
			? messageThreadRootMessageId(intval($params['re']))
			: intval($mid);
		if ($thread_group < 1)
			$thread_group = intval($mid);
		$db->update('Msg', array('mGroup' => $thread_group), '', 'mID=?d', array($mid));
	}
	$a = array(
		'from' => StringHelper::exValue($frommail, $usr['uLogin']),
		'topic' => $topic,
		'message' => $text,
		'remessage' => $db->fetch1($db->select('MBox LEFT JOIN Msg ON mID=bmID', 'mText', 'bID=?d', array($params['re']))),
		'url' => fullURL(moduleToLink('message/show'))
	);
	foreach ($users as $u)
	{
		$id = 0;
		if ($u['uID'])
		{
			$id = $db->insert('MBox', array( // Inbox
				'buID' => $u['uID'],
				'bmID' => $mid,
				'bRe' => $params['re'],
				'buID2' => $from,
				'bMail' => $frommail,
				'bRTS' => 0
			));
		}
		$sendEmail = !$u['uID'] || (empty($u['aNoMail']) && ($params['full_email'] || !$_cfg['Msg_NoMail']));
		if ($sendEmail)
			sendMailToUser2($u['uMail'], 'Notice' . StringHelper::valueIf(!$u['uID'] or !$_cfg['Msg_Mode'] or $params['full_email'], 'ToMail'),
				$a + array('name' => $u['aName'], 'id' => $id), $u['uLang'], 'user', StringHelper::exValue($usr['uMail'], $frommail));
	}
	return $mid;
}

/**
 * Resolve the root inbox row for a reply chain.
 */
function messageThreadRootBox($box_id)
{
	global $db;
	$box_id = intval($box_id);
	if ($box_id < 1)
		return array();

	$root = $db->fetch1Row($db->select('MBox', 'bID, bmID, bRe', 'bID=?d', array($box_id)));
	$seen = array();
	while (!empty($root['bRe']) and (count($seen) < 200))
	{
		$current_id = intval($root['bID'] ?? 0);
		if (($current_id < 1) or isset($seen[$current_id]))
			break;
		$seen[$current_id] = true;
		$parent = $db->fetch1Row($db->select('MBox', 'bID, bmID, bRe', 'bID=?d', array($root['bRe'])));
		if (!$parent)
			break;
		$root = $parent;
	}
	return $root;
}

function messageThreadRootMessageId($box_id)
{
	$root = messageThreadRootBox($box_id);
	return intval($root['bmID'] ?? 0);
}

/**
 * Return a complete reply chain for one inbox row.
 *
 * MBox.bRe points to the inbox row being replied to, so the chain has to be
 * resolved by bID rather than by Msg.mGroup (legacy replies did not fill it).
 */
function messageThreadByBox($box_id)
{
	global $db;
	$box_id = intval($box_id);
	if ($box_id < 1)
		return array();

	$root = messageThreadRootBox($box_id);
	if (!$root)
		return array();

	$list = array();
	$queue = array(intval($root['bID']));
	$seen = array();
	while ($queue and (count($seen) < 200))
	{
		$current_id = intval(array_shift($queue));
		if (($current_id < 1) or isset($seen[$current_id]))
			continue;
		$seen[$current_id] = true;
		$row = $db->fetch1Row($db->select(
			'MBox LEFT JOIN Msg ON Msg.mID=MBox.bmID LEFT JOIN Users Sender ON Sender.uID=Msg.muID LEFT JOIN AddInfo SenderInfo ON SenderInfo.auID=Sender.uID LEFT JOIN Users Recipient ON Recipient.uID=MBox.buID',
			'MBox.*, Msg.*, Sender.uID AS uID, Sender.uLogin AS uLogin, Sender.uMail AS uMail, Sender.uLevel AS uLevel, SenderInfo.aName AS aName, SenderInfo.aAvatar AS aAvatar, Recipient.uID AS recipient_uID, Recipient.uLogin AS recipient_login, Recipient.uMail AS recipient_mail, Recipient.uLevel AS recipient_level',
			'MBox.bID=?d', array($current_id)
		));
		if ($row)
			$list[] = $row;
		$children = $db->fetchRows($db->select('MBox', 'bID', 'bRe=?d', array($current_id), 'bID'));
		foreach ($children as $child)
			$queue[] = intval($child['bID']);
	}
	usort($list, static function ($a, $b) {
		$stamp_cmp = strcmp((string)($a['mTS'] ?? ''), (string)($b['mTS'] ?? ''));
		return $stamp_cmp ?: (intval($a['bID'] ?? 0) <=> intval($b['bID'] ?? 0));
	});
	return $list;
}

function messageThreadByMessage($message_id)
{
	global $db;
	$box_id = $db->fetch1($db->select('MBox', 'bID', 'bmID=?d', array(intval($message_id)), 'bID', 1));
	return messageThreadByBox($box_id);
}

/**
 * Pick the other side of a thread. Admin conversations prefer the customer,
 * so a reply from another staff member never gets addressed back to staff.
 */
function messageThreadRecipient($thread, $sender_id, $prefer_customer = false)
{
	$sender_id = intval($sender_id);
	$rows = array_reverse((array)$thread);
	if ($prefer_customer)
	{
		foreach ($rows as $row)
		{
			$from_id = intval($row['muID'] ?? 0);
			$from_level = intval($row['uLevel'] ?? 0);
			if (($from_id != $sender_id) and ($from_level < 50))
			{
				if (!empty($row['uLogin']))
					return (string)$row['uLogin'];
				if (!empty($row['mMail']))
					return 'mailto:' . trim((string)$row['mMail']);
			}
			$recipient_id = intval($row['recipient_uID'] ?? 0);
			if (($recipient_id != $sender_id) and (intval($row['recipient_level'] ?? 0) < 50) and !empty($row['recipient_login']))
				return (string)$row['recipient_login'];
		}
	}

	foreach ($rows as $row)
	{
		$from_id = intval($row['muID'] ?? 0);
		if ($from_id != $sender_id)
		{
			if (!empty($row['uLogin']))
				return (string)$row['uLogin'];
			if (!empty($row['mMail']))
				return 'mailto:' . trim((string)$row['mMail']);
		}
		if ((intval($row['recipient_uID'] ?? 0) != $sender_id) and !empty($row['recipient_login']))
			return (string)$row['recipient_login'];
	}
	return '';
}

function messageThreadBelongsToUser($thread, $uid)
{
	$uid = intval($uid);
	foreach ((array)$thread as $row)
		if ((intval($row['buID'] ?? 0) == $uid) or (intval($row['muID'] ?? 0) == $uid))
			return true;
	return false;
}

/**
 * Backfill the canonical group for legacy data. This turns an old reply chain
 * into the same stable model used by newly created conversations.
 */
function messageNormalizeConversationGroups()
{
	global $db;
	static $normalized = false;
	if ($normalized)
		return;
	$normalized = true;
	$legacy = $db->fetchRows($db->select(
		'MBox LEFT JOIN Msg ON Msg.mID=MBox.bmID',
		'MBox.bID, MBox.bmID, MBox.bRe', 'COALESCE(Msg.mGroup, 0)=0', null, 'MBox.bID'
	));
	foreach ($legacy as $box)
	{
		$group_id = !empty($box['bRe'])
			? messageThreadRootMessageId($box['bID'])
			: intval($box['bmID']);
		if ($group_id > 0)
			$db->update('Msg', array('mGroup' => $group_id), '', 'mID=?d and COALESCE(mGroup, 0)=0', array($box['bmID']));
	}
	$db->query('UPDATE Msg SET mGroup=mID WHERE COALESCE(mGroup, 0)=0');
}

/**
 * Collapse every conversation the user participates in into one summary row.
 * Sent and received messages intentionally share the same journal.
 */
function messageUserConversationSummaries($uid, $page, $page_size)
{
	global $db;
	$uid = intval($uid);
	messageNormalizeConversationGroups();
	$groups = $db->fetchIDRows($db->select(
		'Msg ParticipantMsg LEFT JOIN MBox ParticipantBox ON ParticipantBox.bmID=ParticipantMsg.mID LEFT JOIN Msg ThreadMsg ON ThreadMsg.mGroup=ParticipantMsg.mGroup',
		'ParticipantMsg.mGroup AS group_id, MAX(ThreadMsg.mTS) AS last_ts, MAX(ParticipantBox.bID) AS box_id',
		'(ParticipantMsg.muID=?d or (ParticipantBox.buID=?d and ParticipantBox.bDeleted=0))', array($uid, $uid),
		'MAX(ThreadMsg.mTS) desc, ParticipantMsg.mGroup desc', '', 'ParticipantMsg.mGroup'
	), false, 'group_id');
	$groups = opArrayPageGet(intval($page), intval($page_size), $groups);

	$list = array();
	foreach ($groups as $group)
	{
		$thread = messageThreadByBox($group['box_id']);
		if (!$thread or !messageThreadBelongsToUser($thread, $uid))
			continue;
		$first = reset($thread);
		$last = end($thread);
		$unread = 0;
		foreach ($thread as $row)
			if ((intval($row['buID'] ?? 0) == $uid) and empty($row['bRTS']) and empty($row['bDeleted']))
				$unread++;
		$last['mTopic'] = (string)($first['mTopic'] ?? $last['mTopic'] ?? '');
		$last['_RootMessageID'] = intval($group['group_id']);
		$last['_MessageCount'] = count($thread);
		$last['_UnreadCount'] = $unread;
		$last['_Marked'] = $unread > 0;
		$peer = messageThreadRecipient($thread, $uid);
		$last['_Peer'] = str_starts_with($peer, 'mailto:') ? substr($peer, 7) : $peer;
		$list[intval($last['bID'])] = $last;
	}
	return $list;
}

/**
 * Collapse the global administrator journal into conversations as well.
 */
function messageAdminConversationSummaries($admin_uid, $page, $page_size)
{
	global $db;
	$admin_uid = intval($admin_uid);
	messageNormalizeConversationGroups();
	$group_rows = $db->fetchIDRows($db->select(
		'Msg', 'mGroup AS group_id, MAX(mTS) AS last_ts, MAX(mID) AS last_mid',
		'', null, 'MAX(mTS) desc, MAX(mID) desc', '', 'mGroup'
	), false, 'group_id');
	$group_rows = opArrayPageGet(intval($page), intval($page_size), $group_rows);
	$group_ids = array_map('intval', array_keys($group_rows));
	if (!$group_ids)
		return array();
	$messages = $db->fetchRows($db->select(
		'Msg LEFT JOIN Users Sender ON Sender.uID=Msg.muID',
		'Msg.*, Sender.uLogin, Sender.uMail, Sender.uLevel', 'Msg.mGroup ?i', array($group_ids), 'Msg.mTS, Msg.mID'
	));
	$message_ids = array_map(static fn($message) => intval($message['mID']), $messages);
	$boxes = $message_ids
		? $db->fetchRows($db->select('MBox', '*', 'bmID ?i', array($message_ids), 'bID'))
		: array();
	$boxes_by_message = array();
	foreach ($boxes as $box)
		$boxes_by_message[intval($box['bmID'])][] = $box;
	$groups = array();
	foreach ($messages as $message)
	{
		$group_id = intval($message['mGroup']);
		if (!isset($groups[$group_id]))
			$groups[$group_id] = array('messages' => array(), 'boxes' => array());
		$groups[$group_id]['messages'][] = $message;
		$groups[$group_id]['boxes'] = array_merge(
			$groups[$group_id]['boxes'],
			$boxes_by_message[intval($message['mID'])] ?? array()
		);
	}

	$list = array();
	foreach ($groups as $group_id => $group)
	{
		$first = reset($group['messages']);
		$last = end($group['messages']);
		$unread = 0;
		$marked = false;
		$important = false;
		$peers = array();
		foreach ($group['messages'] as $message)
		{
			$marked = $marked or (intval($message['mAttn'] ?? 0) == 9);
			$important = $important or (intval($message['mAttn'] ?? 0) > 0);
			if ((intval($message['muID'] ?? 0) != $admin_uid) and !empty($message['uLogin']))
				$peers[(string)$message['uLogin']] = true;
			elseif (!empty($message['mMail']))
				$peers[(string)$message['mMail']] = true;
			foreach (asArray($message['mTo'] ?? '', HS2_NL) as $recipient)
				if ($recipient and ($recipient != ($GLOBALS['_user']['uLogin'] ?? '')))
					$peers[(string)$recipient] = true;
		}
		foreach ($group['boxes'] as $box)
			if ((intval($box['buID'] ?? 0) == $admin_uid) and empty($box['bRTS']) and empty($box['bDeleted']))
				$unread++;
		$last['mTopic'] = (string)($first['mTopic'] ?? $last['mTopic'] ?? '');
		$last['mID'] = intval($last['mID']);
		$last['_RootMessageID'] = intval($group_id);
		$last['_MessageCount'] = count($group['messages']);
		$last['_UnreadCount'] = $unread;
		$last['_Marked'] = $marked or ($unread > 0);
		$last['_Important'] = $important;
		$last['_Peer'] = asStr(array_keys($peers), ', ');
		$last['To'] = asStr(asArray($last['mTo'] ?? '', HS2_NL), ', ');
		$list[$last['mID']] = $last;
	}
	uksort($list, static function ($a, $b) use ($list) {
		$stamp_cmp = strcmp((string)($list[$b]['mTS'] ?? ''), (string)($list[$a]['mTS'] ?? ''));
		return $stamp_cmp ?: (intval($b) <=> intval($a));
	});
	return $list;
}

function messageDeleteConversationForAdmin($message_id)
{
	global $db;
	messageNormalizeConversationGroups();
	$group_id = intval($db->fetch1($db->select('Msg', 'mGroup', 'mID=?d', array(intval($message_id)))));
	$message_ids = $group_id > 0
		? $db->fetchRows($db->select('Msg', 'mID', 'mGroup=?d', array($group_id)), 1)
		: array();
	$message_ids = array_values(array_unique(array_filter($message_ids)));
	if (!$message_ids)
		return false;
	$db->delete('MBox', 'bmID ?i', array($message_ids));
	$db->delete('Msg', 'mID ?i', array($message_ids));
	return true;
}

function sendMailToUser2($mail, $section, $consts = array(), $lang = '', $scope = 'user', $from = '')
{
	global $_GS, $_cfg;
	if (!validMail($mail) or !$section)
		return false;
	$scope = $scope === 'admin' ? 'admin' : 'user';
	$content = View::emailContent($section, $consts, $lang, $scope);
	if (!$content)
		return false;
	return Mailer::send(
		$mail,
		$content['subject'],
		$content['message'],
		StringHelper::exValue($_cfg['Sys_NotifyMail'], $from)
	);
}

?>
