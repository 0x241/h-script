<?php

namespace HScript\Payment;

use HScript\Observability\StructuredLogger;

/** Fixed reason/state and a one-way body fingerprint; no provider fields. */
final class CallbackAudit
{
    public static function record(string $gateway, string $fingerprint, string $state, string $reason): bool
    {
        $allowed = array_merge(CallbackResult::OUTCOMES, ['settled','replay','conflict','unavailable']);
        if (!preg_match('/^[a-f0-9]{64}$/D', $fingerprint) || !in_array($state, $allowed, true)
            || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reason)) return false;
        $success = in_array($state, ['verified','settled'], true);
        $noop = in_array($state, ['pending','preflight','replay','rejected'], true);
        return StructuredLogger::event($success || $noop ? 'info' : 'warning', 'payment', 'gateway_callback',
            $success ? 'success' : ($noop ? 'noop' : 'blocked'), 0, 'callback:' . $fingerprint,
            ['gateway'=>preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $gateway) ? strtolower($gateway) : 'unknown',
             'state'=>$state, 'reason'=>$reason]);
    }
}
