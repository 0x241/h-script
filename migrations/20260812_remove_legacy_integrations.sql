START TRANSACTION;

DELETE FROM Cfg
WHERE (Module = 'Account' AND Prop = 'ISP')
   OR (Module = 'Captcha' AND Prop IN ('ReCaptcha_PublicKey', 'ReCaptcha_PrivateKey'))
   OR (Module = 'SMS' AND Prop = 'SP_Pass')
   OR (Module = 'Cron' AND Prop = 'ByHost');

UPDATE Cfg
SET Val = 'default'
WHERE Module = 'Captcha'
  AND Prop = 'Service'
  AND Val = 'recaptcha';

UPDATE Cfg
SET Val = '0'
WHERE Module = 'SMS'
  AND Prop = 'Prov'
  AND Val NOT IN ('0', '1');

COMMIT;
