const { test, expect } = require('@playwright/test');
require('./capture-callbacks.cjs').captureCallbacks(test, 'coinpayments_yoomoney_webmoney');
const crypto = require('node:crypto');
const fs = require('node:fs');
const token = index => 'hs_' + String(index).repeat(64);
const post = (request, route, key, data, user = 1) => request.post('/api/v1/' + route, {
  headers: { Authorization: 'Bearer ' + token(user), ...(key === null ? {} : { 'Idempotency-Key': key }) }, data,
});
const data = { currency_id: 2, amount: '25.00', memo: 'Synthetic HTTP test' };

test('REST retries, normalization, concurrent writes, conflicts and owner/route separation', async ({ request }) => {
  expect((await post(request, 'deposit', null, data)).status()).toBe(400);
  expect((await post(request, 'deposit', 'invalid key', data)).status()).toBe(400);
  const responses = await Promise.all(Array.from({ length: 12 }, () => post(request, 'deposit', 'http-deposit', data)));
  expect(responses.map(r => r.status()), await responses[0].text()).toEqual(Array(12).fill(201));
  const bodies = await Promise.all(responses.map(r => r.json()));
  for (const body of bodies) expect(body.data).toEqual(bodies[0].data);
  fs.writeFileSync('/tmp/financial-original.json',JSON.stringify(bodies[0].data),{mode:0o600});
  expect(new Set(bodies.map(b => b.data.operation.id)).size).toBe(1);
  expect(bodies[0].data.payment.checkout_url).toContain('/operation?id=');
  expect(JSON.stringify(bodies)).not.toContain('synthetic-destination');
  const equivalent = await post(request, 'deposit', 'http-deposit', { ...data, amount: 25 });
  expect(equivalent.status()).toBe(201);
  expect(equivalent.headers()['idempotency-replayed']).toBe('true');
  expect((await equivalent.json()).data).toEqual(bodies[0].data);
  expect((await post(request, 'deposit', 'http-deposit', { ...data, amount: '26' })).status()).toBe(409);
  const peer = await post(request, 'deposit', 'http-deposit', data, 2);
  expect(peer.status()).toBe(201);
  expect((await peer.json()).data.operation.id).not.toBe(bodies[0].data.operation.id);
  const withdrawals = await Promise.all(Array.from({ length: 12 }, () => post(request, 'withdraw', 'http-deposit', data)));
  expect(withdrawals.map(r => r.status())).toEqual(Array(12).fill(202));
  const payout = (await withdrawals[0].json()).data;
  for (const response of withdrawals) expect((await response.json()).data).toEqual(payout);
  expect(payout.operation.state).toBe('processing');
  expect((await post(request, 'withdraw', 'http-deposit', { ...data, memo: 'Changed' })).status()).toBe(409);
  expect((await post(request, 'deposit', 'http-bad', { ...data, amount: '1e99' })).status()).toBe(422);
  expect((await post(request, 'withdraw', 'http-too-large', { ...data, amount: '999999' })).status()).toBe(422);
});

