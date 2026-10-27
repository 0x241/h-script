const {test,expect}=require('@playwright/test');
require('./capture-callbacks.cjs').captureCallbacks(test, 'volet_usd_sci');
const fs=require('node:fs');
const crypto=require('node:crypto');
const artifact='/tmp/volet-http-original.json';
const token='hs_'+'1'.repeat(64);
const fields=(id,date,change={})=>({ac_transfer:'235f9d0b-b48f-462c-9949-'+String(id).padStart(12,'0'),ac_start_date:date,
  ac_sci_name:'Synthetic Store',ac_src_wallet:'USD123456789012',ac_dest_wallet:'USD210987654321',ac_order_id:String(id),
  ac_amount:'1.23',ac_merchant_currency:'USD',ac_transaction_status:'COMPLETED',ac_fee:'0.00',ac_merchant_amount:'1.23',
  ac_buyer_email:'buyer@fixture.invalid',...change});
const body=(saved,kind='good',change={})=>{
  const data=fields(saved.id,saved.date,{fixture_case:kind,...change});
  const signed=['ac_transfer','ac_start_date','ac_sci_name','ac_src_wallet','ac_dest_wallet','ac_order_id','ac_amount','ac_merchant_currency'];
  data.ac_hash=crypto.createHash('sha256').update(signed.map(key=>data[key]).join(':')+':synthetic-volet-sci-secret').digest('hex');
  return new URLSearchParams(data).toString();
};
const send=(request,raw,route='/balance/status')=>request.post(route,{data:raw,headers:{'Content-Type':'application/x-www-form-urlencoded'}});
const reject=async(request,raw,status,route)=>{
  const response=await send(request,raw,route); const text=await response.text();
  expect(response.status(),text).toBe(status);
  for(const privateValue of ['synthetic-volet-sci-secret','synthetic-volet-api-secret','synthetic-private-provider-message','volet_proof_hash','USD210987654321','merchant@fixture.invalid']) expect(text).not.toContain(privateValue);
};
const mutations={merchant:400,receiver:400,source:400,amount:400,fee:422,income:422,asset:400,direction:422,type:422,sci:422,api:422,
  order:400,transaction:400,pending:422,refunded:422,transaction_refunded:422,created:400,tls:503,timeout:503,http500:503,malformed:503,oversized:503,xxe:503,namespace:503};

