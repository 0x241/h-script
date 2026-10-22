<?php

use HScript\Payment\PaymentManager;
use HScript\Payment\CallbackAudit;
use HScript\Finance\GatewaySettlement;
use HScript\Finance\IdempotencyConflict;
use HScript\Finance\CallbackValidationException;

$rawBody = file_get_contents('php://input', false, null, 0, 65537);
$rawBody = is_string($rawBody) ? $rawBody : '';
$fingerprint = hash('sha256', $rawBody !== '' ? $rawBody : (string)($_SERVER['QUERY_STRING'] ?? ''));
header('Content-Type: text/plain; charset=UTF-8');
if (strlen($rawBody) > 65536) {
    CallbackAudit::record('unknown', $fingerprint, 'invalid_payload', 'callback_body_invalid');
    http_response_code(413); exit('Invalid callback');
}
// Signed fields must retain their exact decoded bytes, including whitespace.
$_IN = $_POST ?: (json_decode($rawBody, true) ?: $_GET);
if (!is_array($_IN)) $_IN = [];
$_smode = 2;
require_once('module/auth.php');
$paymentManager = new PaymentManager($db);
try {
    $stripe=($_GET['its']??null)==='ST';
    $psys = $stripe ? 'ST' : $paymentManager->detectCallback($_IN, $_GET);
    if ($psys === '') {
        CallbackAudit::record('unknown', $fingerprint, 'unsupported', 'gateway_unknown');
        http_response_code(400); exit('Unknown callback');
    }
    $request = $_IN;
    $request['_raw_body'] = $rawBody;
    $request['_json'] = json_decode($rawBody, true);
    $request['_server'] = $_SERVER;
    if ($stripe) {
        // Public endpoint selects a profile; untrusted metadata only selects a
        // retained secret version. It has no authority until the raw HMAC passes.
        $untrusted=HScript\Payment\JsonCallbackPayload::object($rawBody);
        $public=$_GET['provider']??($untrusted->data->object->metadata->provider??null);
        if ($public===null) $profile=$db->fetch1Row($db->select('PaymentProviders','*',"ppKind='STRIPE' AND ppSlot='STRIPE'"));
        else {
            if (!is_string($public) || !preg_match('/^[a-f0-9]{32}$/D',$public)) throw new InvalidArgumentException();
            $profile=$db->fetch1Row($db->select('PaymentProviders','*',"ppKind='STRIPE' AND ppPublicID=?",[$public]));
        }
        if (!$profile) throw new InvalidArgumentException();
        $public=$profile['ppPublicID'];
        $version=HScript\Payment\Provider\ProviderService::number($untrusted->data->object->metadata->version??(string)$profile['ppCurrentKeyVersion']);
        if ($version<1) throw new InvalidArgumentException();
        $key=(new HScript\Payment\Provider\ProviderRepository($db))->credentials($profile,$version);
        $p_sci=HScript\Payment\Provider\ProviderSettings::configuration('STRIPE',$key)['sci']+[
            '_provider_id'=>(int)$profile['ppID'],'_provider_public'=>$public,'_provider_version'=>$version,'_provider_environment'=>$profile['ppEnvironment']];
        $res=$paymentManager->handleCallback('ST',$request,$p_sci);
        if ($res['outcome']==='rejected' && $res['authenticated']) { echo 'OK';exit; }
        if ($res['outcome']!=='verified') {
            CallbackAudit::record('ST',$fingerprint,$res['outcome'],$res['reason']);
            http_response_code($res['outcome']==='authentication_failed'?401:400);exit('Invalid callback');
        }
        $session=json_decode($res['stripe_session'],true,8,JSON_THROW_ON_ERROR);
        $currency=HScript\Payment\Provider\ProviderService::number($session['metadata']['currency']??null);
        $c=$db->fetch1Row($db->select('Currs','*',"cID=?d AND cCID='ST' AND cProviderID=?d AND cProviderSelected=1 AND cDisabled=0",[$currency,(int)$profile['ppID']]));
    } elseif($psys==='CCT'){
        $label=$_IN['label']??null;
        if(!is_scalar($label) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$label))throw new InvalidArgumentException();
        $c=$db->fetch1Row($db->query("SELECT Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d AND oOper='CASHIN' AND cCID='CCT' AND cDisabled=0 AND cProviderSelected=1",[(int)$label]));
    } else $c = $db->fetch1Row($db->select('Currs', '*', 'cDisabled=0 and cCID=?', [$psys]));
    if (!$c) {
        CallbackAudit::record($psys, $fingerprint, 'unsupported', 'currency_unavailable');
        http_response_code(404); exit('Callback unavailable');
    }
    if (!$stripe) {
        opDecodeCurrParams($c, $p, $p_sci, $p_api);
        $res = $paymentManager->handleCallback($psys, $request, $p_sci);
    }
    if ($res['outcome'] === 'preflight') {
        GatewaySettlement::preflight($db, $psys, (int)$c['cID'], $res);
        CallbackAudit::record($psys, $fingerprint, 'preflight', 'order_validated');
        echo 'YES'; exit;
    }
    CallbackAudit::record($psys, $fingerprint, $res['outcome'], $res['reason']);
    if (!in_array($res['outcome'], ['verified','pending'], true)) {
        http_response_code(match ($res['outcome']) {
            'authentication_failed'=>401, 'invalid_payload'=>400, 'unsupported'=>422, default=>200,
        });
        echo $res['response'] ?? ''; exit;
    }
    // Provider acknowledgement follows the committed local transaction.
    $result = GatewaySettlement::settle($db, $psys, (int)$c['cID'], $res);
    HScript\Payment\CheckoutProgress::record($db,$psys,(int)$c['cID'],$res);
    CallbackAudit::record($psys, $fingerprint, $result->replayed ? 'replay' : (!empty($result->data['settled']) ? 'settled' : 'pending'),
        $result->replayed ? 'exact_retry' : 'transaction_committed');
    echo $res['response'] ?? '';
} catch (IdempotencyConflict) {
    CallbackAudit::record($psys ?? 'unknown', $fingerprint, 'conflict', 'transaction_identity_conflict');
    http_response_code(409); echo 'Transaction conflict';
} catch (CallbackValidationException $error) {
    CallbackAudit::record($psys ?? 'unknown', $fingerprint, 'invalid_payload', $error->reason);
    http_response_code(400); echo 'Invalid transaction';
} catch (InvalidArgumentException) {
    CallbackAudit::record($psys ?? 'unknown', $fingerprint, 'invalid_payload', 'transaction_invalid');
    http_response_code(400); echo 'Invalid transaction';
} catch (Throwable) {
    CallbackAudit::record($psys ?? 'unknown', $fingerprint, 'unavailable', 'settlement_unavailable');
    http_response_code(503); echo 'Settlement unavailable';
}
