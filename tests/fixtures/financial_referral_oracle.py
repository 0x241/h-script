#!/usr/bin/env python3
"""Offline seeded graphs and issuance oracle, Python Decimal independent of PHP."""
import json
import random
from decimal import Decimal as D, ROUND_HALF_UP, localcontext
from pathlib import Path


def q(value, scale):
    return value.quantize(D(1).scaleb(-scale), rounding=ROUND_HALF_UP)


with localcontext() as ctx:
    ctx.prec = 120
    cases = []
    for seed in [71112137, 17, 41]:
        rng = random.Random(seed)
        for scale in [0, 2, 3, 4, 6]:
            for cyclic in [False, True]:
                for sample in range(3):
                    length = rng.randint(2, 6)
                    users = list(range(2, length + 2))
                    parents = {1: 2} | {u: u + 1 if u != users[-1] else 0 for u in users}
                    if cyclic:
                        parents[users[-1]] = rng.choice([1] + users)
                    rates = [str(D(rng.randint(0, 250000)) / 10000) for _ in range(length + 2)]
                    active = {u: rng.randrange(4) != 0 for u in users}
                    overrides = {u: [str(D(rng.randint(0, 80000)) / 10000) for _ in range(rng.randint(1, length + 2))]
                                 for u in users if rng.randrange(3) == 0}
                    raw = D(rng.randint(10**6, 500*10**6)) / 10**6
                    gross = q(raw, scale)
                    fee_percent = D(rng.choice(['0', '1.17', '2.345678']))
                    fee = q(q(gross * fee_percent / 100, 6), scale)
                    net = gross - fee
                    payouts = {}
                    node = 2
                    seen = {1}
                    error = None
                    for level, global_rate in enumerate(rates):
                        if node in seen:
                            error = 'ref_cycle'
                            payouts = {}
                            break
                        if node not in parents:
                            break
                        seen.add(node)
                        personal = overrides.get(node)
                        rate = D((personal[level] if level < len(personal) else '0') if personal else global_rate)
                        reward = q(q(net * rate / 100, 6), scale)
                        if active[node] and reward:
                            payouts[str(node)] = format(q(reward, 6), 'f')
                        node = parents[node]
                    assert bool(error) == cyclic
                    cases.append(dict(seed=seed, sample=sample, scale=scale, cid=rng.choice([1, 2, 3]),
                                      raw=format(raw, 'f'), gross=format(q(gross, 6), 'f'),
                                      fee_percent=str(fee_percent), fee=format(q(fee, 6), 'f'), net=format(q(net, 6), 'f'),
                                      parents=parents, active=active, overrides=overrides, rates=rates,
                                      payouts=payouts, issuance=format(q(sum(map(D, payouts.values()), D(0)), 6), 'f'), error=error))
out = Path(__file__).with_name('financial_referral_cases.json')
out.write_text(json.dumps(dict(format='hscript-referral-properties-v1', oracle='Python Decimal precision120, HALF_UP6 then currency; seeded graph and net-source issuance', cases=cases), indent=2) + '\n')
out.chmod(0o600)
