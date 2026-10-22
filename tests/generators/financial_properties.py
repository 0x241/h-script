#!/usr/bin/env python3
"""Generate replayable financial properties with a stdlib Decimal oracle; no database I/O."""
import argparse
import json
import random
from decimal import Decimal, ROUND_HALF_UP, getcontext

getcontext().prec = 80
D = Decimal
ZERO = D(0)


def quant(value, scale):
    return value.quantize(D(1).scaleb(-scale), rounding=ROUND_HALF_UP)


def text(value, scale=6):
    return format(quant(value, scale), f'.{scale}f')


def percent(value, rate, scale):
    return quant(quant(value * D(rate) / 100, 6), scale)


def fee(value, policy, scale):
    result = percent(value, policy['percent'], scale)
    if result < D(policy['min']):
        result = D(policy['min'])
    elif D(policy['max']) > 0 and result > D(policy['max']):
        result = D(policy['max'])
    return quant(result, scale)


def stored(value):
    return value == quant(value, 6) and abs(value) <= D('9999999.999999')


def sequence(seed, scale):
    rng = random.Random(seed * 31 + scale)
    scales = {1: scale, 2: rng.choice([2, 4, 6, 8])}
    rate = rng.choice(['0.25', '0.333333', '1', '2', '3', '10'])
    policy = {cid: {kind: {'percent': rng.choice(['0', '0.5', '1.25', '2', '5']),
                          'min': rng.choice(['0', '0.01']), 'max': rng.choice(['0', '0.10', '5'])}
                    for kind in ['TR', 'EX', 'CASHIN', 'CASHOUT']} for cid in [1, 2]}
    corrections = {'in': rng.choice(['-5', '0', '2.5']), 'out': rng.choice(['-5', '0', '2.5'])}
    balances = {f'{uid}:{cid}': D(5000) for uid in [1, 2, 3] for cid in [1, 2]}
    ledger = {cid: {'external_in': ZERO, 'external_out': ZERO, 'fees': ZERO,
                    'exchange_in': ZERO, 'exchange_out': ZERO} for cid in [1, 2]}
    steps = []
    audit = 6
    operations = 0
    for i in range(30):
        kind = ['TR', 'EX', 'CASHIN', 'CASHOUT', 'cancel', 'overdraft'][i % 6] if i < 12 else rng.choice(['TR', 'EX', 'CASHIN', 'CASHOUT', 'cancel', 'overdraft'])
        uid, cid = rng.randint(1, 3), rng.randint(1, 2)
        target = rng.choice([u for u in [1, 2, 3] if u != uid]) if kind in ['TR', 'cancel', 'overdraft'] else 3 - cid
        raw = D(rng.randint(10000, 2500000)) / 100000
        if i % 13 == 0 and scales[cid] == 8:
            raw += D('0.00000001')
        if kind == 'overdraft':
            raw = D('999999')
        operation = 'TR' if kind in ['cancel', 'overdraft'] else kind
        amount = quant(raw, scales[cid])
        commission = fee(amount, policy[cid][operation], scales[cid])
        net = amount - commission
        error = None
        quote = None
        if not stored(amount) or not stored(commission):
            error = 'sum_precision'
        elif net < 0 or amount <= 0:
            error = 'sum_wrong'
        elif operation != 'CASHIN' and balances[f'{uid}:{cid}'] < amount:
            error = 'low_bal1'
        elif operation == 'EX':
            quote = net
            if cid != 1:
                quote = quant(quote * D(rate) * (1 + D(corrections['out']) / 100), scales[cid])
            if target != 1:
                quote = quant(quote / (D(rate) * (1 - D(corrections['in']) / 100)), scales[target])
            quote = quant(quote, scales[target])
            if quote <= 0:
                error = 'ex_sum_wrong'
            elif not stored(quote):
                error = 'sum_precision'
        audit_delta = 0
        operation_delta = 0
        if error is None:
            operation_delta = 1
            if kind == 'cancel':
                audit_delta = 2
            elif operation == 'CASHIN':
                balances[f'{uid}:{cid}'] += net
                ledger[cid]['external_in'] += amount
                ledger[cid]['fees'] += commission
                audit_delta = 1
            else:
                balances[f'{uid}:{cid}'] -= amount
                ledger[cid]['fees'] += commission
                audit_delta = 2
                if operation == 'TR':
                    balances[f'{target}:{cid}'] += net
                    audit_delta += 1
                    operation_delta += 1
                elif operation == 'EX':
                    balances[f'{uid}:{target}'] += quote
                    ledger[cid]['exchange_out'] += net
                    ledger[target]['exchange_in'] += quote
                    audit_delta += 1
                    operation_delta += 1
                else:
                    ledger[cid]['external_out'] += net
            audit += audit_delta
            operations += operation_delta
        for currency in [1, 2]:
            expected_total = D(15000) + ledger[currency]['external_in'] - ledger[currency]['external_out'] - ledger[currency]['fees'] + ledger[currency]['exchange_in'] - ledger[currency]['exchange_out']
            assert sum(v for k, v in balances.items() if k.endswith(f':{currency}')) == expected_total
        steps.append({'index': i, 'kind': kind, 'operation': operation, 'uid': uid, 'cid': cid, 'target': target,
                      'raw': format(raw, 'f'), 'amount': text(amount), 'fee': text(commission),
                      'quote': text(quote) if quote is not None else None, 'error': error,
                      'audit_delta': audit_delta, 'audit_count': audit, 'operation_count': operations,
                      'balances': {k: text(v) for k, v in balances.items()},
                      'user_totals': {uid: text(sum(balances[f'{uid}:{cid}'] for cid in [1, 2])) for uid in [1, 2, 3]},
                      'ledger': {cid: {k: text(v) for k, v in entries.items()} for cid, entries in ledger.items()}})
    return {'seed': seed, 'scale': scale, 'scales': scales, 'rate': rate, 'corrections': corrections,
            'policies': policy, 'steps': steps}


