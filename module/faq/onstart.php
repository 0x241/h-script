<?php

use HScript\Template\View;

if ($_cfg['FAQ_InBlock'] > 0)
{
	useLib('faq');
	View::setPage('leftfaqs', faqGetBlock(), 1);
}

?>