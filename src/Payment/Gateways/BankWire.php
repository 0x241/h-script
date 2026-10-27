<?php

namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;

/**
 * Defines the manual bank-transfer fields used for offline settlement.
 */
class BankWire extends AbstractGateway
{
    public static function hasDestination(array $params): bool
    {
        return !empty($params['acc']) || !empty($params['iban']);
    }

	public function getFields(string $type): array
	{
		if ($type !== 'pay')
			return array();
		return array(
			'bname' => array('{!ru!}Банк получателя{!en!}Beneficiary bank'),
			'baddr' => array('{!ru!}Адрес банка{!en!}Bank address'),
			'bic' => array('{!ru!}SWIFT / BIC / национальный банковский код{!en!}SWIFT / BIC / national bank code'),
			'cname' => array('{!ru!}Имя или наименование получателя{!en!}Beneficiary name'),
			'addr' => array('{!ru!}Адрес получателя{!en!}Beneficiary address'),
			'acc' => array('{!ru!}Номер счёта получателя{!en!}Account number'),
			'iban' => array('IBAN','','{!ru!}Международный номер счёта по ISO 13616, если используется вашим банком{!en!}ISO 13616 international account number, where supported by your bank'),
		);
	}
}
