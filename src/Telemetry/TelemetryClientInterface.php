<?php

namespace HScript\Telemetry;

interface TelemetryClientInterface
{
	public function request(string $method, string $path, array $payload, string $token): array;
}
