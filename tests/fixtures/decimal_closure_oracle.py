#!/usr/bin/env python3
"""Independent Decimal oracle for transport, simple previews and public amounts."""
from decimal import Decimal, localcontext, ROUND_HALF_UP
import json, random
from pathlib import Path
r=random.Random(71411)
with localcontext() as c:
    c.prec=160
    gateway=[]; summary=[]; public=[]; landing=[]
    for scale in (2,8):
        for _ in range(120):
            raw=f'{r.randrange(0,10**12)}.{r.randrange(10**scale):0{scale}d}'
            gateway.append(dict(value=raw,scale=scale,expected=raw))
    for raw in ['1.005','1.00499999','999999999999.994999','0.00999999','12.3']:
        gateway.append(dict(value=raw,scale=2,checkout=True,expected=format(Decimal(raw).quantize(Decimal('.01'),rounding=ROUND_HALF_UP),'f')))
    for _ in range(240):
        raw=f'{r.randrange(10**12)}.{r.randrange(10**8):08}'
        perc=f'{r.randrange(-10000,100000)}.{r.randrange(100):02}'
        count=r.choice([0,1,2,30,9999,-2]);n=count or 1
        period=Decimal(raw)*Decimal(perc)/100;total=period*n
        summary.append(dict(sum=raw,plan=dict(pPerc=perc,pNPer=count),expected=dict(summary_sum=format(Decimal(raw),'f'),period_profit=format(period,'f'),total_profit=format(total,'f'),summary_return=format(Decimal(raw)+total,'f'))))
    for raw in ['0.000000005','5.000000000000000000000001','1.004999999999999999999999','9999999999999999.999999995']+[f'{r.randrange(10**36)}.{r.randrange(10**24):024}' for _ in range(240)]:
        public.append(dict(value=raw,expected=format(Decimal(raw).quantize(Decimal('.00000001'),rounding=ROUND_HALF_UP),'f')))
    for raw in ['0','12.00000099','12.000001','12.99999901','12.999999','1.005','9007199254740993.01','9999999999999999.999999995']:
        value=Decimal(raw);scale=0 if abs(value-value.quantize(Decimal(1),rounding=ROUND_HALF_UP))<Decimal('.000001') else 2
        landing.append(dict(value=raw,expected=format(value.quantize(Decimal(1).scaleb(-scale),rounding=ROUND_HALF_UP),f',.{scale}f')))
Path(__file__).with_name('decimal_closure_cases.json').write_text(json.dumps(dict(gateway=gateway,summary=summary,public=public,landing=landing),indent=2)+'\n')
