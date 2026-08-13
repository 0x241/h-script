START TRANSACTION;

-- API v3 requires public/private keys. Disable the provider when only legacy
-- login/password credentials are present so queued jobs do not fail repeatedly.
UPDATE Cfg provider
LEFT JOIN Cfg public_key
    ON public_key.Module = 'SMS' AND public_key.Prop = 'EP_PublicKey'
LEFT JOIN Cfg private_key
    ON private_key.Module = 'SMS' AND private_key.Prop = 'EP_PrivateKey'
SET provider.Val = '0'
WHERE provider.Module = 'SMS'
  AND provider.Prop = 'Prov'
  AND provider.Val = '1'
  AND (COALESCE(public_key.Val, '') = '' OR COALESCE(private_key.Val, '') = '');

DELETE FROM Cfg
WHERE (Module = 'Account' AND Prop = 'UseAvatar')
   OR (Module = 'SMS' AND Prop IN ('EP_Login', 'EP_Pass'));

COMMIT;
