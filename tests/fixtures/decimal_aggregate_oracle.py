#!/usr/bin/env python3
"""Independent decimal read projection vectors, no application imports or database writes."""
import json, random
from decimal import Decimal as D, localcontext, ROUND_HALF_UP
from pathlib import Path
rng = random.Random(704004)
def fixed(value, places):
    return format(D(value).quantize(D(1).scaleb(-places), rounding=ROUND_HALF_UP), 'f')
with localcontext() as ctx:
    ctx.prec = 180
    portfolios = []
    for i in range(96):
        rows = {}
        for cid in range(1, 7):
            amount = format(D(rng.randrange(-9999999999999, 9999999999999)).scaleb(-6), 'f')
            if i % 8 == 0: amount = '9007199254740993.' + str(cid).zfill(6)
            if i % 8 == 1: amount = '0'
            if i % 8 == 2: amount = '-0.000001'
            if i % 8 == 3: amount = None if cid % 2 else '3'
            rows[str(cid)] = {'cCurrID': ('USD' if cid < 4 else 'EUR'), 'cCurr': 'label-' + str(cid), 'wBal': amount}
        if i % 6 == 0: rows['6'].update(cCurrID='', cCurr='')
        if i % 6 == 1: rows['6'].update(cCurrID='', cCurr='USD')
        groups = {}
        currencies = json.loads(json.dumps(rows))
        for cid, row in rows.items():
            code = row['cCurrID'] or row['cCurr'] or 'CUR' + cid
            group = groups.setdefault(code, {'cCurr':code, 'cID':int(cid), 'balance':D(0), 'systems':0})
            group['balance'] += D(row['wBal'] or 0)
            group['systems'] += 1
            currencies[cid]['wBal'] = fixed(row['wBal'] or 0, 6)
        for cid, row in currencies.items():
            code = row['cCurrID'] or row['cCurr'] or 'CUR' + cid
            total = groups[code]['balance']
            row['alloc_pct'] = fixed(D(row['wBal']) * 100 / total if total > 0 else 0, 1)
        ordered = sorted(groups.values(), key=lambda g: (-g['balance'], g['cCurr']))
        for group in ordered: group['balance'] = fixed(group['balance'], 6)
        portfolios.append({'input':rows, 'expected':{'currencies':currencies, 'groups':ordered, 'primary':ordered[0]}})
    displays = []
    for i in range(108):
        value = D(rng.randrange(-9999999999999, 9999999999999)).scaleb(-6) * D(rng.randrange(1, 999999999999999999)).scaleb(-18)
        scale = i % 9
        displays.append({'value':format(value, 'f'), 'scale':scale, 'expected':fixed(value, scale)})
    data = {'portfolios':portfolios,'displays':displays}
