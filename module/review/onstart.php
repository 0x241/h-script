<?php

use HScript\Template\View;

if ($_cfg['Review_InBlock'] > 0)
{
	useLib('review');
	View::setPage('leftreview', reviewGetBlock(), 1);
}

?>
