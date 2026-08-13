<?php

namespace HScript\Payment\Gateways;

/**
 * Configures the hosted cryptocurrency adapter for TRON payments.
 */
class TronApi extends HostedCryptoGateway
{
	protected function marker(): string { return 'tronapi.net'; }
	protected function addressLabel(): string { return 'TRON-Address'; }
	protected function addressPattern(): string { return 'T[1-9A-Za-z]{27,34}'; }
	protected function isTokenVariant(): bool { return $this->id === 'TRAT'; }
	protected function minimumConfirmations(): int { return 10; }
	protected function omitEmptyTokenFromSignature(): bool { return false; }

	protected function depositUrl(array $config, array $params): string
	{
		return 'https://tronapi.net/api/.give?key=' . urlencode((string)$this->value($config, 'apipass'))
			. ($this->isTokenVariant() ? '&token=' . urlencode($this->getCurrencyId()) : '')
			. '&tag=' . urlencode((string)$this->value($params, 'tag'))
			. '&statusURL=' . urlencode((string)$this->value($params, 'url_callback'));
	}

	protected function balanceUrl(array $config): string
	{
		return 'https://tronapi.net/api/.balance?key=' . urlencode((string)$this->value($config, 'apipass'))
			. ($this->isTokenVariant() ? '&token=' . urlencode($this->getCurrencyId()) : '');
	}

	protected function withdrawalUrl(array $config, array $params): string
	{
		$to = (array)$this->value($params, 'to', array());
		return 'https://tronapi.net/api/.send?key=' . urlencode((string)$this->value($config, 'apipass'))
			. ($this->isTokenVariant() ? '&token=' . urlencode($this->getCurrencyId()) : '')
			. '&address=' . urlencode((string)$this->value($to, 'acc'))
			. '&amount=' . urlencode((string)$this->value($params, 'sum'))
			. '&tag=' . urlencode((string)$this->value($params, 'tag'))
			. '&statusURL=' . urlencode((string)$this->value($params, 'url_callback'));
	}
}
