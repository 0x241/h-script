<?php

use HScript\Finance\RateUpdater;

useLib('balance');

if (stampToTime($_cfg['Bal_LastUpdate']) + HS2_UNIX_HOUR > time())
	return;

(new RateUpdater())->refresh($_cfg, $_currs, $db, timeToStamp());
