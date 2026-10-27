<?php

namespace HScript\Payment;

/** Accepted automatic callback profiles; metadata does not authorize settlement. */
final class CallbackCapabilities
{
    public const PROFILES = [
        'AC'=>true,'VEUR'=>true,'VUST'=>true,'VUSC'=>true,'VBTC'=>true,'VETH'=>true,'VSOL'=>true,'VTRX'=>true,'VXRP'=>true,'VTON'=>true,'VLTC'=>true,'ACR'=>false,'PKAU'=>false,'PKAR'=>false,'WM'=>true,'YM'=>true,'YMC'=>true,
        'SB'=>false,'BW'=>false,'CP'=>true,'CPE'=>true,'CPL'=>true,'CPR'=>true,
        'PKDG'=>true,'PKDA'=>true,'PKBC'=>true,'PKXR'=>true,'PKTR'=>true,'PKXL'=>true,'PKBN'=>true,'PKBB'=>true,'PKBE'=>true,'PKBD'=>true,'PKBT'=>true,'PKBU'=>true,'PKBZ'=>true,'PKEZ'=>true,'PKBO'=>true,'PKBA'=>true,'PKBS'=>true,'PKES'=>true,'PKTN'=>true,'PKTT'=>true,
        'PKB'=>true,'PKE'=>true,'PKL'=>true,'CCAB'=>true,'CCAL'=>true,'CCAD'=>true,'CCAG'=>true,'CCAH'=>true,'CCAE'=>true,'CCAM'=>true,'CCAN'=>true,'CCAT'=>true,'CCAX'=>true,'CCAO'=>true,'CCAS'=>true,
        'CCT'=>true,'CCTU'=>true,'CCTC'=>true,'PKTU'=>true,'PKTC'=>true,
        'PAW'=>true,'ST'=>true,
    ];

    public const DISABLED_AUTOMATION = ['ACR','PKAU','PKAR','EA','EAT','EAT1','BSC','BSCT','TRA','TRAT','XRPA'];

    public static function allows(string $gateway,string $direction='in'): bool
    {
        return $direction==='in' && (self::PROFILES[$gateway] ?? false);
    }

    public static function disabledAutomation(string $gateway): bool
    {
        return in_array($gateway,self::DISABLED_AUTOMATION,true);
    }
}
