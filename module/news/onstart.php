<?php

use HScript\Template\View;

if ($_cfg['News_InBlock'] > 0)
{
	useLib('news');
	View::setPage('leftnews', newsGetBlock(), 1);
}

?>