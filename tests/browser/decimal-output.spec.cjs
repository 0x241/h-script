const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const headers = { Authorization: 'Bearer hs_' + '3'.repeat(64) };
const get = (request,route) => request.get('/api/v1/'+route,{headers});
const oldBody = { currency_id:5,amount:'25',memo:'Synthetic legacy numeric receipt' };
const newBody = { currency_id:5,amount:'1.005001',memo:'Synthetic new string receipt' };
const post = (request,key,data) => request.post('/api/v1/deposit',{headers:{...headers,'Idempotency-Key':key},data});
const money = value => expect(value).toMatch(/^-?\d+\.\d{6}$/);

test('stored API decimals, wide totals, new string snapshots and old numeric receipts',async({request})=>{
  const balance=await get(request,'balance');expect(balance.status()).toBe(200);
  const wallet=(await balance.json()).data.wallets.find(w=>w.currency.id===5);
  expect(wallet).toMatchObject({available:'9999999.999999',locked:'9999999.999998',outgoing:'0.000001',total:'19999999.999998'});
  for(const field of ['available','locked','outgoing','total']) money(wallet[field]);
  const limits=await get(request,'deposit');expect(limits.status()).toBe(200);
  const currency=(await limits.json()).data.currencies.find(c=>c.id===5);
  expect(currency).toMatchObject({minimum:'0.000001',maximum:'9999999.999999',fee_percent:'5.55',fee_minimum:'0.000001',fee_maximum:'9999999.999999'});
  const op=await get(request,'operations');expect(op.status()).toBe(200);
  expect((await op.json()).data.operations[0]).toMatchObject({amount:'0.000001',fee:'0.000002',net_amount:'-0.000001'});
  const old=await post(request,'output-legacy',oldBody);expect(old.status()).toBe(201);expect(old.headers()['idempotency-replayed']).toBe('true');
  const legacy=(await old.json()).data;expect(legacy.operation.amount).toBe(25);expect(typeof legacy.operation.amount).toBe('number');
  const responses=await Promise.all(Array.from({length:8},()=>post(request,'output-new',newBody)));
  for(const response of responses) expect(response.status()).toBe(201);
  const originals=await Promise.all(responses.map(r=>r.json()));const fresh=originals[0].data;
  expect(fresh.operation).toMatchObject({amount:'1.010000',fee:'0.060000',net_amount:'0.950000'});
  for(const body of originals) expect(body.data).toEqual(fresh);
  const repeat=await post(request,'output-new',newBody);expect((await repeat.json()).data).toEqual(fresh);
  expect((await post(request,'output-new',{...newBody,amount:'2'})).status()).toBe(409);
  fs.writeFileSync('/tmp/decimal-output-original.json',JSON.stringify({legacy,fresh,wallet}),{mode:0o600});
});

test('restart returns original string and numeric snapshots after currency policy drift',async({request})=>{
  const saved=JSON.parse(fs.readFileSync('/tmp/decimal-output-original.json','utf8'));
  for(const [key,body,expected] of [['output-legacy',oldBody,saved.legacy],['output-new',newBody,saved.fresh]]) {
    const response=await post(request,key,body);expect(response.status()).toBe(201);expect(response.headers()['idempotency-replayed']).toBe('true');
    const current=(await response.json()).data;expect(JSON.stringify(current)).toBe(JSON.stringify(expected));
  }
  const wallet=(await (await get(request,'balance')).json()).data.wallets.find(w=>w.currency.id===5);
  expect(wallet.available).toBe(saved.wallet.available);expect(wallet.total).toBe(saved.wallet.total);expect(wallet.currency.decimals).toBe(8);
  const operations=(await (await get(request,'operations')).json()).data.operations;
  expect(operations.find(o=>o.type==='BONUS')).toMatchObject({amount:'0.000001',fee:'0.000002',net_amount:'-0.000001'});
});
