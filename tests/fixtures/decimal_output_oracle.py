#!/usr/bin/env python3
"""Offline fixed-format HALF_UP oracle using only Python stdlib Decimal."""
import json
from decimal import Decimal as D, localcontext, ROUND_HALF_UP
from pathlib import Path

with localcontext() as ctx:
    ctx.prec = 120
    cases = []
    for scale in range(9):
        for value in ['0', '-0', '0.000001', '-0.000001', '0.004999', '0.005000',
                      '-0.005000', '1.005001', '9999999.999999', '-9999999.999999',
                      '19999999.999998', '0.000000005', '1.234567891', '.5', '5.',
                      '5.e-2', '1e-9', '1234567891e-9']:
            rounded = D(value).quantize(D(1).scaleb(-scale), rounding=ROUND_HALF_UP)
            if not rounded: rounded = abs(rounded)
            cases.append(dict(value=value, scale=scale, expected=format(rounded, 'f')))
Path(__file__).with_name('decimal_output_cases.json').write_text(json.dumps(
    dict(oracle='Python stdlib Decimal precision120 HALF_UP', cases=cases), indent=2)+'\n')
