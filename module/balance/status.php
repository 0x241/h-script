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
$pawEndpoint=($_GET['its']??null)==='PAW';
$ccapiOutEndpoint=($_GET['its']??null)==='CCAPI_OUT';
$_IN = ($pawEndpoint || $ccapiOutEndpoint) ? [] : ($_POST ?: (json_decode($rawBody, true) ?: $_GET));
if (!is_array($_IN)) $_IN = [];
$_smode = 2;
require_once('module/auth.php');
$paymentManager = new PaymentManager($db);
try {
    if($ccapiOutEndpoint) {
        $psys='CCAPI_OUT';
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST' || !preg_match('~^application/json(?:\s*;[^\r\n]*)?$~iD',$_SERVER['CONTENT_TYPE']??''))throw new InvalidArgumentException();
        $id=$_GET['operation']??null;$version=$_GET['version']??null;$public=$_GET['provider']??null;
        if(!is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/D',$id) || (int)$id>2147483647 || !is_string($version) || !preg_match('/^[1-9][0-9]{0,9}$/D',$version)
            || !is_string($public) || !preg_match('/^[a-f0-9]{32}$/D',$public))throw new InvalidArgumentException();
        $result=HScript\Finance\CcapiPayout::receive($db,(int)$id,$rawBody,$public,(int)$version);
        CallbackAudit::record($psys,$fingerprint,isset($result['completed'])?'settled':'pending','transaction_committed');
        echo 'OK';exit;
    }
    $stripe=($_GET['its']??null)==='ST';
    $paw=($_GET['its']??null)==='PAW';
    $psys = $paw ? 'PAW' : ($stripe ? 'ST' : $paymentManager->detectCallback($_IN, $_GET));
    if ($psys === '') {
        CallbackAudit::record('unknown', $fingerprint, 'unsupported', 'gateway_unknown');
        http_response_code(400); exit('Unknown callback');
    }
    $request = $_IN;
    $request['_raw_body'] = $rawBody;
    $request['_json'] = $pawEndpoint ? null : json_decode($rawBody, true);
    $request['_server'] = $_SERVER;
    if ($paw) {
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST' || !preg_match('~^application/json(?:\s*;[^\r\n]*)?$~iD',$_SERVER['CONTENT_TYPE']??''))throw new InvalidArgumentException();
        $public=$_GET['provider']??null;$version=$_GET['version']??null;
        if(!is_string($public) || !preg_match('/^[a-f0-9]{32}$/D',$public))throw new InvalidArgumentException();
        $version=HScript\Payment\Provider\PawProviderService::number($version);
        if($version<1)throw new InvalidArgumentException();
        $profile=$db->fetch1Row($db->select('PaymentProviders','*',"ppKind='PAW' AND ppPublicID=?",[$public]));
        if(!$profile)throw new InvalidArgumentException();
        $p_sci=['key'=>(new HScript\Payment\Provider\PawProviderRepository($db))->key((int)$profile['ppID'],$version),
            '_provider_id'=>(int)$profile['ppID'],'_provider_public'=>$public,'_provider_version'=>$version,'_provider_environment'=>$profile['ppEnvironment']];
        $res=$paymentManager->handleCallback('PAW',$request,$p_sci);
        if($res['outcome']!=='verified') {
            CallbackAudit::record('PAW',$fingerprint,$res['outcome'],$res['reason']);
            http_response_code($res['outcome']==='authentication_failed'?401:($res['outcome']==='rejected'?200:400));
            exit($res['response']??'Invalid callback');
        }
        $currency=HScript\Payment\Provider\PawProviderService::number($res['paw_invoice']['metadata']['currency']??null);
        // Already issued invoices keep their exact receiver after disable/removal.
        $c=$db->fetch1Row($db->select('Currs','*',"cID=?d AND cCID='PAW' AND cProviderID=?d",[$currency,(int)$profile['ppID']]));
    } elseif ($stripe) {
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
        $c=$db->fetch1Row($db->select('Currs','*',"cID=?d AND cCID='ST' AND cProviderID=?d",[$currency,(int)$profile['ppID']]));
    } else {
        $routedOrder=HScript\Finance\ArchivedReplay::operationTag($psys,$rawBody);
        $c=$db->fetch1Row($db->query("SELECT Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d AND oOper='CASHIN' AND cCID=?",[$routedOrder,$psys]));
    }
    if (!$c) {
        CallbackAudit::record($psys, $fingerprint, 'unsupported', 'currency_unavailable');
        http_response_code(404); exit('Callback unavailable');
    }
    if (!$stripe && !$paw) {
        opDecodeCurrParams($c, $p, $p_sci, $p_api);
        $res = $paymentManager->handleCallback($psys, $request, $p_sci);
    }
    if(isset($routedOrder) && in_array($res['outcome']??null,['verified','pending','preflight'],true) && (string)($res['tag']??'')!==(string)$routedOrder)throw new InvalidArgumentException();
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
    if(!HScript\Finance\ArchivedReplay::inactive($c))HScript\Payment\CheckoutProgress::record($db,$psys,(int)$c['cID'],$res);
    CallbackAudit::record($psys, $fingerprint, $result->replayed ? 'replay' : (!empty($result->data['settled']) ? 'settled' : 'pending'),
        $result->replayed ? 'exact_retry' : 'transaction_committed');
    echo $res['response'] ?? '';
} catch (IdempotencyConflict) {
    CallbackAudit::record($psys ?? 'unknown', $fingerprint, 'conflict', 'transaction_identity_conflict');
    http_response_code(409); echo 'Transaction conflict';
} catch (CallbackValidationException $error) {
    CallbackAudit::record($psys ?? 'unknown', $fingerprint, 'invalid_payload', $error->reason);
    http_response_code((($paw??false) && $error->reason==='paw_invoice_missing') || in_array($error->reason,['ccapi_payout_ack_pending','archived_callback_reconciliation_required'],true)?503:400); echo 'Invalid transaction';
} catch (InvalidArgumentException) {
    CallbackAudit::record($psys ?? 'unknown', $fingerprint, 'invalid_payload', 'transaction_invalid');
    http_response_code(400); echo 'Invalid transaction';
} catch (Throwable) {
    CallbackAudit::record($psys ?? 'unknown', $fingerprint, 'unavailable', 'settlement_unavailable');
    http_response_code(503); echo 'Settlement unavailable';
}
