<?php

use HScript\Http\ApiRequest;
use HScript\Http\ApiResponse;
use HScript\Telemetry\DomainProofDocument;

$_smode = 2;
$_auth = 0;
require_once('module/auth.php');
if (ApiRequest::method() !== 'GET')
{
	header('Allow: GET');
	ApiResponse::error('method_not_allowed', 'Method not allowed', 405);
}
$proof = DomainProofDocument::fromConfig($_cfg);
if ($proof === null)
	ApiResponse::error('proof_unavailable', 'No active domain challenge', 404);
ApiResponse::success($proof);
