#!/usr/bin/env python3
"""Independent signed SQL configuration vectors; no application imports."""
import json, random
from decimal import Decimal as D, localcontext
from pathlib import Path
rng=random.Random(704006)
fields={'cCASHOUTLimit':(13,6),'cEXIn':(10,6),'cEXOut':(10,6)}
for op in ('CASHIN','CASHOUT','EX','TR'):
    for suffix in ('Min','Max','ComisMin','ComisMax'): fields['c'+op+suffix]=(13,6)
    fields['c'+op+'Comis']=(6,2)
valid=[];invalid=[]
with localcontext() as ctx:
    ctx.prec=100
    for i in range(120):
        row={k:format(D(rng.randrange(-(10**p-1),10**p-1)).scaleb(-s),'f') for k,(p,s) in fields.items()}
        if i%4==0: row={k:('-' if i%8==0 else '')+('9'*(p-s))+'.'+('9'*s) for k,(p,s) in fields.items()}
        expected={k:format(D(v).quantize(D(1).scaleb(-fields[k][1])),'f') for k,v in row.items()}
        if i%3==0: row={k:v.replace('.',',') for k,v in row.items()}
        if i%3==1: row={k:' '+v+'e0 ' for k,v in row.items()}
        valid.append(dict(input=row,expected=expected))
    valid.append(dict(input={},expected={k:'0.'+'0'*s for k,(_,s) in fields.items()}))
    for k,(p,s) in fields.items():
        for value in ['0.'+'0'*s+'1',str(10**(p-s)),str(-10**(p-s)),'NaN','1e9999999',[],True]:
            invalid.append(dict(input={k:value},error='currency_decimal_invalid'))
    calendar=[dict(input=v,expected=format(D(v.replace(',','.').strip() or '0').quantize(D('.01')),'f')) for v in ['0','1','100','100.01','99999.99','+.5','1e2','33,33','',' 0.00 ']]
    for i in range(80):
        v=format(D(rng.randrange(0,9999999)).scaleb(-2),'f');calendar.append(dict(input=v,expected=format(D(v).quantize(D('.01')),'f')))
Path(__file__).with_name('decimal_configuration_cases.json').write_text(json.dumps(dict(fields=fields,valid=valid,invalid=invalid,calendar=calendar),separators=(',',':'))+'\n')
