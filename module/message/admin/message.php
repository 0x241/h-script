<?php

use HScript\Util\StringHelper;

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

$table = 'Msg';
$id_field = 'mID';
$out_link = moduleToLink('message/admin/messages');
$message_link = moduleToLink('message/admin/message');
$is_hx = isset($_SERVER['HTTP_HX_REQUEST']) && ($_SERVER['HTTP_HX_REQUEST'] == 'true');
$el = array();

try 
{
	if (View::sendedForm('reply'))
	{
		View::checkFormSecurity();
		$thread = messageThreadByBox(_INN('ThreadBox'));
		if (!$thread)
			View::setError('message_not_found');
		$first = reset($thread);
		$last = end($thread);
		$recipient = messageThreadRecipient($thread, _uid(), true);
		if (!$recipient)
			View::setError('user_not_found');
		$params = array(
			're' => intval($last['bID'] ?? 0),
			'attn' => 0,
			'full_email' => false,
			'group' => intval($first['mID'] ?? 0)
		);
		View::setError($new_message_id = messageSend(
			_uid(), '', $recipient, $params,
			(string)($first['mTopic'] ?? $last['mTopic'] ?? ''), _IN('mText')
		));
		if ($is_hx)
		{
			unset($_IN['mText']);
			$_GET['id'] = $new_message_id;
		}
		else
			View::showInfo('Completed', $message_link . "?id=$new_message_id");
	}

	if (View::sendedForm('send'))
	{
		View::checkFormSecurity();
		
		$uid = 0;
		if (!_IN('mMail') and !($uid = $db->fetch1($db->select('Users', 'uID', 'uLogin=? and uState=1', array(_IN('uLogin'))))))
			View::setError('user_not_found');
		$params = array(
			're' => _INN('Re'),
			'attn' => _IN('Attn'),
			'full_email' => _IN('FullEmail'),
			'group' => 0
		);
		if (trim($mTo = _IN('mTo')) == '*')
			$mTo = $db->fetchRows($db->select('Users', 'uLogin', 'uState=1'), 1);
		else
			$mTo = asArray($mTo, HS2_NL);
		View::setError($id = messageSend($uid, _IN('mMail'), $mTo, $params, _IN('mTopic'), _IN('mText')));
		View::showInfo('Completed', $out_link . "?id=$id");
	}

} 
catch (FormAbortException $e)
{
}

if (!isset($_GET['add']))
{
	if ($id = _GETN('id'))
		$el = $db->fetch1Row($db->select("$table LEFT JOIN Users ON uID=muID LEFT JOIN AddInfo ON auID=uID",
			"$table.*, Users.uID, Users.uLogin, Users.uMail, Users.uLevel, AddInfo.aName, AddInfo.aAvatar", "$id_field=?d", array($id)));
	if (!$el)
		goToURL(moduleToLink() . '?add');
	$db->update($table, array('mAttn' => 1), '', "$id_field=?d and mAttn=9", array($id));
	View::stampArrayToStr($el, 'mTS', 0);
	$thread = messageThreadByMessage($id);
	$marked_read = false;
	foreach ($thread as $thread_item)
	{
		if ((intval($thread_item['buID'] ?? 0) == _uid()) and empty($thread_item['bRTS']))
			if ($db->update('MBox', array('bRTS' => timeToStamp()), '', 'bID=?d and buID=?d', array($thread_item['bID'], _uid())))
				$marked_read = true;
		if (intval($thread_item['mAttn'] ?? 0) == 9)
			$db->update('Msg', array('mAttn' => 1), '', 'mID=?d and mAttn=9', array($thread_item['mID']));
	}
	View::setPage('thread_box_id', $thread ? intval($thread[0]['bID']) : 0);
	View::stampTableToStr($thread, 'mTS, bRTS', 0);
	View::setPage('list', $thread, 2);
	if ($marked_read)
		updateUserCounters();
}
elseif ($re = _GETN('re'))
	if ($rel = $db->fetch1Row($db->select("$table LEFT JOIN Users ON uID=muID", 
		"$table.*, Users.uLogin", "$id_field=?d", array($re))))
	{
		if ($rel['mToCnt'] != 1)
			goToURL(moduleToLink() . '?add');
		$el = array(
			'Re' => $db->fetch1($db->select('MBox', 'bID', 'bmID=?d', array($re))), // Inbox
			'mTopic' => 'Re: ' . $rel['mTopic'],
			'mText' => HS2_NL . HS2_NL . StringHelper::textReplace(HS2_NL . $rel['mText'], HS2_NL, HS2_NL . '> '),
			'mLang' => $rel['mLang'],
			'mGroup' => StringHelper::exValue($re, $rel['mGroup']),
			'mTo' => StringHelper::exValue('mailto:' . $rel['mMail'], $rel['uLogin'])
		);
		if (StringHelper::textPos('mailto:', $rel['mTo']) == 0)
			$el['mMail'] = trim(StringHelper::textSubStr($rel['mTo'], 7, 60));
		else
			$el['uLogin'] = $rel['mTo'];
	}
	
View::setPage('el', $el, 2);

View::showPage();

?>
