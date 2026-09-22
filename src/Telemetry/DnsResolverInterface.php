<?php

namespace HScript\Telemetry;

interface DnsResolverInterface
{
	/**
	 * @return array{status:string,addresses:array<int,string>,checked_at:int,expires_at:int,error_code:string}
	 */
	public function resolve(string $domain, string $observedIp): array;
}
