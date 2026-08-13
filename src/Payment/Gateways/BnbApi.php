<?php

namespace HScript\Payment\Gateways;

/**
 * Configures the hosted cryptocurrency adapter for BNB payments.
 */
class BnbApi extends HostedCryptoGateway
{
	protected function marker(): string { return 'bnbapi.net'; }
	protected function addressLabel(): string { return 'BSC-Address'; }
	protected function addressPattern(): string { return '0x[0-9A-Za-z]{40}'; }
	protected function isTokenVariant(): bool { return $this->id === 'BSCT'; }
	protected function minimumConfirmations(): int { return 2; }

	protected function depositUrl(array $config, array $params): string
	{
		return 'https://bnbapi.net/api/.give?key=' . urlencode((string)$this->value($config, 'apipass'))
			. ($this->isTokenVariant() ? '&token=' . urlencode($this->getCurrencyId()) : '')
			. '&tag=' . urlencode((string)$this->value($params, 'tag'))
			. '&statusURL=' . urlencode((string)$this->value($params, 'url_callback'));
	}

	protected function balanceUrl(array $config): string
	{
		return 'https://bnbapi.net/api/.balance?key=' . urlencode((string)$this->value($config, 'apipass'))
			. ($this->isTokenVariant() ? '&token=' . urlencode($this->getCurrencyId()) : '');
	}

	protected function withdrawalUrl(array $config, array $params): string
	{
		$to = (array)$this->value($params, 'to', array());
		return 'https://bnbapi.net/api/.send?key=' . urlencode((string)$this->value($config, 'apipass'))
			. ($this->isTokenVariant() ? '&token=' . urlencode($this->getCurrencyId()) : '')
			. '&address=' . urlencode((string)$this->value($to, 'acc'))
			. '&amount=' . urlencode((string)$this->value($params, 'sum'))
			. '&tag=' . urlencode((string)$this->value($params, 'tag'))
			. '&statusURL=' . urlencode((string)$this->value($params, 'url_callback'));
	}
}
