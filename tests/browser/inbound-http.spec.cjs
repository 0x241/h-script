const {test,expect}=require('@playwright/test');
require('./capture-callbacks.cjs').captureCallbacks(test, 'original_inbound_commission');
const fs=require('node:fs');
const crypto=require('node:crypto');
const artifact='/tmp/inbound-http-original.json';
const signed=(saved,status='100',changes={})=>{
  const body=new URLSearchParams({merchant:'fixture-inbound-merchant',ipn_version:'1.0',ipn_id:'synthetic-contract-ipn',ipn_mode:'hmac',ipn_type:'simple',invoice:String(saved.id),
    txn_id:'fixture-inbound-http-batch',amount1:'100.00',currency1:'ETH',status,...changes}).toString();
  return {data:body,headers:{'Content-Type':'application/x-www-form-urlencoded',HMAC:crypto.createHmac('sha512','synthetic-inbound-secret').update(body).digest('hex')}};
};
const send=(request,saved,status,changes)=>request.post('/balance/status?its=CPE',signed(saved,status,changes));

test('invoice: actual REST deposit and browser pay POST freeze original fee before policy changes',async({page,request})=>{
  for(const url of ['/tests/fixtures/inbound_http_setup.php','/tests/financial_inbound_intent.php','/tests/browser/inbound-http.spec.cjs','/tests/inbound_http_isolated.sh'])
    expect([403,404]).toContain((await request.get(url)).status());
  const response=await request.post('/api/v1/deposit',{headers:{Authorization:'Bearer hs_'+'1'.repeat(64),'Idempotency-Key':'inbound-http-deposit'},
    data:{currency_id:5,amount:'100',memo:'Synthetic original fee invoice'}});
  expect(response.status(),await response.text()).toBe(201);
  const id=(await response.json()).data.operation.id;
  await page.goto('/login');const login=page.locator('form[name="login_frm"]');
  await login.locator('[name="Login"]').fill('financial-user');await login.locator('[name="Pass"]').fill('synthetic-financial-password');
  await login.locator('button[type="submit"]').click();await expect(page).toHaveURL(/\/cabinet/);
  const route='/operation?id='+id;await page.goto(route);
  const form=page.locator('form#deposit-payment-form');await expect(form).toHaveCount(1);
  const fields=await form.evaluate(el=>Object.fromEntries(new FormData(el)));fields.pay_btnpay='1';
  expect((await page.request.post(route,{form:{...fields,session_csrf:'0'.repeat(64)}})).status()).toBe(403);
  for(let i=0;i<2;i++){
    const pay=await page.request.post(route,{form:fields});expect(pay.status(),await pay.text()).toBe(200);
    const html=await pay.text();expect(html).toContain('https://legacy.coinpayments.net/index.php');
    /* Provider wire precision is eight, independent of SQL/local scale. */
    expect(html).toMatch(/name="amountf"[^>]+value="100\.00000000"/);expect(html).toContain('fixture-inbound-merchant');
    expect(html).not.toContain('inbound_intent');expect(html).not.toContain('inbound_profile');expect(html).not.toContain('synthetic-inbound-secret');
  }
  fs.writeFileSync(artifact,JSON.stringify({id}),{mode:0o600});
});
test('settlement: changed fee policy, signed pending and 12 concurrent finals preserve original net',async({request})=>{
  const saved=JSON.parse(fs.readFileSync(artifact,'utf8'));
  expect((await send(request,saved,'100',{amount1:'99.00'})).status()).toBe(400);
  const pending=await send(request,saved,'0');expect(pending.status(),await pending.text()).toBe(200);expect(await pending.text()).toBe('IPN OK');
  const responses=await Promise.all(Array.from({length:12},()=>send(request,saved)));
  expect(responses.map(r=>r.status())).toEqual(Array(12).fill(200));for(const response of responses)expect(await response.text()).toBe('IPN OK');
  expect((await send(request,saved,'100',{amount1:'101.00'})).status()).toBe(409);
});
test('restart: exact signed retry retains original committed fee and credit',async({request})=>{
  const saved=JSON.parse(fs.readFileSync(artifact,'utf8'));const response=await send(request,saved);
  expect(response.status(),await response.text()).toBe(200);expect(await response.text()).toBe('IPN OK');
});
