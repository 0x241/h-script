<?php

function updateTestCompatibility(string $minApp, string $maxApp, string $minSchema, string $maxSchema): array
{
	$data = json_decode(file_get_contents(dirname(__DIR__, 2) . '/resources/update-compatibility.json'), true, 32, JSON_THROW_ON_ERROR);
	$data['application'] = array('minimum' => $minApp, 'maximum' => $maxApp);
	$data['schema'] = array('minimum' => $minSchema, 'maximum' => $maxSchema);
	return $data;
}
