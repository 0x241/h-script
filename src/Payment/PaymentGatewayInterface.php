<?php

namespace HScript\Payment;

/**
 * Defines the common contract used to configure and execute payment gateways.
 *
 * Implementations translate gateway-specific fields, callbacks, deposits, and
 * withdrawals into the normalized arrays consumed by the legacy modules.
 */
interface PaymentGatewayInterface
{
	/**
	 * Returns the human-readable gateway name.
	 *
	 * @return string Display name suitable for administrative interfaces.
	 * @throws \RuntimeException When the gateway definition is incomplete.
	 */
	public function getName(): string;

	/**
	 * Returns the configured H-Script currency or payment-system identifier.
	 *
	 * @return string Canonical currency identifier registered by PaymentManager.
	 * @throws \RuntimeException When no identifier is available.
	 */
	public function getCurrencyId(): string;

	/**
	 * Renders gateway-specific form fields for an operation.
	 *
	 * @param array<string, mixed> $operation Deposit or withdrawal context.
	 * @return string HTML fragment understood by the current module templates.
	 * @throws \RuntimeException When required operation data is missing.
	 */
	public function getFormFields(array $operation): string;

	/**
	 * Creates or prepares a deposit at the remote gateway.
	 *
	 * @param array<string, mixed> $params Validated gateway configuration and operation data.
	 * @return array<string, mixed> Normalized redirect, form, address, or error payload.
	 * @throws \RuntimeException When the request cannot be created or validated.
	 */
	public function processDeposit(array $params): array;

	/**
	 * Creates or prepares a withdrawal at the remote gateway.
	 *
	 * @param array<string, mixed> $params Validated gateway configuration and operation data.
	 * @return array<string, mixed> Normalized transaction, status, or error payload.
	 * @throws \RuntimeException When the withdrawal cannot be created or validated.
	 */
	public function processWithdrawal(array $params): array;

	/**
	 * Verifies and normalizes an inbound gateway callback.
	 *
	 * @param array<string, mixed> $request Callback query, form, headers, and body data.
	 * @return array<string, mixed> Normalized callback result for module processing.
	 * @throws \RuntimeException When callback authentication or validation fails.
	 */
	public function handleCallback(array $request): array;

	/**
	 * Checks whether the supplied gateway configuration is usable.
	 *
	 * @param array<string, mixed> $config Gateway-specific credentials and options.
	 * @return bool True when all mandatory configuration values are present.
	 * @throws \RuntimeException When configuration validation cannot be completed.
	 */
	public function validateConfig(array $config): bool;
}
