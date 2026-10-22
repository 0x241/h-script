#!/usr/bin/env python3
"""Offline oracle. Regenerate with Python stdlib Decimal; no DB/network access."""
import json
from decimal import Decimal as D, localcontext, ROUND_HALF_UP, ROUND_CEILING
from pathlib import Path

def rounded(v, scale):
    return v.quantize(D(1).scaleb(-scale), rounding=ROUND_HALF_UP)
def percentage(amount, percent, scale):
    return rounded(rounded(amount * percent / 100, 6), scale)
def stored(v):
    return format(v.quantize(D('.000001')), 'f')

with localcontext() as ctx:
    ctx.prec = 120
    cases=[]
    for scale in [0, 2, 3, 6, 8]:
        for principal in ['0.000001','0.01','1.005001','123.499950','99999.999999']:
            for terminal in [False, True]:
                for ret in ['0','50','100','120']:
                    p=D(principal); compound=rounded(p/D(4),6)
                    profit=percentage(p,D('1.23'),scale)
                    returned=compound+(p-compound if ret=='100' else percentage(p-compound,D(ret),scale)) if terminal else D(0)
                    reinvest=D(0) if terminal else percentage(profit,D('33.33'),scale)
                    totals={'profit':profit,'returned':returned,'reinvest':reinvest,'totalProfit':D('.000001')+profit,'release':p if terminal else D(0),'worked':p-compound}
                    invalid=any(v != rounded(v,6) for v in [profit,returned,reinvest,totals['totalProfit'],profit+returned])
                    cases.append({'id':f'{scale}:{principal}:{terminal}:{ret}','scale':scale,'principal':principal,'compounded':stored(compound),'previousProfit':'0.000001','percent':'1.23','returnPercent':ret,'compoundPercent':'33.33','terminal':terminal,'expected':None if invalid else {k:stored(v) for k,v in totals.items()}})
    caps=[]
    for scale,rate,minimum,principal,compound in [(2,'3','1','1','0.9'),(2,'2','1','2','1.8'),(6,'30000','1','0.0001','0.00009'),(8,'3','1','1','0.9'),(3,'1.2345','10','20','15')]:
        native=(D(minimum)/D(rate)).quantize(D(1).scaleb(-min(scale,6)),rounding=ROUND_CEILING)
        cap=max(D(0),D(principal)-native)
        caps.append(dict(scale=scale,rate=rate,minimum=minimum,principal=principal,compounded=compound,expected=stored(min(D(compound),cap))))
Path(__file__).with_name('decimal_deposit_cases.json').write_text(json.dumps({'oracle':'Python decimal, explicit HALF_UP6 -> currency; exact 100% identity; CEILING native cap','cases':cases,'caps':caps},indent=2)+'\n')