test('signed callback retries before and after settlement acknowledge one effect', async ({ request }) => {
  const response = await post(request, 'deposit', 'http-callback-deposit', { ...data, amount: '12' });
  expect(response.status()).toBe(201);
  const id = (await response.json()).data.operation.id;
  const callback = { ipn_version: '1.0', ipn_id: 'synthetic-contract-ipn', ipn_mode: 'hmac', ipn_type: 'simple', merchant: 'fixture-merchant', invoice: String(id), txn_id: 'fixture-http-batch', amount1: '12.00', currency1: 'BTC', status: '100' };
  const body = new URLSearchParams(callback).toString();
  const hmac = crypto.createHmac('sha512', 'synthetic-callback-secret').update(body).digest('hex');
  const send = () => request.post('/balance/status?its=CP', { data: body, headers: { 'Content-Type': 'application/x-www-form-urlencoded', HMAC: hmac } });
  const signed = raw => request.post('/balance/status?its=CP', { data: raw, headers: { 'Content-Type': 'application/x-www-form-urlencoded', HMAC: crypto.createHmac('sha512', 'synthetic-callback-secret').update(raw).digest('hex') } });
  expect((await request.post('/balance/status?its=CP', { data: body, headers: { 'Content-Type': 'application/x-www-form-urlencoded', HMAC: '0'.repeat(128) } })).status()).toBe(401);
  expect((await signed(body + '&invoice=999')).status()).toBe(400);
  expect((await signed(new URLSearchParams({ ...callback, currency1: 'ETH' }).toString())).status()).toBe(400);
  expect((await signed(new URLSearchParams({ ...callback, amount1: '11.00' }).toString())).status()).toBe(400);
  expect((await signed(new URLSearchParams({ ...callback, amount1: '12.001' }).toString())).status()).toBe(400);
  const pending = await signed(new URLSearchParams({ ...callback, status: '0' }).toString());
  expect(pending.status()).toBe(200); expect(await pending.text()).toBe('IPN OK');
  const responses = await Promise.all(Array.from({ length: 12 }, send));
  expect(responses.map(r => r.status())).toEqual(Array(12).fill(200));
  for (const r of responses) expect(await r.text()).toBe('IPN OK');
  expect(await (await send()).text()).toBe('IPN OK');
  const changed = new URLSearchParams({ ...callback, amount1: '13.00' }).toString();
  expect((await request.post('/balance/status?its=CP', { data: changed, headers: { 'Content-Type': 'application/x-www-form-urlencoded', HMAC: crypto.createHmac('sha512', 'synthetic-callback-secret').update(changed).digest('hex') } })).status()).toBe(409);
});

