const { test, expect } = require('@playwright/test');
const crypto = require('node:crypto');
require('./capture-callbacks.cjs').captureCallbacks(test, 'capability_policy');
const gateways = ['EA','EAT','EAT1','BSC','BSCT','XRPA','TRA','TRAT','ACR','PKAU','PKAR'];
// Removed adapters and the old Volet RUR shape no longer have a detector.
// Retained PayKassa fiat shapes are detected, but have no supported order route.
const unknownCallbacks = new Set(['EA','EAT','EAT1','BSC','BSCT','XRPA','TRA','TRAT','ACR']);
const send = (request, fields, headers = {}, route = '/balance/status') => request.post(route, {
  data: new URLSearchParams(fields).toString(), headers: {'Content-Type':'application/x-www-form-urlencoded', ...headers},
});

test('retired and disabled callbacks reject old signed shapes; stale automation flags cannot create money', async ({ request }) => {
  for (const [index, id] of gateways.entries()) {
    let fields;
    if (id.startsWith('PK')) fields={private_hash:'synthetic-token',system:'advcash',currency:id==='PKAU'?'USD':'RUB',order_id:'42'};
    else if (id==='ACR') fields={ac_transfer:'synthetic-transfer',ac_merchant_currency:'RUR'};
    else {
      const marker=id.startsWith('EA')?'etherapi.net':id.startsWith('BSC')?'bnbapi.net':id.startsWith('TRA')?'tronapi.net':'xrpapi.net';
      fields={[marker]:'1',type:'in',date:String(Math.floor(Date.now()/1000)),from:'synthetic-source',to:'synthetic-destination',
        amount:'100',txid:'synthetic-transaction',confirmations:'12',tag:'42',label:'42',fee:'0.00',
        token:['EAT','BSCT','TRAT'].includes(id)?'USDT':id==='EAT1'?'DAI':''};
      const parts=['type','date','from','to'];
      if(id!=='BSC' && id!=='XRPA') parts.push('token');
      if(id==='XRPA') parts.push('tag');
      parts.push('amount');
      if(id==='XRPA') parts.push('fee');
      parts.push('txid',...(id==='XRPA'?['label']:['confirmations','tag']));
      fields.sign=crypto.createHash('sha1').update([...parts.map(field=>fields[field]),'synthetic-policy-key'].join(':')).digest('hex');
    }
    // JSON preserves legacy dotted provider markers; PHP form decoding changes
    // dots to underscores before the route's provider detection.
    const response=!['ACR','PKAU','PKAR'].includes(id)
      ? await request.post('/balance/status',{data:JSON.stringify(fields),headers:{'Content-Type':'application/json'}})
      : await send(request,fields);
    expect(response.status(),id+' callback').toBe(400);
    expect(await response.text()).toBe(unknownCallbacks.has(id) ? 'Unknown callback' : 'Invalid transaction');
    for (const action of ['deposit','withdraw']) {
      const failed=await request.post('/api/v1/'+action,{headers:{Authorization:'Bearer hs_'+'1'.repeat(64),'Idempotency-Key':'policy-'+id+'-'+action},
        data:{currency_id:10+index,amount:'25',memo:'Synthetic unsupported dispatch'}});
      expect(failed.status(),id+' '+action+' '+await failed.text()).toBe(422);
    }
  }
});

test('signed long transaction IDs and unknown IPN versions are refused before claim', async ({ request }) => {
  const fields={ipn_version:'1.0',ipn_id:'synthetic-policy-ipn',ipn_mode:'hmac',ipn_type:'simple',merchant:'fixture-merchant',
    invoice:'42',txn_id:'a'.repeat(40),amount1:'100.00',currency1:'BTC',status:'100'};
  for (const changes of [{},{txn_id:'a'.repeat(128)},{txn_id:'synthetic-policy-cp',ipn_version:'2.0'}]) {
    const body=new URLSearchParams({...fields,...changes}).toString();
    const response=await request.post('/balance/status?its=CP',{data:body,headers:{'Content-Type':'application/x-www-form-urlencoded',
      HMAC:crypto.createHmac('sha512','synthetic-callback-secret').update(body).digest('hex')}});
    expect(response.status()).toBe(422);
    expect(await response.text()).not.toContain('SQL');
  }
});

test('unconfigured WebMoney timezone cannot reply YES or settle; fixtures are inaccessible', async ({ request }) => {
  const fields={LMI_PREREQUEST:'1',LMI_PAYEE_PURSE:'Z123456789012',LMI_PAYMENT_AMOUNT:'100.00',LMI_PAYMENT_NO:'43',LMI_MODE:'0',LMI_PAYMENT_DESC:'Synthetic policy original order'};
  const response=await send(request,fields);
  expect(response.status()).toBe(422);
  expect(await response.text()).not.toBe('YES');
  for (const path of ['/tests/fixtures/phase3_callback_cases.json','/tests/fixtures/gateway_contract_profiles.json'])
    expect((await request.get(path)).status()).toBe(403);
  for (const method of ['get','post']) expect((await request[method]('/tests/gateway_callback_policy.php')).status()).toBe(403);
});
