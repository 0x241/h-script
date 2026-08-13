<?php

use HScript\Template\View;
use HScript\Queue\JobQueue;

$_auth = 90;
require_once('module/auth.php');

$table = 'Jobs';
$id_field = 'jID';
	
try 
{

	if (isset_IN('ids') and (count($ids = (array)_IN('ids')) > 0))
	{
		$ids = $db->fetchRows($db->select($table, $id_field, '?# ?i', array($id_field, $ids)), 1);
		if (count($ids) > 0)
		{
			View::checkFormSecurity();
			
			if (View::sendedForm('del'))
			{
				$db->delete($table, '?# ?i', array($id_field, $ids));
			}
			
			View::showInfo();
		}
		else
			View::showInfo('*NoSelected');
	}

} 
catch (FormAbortException $e)
{
}

$list = opPageGet(_GETN('page'), 20, $table, '*', 'jType=?', array('sms'),
	array(
		$id_field => array(),
		'jState' => array('jState', 'jState desc', 'jCTS desc', 'jCTS'),
		'jAttempts' => array('jAttempts', 'jAttempts desc')
	),
	_GET('sort'), $id_field
);
$userIds = array();
foreach ($list as $id => $job)
{
	$payload = JobQueue::decodePayload((string)$job['jPayload']);
	$list[$id]['payload'] = $payload;
	$list[$id]['result'] = isset($payload['result']) && is_array($payload['result'])
		? $payload['result']
		: array();
	$userId = (int)($payload['uid'] ?? 0);
	$list[$id]['user_id'] = $userId;
	if ($userId > 0)
		$userIds[$userId] = $userId;
}
$users = $userIds
	? $db->fetchIDRows(
		$db->select('Users', 'uID, uLogin', 'uID ?i', array(array_values($userIds))),
		'uLogin',
		'uID'
	)
	: array();
foreach ($list as $id => $job)
	$list[$id]['user_login'] = $users[$job['user_id']] ?? '';
View::stampTableToStr($list, 'jCTS, jPTS, jDTS');

View::setPage('list', $list);

View::showPage();

?>
