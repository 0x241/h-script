<?php

namespace HScript\Payment\Gateways;

/**
 * Configures the hosted cryptocurrency adapter for Ethereum payments.
 */
class EtherApi extends HostedCryptoGateway
{
	protected function marker(): string { return 'etherapi.net'; }
	protected function addressLabel(): string { return 'Ether-Address'; }
	protected function addressPattern(): string { return '0x[0-9A-Za-z]{40}'; }
	protected function isTokenVariant(): bool { return $this->id === 'EAT' || $this->id === 'EAT1'; }
	protected function minimumConfirmations(): int { return 12; }

	protected function depositUrl(array $config, array $params): string
	{
		if (!$this->isTokenVariant())
			return 'https://etherapi.net/api?token=' . urlencode((string)$this->value($config, 'apipass'))
				. '&method=give&tag=' . urlencode((string)$this->value($params, 'tag'))
				. '&statusURL=' . urlencode((string)$this->value($params, 'url_callback'));
		return 'https://etherapi.net/api/v2/.give?key=' . urlencode((string)$this->value($config, 'apipass'))
			. '&token=' . urlencode($this->getCurrencyId())
			. '&tag=' . urlencode((string)$this->value($params, 'tag'))
			. '&statusURL=' . urlencode((string)$this->value($params, 'url_callback'));
	}

	protected function balanceUrl(array $config): string
	{
		if (!$this->isTokenVariant())
			return 'https://etherapi.net/api?token=' . urlencode((string)$this->value($config, 'apipass')) . '&method=balance';
		return 'https://etherapi.net/api/v2/.balance?key=' . urlencode((string)$this->value($config, 'apipass'))
			. '&token=' . urlencode($this->getCurrencyId());
	}

	protected function withdrawalUrl(array $config, array $params): string
	{
		$to = (array)$this->value($params, 'to', array());
		$base = $this->isTokenVariant()
			? 'https://etherapi.net/api/v2/.send?key=' . urlencode((string)$this->value($config, 'apipass'))
				. '&token=' . urlencode($this->getCurrencyId())
			: 'https://etherapi.net/api?token=' . urlencode((string)$this->value($config, 'apipass')) . '&method=send';
		return $base
			. '&address=' . urlencode((string)$this->value($to, 'acc'))
			. '&amount=' . urlencode((string)$this->value($params, 'sum'))
			. '&tag=' . urlencode((string)$this->value($params, 'tag'))
			. '&statusURL=' . urlencode((string)$this->value($params, 'url_callback'));
	}
}