Path(__file__).with_name('decimal_aggregate_cases.json').write_text(json.dumps(data, indent=2)+'\n')
# Model SQL predicates independently: NULL arithmetic/memos are excluded by SUM/NOT LIKE.
with localcontext() as ctx:
    ctx.prec=180
    rows=[]
    for i in range(1001):
        rows.append({'ocID':1 if i%2 else 4,'oOper':'CASHIN','oState':3,'oSum':'9999999.999999','oComis':'0.000000','oMemo':'fixture','oCTS':'20250101000000'})
    rows += [
        {'ocID':2,'oOper':'CASHIN','oState':3,'oSum':'1.005001','oComis':'0.000000','oMemo':'fixture','oCTS':'20250102000000'},
        {'ocID':3,'oOper':'CASHIN','oState':3,'oSum':'0.000001','oComis':'0.000000','oMemo':'fixture','oCTS':'20250103000000'},
        {'ocID':99,'oOper':'CASHIN','oState':3,'oSum':'99.000000','oComis':'0.000000','oMemo':'fixture','oCTS':'20250101000000'},
        {'ocID':1,'oOper':'CASHIN','oState':2,'oSum':'12.000000','oComis':'0.000000','oMemo':'fixture','oCTS':'20250101000000'},
        {'ocID':1,'oOper':'CASHOUT','oState':3,'oSum':'3.005001','oComis':'0.500000','oMemo':'fixture','oCTS':'20250101000000'},
        {'ocID':4,'oOper':'CASHOUT','oState':3,'oSum':'9.000000','oComis':None,'oMemo':'fixture','oCTS':'20250102000000'},
        {'ocID':2,'oOper':'CASHOUT','oState':3,'oSum':None,'oComis':'1.000000','oMemo':'fixture','oCTS':'20250103000000'},
        {'ocID':3,'oOper':'CASHOUT','oState':3,'oSum':'0.000001','oComis':'0.000002','oMemo':'fixture','oCTS':'20250101000000'},
        {'ocID':3,'oOper':'CASHOUT','oState':2,'oSum':'4.000000','oComis':'0.000000','oMemo':'fixture','oCTS':'20250101000000'},
        {'ocID':1,'oOper':'REF','oState':3,'oSum':'2.000001','oComis':'0.000000','oMemo':'fixture','oCTS':'20250101000000'},
        {'ocID':99,'oOper':'REF','oState':3,'oSum':'-1.000001','oComis':'0.000000','oMemo':'fixture','oCTS':'20250101000000'},
        {'ocID':2,'oOper':'GIVE','oState':3,'oSum':'5.000001','oComis':'0.000000','oMemo':'Manual','oCTS':'20250101000000'},
        {'ocID':4,'oOper':'GIVE','oState':3,'oSum':'6.000001','oComis':'0.000000','oMemo':'Auto reinvest','oCTS':'20250101000000'},
        {'ocID':3,'oOper':'GIVE','oState':3,'oSum':'7.000001','oComis':'0.000000','oMemo':None,'oCTS':'20250101000000'},
    ]
    currencies={'USD':[1,4],'EUR':[2],'BTC':[3],'EMPTY':[]}
    stats=[]
    for i in range(32):
        cfg={'Depo_S2':format(D(rng.randrange(-999999,999999)).scaleb(-6),'f'),'Depo_S3':'0.000000000000000001',
             'Depo_S2USD':'0.000000000000000001','Depo_S3BTC':'-0.000000000000000001',
             'Depo_S4':'1e-18','Depo_S5':'-1e-18','Bal_RateUSD':format(D(rng.randrange(1,999999999999999999)).scaleb(-18)),
             'Bal_RateEUR':'-2.000000000000000001','Bal_RateBTC':'30000.000000000000000001'}
        if i%4==0: cfg['Bal_RateEUR']='0'
        if i%4==1: del cfg['Bal_RateEUR']
        if i%4==2: cfg['Depo_S2']='9007199254740993.000001'
        sums={'zin':D(cfg['Depo_S2']),'zout':D(cfg['Depo_S3'])};maps={'zin2':{},'zout2':{}}
        for currency,ids in currencies.items():
            if not ids: continue
            for output,op,offset in [('zin','CASHIN','Depo_S2'),('zout','CASHOUT','Depo_S3')]:
                values=[D(r['oSum'])-(D(r['oComis']) if op=='CASHOUT' else 0) for r in rows
                        if r['ocID'] in ids and r['oOper']==op and r['oState']==3 and r['oSum'] is not None and (op!='CASHOUT' or r['oComis'] is not None)]
                amount=sum(values,D(0))+D(cfg.get(offset+currency,'0'))
                maps[output+'2'][currency]=format(amount,'f')
                sums[output]+=amount*D(cfg.get('Bal_Rate'+currency,'1'))
        sums['zref']=sum((D(r['oSum']) for r in rows if r['oOper']=='REF' and r['oState']==3 and r['oSum'] is not None),D(0))+D(cfg['Depo_S4'])
        sums['zreinv']=sum((D(r['oSum']) for r in rows if r['oOper']=='GIVE' and r['oState']==3 and r['oSum'] is not None and r['oMemo'] is not None and 'auto' not in r['oMemo'].lower()),D(0))+D(cfg['Depo_S5'])
        stats.append({'config':cfg,'expected':{**{k:format(v,'f') for k,v in sums.items()},**maps}})
    data.update(operations=rows,currencies=currencies,statistics=stats)
Path(__file__).with_name('decimal_aggregate_cases.json').write_text(json.dumps(data,indent=2)+'\n')