def deposit(seed, scale):
    rng = random.Random(seed * 47 + scale)
    plan = {'percent': rng.choice(['0.5', '5', '12.34']), 'bonus': rng.choice(['0', '1.5', '10']),
            'return': rng.choice(['0', '50', '100', '120']), 'compound': rng.choice(['0', '25', '50', '100']),
            'periods': rng.randint(1, 4), 'give_fee': rng.choice(['0', '1.25']),
            'take_fee': rng.choice(['0', '0.75']), 'close_fee': rng.choice(['0', '1.5', '10'])}
    available, principal, compounded, profit = D(1000), ZERO, ZERO, ZERO
    audit = 1
    steps = []
    for kind in ['create', 'add', 'sub'] + ['charge'] * plan['periods']:
        raw = D(rng.randint(100000, 300000)) / 10000 if kind == 'create' else D(rng.randint(10000, 50000)) / 10000
        amount = quant(raw, scale)
        earned, returned, reinvest, burn = ZERO, ZERO, ZERO, ZERO
        if kind in ['create', 'add']:
            charge = percent(amount, plan['give_fee'], scale)
            available -= amount
            principal += amount - charge
            burn = charge
            if kind == 'create':
                earned = percent(amount, plan['bonus'], scale)
                principal += earned
            audit += 2
        elif kind == 'sub':
            charge = percent(amount, plan['take_fee'], scale) + percent(amount, plan['close_fee'], scale)
            available += amount - charge
            principal -= amount
            compounded = max(ZERO, compounded - amount)
            burn = charge
            audit += 2
        else:
            earned = percent(principal, plan['percent'], scale)
            profit += earned
            terminal = sum(s['kind'] == 'charge' for s in steps) + 1 == plan['periods']
            if terminal:
                worked = principal - compounded
                returned = compounded + (worked if plan['return'] == '100' else percent(worked, plan['return'], scale))
                burn = principal - returned  # Negative burn describes contractual return above principal.
                available += earned + returned
                locked = ZERO
                audit += int(earned + returned != 0 or principal != 0)
            else:
                reinvest = percent(earned, plan['compound'], scale)
                # An internal GIVE pays configured fee too; actual locked addition is its net.
                charge = percent(reinvest, plan['give_fee'], scale)
                principal += reinvest - charge
                compounded += reinvest - charge
                available += earned - reinvest
                burn = charge
                audit += int(earned != 0) + (2 if reinvest != 0 else 0)
        locked = ZERO if kind == 'charge' and terminal else principal
        steps.append({'kind': kind, 'amount': text(amount), 'available': text(available), 'locked': text(locked),
                      'principal': text(principal), 'compounded': text(compounded), 'profit': text(profit),
                      'earned': text(earned), 'returned': text(returned), 'burn': text(burn),
                      'audit_count': audit, 'terminal': kind == 'charge' and terminal})
    return {'seed': seed, 'scale': scale, 'plan': plan, 'steps': steps}


def referral(seed, scale):
    rng = random.Random(seed * 53 + scale)
    chain = rng.sample([2, 3], 2)
    active = {uid: rng.choice([0, 1]) for uid in chain}
    rates = [rng.choice(['0', '0.5', '5', '12.34']) for _ in chain]
    overrides = {uid: ([rng.choice(['0', '1.25', '7'])] if rng.choice([False, True]) else []) for uid in chain}
    source = D(rng.randint(1, 99999999)) / 10000
    rewards = {uid: ZERO for uid in [1, 2, 3]}
    for level, uid in enumerate(chain):
        rate = overrides[uid][level] if len(overrides[uid]) > level else ('0' if overrides[uid] else rates[level])
        if active[uid]:
            rewards[uid] = percent(source, rate, scale)
    return {'seed': seed, 'scale': scale, 'chain': chain, 'active': active, 'rates': rates, 'overrides': overrides,
            'source': format(source, 'f'), 'rewards': {k: text(v) for k, v in rewards.items()},
            'audit_count': sum(v != 0 for v in rewards.values())}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--seed', type=int, action='append', help='Repeat for multiple reproducible seeds.')
    args = parser.parse_args()
    seeds = args.seed or [71111005, 17, 41]
    if any(seed < 0 or seed > 2147483647 for seed in seeds):
        parser.error('seed must be in 0..2147483647')
    cases = [(seed, scale) for seed in seeds for scale in [0, 2, 3, 4, 6, 8]]
    result = {'format': 'hscript-financial-properties-v1', 'seeds': seeds,
              'sequences': [sequence(seed, scale) for seed, scale in cases],
              'deposits': [deposit(seed, scale) for seed, scale in cases],
              'referrals': [referral(seed, scale) for seed, scale in cases]}
    print(json.dumps(result, indent=2, ensure_ascii=True))


if __name__ == '__main__':
    main()
