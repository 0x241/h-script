<?php
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//
//      http://www.apache.org/licenses/LICENSE-2.0
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.


include_once(__DIR__ . "/class.FixedByteNotation.php");

$autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once($autoload);
}


class GoogleAuthenticator {
    static $PASS_CODE_LENGTH = 6;
    static $PIN_MODULO;
    static $SECRET_LENGTH = 10;
    
    public function __construct() {
        self::$PIN_MODULO = pow(10, self::$PASS_CODE_LENGTH);
    }
    
    public function checkCode($secret,$code) {
        $time = floor(time() / 30);
        for ( $i = -1; $i <= 1; $i++)
            if (($c = $this->getCode($secret,$time + $i)) == $code)
                return true;
        
        return false;
        
    }
    
    public function getCode($secret,$time = null) {
        
        if (!$time) {
            $time = floor(time() / 30);
        }
        $base32 = new FixedBitNotation(5, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', TRUE, TRUE);
        $secret = $base32->decode($secret);
        
        $time = pack("N", $time);
        $time = str_pad($time,8, chr(0), STR_PAD_LEFT);
        
        $hash = hash_hmac('sha1',$time,$secret,true);
        $offset = ord(substr($hash,-1));
        $offset = $offset & 0xF;
        
        $truncatedHash = self::hashToInt($hash, $offset) & 0x7FFFFFFF;
        $pinValue = str_pad($truncatedHash % self::$PIN_MODULO,6,"0",STR_PAD_LEFT);;
        return $pinValue;
    }
    
    protected function hashToInt($bytes, $start) {
        $input = substr($bytes, $start, strlen($bytes) - $start);
        $val2 = unpack("N",substr($input,0,4));
        return $val2[1];
    }
    
    public function getQRUrl($alias, $secret) {
        $url = sprintf("otpauth://totp/%s?secret=%s", rawurlencode($alias), $secret);
        $oldReporting = error_reporting();
        error_reporting($oldReporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        try {
            if (class_exists('\\Endroid\\QrCode\\QrCode') && class_exists('\\Endroid\\QrCode\\Writer\\SvgWriter')) {
                $qrCode = \Endroid\QrCode\QrCode::create($url)
                    ->setSize(220)
                    ->setMargin(8);
                $result = (new \Endroid\QrCode\Writer\SvgWriter())->write($qrCode);
                if (method_exists($result, 'getDataUri')) {
                    error_reporting($oldReporting);
                    return $result->getDataUri();
                }
            }
        } catch (\Throwable $e) {
            error_log('Unable to generate local TOTP QR code: ' . $e->getMessage());
        }
        error_reporting($oldReporting);
        return '';
    }
    
    public function generateSecret() {
        $secret = "";
        for($i = 1;  $i<= self::$SECRET_LENGTH;$i++) {
            $secret .= random_bytes(1);
        }
        $base32 = new FixedBitNotation(5, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', TRUE, TRUE);
        return $base32->encode($secret);
    }
    
}
