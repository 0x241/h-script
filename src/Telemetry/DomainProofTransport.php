<?php

namespace HScript\Telemetry;

interface DomainProofTransport
{
	/** Fetch only the fixed public proof document; throw on unsafe/unreachable hosts. */
	public function fetch(string $domain): array;
}
