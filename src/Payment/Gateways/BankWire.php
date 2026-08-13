<?php

namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;

/**
 * Defines the manual bank-transfer fields used for offline settlement.
 */
class BankWire extends AbstractGateway
{
	public function getFields(string $type): array
	{
		if ($type !== 'pay')
			return array();
		return array(
			'bname' => array('Bank name'),
			'baddr' => array('Bank address'),
			'bic' => array('SWIFT BIC/ABA/National ID'),
			'cname' => array('Customer name'),
			'addr' => array('Customer address'),
			'acc' => array('Customer account No'),
			'iban' => array('IBAN'),
		);
	}
}
