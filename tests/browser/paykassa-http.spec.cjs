const { test, expect } = require('@playwright/test');
require('./capture-callbacks.cjs').captureCallbacks(test, 'paykassa_sci_order');
const fs = require('node:fs');
const artifact='/tmp/paykassa-http-original.json';
const token='hs_'+'1'.repeat(64);
const body=(id,kind='good',change={})=>new URLSearchParams({private_hash:`fixture-${id}-${kind}`,system:'BitCoin',currency:'BTC',order_id:String(id),...change}).toString();
const send=(request,raw,route='/balance/status')=>request.post(route,{data:raw,headers:{'Content-Type':'application/x-www-form-urlencoded'}});
const reject=async(request,raw,status,route)=>{
  const response=await send(request,raw,route);
  expect(response.status(),await response.text()).toBe(status);
  const text=await response.text();
  for(const secret of ['synthetic-paykassa-sci-secret','synthetic-private-provider-message','private_hash','paykassa_','bc1qsynthetic']) expect(text).not.toContain(secret);
  expect(text).not.toContain('|success');
};
const mutationCases=()=>JSON.parse(fs.readFileSync('/tmp/paykassa-http-cases.json','utf8'));
const mutations={merchant:401,receiver:400,hash:400,tag:400,asset:400,amount:400,float:400,partial:422,order:400,invoice:400,
  remoteerror:401,tls:503,timeout:503,http500:503,malformed:503,oversized:503};

test('preconfirmation: actual deposit/pay POST saves one protected invoice and rejects invalid callbacks',async({page,request})=>{
  for(const url of ['/tests/fixtures/paykassa_http_prepend.php','/tests/fixtures/paykassa_order.json','/tests/browser/paykassa-http.spec.cjs','/tests/paykassa_http_isolated.sh','/fixture/paykassa_http_prepend.php'])
    expect([403,404]).toContain((await request.get(url)).status());
  const deposit=await request.post('/api/v1/deposit',{headers:{Authorization:'Bearer '+token,'Idempotency-Key':'paykassa-http-deposit'},data:{currency_id:5,amount:'1.23',memo:'Synthetic Paykassa HTTP invoice'}});
  expect(deposit.status(),await deposit.text()).toBe(201);
  const data=(await deposit.json()).data;
  const id=data.operation.id;
  const repeated=await request.post('/api/v1/deposit',{headers:{Authorization:'Bearer '+token,'Idempotency-Key':'paykassa-http-deposit'},data:{currency_id:5,amount:'1.230000',memo:'Synthetic Paykassa HTTP invoice'}});
  expect(repeated.status()).toBe(201); expect((await repeated.json()).data).toEqual(data);
  const context=page.context();
  let providerNavigations=0;
  await context.route('**/*',async route=>new URL(route.request().url()).hostname==='fixture.invalid'?route.continue():route.abort());
  await context.route('https://crypto.paykassa.pro/**',async route=>{providerNavigations++;await route.fulfill({status:200,contentType:'text/html',body:'<p>Isolated payment form intercepted</p>'});});
  await page.goto('/login');
  const login=page.locator('form[name="login_frm"]');
  await login.locator('[name="Login"]').fill('financial-user'); await login.locator('[name="Pass"]').fill('synthetic-financial-password');
  await login.locator('button[type="submit"]').click(); await expect(page).toHaveURL(/\/cabinet/);
  const operation='/operation?id='+id;
  await page.goto(operation);
  const pay=page.locator('form#deposit-payment-form');
  await expect(pay).toHaveCount(1);
  const fields=await pay.evaluate(el=>Object.fromEntries(new FormData(el))); fields.pay_btnpay='1';
  expect(fields.session_csrf).toBeTruthy(); expect(fields.financial_key).toBeTruthy();
  expect((await page.request.post(operation,{form:{...fields,session_csrf:'0'.repeat(64)}})).status()).toBe(403);
  const posted=page.waitForResponse(r=>r.url().endsWith(operation)&&r.request().method()==='POST');
  await page.locator('button[name="pay_btnpay"]').click();
  const response=await posted; expect(response.status(),await response.text()).toBe(200);
  const html=await response.text();
  expect(html).not.toMatch(/<b>(?:Warning|Notice|Fatal error)<\/b>/);
  await expect.poll(()=>providerNavigations).toBe(1);
  // A routed request is observed before its navigation commits. Wait for the
  // intercepted checkout document before using its JavaScript context.
  await expect(page).toHaveURL(`https://crypto.paykassa.pro/sci/index.php?hash=fixture_paykassa_${id}`);
  await expect(page.getByText('Isolated payment form intercepted',{exact:true})).toBeVisible();
  const formData=await page.evaluate(html=>{
    const document=new DOMParser().parseFromString(html,'text/html');const form=document.querySelector('form[name="pay_form"]');
    return form?{method:form.method,action:form.action,fields:Object.fromEntries(new FormData(form))}:null;
  },html);
  expect(formData).toEqual({method:'get',action:`https://crypto.paykassa.pro/sci/index.php?hash=fixture_paykassa_${id}`,fields:{hash:`fixture_paykassa_${id}`}});
  for(const privateField of ['paykassa_merchant_id','paykassa_invoice','paykassa_address','paykassa_hash','psysorder','synthetic-paykassa-sci-secret']) expect(html).not.toContain(privateField);
  const retry=await page.request.post(operation,{form:fields}); expect(retry.status()).toBe(200);
  expect(await retry.text()).toContain(`name="hash" value="fixture_paykassa_${id}"`);
  for(const [kind,status]of Object.entries(mutations)) await reject(request,body(mutationCases()[kind],kind),status);
  await reject(request,body(id)+'&private_hash=duplicate',400);
  await reject(request,body(id)+'&order_id=999999',400);
  await reject(request,body(id,'good',{currency:'ETH'}),400);
  // A well-formed unknown order has no original currency/credential route.
  await reject(request,body(id,'good',{order_id:'999999'}),404);
  await reject(request,body(mutationCases().unknown,'good',{private_hash:'unknown'}),503);
  await reject(request,body(id).replace('private_hash=', 'private_hash%5B%5D='),400,'/balance/status?its=PKB');
  await reject(request,body(id).replace('private_hash=', 'private_hash=%GG'),400);
  await reject(request,JSON.stringify({private_hash:'fixture-'+id+'-good',currency:'BTC',system:'BitCoin',order_id:String(id)}),400,'/balance/status?its=PKB');
  await reject(request,'x'.repeat(65537),413);
  fs.writeFileSync(artifact,JSON.stringify({id,body:body(id),operation,fields,data}),{mode:0o600});
});

