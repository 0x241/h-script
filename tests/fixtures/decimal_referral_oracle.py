#!/usr/bin/env python3
"""Independent offline Python Decimal referral oracle; no DB or network access."""
import json
from decimal import Decimal as D, localcontext, ROUND_HALF_UP
from pathlib import Path

def rounded(value, scale):
    return value.quantize(D(1).scaleb(-scale), rounding=ROUND_HALF_UP)

with localcontext() as ctx:
    ctx.prec = 120
    cases = []
    for scale in [0, 2, 3, 4, 6, 8]:
        for source in ['0.000001', '0.01', '0.049995', '1.005001', '100.000001', '9999999.999999']:
            for percent in ['0', '0.000001', '0.01', '1.23456789', '5', '10', '120']:
                reward = rounded(rounded(D(source) * D(percent) / 100, 6), scale)
                error = 'sum_overflow' if reward > D('9999999.999999') else 'sum_precision' if reward != rounded(reward, 6) else None
                cases.append(dict(id=f'{scale}:{source}:{percent}', scale=scale, source=source, percent=percent,
                                  expected=None if error else format(rounded(reward, 6), 'f'), error=error))
    tiers = []
    for metric in ['0', '99.999999', '100', '100.000001', '19999999.999998', '20000000', '20000000.000001']:
        entries=['0-1', '100-2.3456789', '20000000-5']
        percent=D(0);level=0
        for i,entry in enumerate(entries):
            threshold, candidate = map(D, entry.split('-', 1));level=i+1
            if threshold > D(metric): break
            percent=candidate
        tiers.append(dict(metric=metric, entries=entries, percent=format(percent,'f'), level=level))
Path(__file__).with_name('decimal_referral_cases.json').write_text(json.dumps(
    dict(oracle='Python stdlib Decimal, precision120; HALF_UP6 then currency; ordered exact tiers', cases=cases, tiers=tiers), indent=2)+'\n')
