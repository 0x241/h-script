<?php

namespace HScript\Payment;

/** Verification outcome is separate from the later committed settlement/replay. */
final class CallbackResult
{
    public const OUTCOMES = ['authentication_failed', 'invalid_payload', 'pending', 'verified', 'rejected', 'unsupported', 'preflight'];

    public static function failure(string $outcome, string $reason, bool $authenticated = false): array
    {
        return ['outcome'=>$outcome, 'reason'=>$reason, 'authenticated'=>$authenticated, 'correct'=>false];
    }

    public static function normalize(array $result): array
    {
        $outcome = $result['outcome'] ?? (!empty($result['correct']) ? (!empty($result['wait']) ? 'pending' : 'verified') : 'authentication_failed');
        if (!in_array($outcome, self::OUTCOMES, true)) $outcome = 'invalid_payload';
        $reason = $result['reason'] ?? ($outcome === 'verified' ? 'signature_verified' : $outcome);
        if (!is_string($reason) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reason)) $reason = 'callback_invalid';
        $authenticated = !empty($result['authenticated']) || !empty($result['correct']);
        $correct = $authenticated && in_array($outcome, ['pending', 'verified'], true);
        // Calculated/provided signatures and arbitrary provider payload never
        // reach operation parameters, response snapshots or audit context.
        $data = array_intersect_key($result, array_flip(['tag','sum','sum2','curr','accto','accfrom','batch','date','signed_at','destination_tag','description_hash','profile_key_hash','profile_timezone','merchant_id','provider_invoice_hash','volet_profile','volet_proof_hash','volet_fee_policy_hash','stripe_session','stripe_provider','stripe_version','stripe_key_hash','ccapi_confirmations','ccapi_route_hash','payment_route_hash','paykassa_system','ccapi_profile','ccapi_chain','ccapi_position','ccapi_network_fee','ccapi_key_hash','ccapi_event_hash','wait','outbound','response']));
        return ['outcome'=>$outcome, 'reason'=>$reason, 'authenticated'=>$authenticated, 'correct'=>$correct] + $data;
    }
}