test('settlement: twelve concurrent callbacks and exact replay credit one invoice',async({request})=>{
  const saved=JSON.parse(fs.readFileSync(artifact,'utf8'));
  const exactEventually=async()=>{
    for(let attempt=0;attempt<60;attempt++) {
      const response=await send(request,saved.body);
      if(response.status()!==503) return response;
      await new Promise(resolve=>setTimeout(resolve,100));
    }
    throw new Error('Original token did not converge after concurrent verification');
  };
  const responses=await Promise.all(Array.from({length:12},exactEventually));
  expect(responses.map(r=>r.status()),await responses[0].text()).toEqual(Array(12).fill(200));
  for(const response of responses) expect(await response.text()).toBe(saved.id+'|success');
  expect(await(await send(request,saved.body)).text()).toBe(saved.id+'|success');
  // Only the exact authenticated token may use the durable cache. Unsigned
  // route/order changes still fail locally, and a different token gets no ACK.
  await reject(request,body(saved.id,'forged'),503);
  await reject(request,body(saved.id,'good',{currency:'ETH'}),400);
  await reject(request,body(saved.id,'good',{system:'Ethereum'}),400);
  await reject(request,body(saved.id,'good',{order_id:'999999'}),404);
  await reject(request,saved.body+'&private_hash=duplicate',400);
});

test('restart: installed callback identity replays and rejects changed immutable fields',async({request})=>{
  const saved=JSON.parse(fs.readFileSync(artifact,'utf8'));
  const exact=await send(request,saved.body); expect(exact.status()).toBe(200);expect(await exact.text()).toBe(saved.id+'|success');
  const queryOverride=await send(request,saved.body,'/balance/status?private_hash=unknown&order_id=999999&its=PKB');
  expect(queryOverride.status()).toBe(200);expect(await queryOverride.text()).toBe(saved.id+'|success');
  await reject(request,body(saved.id,'forged'),503);
  await reject(request,body(saved.id,'good',{currency:'ETH'}),400);
  await reject(request,body(saved.id,'good',{order_id:'999999'}),404);
  await reject(request,saved.body+'&private_hash=duplicate',400);
});
