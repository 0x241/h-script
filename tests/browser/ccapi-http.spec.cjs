const {test,expect}=require('@playwright/test');
require('./capture-callbacks.cjs').captureCallbacks(test, 'ccapi_v3_native');
const fs=require('node:fs');
const crypto=require('node:crypto');
const artifact='/tmp/ccapi-http-original.json';
const secret='synthetic-ccapi-v3-key';
const sign=(payload)=>{
  const unsigned={...payload};delete unsigned.sign;
  const text=Object.keys(unsigned).sort().map(key=>Array.isArray(unsigned[key])?'Array':unsigned[key]===null?'':String(unsigned[key])).join(':')
    +':'+crypto.createHash('md5').update(secret).digest('hex');
  return {...unsigned,sign:crypto.createHash('sha1').update(text).digest('hex')};
};
const payload=(saved,confirmation=2,changes={})=>sign({'cryptocurrencyapi.net':3,chain:'bitcoin',currency:'BTC',type:'in',date:saved.date,
  from:null,to:'bc1q'+'q'.repeat(38),token:null,tokenContract:null,amount:'100.000000',fee:'0.00000001',
  txid:'a'.repeat(64),pos:0,confirmation,label:String(saved.id),...changes});
const send=(request,data,route='/balance/status')=>request.post(route,{data:JSON.stringify(data),headers:{'Content-Type':'application/json'}});
const rejected=async(request,data,status,route)=>{
  const response=await send(request,data,route);expect(response.status(),await response.text()).toBe(status);
  for(const privateField of [secret,'synthetic-private-provider-key','ccapi_']) expect(await response.text()).not.toContain(privateField);
};

