<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const POLICY_KEYS = array(
    'RECOVERY_CMS_RPO_SECONDS', 'RECOVERY_CMS_RTO_SECONDS',
    'RECOVERY_COLLECTOR_RPO_SECONDS', 'RECOVERY_COLLECTOR_RTO_SECONDS',
    'RECOVERY_SERVICE_TOKENS_RPO_SECONDS', 'RECOVERY_SERVICE_TOKENS_RTO_SECONDS',
    'RECOVERY_DRILL_MAX_AGE_SECONDS',
);

function policyValid(array $config, bool $overrides = false): bool
{
    $app = $config['services']['app']['environment'] ?? array();
    $cron = $config['services']['cron']['environment'] ?? array();
    $worker = $config['services']['recovery']['environment'] ?? array();
    // Cron and recovery share the app's persistent config volume but must not write it.
    if ((string)($cron['APP_GENERATE_CONFIG'] ?? '') !== '0'
        || (string)($worker['APP_GENERATE_CONFIG'] ?? '') !== '0') return false;
    foreach (POLICY_KEYS as $key) {
        $value = $app[$key] ?? null;
        if ((!is_string($value) && !is_int($value)) || !ctype_digit((string)$value) || (int)$value < 1
            || (string)$value !== (string)($worker[$key] ?? '')) return false;
    }
    foreach (array('RECOVERY_DRILL_DB_ADMIN_PASSWORD', 'RECOVERY_DRILL_DB_ADMIN_PASSWORD_FILE') as $key)
        if (array_key_exists($key, $app)) return false;
    foreach ($config['services']['app']['volumes'] ?? array() as $volume)
        if (($volume['target'] ?? '') === '/run/secrets/hscript-recovery') return false;
    return !$overrides || ((string)$app['RECOVERY_CMS_RPO_SECONDS'] === '1234'
        && (string)$app['RECOVERY_DRILL_MAX_AGE_SECONDS'] === '4567');
}

try {
    if (($argv[1] ?? '') === '--self-test') {
        $environment = array_fill_keys(POLICY_KEYS, '3600');
        $valid = array('services'=>array('app'=>array('environment'=>$environment, 'volumes'=>array()),
            'cron'=>array('environment'=>array('APP_GENERATE_CONFIG'=>'0')),
            'recovery'=>array('environment'=>$environment + array('APP_GENERATE_CONFIG'=>'0'))));
        if (!policyValid($valid) || policyValid($valid, true)) throw new RuntimeException();
        $cases = array();
        $case = $valid; $case['services']['cron']['environment']['APP_GENERATE_CONFIG'] = '1'; $cases[] = $case;
        $case = $valid; $case['services']['recovery']['environment']['APP_GENERATE_CONFIG'] = '1'; $cases[] = $case;
        $case = $valid; unset($case['services']['app']['environment'][POLICY_KEYS[0]]); $cases[] = $case;
        $case = $valid; $case['services']['recovery']['environment'][POLICY_KEYS[0]] = '1'; $cases[] = $case;
        foreach (array('0', '-1', '1.5', 'invalid') as $bad) {
            $case = $valid;
            foreach (array('app', 'recovery') as $service) $case['services'][$service]['environment'][POLICY_KEYS[0]] = $bad;
            $cases[] = $case;
        }
        foreach (array('RECOVERY_DRILL_DB_ADMIN_PASSWORD', 'RECOVERY_DRILL_DB_ADMIN_PASSWORD_FILE') as $key) {
            $case = $valid; $case['services']['app']['environment'][$key] = ''; $cases[] = $case;
        }
        $case = $valid; $case['services']['app']['volumes'][] = array('target'=>'/run/secrets/hscript-recovery'); $cases[] = $case;
        foreach ($cases as $case) if (policyValid($case)) throw new RuntimeException();
        foreach (array('app', 'recovery') as $service) {
            $valid['services'][$service]['environment']['RECOVERY_CMS_RPO_SECONDS'] = '1234';
            $valid['services'][$service]['environment']['RECOVERY_DRILL_MAX_AGE_SECONDS'] = '4567';
        }
        if (!policyValid($valid, true)) throw new RuntimeException();
        echo "Recovery Compose validator regression tests passed.\n";
        exit(0);
    }
    $config = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($config) || !policyValid($config, ($argv[1] ?? '') === '--overrides')) throw new RuntimeException();
} catch (Throwable) {
    // Compose input may contain live CI secrets: never echo it or raw exceptions.
    fwrite(STDERR, "Recovery Compose policy or web/drill secret isolation check failed.\n");
    exit(1);
}
