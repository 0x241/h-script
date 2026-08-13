<?php

namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;

/**
 * Defines manual Sberbank transfer fields used for offline settlement.
 */
class Sberbank extends AbstractGateway
{
	public function getFields(string $type): array
	{
		if ($type !== 'pay')
			return array();
		return array(
			'acc' => array('Card No'),
			'name1' => array('First name'),
			'name2' => array('Middle name'),
			'name3' => array('Second name'),
		);
	}
}