test('invoice: REST deposit and browser pay save one protected native v3 invoice',async({page,request})=>{
  for(const path of ['/tests/fixtures/ccapi_http_prepend.php','/tests/fixtures/ccapi_v3_native.json','/tests/browser/ccapi-http.spec.cjs','/tests/ccapi_http_isolated.sh','/fixture/ccapi_http_prepend.php'])
    expect([403,404]).toContain((await request.get(path)).status());
  const response=await request.post('/api/v1/deposit',{headers:{Authorization:'Bearer hs_'+'1'.repeat(64),'Idempotency-Key':'ccapi-http-deposit'},
    data:{currency_id:5,amount:'100',memo:'Synthetic CCAPI v3 native invoice'}});
  expect(response.status(),await response.text()).toBe(201);
  const id=(await response.json()).data.operation.id;
  await page.goto('/login');const login=page.locator('form[name="login_frm"]');
  await login.locator('[name="Login"]').fill('financial-user');await login.locator('[name="Pass"]').fill('synthetic-financial-password');
  await login.locator('button[type="submit"]').click();await expect(page).toHaveURL(/\/cabinet/);
  const route='/operation?id='+id;await page.goto(route);
  await expect(page.locator('[data-hs-payment-watch]')).toHaveCount(0);
  await expect(page.locator('button[name="pay_btnpay"]')).toHaveText(/Перейти к оплате|Proceed to payment/);
  const form=page.locator('form#deposit-payment-form');await expect(form).toHaveCount(1);
  const pay=page.locator('button[name="pay_btnpay"]'),cancel=page.locator('button[name$="_btncancel"]');
  expect(await pay.evaluate(e=>e.form.id)).toBe('deposit-payment-form');
  expect(await cancel.evaluate(e=>e.form.id)).not.toBe('deposit-payment-form');
  const payBox=await pay.boundingBox(),cancelBox=await cancel.boundingBox();
  expect(payBox.x).toBeLessThan(cancelBox.x);expect(Math.abs(payBox.y-cancelBox.y)).toBeLessThan(5);
  await page.screenshot({path:'/tmp/ccapi-checkout.png',fullPage:true});
  const fields=await form.evaluate(el=>Object.fromEntries(new FormData(el)));fields.pay_btnpay='1';
  expect((await page.request.post(route,{form:{...fields,session_csrf:'0'.repeat(64)}})).status()).toBe(403);
  const posted=page.waitForResponse(response=>response.url().endsWith(route)&&response.request().method()==='POST');
  await page.locator('button[name="pay_btnpay"]').click();
  const first=await posted;
  await expect(page.locator('#native-payment-address')).toBeVisible();
  await expect(page.locator('#native-payment-address')).toHaveValue('bc1q'+'q'.repeat(38));
  const checkBox=await page.locator('[data-payment-refresh]').boundingBox(),cancelSize=await page.locator('button[name$="_btncancel"]').boundingBox();expect(checkBox.width).toBe(cancelSize.width);expect(checkBox.height).toBe(cancelSize.height);
  expect(await page.locator('.hs-native-payment').evaluate(e=>Boolean(e.compareDocumentPosition(document.querySelector('form:has(button[name$="_btncancel"])'))&Node.DOCUMENT_POSITION_FOLLOWING))).toBe(true);
  await page.locator('#native-payment-address').focus();
  expect(await page.locator('.hs-native-address-row').evaluate(e=>getComputedStyle(e).boxShadow)).not.toBe('none');
  expect(await page.locator('#native-payment-address').evaluate(e=>getComputedStyle(e).boxShadow)).toBe('none');
  await expect(page.locator('#native-payment-amount')).toHaveText('100.000000 BTC');
  await expect(page.locator('.hs-native-network')).toHaveText('Bitcoin');
  const qr=page.locator('.hs-native-qr img');await expect(qr).toBeVisible();
  expect(await qr.getAttribute('src')).toMatch(/^data:image\/svg\+xml;base64,/);
  expect(await qr.evaluate(e=>e.complete&&e.naturalWidth>0)).toBe(true);
  await page.locator('[data-copy-target="native-payment-address"]').click();
  expect(await page.locator('#native-payment-address').evaluate(e=>e.selectionEnd-e.selectionStart)).toBe(42);
  await page.screenshot({path:'/tmp/ccapi-details.png',fullPage:true});
  await page.setViewportSize({width:390,height:844});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  await expect(qr).toBeVisible();await page.setViewportSize({width:1440,height:1100});
  await expect(page.locator('form[name="pay_form"]')).toHaveCount(0);
  for(const pay of [first,await page.request.post(route,{form:fields})]){
    expect(pay.status(),await pay.text()).toBe(200);
    const html=await pay.text();expect(html).toContain('bc1q'+'q'.repeat(38));
    expect(html).not.toMatch(/<b>(?:Warning|Notice|Fatal error)<\/b>/);
    for(const privateField of ['inbound_intent','ccapi_',secret,'synthetic-private-provider-key','synthetic-public-provider-key']) expect(html).not.toContain(privateField);
  }
  await page.goto(route);
  await expect(page.locator('#native-payment-address')).toBeVisible();
  await expect(page.locator('#native-payment-address')).toHaveValue('bc1q'+'q'.repeat(38));
  await expect(page.locator('#native-payment-amount')).toHaveText('100.000000 BTC');
  fs.writeFileSync(artifact,JSON.stringify({id,date:Math.floor(Date.now()/1000)}),{mode:0o600});
});

