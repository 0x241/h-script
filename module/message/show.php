<?php

use HScript\Util\StringHelper;

use HScript\Template\View;

$_auth = 1;
require_once('module/auth.php');

$table = 'MBox';
$id_field = 'bID';
$out_link = moduleToLink('message');
$message_link = moduleToLink('message/show');
$is_hx = isset($_SERVER['HTTP_HX_REQUEST']) && ($_SERVER['HTTP_HX_REQUEST'] == 'true');

if (($_cfg['Msg_Mode'] < 1) or (isset($_GET['new']) and ($_cfg['Msg_Mode'] < 2)))
	goToURL($out_link);

try
{
	if (View::sendedForm('reply'))
	{
		View::checkFormSecurity();
		$thread = messageThreadByBox(_INN('ThreadBox'));
		if (!$thread)
			View::setError('message_not_found');
		if (!messageThreadBelongsToUser($thread, _uid()))
			View::setError('message_not_found');
		$first = reset($thread);
		$last = end($thread);
		$recipient = messageThreadRecipient($thread, _uid());
		if (!$recipient)
			View::setError('user_not_found');
		$params = array(
			're' => intval($last['bID'] ?? 0),
			'attn' => 0,
			'group' => intval($first['mID'] ?? 0)
		);
		View::setError($new_message_id = messageSend(
			_uid(), '', $recipient, $params,
			(string)($first['mTopic'] ?? $last['mTopic'] ?? ''), _IN('mText')
		));
		$new_box_id = $db->fetch1($db->select('MBox', 'bID', 'bmID=?d', array($new_message_id), 'bID', 1));
		if ($is_hx)
		{
			unset($_IN['mText']);
			$_GET['id'] = $new_box_id;
		}
		else
			View::showInfo('Completed', $message_link . "?id=$new_box_id");
	}

	if (View::sendedForm('send'))
	{
		View::checkFormSecurity();
		
		$params = array(
			're' => _INN('Re'),
			'attn' => _IN('Attn'),
			'group' => 0
		);
		View::setError($id = messageSend(_uid(), '', _IN('mTo'), $params, _IN('mTopic'), _IN('mText')));
		$new_box_id = $db->fetch1($db->select('MBox', 'bID', 'bmID=?d', array($id), 'bID', 1));
		View::showInfo('Completed', $new_box_id ? $message_link . "?id=$new_box_id" : $out_link);
	}

} 
catch (FormAbortException $e)
{
}

if (!isset($_GET['new']))
{
	if ($id = _GETN('id'))
		$el = $db->fetch1Row($db->select("$table LEFT JOIN Msg ON mID=bmID LEFT JOIN Users ON uID=buID2 LEFT JOIN AddInfo ON auID=uID",
		"$table.*, Msg.*, Users.uID, Users.uLogin, Users.uMail, Users.uLevel, AddInfo.aName, AddInfo.aAvatar", "$id_field=?d and (buID=?d or muID=?d)", array($id, _uid(), _uid())));
	if (!$el)
		goToURL($out_link);
	$thread = messageThreadByBox($id);
	$marked_read = false;
	foreach ($thread as $thread_item)
		if ((intval($thread_item['buID'] ?? 0) == _uid()) and empty($thread_item['bRTS']))
			if ($db->update($table, array('bRTS' => timeToStamp()), '', "$id_field=?d and buID=?d", array($thread_item['bID'], _uid())))
				$marked_read = true;
	View::stampArrayToStr($el, 'mTS, bRTS', 0);
	View::stampTableToStr($thread, 'mTS, bRTS', 0);
	View::setPage('list', $thread, 2);

	if ($marked_read)
		updateUserCounters();
}
elseif ($re = _GETN('re'))
	if ($rel = $db->fetch1Row($db->select("$table LEFT JOIN Msg ON mID=bmID LEFT JOIN Users ON uID=muID",
		"$table.*, Msg.*, Users.uLogin", "$id_field=?d and buID=?d", array($re, _uid()))))
		$el = array(
			'Re' => $re,
			'mTopic' => 'Re: ' . $rel['mTopic'],
			'mText' => HS2_NL . HS2_NL . StringHelper::textReplace(HS2_NL . $rel['mText'], HS2_NL, HS2_NL . '> '),
			'mLang' => $rel['mLang'],
			'mGroup' => StringHelper::exValue($re, $rel['mGroup']),
			'mTo' => StringHelper::exValue('mailto:' . $rel['mMail'], $rel['uLogin'])
		);

View::setPage('el', $el, 2);
View::showPage();

?>
