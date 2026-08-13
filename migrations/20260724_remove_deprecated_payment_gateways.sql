START TRANSACTION;

-- Historical operations and deposits still join Currs by cID. Keep such rows as
-- hidden, disabled reference data; unused gateway configurations are removed.
UPDATE Currs
SET cDisabled = 1,
    cHidden = 1,
    cCASHINMode = 0,
    cCASHOUTMode = 0,
    cEXMode = 0,
    cTRMode = 0,
    cBUYMode = 0,
    cSELLMode = 0,
    cBUY2Mode = 0,
    cSELL2Mode = 0,
    cGIVEMode = 0,
    cTAKEMode = 0,
    cParams = '',
    cParamsSCI = '',
    cParamsAPI = ''
WHERE cCID IN (
    'LR', 'LR0', 'EP', 'PZ', 'PM', 'PKPM', 'PY', 'PYR', 'PYE', 'PKPU', 'PKPR',
    'NX', 'NXE', 'NXB', 'NXR', 'STP', 'OK', 'OKR', 'QW', 'QW2', 'QWA', 'QG',
    'FKV', 'FKY', 'FKQ', 'FKA', 'HM', 'EPCU', 'EPCB', 'EPCT', 'IBC', 'CKE',
    'CB', 'BC', 'BCM', 'AEX1', 'AEX2', 'AEX3', 'AEX4', 'AEX5', 'AEX6', 'AEX7',
    'AEX8', 'GDP', 'LP', 'MR', 'PP', 'EGC', 'C4P', 'C4', 'W1', 'ON', 'SY',
    'IK', 'A1', 'SP', 'PC'
);

DELETE w
FROM Wallets w
JOIN Currs c ON c.cID = w.wcID
WHERE c.cCID IN (
    'LR', 'LR0', 'EP', 'PZ', 'PM', 'PKPM', 'PY', 'PYR', 'PYE', 'PKPU', 'PKPR',
    'NX', 'NXE', 'NXB', 'NXR', 'STP', 'OK', 'OKR', 'QW', 'QW2', 'QWA', 'QG',
    'FKV', 'FKY', 'FKQ', 'FKA', 'HM', 'EPCU', 'EPCB', 'EPCT', 'IBC', 'CKE',
    'CB', 'BC', 'BCM', 'AEX1', 'AEX2', 'AEX3', 'AEX4', 'AEX5', 'AEX6', 'AEX7',
    'AEX8', 'GDP', 'LP', 'MR', 'PP', 'EGC', 'C4P', 'C4', 'W1', 'ON', 'SY',
    'IK', 'A1', 'SP', 'PC'
)
  AND w.wBal = 0
  AND w.wLock = 0
  AND w.wOut = 0
  AND NOT EXISTS (SELECT 1 FROM Opers o WHERE o.ocID = c.cID)
  AND NOT EXISTS (SELECT 1 FROM Deps d WHERE d.dcID = c.cID);

DELETE c
FROM Currs c
WHERE c.cCID IN (
    'LR', 'LR0', 'EP', 'PZ', 'PM', 'PKPM', 'PY', 'PYR', 'PYE', 'PKPU', 'PKPR',
    'NX', 'NXE', 'NXB', 'NXR', 'STP', 'OK', 'OKR', 'QW', 'QW2', 'QWA', 'QG',
    'FKV', 'FKY', 'FKQ', 'FKA', 'HM', 'EPCU', 'EPCB', 'EPCT', 'IBC', 'CKE',
    'CB', 'BC', 'BCM', 'AEX1', 'AEX2', 'AEX3', 'AEX4', 'AEX5', 'AEX6', 'AEX7',
    'AEX8', 'GDP', 'LP', 'MR', 'PP', 'EGC', 'C4P', 'C4', 'W1', 'ON', 'SY',
    'IK', 'A1', 'SP', 'PC'
)
  AND NOT EXISTS (SELECT 1 FROM Opers o WHERE o.ocID = c.cID)
  AND NOT EXISTS (SELECT 1 FROM Deps d WHERE d.dcID = c.cID)
  AND NOT EXISTS (SELECT 1 FROM Wallets w WHERE w.wcID = c.cID);

COMMIT;