test('settlement: native contract attacks, pending and twelve finals credit original net once',async({page,request,browser})=>{
  const saved=JSON.parse(fs.readFileSync(artifact,'utf8'));
  const statusRoute='/operation?id='+saved.id+'&payment_status=1';
  expect([302,403]).toContain((await request.get(statusRoute,{maxRedirects:0})).status());
  const loginAs=async(p,name)=>{
    await p.goto('/login');const f=p.locator('form[name="login_frm"]');await f.locator('[name="Login"]').fill(name);await f.locator('[name="Pass"]').fill('synthetic-financial-password');await f.locator('button[type="submit"]').click();await expect(p).toHaveURL(/\/cabinet/);
  };
  const peerContext=await browser.newContext(),peer=await peerContext.newPage();await loginAs(peer,'financial-peer');
  expect((await peer.request.get(statusRoute)).status()).toBe(404);await peerContext.close();
  await loginAs(page,'financial-user');
  expect((await page.request.post(statusRoute)).status()).toBe(405);
  expect((await page.request.get('/operation?id[]=1&payment_status=1')).status()).toBe(404);
  const waiting=await page.request.get(statusRoute);expect(waiting.status()).toBe(200);expect(await waiting.json()).toEqual({state:'waiting'});expect(waiting.headers()['cache-control']).toContain('no-store');
  expect((await page.request.get(statusRoute)).status()).toBe(429);
  await page.goto('/operation?id='+saved.id);await expect(page.locator('#native-payment-address')).toBeVisible();
  const invalidSignature=payload(saved);invalidSignature.sign='0'.repeat(40);await rejected(request,invalidSignature,401);
  await rejected(request,payload(saved,2,{to:'bc1q'+'p'.repeat(38)}),400);
  await rejected(request,payload(saved,2,{amount:'99.000000'}),400);
  // Token payloads route to token adapters; no such currency is configured here.
  await rejected(request,payload(saved,2,{token:'USDT'}),404);
  await rejected(request,payload(saved,2,{tokenContract:'not-native'}),404);
  for(const manual of [100,101]) await rejected(request,payload(saved,manual),422);
  for(const type of ['out','outTx','inFee','forward','track','swap']) await rejected(request,payload(saved,2,{type}),422);
  await rejected(request,payload(saved,2,{pos:-1}),400);
  await rejected(request,payload(saved,2,{amount:1.23}),400);
  const arrays=payload(saved);delete arrays.label;arrays.labels=[String(saved.id)];await rejected(request,sign(arrays),400);
  const extra=payload(saved,2,{sum:'900',oComis:'0'});await rejected(request,extra,400);
  const duplicate=JSON.stringify(payload(saved)).replace('"amount":"100.000000"','"amount":"100.000000","amount":"99.000000"');
  expect((await request.post('/balance/status',{data:duplicate,headers:{'Content-Type':'application/json'}})).status()).toBe(400);
  for(const confirmation of [0,1]){
    const pending=await send(request,payload(saved,confirmation));expect(pending.status(),await pending.text()).toBe(200);expect(await pending.text()).toBe('OK');
  }
  await expect(page.locator('[data-hs-payment-watch]')).toHaveAttribute('data-state','confirming',{timeout:20000});await expect(page.locator('[data-payment-confirmations]')).toContainText('1');
  const responses=await Promise.all(Array.from({length:12},()=>send(request,payload(saved))));
  expect(responses.map(response=>response.status())).toEqual(Array(12).fill(200));
  for(const response of responses) expect(await response.text()).toBe('OK');
  await rejected(request,payload(saved,2,{amount:'101.000000'}),409);
  await rejected(request,payload(saved,2,{pos:1}),400);
  await expect(page).toHaveURL(/\/cabinet/,{timeout:25000});
  await expect.poll(async()=>{const r=await page.request.get(statusRoute);return r.status()===200?(await r.json()).state:r.status();},{intervals:[5100],timeout:7000}).toBe('credited');
  const returned=await page.request.get('/operation?id='+saved.id+'&check',{maxRedirects:0});expect(returned.status()).toBe(302);expect(returned.headers().location).toContain('/cabinet');
});

test('restart: FAQ closes the previous item, including another category',async({page})=>{
  await page.goto('/faq');const items=page.locator('[data-hs-accordion] details').filter({hasText:'synthetic FAQ'});await expect(items).toHaveCount(3);
  await items.nth(0).locator('summary').click();await expect(items.nth(0)).toHaveAttribute('open','');
  await items.nth(1).locator('summary').click();await expect(items.nth(0)).not.toHaveAttribute('open','');await expect(items.nth(1)).toHaveAttribute('open','');
  await items.nth(2).locator('summary').click();await expect(items.nth(1)).not.toHaveAttribute('open','');await expect(items.nth(2)).toHaveAttribute('open','');
  await items.nth(2).locator('summary').click();await expect(page.locator('details[open]')).toHaveCount(0);
});

test('restart: exact v3 callback retry leaves the complete financial projection stable',async({request})=>{
  const saved=JSON.parse(fs.readFileSync(artifact,'utf8'));
  const exact=await send(request,payload(saved));expect(exact.status(),await exact.text()).toBe(200);expect(await exact.text()).toBe('OK');
  const query=await send(request,payload(saved),'/balance/status?its=CCAL&label=999999&amount=900');
  expect(query.status(),await query.text()).toBe(200);expect(await query.text()).toBe('OK');
  await rejected(request,payload(saved,2,{fee:'0.00000002'}),400);
  await rejected(request,payload(saved,2,{date:saved.date+1}),400);
  await rejected(request,payload(saved,2,{to:'bc1q'+'p'.repeat(38)}),400);
  await rejected(request,payload(saved,2,{pos:1}),400);
});