test('preconfirmation: actual deposit/pay POST saves protected full USD intent and rejects callbacks',async({page,request})=>{
  for(const url of ['/tests/fixtures/volet_http_prepend.php','/tests/fixtures/volet_sci.json','/tests/browser/volet-http.spec.cjs','/tests/volet_http_isolated.sh','/fixture/volet_http_prepend.php'])
    expect([403,404]).toContain((await request.get(url)).status());
  const deposit=await request.post('/api/v1/deposit',{headers:{Authorization:'Bearer '+token,'Idempotency-Key':'volet-http-deposit'},data:{currency_id:5,amount:'1.23',memo:'Synthetic Volet HTTP invoice'}});
  expect(deposit.status(),await deposit.text()).toBe(201);
  const data=(await deposit.json()).data; const id=data.operation.id;
  const date=new Date(data.operation.created_at).toISOString().slice(0,19).replace('T',' ');
  const saved={id,date,data,operation:'/operation?id='+id};
  const repeated=await request.post('/api/v1/deposit',{headers:{Authorization:'Bearer '+token,'Idempotency-Key':'volet-http-deposit'},data:{currency_id:5,amount:'1.230000',memo:'Synthetic Volet HTTP invoice'}});
  expect(repeated.status()).toBe(201); expect((await repeated.json()).data).toEqual(data);
  let providerNavigations=0;
  await page.context().route('**/*',async route=>new URL(route.request().url()).hostname==='fixture.invalid'?route.continue():route.abort());
  await page.context().route('https://account.volet.com/**',async route=>{providerNavigations++;await route.fulfill({status:200,contentType:'text/html',body:'<p>Isolated Volet checkout intercepted</p>'});});
  await page.goto('/login'); const login=page.locator('form[name="login_frm"]');
  await login.locator('[name="Login"]').fill('financial-user'); await login.locator('[name="Pass"]').fill('synthetic-financial-password');
  await login.locator('button[type="submit"]').click(); await expect(page).toHaveURL(/\/cabinet/);
  await page.goto(saved.operation); const pay=page.locator('form#deposit-payment-form');
  await expect(pay).toHaveCount(1);
  saved.fields=await pay.evaluate(el=>Object.fromEntries(new FormData(el))); saved.fields.pay_btnpay='1';
  expect(saved.fields.session_csrf).toBeTruthy(); expect(saved.fields.financial_key).toBeTruthy();
  expect((await page.request.post(saved.operation,{form:{...saved.fields,session_csrf:'0'.repeat(64)}})).status()).toBe(403);
  const posted=page.waitForResponse(r=>r.url().endsWith(saved.operation)&&r.request().method()==='POST');
  await page.locator('button[name="pay_btnpay"]').click(); const response=await posted;
  expect(response.status(),await response.text()).toBe(200); const html=await response.text();
  expect(html).not.toMatch(/<b>(?:Warning|Notice|Fatal error)<\/b>/);
  await expect.poll(()=>providerNavigations).toBe(1);
  // Request interception alone does not mean the checkout document is ready.
  await expect(page).toHaveURL('https://account.volet.com/api/public/payment-gateway');
  await expect(page.getByText('Isolated Volet checkout intercepted',{exact:true})).toBeVisible();
  const form=await page.evaluate(html=>{
    const doc=new DOMParser().parseFromString(html,'text/html'); const form=doc.querySelector('form[name="pay_form"]');
    return form?{method:form.method,action:form.action,fields:Object.fromEntries(new FormData(form))}:null;
  },html);
  expect(form.method).toBe('post'); expect(form.action).toBe('https://account.volet.com/api/public/payment-gateway');
  expect(form.fields).toMatchObject({ac_account_email:'merchant@fixture.invalid',ac_sci_name:'Synthetic Store',ac_amount:'1.23',ac_currency:'USD',ac_order_id:String(id),ac_ps:'VOLET',ac_status_url_method:'POST'});
  expect(form.fields.ac_sign).toBe(crypto.createHash('sha256').update(`merchant@fixture.invalid:Synthetic Store:1.23:USD:synthetic-volet-sci-secret:${id}`).digest('hex'));
  for(const privateValue of ['volet_profile','volet_api_secret','volet_api_name','volet_wallet','volet_scale','synthetic-volet-api-secret','synthetic-volet-sci-secret','USD210987654321']) expect(html).not.toContain(privateValue);
  const retry=await page.request.post(saved.operation,{form:saved.fields}); expect(retry.status()).toBe(200);
  expect(await retry.text()).toContain('name="ac_amount" value="1.23"');
  for(const [kind,status] of Object.entries(mutations)) await reject(request,body(saved,kind),status);
  await reject(request,body(saved)+'&ac_order_id=999999',400);
  await reject(request,body(saved)+'&ac_hash=duplicate',400);
  await reject(request,body(saved).replace('ac_hash=','ac_hash%5B%5D='),400);
  await reject(request,body(saved).replace('ac_hash=','ac_hash=%GG'),400);
  await reject(request,body(saved).replace(/ac_hash=[a-f0-9]+/,'ac_hash='+'0'.repeat(64)),401);
  await reject(request,body(saved,'good',{ac_dest_wallet:'USD999999999999'}),400);
  await reject(request,body(saved,'good',{ac_amount:'1.234'}),400);
  await reject(request,JSON.stringify(fields(saved.id,saved.date)),400);
  await reject(request,'x'.repeat(65537),413);
  fs.writeFileSync(artifact,JSON.stringify(saved),{mode:0o600});
});

test('settlement: twelve concurrent two-lookup callbacks commit one exact credit',async({request})=>{
  const saved=JSON.parse(fs.readFileSync(artifact,'utf8'));
  const responses=await Promise.all(Array.from({length:12},()=>send(request,body(saved))));
  expect(responses.map(r=>r.status()),await responses[0].text()).toEqual(Array(12).fill(200));
  for(const response of responses) expect(await response.text()).toBe('');
  for(const change of [{ac_transaction_status:'REFUNDED'},{ac_fee:'999.99'},{ac_merchant_amount:'0.01'},{ac_buyer_email:'other@fixture.invalid'},{ac_amount:'1.23000000'}]) {
    const repeated=await send(request,body(saved,'good',change)); expect(repeated.status()).toBe(200); expect(await repeated.text()).toBe('');
  }
  for(const [kind,status] of Object.entries(mutations)) await reject(request,body(saved,kind),status);
  await reject(request,body(saved,'good',{ac_src_wallet:'USD999999999999'}),400);
});

test('restart: exact replay and API refund gates retain one financial result',async({request})=>{
  const saved=JSON.parse(fs.readFileSync(artifact,'utf8'));
  const exact=await send(request,body(saved)); expect(exact.status()).toBe(200); expect(await exact.text()).toBe('');
  const queryOverride=await send(request,body(saved),'/balance/status?ac_order_id=999999&ac_amount=999&ac_transaction_status=COMPLETED');
  expect(queryOverride.status()).toBe(200); expect(await queryOverride.text()).toBe('');
  for(const [kind,status] of Object.entries({merchant:400,receiver:400,amount:400,fee:422,pending:422,refunded:422,transaction_refunded:422,tls:503,malformed:503,xxe:503}))
    await reject(request,body(saved,kind),status);
});
