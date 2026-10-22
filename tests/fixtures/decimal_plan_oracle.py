#!/usr/bin/env python3
"""Deterministic Python Decimal storage/validation vectors, no application imports."""
import json, random
from decimal import Decimal as D, localcontext
from pathlib import Path
rng = random.Random(704005)
base = dict(pMin='0.01', pMax='1000', pPerc='1', pReturn='100', pBonus='0', pMinAdd='0', pCompndMin='0', pCompndMax='100', pClComis='0')
scales = {k: 6 if k in ('pMin', 'pMax', 'pMinAdd') else 2 for k in base}
valid = []
with localcontext() as ctx:
    ctx.prec = 100
    for i in range(180):
        row = dict(base)
        row.update(pMin=format(D(rng.randrange(10000, 9000000000000)).scaleb(-6), 'f'), pMax='9999999.999999',
                   pBonus=format(D(rng.randrange(-9999999, 9999999)).scaleb(-2), 'f'),
                   pMinAdd=format(D(rng.randrange(-9999999999999, 9999999999999)).scaleb(-6), 'f'),
                   pPerc=format(D(rng.randrange(1, 9999999)).scaleb(-2), 'f'),
                   pReturn=format(D(rng.randrange(-9999999, 9999999)).scaleb(-2), 'f'))
        if i % 4 == 0: row['pPerc'] = '-99999.99'; row['pReturn'] = '1'
        if i % 4 == 1: row['pCompndMin'] = '33.33'; row['pCompndMax'] = '33.33'
        expected = {k: format(D(v).quantize(D(1).scaleb(-scales[k])), 'f') for k, v in row.items()}
        if i % 3 == 0: row = {k: v.replace('.', ',') for k, v in row.items()}
        if i % 3 == 1: row = {k: v + 'e0' for k, v in row.items()}
        valid.append(dict(input=row, expected=expected))
    for row in [dict(base, pMin='.0100000', pMax='1e3', pPerc='+1.50', pBonus='-0.00'), dict(base, pMin=' 0,01 ', pMax='1 000', pMinAdd='', pCompndMin='')]:
        expected = {k: format(D(v.replace(' ', '').replace(',', '.') or '0').quantize(D(1).scaleb(-scales[k])), 'f') for k, v in row.items()}
        expected = {k: ('0.' + '0'*scales[k] if D(v).is_zero() else v) for k, v in expected.items()}
        valid.append(dict(input=row, expected=expected))
invalid = []
for k, scale in scales.items():
    error = dict(pMin='min_wrong', pMax='max_wrong', pMinAdd='minadd_wrong', pPerc='perc_wrong', pReturn='perc2_wrong', pBonus='bonus_wrong', pCompndMin='compndmin_wrong', pCompndMax='compndmax_wrong', pClComis='clcomis_wrong')[k]
    for v in ['0.' + '0'*scale + '1', '10000000' if scale == 6 else '100000', '1e1000000', 'NaN', [], True]:
        invalid.append(dict(input=dict(base, **{k:v}), error=error))
for k, v, error in [('pMin','0.009999','min_wrong'),('pMax','0.01','max_wrong'),('pCompndMin','-0.01','compndmin_wrong'),('pCompndMax','100.01','compndmax_wrong'),('pClComis','-0.01','clcomis_wrong')]:
    invalid.append(dict(input=dict(base, **{k:v}), error=error))
invalid += [dict(input=dict(base,pPerc='0',pReturn='0'),error='percent_required'), dict(input=dict(base,pCompndMin='50',pCompndMax='49.99'),error='compndmax_wrong')]
Path(__file__).with_name('decimal_plan_cases.json').write_text(json.dumps(dict(valid=valid, invalid=invalid),indent=2)+'\n')