test('YooMoney HMAC rejects tampering and settles signed gross amount once', async ({ page, request }) => {
  const deposit = await post(request, 'deposit', 'http-yoo-deposit', { currency_id: 3, amount: '50', memo: 'Synthetic YooMoney deposit' });
  expect(deposit.status()).toBe(201);
  const id = (await deposit.json()).data.operation.id;
  await originalCheckout(page,id);
  const callback = { notification_type: 'p2p-incoming', operation_id: 'fixture-http-yoo', amount: '49.75', withdraw_amount: '50.00', currency: '643', datetime: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'), sender: 'fixture-yoo-wallet', codepro: 'false', label: String(id), unaccepted: 'false', fixture_note: "  UTF-8 тест + & = !'()*  " };
  const encode = value => encodeURIComponent(value).replace(/[!'()*]/g, char => '%' + char.charCodeAt(0).toString(16).toUpperCase());
  const signedBody = fields => {
    const canonical = Object.keys(fields).sort().map(key => key + '=' + encode(fields[key])).join('&');
    return new URLSearchParams({ ...fields, sign: crypto.createHmac('sha256', 'synthetic-yoo-secret').update(canonical).digest('hex') }).toString();
  };
  const send = body => request.post('/balance/status', { data: body, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
  const body = signedBody(callback);
  expect((await send(body.replace('sign=', 'sign=0'))).status()).toBe(401);
  expect((await send(body + '&label=999')).status()).toBe(400);
  expect((await send(signedBody({ ...callback, currency: '840' }))).status()).toBe(400);
  expect((await send(signedBody({ ...callback, withdraw_amount: '49.00' }))).status()).toBe(400);
  expect((await send(signedBody({ ...callback, datetime: new Date(Date.now() - 172800000).toISOString().replace(/\.\d{3}Z$/, 'Z') }))).status()).toBe(400);
  expect((await send(signedBody({ ...callback, datetime: new Date(Date.now() + 600000).toISOString().replace(/\.\d{3}Z$/, 'Z') }))).status()).toBe(400);
  expect((await send(signedBody({ ...callback, test_notification: 'true' }))).status()).toBe(200);
  const responses = await Promise.all(Array.from({ length: 12 }, () => send(body)));
  expect(responses.map(response => response.status())).toEqual(Array(12).fill(200));
  for (const response of responses) expect(await response.text()).toBe('');
  expect((await send(body)).status()).toBe(200);
  expect((await send(signedBody({ ...callback, withdraw_amount: '51.00' }))).status()).toBe(409);
});

test('WebMoney preflight checks order and HASH2 concurrent callbacks settle one effect', async ({ page, request }) => {
  expect((await request.get('/tests/fixtures/webmoney_callbacks.json')).status()).toBe(403);
  const deposit=await post(request,'deposit','http-webmoney-deposit',{currency_id:4,amount:'25',memo:'Synthetic WebMoney deposit'});
  expect(deposit.status()).toBe(201);
  const id=(await deposit.json()).data.operation.id;
  await originalCheckout(page,id);
  const pre={LMI_PREREQUEST:'1',LMI_PAYEE_PURSE:'Z123456789012',LMI_PAYMENT_AMOUNT:'25.00',LMI_PAYMENT_NO:String(id),LMI_MODE:'0',LMI_PAYMENT_DESC:'Synthetic WebMoney deposit'};
  const send=fields=>request.post('/balance/status',{data:new URLSearchParams(fields).toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'}});
  const validPre=await send(pre); expect(validPre.status()).toBe(200); expect(await validPre.text()).toBe('YES');
  expect((await send({...pre,LMI_PAYMENT_AMOUNT:'24.00'})).status()).toBe(400);
  expect((await send({...pre,LMI_PAYEE_PURSE:'Z111111111111'})).status()).toBe(400);
  // An unknown order cannot select a currency or its callback credentials.
  const unknownOrder=await send({...pre,LMI_PAYMENT_NO:'999999'});
  expect(unknownOrder.status()).toBe(404);
  expect(await unknownOrder.text()).toBe('Callback unavailable');
  const wrongDescription=await send({...pre,LMI_PAYMENT_DESC:'another invoice'});
  expect(wrongDescription.status()).toBe(400); expect(await wrongDescription.text()).not.toBe('YES');
  const callback={LMI_PAYEE_PURSE:pre.LMI_PAYEE_PURSE,LMI_PAYMENT_AMOUNT:pre.LMI_PAYMENT_AMOUNT,LMI_PAYMENT_NO:pre.LMI_PAYMENT_NO,
    LMI_MODE:'0',LMI_SYS_INVS_NO:'120',LMI_SYS_TRANS_NO:'45678',LMI_SYS_TRANS_DATE:new Date().toISOString().slice(0,19).replaceAll('-','').replace('T',' '),
    LMI_PAYER_PURSE:'Z987654321012',LMI_PAYER_WM:'123456789012',LMI_PAYMENT_DESC:pre.LMI_PAYMENT_DESC};
  const signed=(fields,legacy=false)=>{
    const parts=[fields.LMI_PAYEE_PURSE,fields.LMI_PAYMENT_AMOUNT];
    if(fields.LMI_HOLD!==undefined) parts.push(fields.LMI_HOLD);
    parts.push(fields.LMI_PAYMENT_NO,fields.LMI_MODE,fields.LMI_SYS_INVS_NO,fields.LMI_SYS_TRANS_NO,fields.LMI_SYS_TRANS_DATE,
      'synthetic-webmoney-secret',fields.LMI_PAYER_PURSE,fields.LMI_PAYER_WM);
    return {...fields,[legacy?'LMI_HASH':'LMI_HASH2']:crypto.createHash('sha256').update(parts.join(legacy?'':';')).digest('hex').toUpperCase()};
  };
  expect((await send(signed(callback,true))).status()).toBe(422);
  expect((await send(signed({...callback,LMI_HOLD:'1'}))).status()).toBe(422);
  expect((await send(signed({...callback,LMI_MODE:'1'}))).status()).toBe(200);
  expect((await send({...signed(callback),LMI_HASH2:'0'.repeat(64)})).status()).toBe(401);
  expect((await send({...signed(callback),LMI_PAYMENT_DESC:'another invoice'})).status()).toBe(400);
  const body=new URLSearchParams(signed(callback)).toString();
  expect((await request.post('/balance/status',{data:body+'&LMI_PAYMENT_NO=999',headers:{'Content-Type':'application/x-www-form-urlencoded'}})).status()).toBe(400);
  const responses=await Promise.all(Array.from({length:12},()=>send(signed(callback))));
  expect(responses.map(r=>r.status())).toEqual(Array(12).fill(200));
  for(const response of responses) expect(await response.text()).toBe('');
  expect((await send({...signed(callback),LMI_PAYMENT_DESC:'another invoice'})).status()).toBe(400);
  expect((await send(pre)).status()).toBe(400);
  fs.writeFileSync('/tmp/financial-webmoney-original.json',JSON.stringify(signed(callback)),{mode:0o600});
});

test('browser transfer and deposit forms preserve CSRF and replay the original redirect', async ({ page }) => {
  await page.goto('/login');
  const login = page.locator('form[name="login_frm"]');
  await login.locator('[name="Login"]').fill('financial-user');
  await login.locator('[name="Pass"]').fill('synthetic-financial-password');
  await login.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(/\/cabinet/);
  await page.goto('/operation?add=TR');
  const form = page.locator('form:has(input[name="Oper"])');
  const fields = await form.evaluate(el => Object.fromEntries(new FormData(el)));
  fields.PSys='2'; fields.Sum='10'; fields.Login2='financial-peer'; fields.Memo='Synthetic browser transfer';
  fields[await form.locator('button[type="submit"]').last().getAttribute('name')]='1';
  expect((await page.request.post('/operation?add=TR', { form: { ...fields, session_csrf: '0'.repeat(64) }, maxRedirects: 0 })).status()).toBe(403);
  const first = await page.request.post('/operation?add=TR', { form: fields, maxRedirects: 0 });
  expect(first.status()).toBe(302);
  const retry = await page.request.post('/operation?add=TR', { form: fields, maxRedirects: 0 });
  expect(retry.status()).toBe(302); expect(retry.headers().location).toBe(first.headers().location);
  expect((await page.request.post('/operation?add=TR', { form: { ...fields, Sum: '11' }, maxRedirects: 0 })).status()).toBe(409);
  await page.goto(first.headers().location);
  const confirm = page.locator('form:has(input[name="oID"])').first();
  const confirmed = await confirm.evaluate(el=>Object.fromEntries(new FormData(el)));
  confirmed[await confirm.locator('button[name$="_btn"]').getAttribute('name')]='1';
  const confirmation = await page.request.post(first.headers().location, { form: confirmed, maxRedirects: 0 });
  expect(confirmation.status()).toBe(302);
  const repeated = await page.request.post(first.headers().location, { form: confirmed, maxRedirects: 0 });
  expect(repeated.status()).toBe(302); expect(repeated.headers().location).toBe(confirmation.headers().location);
});

const loginAs = async (page, name) => {
  await page.context().clearCookies(); await page.goto('/login');
  const form=page.locator('form[name="login_frm"]');
  await form.locator('[name="Login"]').fill(name);
  await form.locator('[name="Pass"]').fill('synthetic-financial-password');
  await form.locator('button[type="submit"]').click();
};
const originalCheckout = async (page,id) => {
  await loginAs(page,'financial-user');
  const url='/operation?id='+id;
  await page.goto(url);
  const pay=page.locator('form#deposit-payment-form');
  await expect(pay).toHaveCount(1);
  const fields=await pay.evaluate(el=>Object.fromEntries(new FormData(el))); fields.pay_btnpay='1';
  const posted=await page.request.post(url,{form:fields});
  expect(posted.status(),await posted.text()).toBe(200);
  const html=await posted.text();
  expect(html).toContain('name="pay_form"');
  for(const privateField of ['inbound_profile','profile_key_hash','profile_timezone','synthetic-yoo-secret','synthetic-webmoney-secret'])
    expect(html).not.toContain(privateField);
  expect((await page.request.post(url,{form:fields})).status()).toBe(200);
};
const formPayload = async (form, button = 'button[name$="_btn"]') => {
  const fields=await form.evaluate(el=>Object.fromEntries(new FormData(el)));
  fields[await form.locator(button).getAttribute('name')]='1'; return fields;
};
const repeatForm = async (page, url, fields) => {
  const first=await page.request.post(url,{form:fields,maxRedirects:0});
  expect(first.status(), (await first.text()).slice(0,500)).toBe(302);
  const second=await page.request.post(url,{form:fields,maxRedirects:0});
  expect(second.status()).toBe(302); expect(second.headers().location).toBe(first.headers().location);
  return first.headers().location;
};

test('owner deposit creation, top-up and partial withdrawal have one effect per form', async ({ page }) => {
  await loginAs(page,'financial-user');
  await page.goto('/deposit?add=DEPO');
  const form=page.locator('form:has(input[name="Sum"])');
  const fields=await formPayload(form); fields.PSys='2'; fields.Sum='50'; fields.Plan='1'; fields.Compnd='0';
  const redirect=await repeatForm(page,'/deposit?add=DEPO',fields);
  fs.writeFileSync('/tmp/financial-deposit-original.json',JSON.stringify({fields,route:'/deposit?add=DEPO',location:redirect}),{mode:0o600});
  await page.goto(redirect);
  const modify=page.locator('form:has(input[name="dID"])');
  const add=await formPayload(modify,'button[name$="_btnadd"]'); add.Sum='10';
  await repeatForm(page,redirect,add);
  await page.goto(redirect);
  const sub=await formPayload(page.locator('form:has(input[name="dID"])'),'button[name$="_btnsub"]'); sub.Sum='5';
  await repeatForm(page,redirect,sub);
  expect((await page.request.post(redirect,{form:{...sub,Sum:'6'},maxRedirects:0})).status()).toBe(409);
  await page.context().storageState({path:'/tmp/financial-deposit-session.json'});
});

test('administrator bonus and deposit submissions replay with role/CSRF protection', async ({ page }) => {
  await loginAs(page,'financial-admin');
  await page.goto('/admin/oper?add=BONUS&user=financial-peer');
  const form=page.locator('form:has(input[name="Oper"])');
  const fields=await formPayload(form); fields.PSys='2'; fields.Sum='3'; fields.Memo='Synthetic admin bonus';
  const redirect=await repeatForm(page,'/admin/oper?add=BONUS&user=financial-peer',fields);
  await page.goto(redirect);
  const completed=await formPayload(page.locator('form:has(input[name="oID"])').first());
  await repeatForm(page,redirect,completed);
  await page.goto('/admin/depo?add&user=financial-peer');
  const create=await formPayload(page.locator('form:has(input[name="Sum"])')); create.PSys='2'; create.Sum='20'; create.Plan='1'; create.Compnd='0';
  const deposit=await repeatForm(page,'/admin/depo?add&user=financial-peer',create);
  await page.goto(deposit);
  const close=await formPayload(page.locator('form:has(input[name="dID"])'),'button[name$="_btncls"]');
  await repeatForm(page,deposit,close);
});

test('administrator bulk confirmation is atomic and repeated selection conflicts', async ({ page }) => {
  await loginAs(page,'financial-admin');
  const response=await post(page.request,'deposit','http-deposit',data);
  const id=(await response.json()).data.operation.id;
  await page.goto('/admin/opers');
  const form=page.locator('form:has(input[name="ids[]"])');
  const fields=await formPayload(form,'button[name$="_btnconfirm"]'); fields['ids[]']=String(id);
  expect((await page.request.post('/admin/opers',{form:{...fields,session_csrf:'0'.repeat(64)},maxRedirects:0})).status()).toBe(403);
  await repeatForm(page,'/admin/opers',fields);
  expect((await page.request.post('/admin/opers',{form:{...fields,'ids[]':'999999'},maxRedirects:0})).status()).toBe(409);
});

test('administrator manual accrual repeats its original boundary exactly once', async ({ page }) => {
  await loginAs(page,'financial-admin');
  await page.goto('/admin/charge');
  const form=page.locator('form:has(input[name="ids[]"])');
  const fields=await formPayload(form,'button[name$="_btndel"]'); fields['ids[]']='2'; fields['p[2]']='2';
  expect(Number(fields.financial_boundary)).toBeGreaterThan(0);
  const redirect=await repeatForm(page,'/admin/charge',fields);
  expect(redirect).toContain('done=1');
  expect((await page.request.post('/admin/charge',{form:{...fields,'p[2]':'3'},maxRedirects:0})).status()).toBe(409);
});

test('browser cashin records a submission for retry after configuration changes', async ({ page }) => {
  await loginAs(page,'financial-user');
  await page.goto('/operation?add=CASHIN');
  const form=page.locator('form:has(input[name="Oper"])');
  const fields=await formPayload(form); fields.PSys='2'; fields.Sum='30'; fields.Memo='Synthetic browser cashin';
  const route='/operation?add=CASHIN';
  const location=await repeatForm(page,route,fields);
  fs.writeFileSync('/tmp/financial-browser-original.json',JSON.stringify({fields,route,location}),{mode:0o600});
  await page.context().storageState({path:'/tmp/financial-browser-session.json'});
});

test('decimal commission and net previews retain strings and refuse malformed amounts', async ({ page }) => {
  await loginAs(page,'financial-user');
  const commission=await page.request.get('/ajax?module=balance&do=getcommission&Oper=TR&PSys=2&Sum=10.01');
  expect(commission.status()).toBe(200);
  expect(await commission.text()).toContain('>0.00</span>');
  const preview=await page.request.get('/ajax?module=balance&do=getsum&oper=TR&cid=2&sum=10.01');
  expect(preview.status()).toBe(200);
  const body=await preview.json();
  expect(body[2]).toContain('<b>10.01</b>');
  expect(body[1]).toBe('-');
  const exchange=await page.request.get('/ajax?module=balance&do=getsum&oper=EX&cid=2&cid2=3&sum=10.01');
  expect(exchange.status()).toBe(200);
  expect((await exchange.json())[2]).toContain('<b>10.01</b>');
  expect((await page.request.get('/ajax?module=balance&do=getcommission&Oper=TR&PSys=2&Sum=NaN')).status()).toBe(422);
  const overflow=await post(page.request,'deposit','decimal-storage-overflow',{...data,amount:'9999999.9999995'});
  expect(overflow.status()).toBe(422);
  expect((await overflow.json()).error.code).toBe('sum_overflow');
});

test('restart replays original REST/browser results after settlement and configuration changes', async ({ request, browser }) => {
  const webmoney=JSON.parse(fs.readFileSync('/tmp/financial-webmoney-original.json','utf8'));
  const callback=await request.post('/balance/status',{data:new URLSearchParams(webmoney).toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'}});
  expect(callback.status()).toBe(200); expect(await callback.text()).toBe('');
  const original=JSON.parse(fs.readFileSync('/tmp/financial-original.json','utf8'));
  const response=await post(request,'deposit','http-deposit',data);
  expect(response.status()).toBe(201);
  expect(response.headers()['idempotency-replayed']).toBe('true');
  expect((await response.json()).data).toEqual(original);
  expect(original.operation.state).toBe('awaiting_confirmation');
  expect((await post(request,'deposit','http-deposit',{...data,amount:'26'})).status()).toBe(409);
  const withdrawal=await post(request,'withdraw','http-deposit',data);
  expect(withdrawal.status()).toBe(202);
  expect(withdrawal.headers()['idempotency-replayed']).toBe('true');
  expect((await withdrawal.json()).data.operation.state).toBe('processing');
  const previous=JSON.parse(fs.readFileSync('/tmp/financial-browser-original.json','utf8'));
  const context=await browser.newContext({baseURL:'http://fixture.invalid',storageState:'/tmp/financial-browser-session.json'});
  const repeated=await context.request.post(previous.route,{form:previous.fields,maxRedirects:0});
  expect(repeated.status(),(await repeated.text()).slice(0,500)).toBe(302);
  expect(repeated.headers().location).toBe(previous.location);
  expect((await context.request.post(previous.route,{form:{...previous.fields,Sum:'31'},maxRedirects:0})).status()).toBe(409);
  const preview=await context.request.get('/ajax?module=balance&do=getsum&oper=CASHIN&cid=2&sum=10');
  expect(preview.status()).toBe(200);
  expect((await preview.json())[2]).toContain('<b>10.00</b>');
  const commission=await context.request.get('/ajax?module=balance&do=getcommission&Oper=CASHIN&PSys=2&Sum=10');
  expect(commission.status()).toBe(200);
  expect(await commission.text()).toContain('>0.00</span>');
  await context.close();
  const deposit=JSON.parse(fs.readFileSync('/tmp/financial-deposit-original.json','utf8'));
  const depositContext=await browser.newContext({baseURL:'http://fixture.invalid',storageState:'/tmp/financial-deposit-session.json'});
  const replay=await depositContext.request.post(deposit.route,{form:deposit.fields,maxRedirects:0});
  expect(replay.status(),(await replay.text()).slice(0,500)).toBe(302);
  expect(replay.headers().location).toBe(deposit.location);
  await depositContext.close();
});
