#!/usr/bin/env python3
"""Independent offline Python stdlib Decimal forecast/list oracle."""
import json
from decimal import Decimal as D, localcontext, ROUND_HALF_UP
from pathlib import Path

def fixed(value, scale=2):
    rounded = value.quantize(D(1).scaleb(-scale), rounding=ROUND_HALF_UP)
    return abs(rounded) if not rounded else rounded

with localcontext() as ctx:
    ctx.prec = 160
    forecasts = []
    for amount in ['0.004999', '0.005', '1.005001', '1234.567891', '9999999.999999']:
        for bonus, percent, ret in [('0','0.5','100'),('1.23','1.23','50'),('-2.55','-1.01','120')]:
            for compound in ['0','33.33','100']:
                for periods in [0,1,7,365]:
                    base = fixed(D(amount))
                    bonus_sum = fixed(base * D(bonus) / 100)
                    balances = [base + bonus_sum]
                    payouts, capital = [], []
                    for _ in range(periods):
                        gross = fixed(balances[-1] * D(percent) / 100)
                        reinvest = fixed(gross * D(compound) / 100)
                        payouts.append(gross - reinvest)
                        capital.append(reinvest)
                        balances.append(balances[-1] + reinvest)
                    profit = fixed(sum(payouts, D(0)) + sum(capital, D(0)))
                    returned = fixed((balances[-1] - sum(capital, D(0))) * D(ret) / 100)
                    expected = dict(bonus_sum=bonus_sum, profit_sum=profit,
                                    deposit_return_sum=returned, total_return_sum=fixed(returned + profit))
                    forecasts.append(dict(amount=amount,bonus=bonus,percent=percent,returnPercent=ret,
                        compound=compound,periods=periods,expected={k:format(v,'f') for k,v in expected.items()}))
    profits = []
    for scale in [0,2,6,8]:
        for principal in ['0.000001','1.005001','9999999.999999']:
            for hours in [1,3,24]:
                gross = fixed(D(principal)*D('1.23')/100,6)
                seconds = hours*3600
                for elapsed in [0,1,2,seconds//2,seconds,seconds+100]:
                    effective = min(elapsed,seconds) if elapsed>1 else 0
                    profits.append(dict(scale=scale,principal=principal,percent='1.23',hours=hours,elapsed=elapsed,
                        current=format(fixed(gross*effective/seconds,6),'f'),hourly=format(fixed(gross/hours,scale),'f')))
Path(__file__).with_name('decimal_forecast_cases.json').write_text(json.dumps(dict(
    oracle='Python stdlib Decimal precision160 HALF_UP, historical two-place preview and six-place list accrual',
    forecasts=forecasts,profits=profits),indent=2)+'\n')
