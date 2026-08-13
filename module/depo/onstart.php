<?php

use HScript\Template\View;

if ($_cfg['Depo_ShowStat'])
{
	$stat = false;
	if (is_readable('tpl_c/stat.dat'))
	{
		$storedStat = (string)file_get_contents('tpl_c/stat.dat');
		$stat = json_decode($storedStat, true);
		if (!is_array($stat))
			$stat = safeUnserialize($storedStat);
	}
	View::setPage('leftstat', is_array($stat) ? $stat : array());
}

if ($_cfg['Depo_Interval'] > 0)
{
	$nextdepotime = stampToTime($_cfg['Depo_LastTime']) + HS2_UNIX_MINUTE * $_cfg['Depo_Interval'];
	if ($nextdepotime > (time() + HS2_UNIX_MINUTE))
	{
		View::setPage('nextdepotime', View::timetoStr($nextdepotime, 2));
		View::setPage('nextdepoleft', round(($nextdepotime - time()) / HS2_UNIX_MINUTE));
	}
}

?>
