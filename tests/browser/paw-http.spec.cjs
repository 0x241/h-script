const { test, expect } = require('@playwright/test');
require('./capture-callbacks.cjs').captureCallbacks(test, 'paw_provider');
const fs = require('node:fs');
const artifact='/tmp/paw-http-public.json';
const key='sk_sandbox_HTTP_KEY_CANARY_first';
const login=async(page,name='paw-review-90')=>{
  await page.context().clearCookies();
  await page.context().route('**/*',route=>new URL(route.request().url()).hostname==='fixture.invalid'?route.continue():route.abort());
  await page.goto('/login'); const f=page.locator('form[name="login_frm"]');
  await f.locator('[name="Login"]').fill(name); await f.locator('[name="Pass"]').fill('synthetic-financial-password');
  await f.locator('button[type="submit"]').click(); await expect(page).toHaveURL(/\/cabinet/);
};
const fields=async(form,button)=>new URLSearchParams([...(await form.evaluate(e=>[...new FormData(e).entries()])),[button,'1']]);
const post=(request,url,body)=>request.post(url,{data:body.toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'},maxRedirects:0});
const noSecrets=html=>{for(const secret of ['HTTP_KEY_CANARY','POST_SECRET_CANARY','ppcEncryptedKey']) expect(html).not.toContain(secret);};
const saveForm=page=>page.locator('form:has(input[name="api_key"])');
const manageForm=page=>page.locator('form:has(button[name="paw_profile_manage_btnremove"])');


// Exercise the ordinary authenticated CSRF language endpoint, including pages
// without editorial {!!} markers. Only the synthetic review user's locale changes.
const language = async(page,lang)=>{
  const url=page.url();const f=page.locator('form').filter({has:page.locator('input[name="lang"][value="'+lang+'"]')}).filter({has:page.locator('input[name="session_csrf"]')}).first();
  await expect(f).toHaveCount(1);const action=await f.getAttribute('action');
  const body=new URLSearchParams(await f.evaluate(e=>[...new FormData(e).entries()]));body.set('url',url);
  const r=await post(page.request,action,body);expect(r.status(),await r.text()).toBe(302);await page.goto(r.headers().location);
};
const configurationSnapshot=page=>page.locator('.hs-provider input,.hs-provider select').evaluateAll(es=>es.filter(e=>/^(?:credentials\[|currencies\[|assets\[|activation_scope\[|token_currencies\[|profile_id$|revision$|currency_id$|account_fields\[)/.test(e.name)).map(e=>[e.name,e.type,e.value,e.checked,e.disabled,e.readOnly]).sort((a,b)=>JSON.stringify(a).localeCompare(JSON.stringify(b))));

test('creation: standard Add dropdown connects one shared PAW profile and two fiat currencies',async({page,request})=>{
  for(const url of ['/tests/fixtures/paw_http_prepend.php','/tests/browser/paw-http.spec.cjs','/tests/paw_http_isolated.sh','/fixture/paw_http_prepend.php','/module/balance/admin/curr.paw.php'])
    expect([403,404]).toContain((await request.get(url)).status());
  const anon=await request.get('/admin/curr?provider=PAW',{maxRedirects:0}); expect([302,403]).toContain(anon.status());
  for(const name of ['financial-user','paw-review-89']) {
    await login(page,name);
    for(const url of ['/admin/curr?add','/admin/curr?provider=PAW']) {
      expect([302,403]).toContain((await page.request.get(url,{maxRedirects:0})).status());
      expect([302,403]).toContain((await page.request.post(url,{form:{paw_profile_save_btn:'1',api_key:key},maxRedirects:0})).status());
    }
  }
  await login(page); await page.goto('/admin/curr?add');
  const add=page.locator('form:has(select[name="PSys"])');
  await expect(add.locator('option[value="PAW"]')).toHaveText('PAW Payments');
  await add.locator('select[name="PSys"]').selectOption('PAW');
  const button=await add.locator('button[type="submit"]').getAttribute('name');
  const a=await fields(add,button); const forged=new URLSearchParams(a); forged.set('__Cert','0'.repeat(64));
  expect((await post(page.request,'/admin/curr?add',forged)).status()).toBe(403);
  const selected=await post(page.request,'/admin/curr?add',a); expect(selected.status()).toBe(302);
  expect(selected.headers().location).toContain('/admin/curr?provider=PAW');
  await page.goto('/admin/curr?provider=PAW&tab=settings');
  await expect(page.locator('form[name="paw_currency_save"]')).toHaveCount(0);
  await expect(page.locator('input[name="api_key"]')).toHaveCount(1);
  expect((await page.request.post('/admin/curr?provider=PAW&tab=settings',{form:{paw_currency_save_btn:'1',cID:'0',profile_id:'0',revision:'0'},maxRedirects:0})).status()).toBe(403);
  await page.goto('/admin/curr?provider=PAW');
  await expect(page.getByRole('heading',{name:'PAW Payments',exact:true})).toHaveCount(1);
  await expect(saveForm(page).locator('[name="api_key"]')).toHaveValue('');
  await saveForm(page).locator('[name="api_key"]').fill(key);
  await saveForm(page).locator('[name="fiats[]"][value="USD"]').check();
  await saveForm(page).locator('[name="fiats[]"][value="EUR"]').check();
  await saveForm(page).locator('input[type="checkbox"][name="enabled"]').check();
  const s=await fields(saveForm(page),'paw_profile_save_btn');
  const created=await post(page.request,'/admin/curr?provider=PAW',s); expect(created.status(),await created.text()).toBe(302);
  expect(created.headers().location).toContain('/admin/curr?provider=PAW&profile_id=');
  expect((await post(page.request,'/admin/curr?provider=PAW',s)).status()).toBe(403);
  await page.goto(created.headers().location); await expect(saveForm(page).locator('[name="api_key"]')).toHaveValue('');
  noSecrets(await page.content()); await expect(page.locator('.hs-provider-overview')).toHaveCount(0);
  const cooldown=await post(page.request,page.url(),await fields(saveForm(page),'paw_profile_save_btn'));
  expect(cooldown.status()).toBe(200); expect(await cooldown.text()).toContain('через 30 секунд'); noSecrets(await cooldown.text());
  await page.goto('/admin/currs');
  const usdUrl=new URL(await page.getByRole('link',{name:'PAW Payments USD',exact:true}).getAttribute('href'),'http://fixture.invalid/');
  const usd=usdUrl.pathname+usdUrl.search;
  const eurUrl=new URL(await page.getByRole('link',{name:'PAW Payments EUR',exact:true}).getAttribute('href'),'http://fixture.invalid/');
  const eur=eurUrl.pathname+eurUrl.search;
  expect(usd).toMatch(/\/admin\/curr\?id=\d+/); expect(eur).toMatch(/\/admin\/curr\?id=\d+/);
  expect(await page.content()).not.toContain('/admin/paw');
  fs.writeFileSync(artifact,JSON.stringify({usd,eur,connection:created.headers().location}));
});


test('creation: PAW connection translations switch RU EN RU with unchanged selected currencies',async({page})=>{
  await login(page);await page.goto('/admin/curr?provider=PAW');const before=await configurationSnapshot(page);const ru=await page.locator('.hs-provider').innerText();expect(ru).toMatch(/[А-Яа-яЁё]/);
  await language(page,'en');await expect(page.locator('html')).toHaveAttribute('lang','en');expect(await page.locator('.hs-provider').innerText()).not.toMatch(/[А-Яа-яЁё]|gateway\.(?:admin|field|error)\./);expect(await configurationSnapshot(page)).toEqual(before);await expect(page.locator('input[name="api_key"]')).toHaveValue('');
  await language(page,'ru');expect(await page.locator('.hs-provider').innerText()).toBe(ru);expect(await configurationSnapshot(page)).toEqual(before);
});

test('management: ordinary currency id keeps shared key blank and preserves history on rotation, disable and retire',async({page})=>{
  const ids=JSON.parse(fs.readFileSync(artifact,'utf8')); await login(page); await page.goto(ids.connection);
  await expect(page.getByRole('heading',{name:'PAW Payments',exact:true})).toHaveCount(1);
  noSecrets(await page.content()); await expect(saveForm(page).locator('[name="api_key"]')).toHaveValue('');
  const s=await fields(saveForm(page),'paw_profile_save_btn'), m=await fields(manageForm(page),'paw_profile_manage_btndisable');
  for(const cert of ['', '0'.repeat(64),m.get('__Cert')]) {
    const forged=new URLSearchParams(s); forged.set('__Cert',cert); forged.set('api_key','sk_sandbox_POST_SECRET_CANARY');
    const r=await post(page.request,ids.connection,forged); expect(r.status()).toBe(403); noSecrets(await r.text());
  }
  for(const url of [ids.usd+'&testapi',ids.usd+'&tab=info',ids.usd+'&paw_profile_manage_btnremove=1&profile_id=1&revision=1']) {
    const r=await page.request.get(url,{maxRedirects:0}); expect(r.status()).toBe(200); noSecrets(await r.text());
    if(url.includes('tab=info')) expect(await r.text()).not.toContain('Регистрация PAW Payments и кешбек H-Script');
  }
  await page.goto(ids.usd);
  const currency=()=>page.locator('form[name="paw_currency_save"]');
  await expect(currency().locator('select[name="cCASHINMode"]')).toBeEnabled();
  await expect(currency().locator('select[name="cCASHOUTMode"]')).toBeDisabled();
  await expect(currency().locator('select[name="cCASHOUTMode"] option')).toHaveCount(1);
  await expect(currency().locator('select[name="cCASHINMode"] option')).toHaveText(['отключено','через PAW']);
  await expect(currency().getByText('Отключить эту валюту',{exact:true})).toHaveCount(2);
  await expect(currency().getByText('Скрыть валюту от пользователей',{exact:true})).toHaveCount(2);
  await expect(currency().locator('input[name="cName"]')).toHaveValue('PAW Payments USD');
  await expect(page.getByRole('link',{name:'← Подключение PAW Payments',exact:true})).toHaveAttribute('href',/provider=PAW/);
  await expect(saveForm(page)).toHaveCount(0);
  await expect(page.locator('.hs-provider-scope')).toContainText('только валютой');
  await page.screenshot({path:'/tmp/paw-currency-settings.png',fullPage:true});
  const settings=await fields(currency(),'paw_currency_save_btn');
  const badCert=new URLSearchParams(settings);badCert.set('__Cert',s.get('__Cert'));
  expect((await post(page.request,ids.usd+'&tab=settings',badCert)).status()).toBe(403);
  const forgedMode=new URLSearchParams(settings);forgedMode.set('cCASHINMode','3');
  const refused=await post(page.request,ids.usd+'&tab=settings',forgedMode);expect(refused.status()).toBe(200);expect(await refused.text()).toContain('Проверьте поля формы');
  await page.goto(ids.usd+'&tab=settings');
  const swapped=await fields(currency(),'paw_currency_save_btn');
  const refusedBinding=await post(page.request,ids.eur+'&tab=settings',swapped);expect(refusedBinding.status()).toBe(200);expect(await refusedBinding.text()).toContain('Проверьте поля формы');
  await page.goto(ids.usd+'&tab=settings');
  const changes=await fields(currency(),'paw_currency_save_btn');changes.set('cCASHINMin','2.345678');changes.set('cTRComis','0.01');changes.set('cCurr','$');
  const savedSettings=await post(page.request,ids.usd+'&tab=settings',changes);expect(savedSettings.status(),await savedSettings.text()).toBe(302);
  expect((await post(page.request,ids.usd+'&tab=settings',changes)).status()).toBe(403);
  await page.goto(savedSettings.headers().location);
  await expect(currency().locator('[name="cCASHINMin"]')).toHaveValue('2.345678');
  await expect(currency().locator('[name="cTRComis"]')).toHaveValue('0.01');
  await expect(currency().locator('[name="cCurr"]')).toHaveValue('$');
  const staleSettings=await fields(currency(),'paw_currency_save_btn');staleSettings.set('revision','1');
  const settingsConflict=await post(page.request,ids.usd+'&tab=settings',staleSettings);expect(await settingsConflict.text()).toContain('Настройки уже изменились');
  await page.goto('/admin/curr?id=2');
  const ordinary=page.locator('form:has(input[name="cID"])');
  const ordinaryButton=await ordinary.locator('button[name$="_btn"]').first().getAttribute('name');
  const tampered=await fields(ordinary,ordinaryButton); tampered.set('cID',new URL('http://fixture.invalid'+ids.usd).searchParams.get('id'));
  tampered.set('cCID','PAW'); tampered.set('cDisabled','0'); tampered.set('cCASHINMode','2');
  const denied=await post(page.request,'/admin/curr?id=2',tampered); expect([200,302]).toContain(denied.status()); noSecrets(await denied.text());
  await page.goto(ids.connection); await saveForm(page).locator('[name="api_key"]').fill('sk_sandbox_HTTP_KEY_CANARY_rotated');
  const rotated=await post(page.request,ids.connection,await fields(saveForm(page),'paw_profile_save_btn'));
  expect(rotated.status(),await rotated.text()).toBe(302); expect(rotated.headers().location).toContain('/admin/curr?provider=PAW');
  await page.goto(ids.connection); await expect(saveForm(page).locator('[name="api_key"]')).toHaveValue('');
  expect(await manageForm(page).locator('[name="revision"]').inputValue()).toBe('3');
  const disable=await fields(manageForm(page),'paw_profile_manage_btndisable');
  expect((await post(page.request,ids.connection,disable)).status()).toBe(302);
  expect((await post(page.request,ids.connection,disable)).status()).toBe(403);
  await page.goto(ids.connection); const stale=await fields(manageForm(page),'paw_profile_manage_btndisable'); stale.set('revision','2');
  const conflict=await post(page.request,ids.connection,stale); expect(conflict.status()).toBe(200); expect(await conflict.text()).toContain('Настройки уже изменились');
  const callback=await page.request.post('/balance/status?its=PAW',{data:'{"order_id":"untrusted","status":"success","fiat_amount":25}',headers:{'Content-Type':'application/json'}});
  expect(callback.status()).toBe(400);
  const deposit=await page.request.post('/api/v1/deposit',{headers:{Authorization:'Bearer hs_'+'1'.repeat(64),'Idempotency-Key':'paw-common-disabled'},data:{currency_id:Number(new URL('http://fixture.invalid'+ids.usd).searchParams.get('id')),amount:'25',memo:'Synthetic'}});
  expect(deposit.status()).toBe(422);
  await page.goto(ids.connection);
  const retired=await post(page.request,ids.connection,await fields(manageForm(page),'paw_profile_manage_btnremove'));
  expect(retired.status()).toBe(200);expect(await retired.text()).toMatch(/незавершённые операции|unfinished operations/i);noSecrets(await retired.text());
});

test('retirement: closed pending operation permits archival with original history',async({page})=>{
  const ids=JSON.parse(fs.readFileSync(artifact,'utf8'));await login(page);await page.goto(ids.connection);
  const retired=await post(page.request,ids.connection,await fields(manageForm(page),'paw_profile_manage_btnremove'));
  expect(retired.status(),await retired.text()).toBe(302);expect(retired.headers().location).toContain('/admin/currs');
  await page.goto(ids.usd);expect(await page.content()).toContain('Это архивная валюта');noSecrets(await page.content());
});

test('restart: retained currency ids show retired profile without API or financial effects',async({page})=>{
  const ids=JSON.parse(fs.readFileSync(artifact,'utf8')); await login(page);
  for(const url of [ids.usd,ids.eur,ids.usd+'&testapi',ids.eur+'&tab=info']) {
    const r=await page.request.get(url,{maxRedirects:0}); expect(r.status()).toBe(200); noSecrets(await r.text());
  }
  await page.goto('/admin/curr?add'); await expect(page.locator('option[value="PAW"]')).toHaveText('PAW Payments');
  await page.goto('/admin/curr?provider=PAW'); await expect(saveForm(page).locator('[name="api_key"]')).toHaveValue('');
});

test('schema: source before migration returns safe 503 for common setup GET and POST',async({page})=>{
  const ids=JSON.parse(fs.readFileSync(artifact,'utf8')); await login(page);
  for(const url of ['/admin/curr?provider=PAW','/admin/curr?provider=PAW&tab=settings',ids.usd,ids.eur+'&tab=info']) {
    const responses=[await page.request.get(url,{maxRedirects:0}),await page.request.post(url,{form:{paw_profile_save_btn:'1',api_key:'sk_sandbox_POST_SECRET_CANARY',profile_id:'0',revision:'0','fiats[]':'USD',enabled:'1'},maxRedirects:0})];
    for(const r of responses) {
      expect(r.status()).toBe(503); expect(r.headers()['cache-control']).toContain('no-store'); expect(r.headers()['retry-after']).toBe('60');
      const html=await r.text(); noSecrets(html); expect(html).toContain('резервной копией'); expect(html).not.toContain('name="api_key"');
      expect(html).not.toContain('paw_profile_save_btn'); expect(html).not.toContain('paw_profile_manage_btn');
    }
  }
});

const nativeArtifact='/tmp/paw-native-http-public.json';
const crypto=require('node:crypto');
const nativeSigned=(payload,changes={})=>{
  const raw=JSON.stringify(payload);
  return {data:raw,headers:{'Content-Type':'application/json','X-Paw-Signature':crypto.createHmac('sha256',key).update(raw).digest('hex'),'X-Paw-Sandbox':'true',...changes}};
};

test('native: two fiat invoices verify raw HMAC and settle actual VARY money once',async({page,request})=>{
  await login(page);await page.goto('/admin/curr?provider=PAW');
  await saveForm(page).locator('[name="api_key"]').fill(key);
  for(const code of ['USD','EUR'])await saveForm(page).locator('[name="fiats[]"][value="'+code+'"]').check();
  await saveForm(page).locator('input[type="checkbox"][name="enabled"]').check();
  const saved=await post(page.request,page.url(),await fields(saveForm(page),'paw_profile_save_btn'));expect(saved.status(),await saved.text()).toBe(302);
  await page.goto(saved.headers().location);
  const urls={};
  for(const code of ['USD','EUR'])urls[code]=new URL(await page.getByRole('link',{name:'Настроить '+code,exact:true}).getAttribute('href'),'http://fixture.invalid/');
  for(const code of ['USD','EUR']){
    await page.goto(urls[code].href+'&tab=settings');const f=page.locator('form[name="paw_currency_save"]');
    await f.locator('[name="cDisabled"]:not([type="hidden"])').uncheck();await f.locator('select[name="cCASHINMode"]').selectOption('2');
    if(code==='USD')await f.locator('[name="cCASHINComisMin"]').fill('2.50');
    const r=await post(page.request,page.url(),await fields(f,'paw_currency_save_btn'));expect(r.status(),await r.text()).toBe(302);
  }
  const auth={Authorization:'Bearer hs_'+'1'.repeat(64)};
  const user=(await (await request.get('/api/v1/user',{headers:auth})).json()).data;
  const facts=[];await login(page,'financial-user');
  for(const code of ['USD','EUR']){
    const cid=Number(urls[code].searchParams.get('id'));
    const r=await request.post('/api/v1/deposit',{headers:{...auth,'Idempotency-Key':'paw-native-'+code},data:{currency_id:cid,amount:'25',memo:'Synthetic PAW '+code}});expect(r.status(),await r.text()).toBe(201);
    const id=(await r.json()).data.operation.id;await page.goto('/operation?id='+id);
    const pay=await post(page.request,page.url(),await fields(page.locator('#deposit-payment-form'),'pay_btnpay'));expect(pay.status(),await pay.text()).toBe(200);
    expect(await pay.text()).not.toContain('data-hs-auto-submit="1"');noSecrets(await pay.text());
    const fixture=JSON.parse(Buffer.from(pay.headers()['x-fixture-paw-invoice'],'base64').toString());
    expect(fixture.invoice.metadata.operation).toBe(String(id));expect(fixture.invoice.metadata.owner).toBe(String(user.id));expect(fixture.invoice.metadata.currency).toBe(String(cid));
    const invoice={...fixture.invoice,initial_fiat_amount:'25.00',fiat_amount:code==='USD'?'12.34':'31.25',status:code==='USD'?'partially_paid':'paid_over',processed_at:String(Math.floor(Date.now()/1000))};
    facts.push({id,cid,callback:fixture.callback,invoice});
    const bad=nativeSigned(invoice);bad.headers['X-Paw-Signature']='0'.repeat(64);expect((await request.post(fixture.callback,bad)).status()).toBe(401);
    const tampered=nativeSigned(invoice);tampered.data+=' ';expect((await request.post(fixture.callback,tampered)).status()).toBe(401);
    expect((await request.post(fixture.callback,nativeSigned(invoice,{'X-Paw-Sandbox':'false'}))).status()).toBe(400);
    const wrong={...invoice,fiat_currency:code==='USD'?'EUR':'USD'};expect((await request.post(fixture.callback,nativeSigned(wrong))).status()).toBe(400);
    for(const state of ['processing','expired','cancelled','failed','refunded'])expect((await request.post(fixture.callback,nativeSigned({...invoice,status:state}))).status()).toBe(200);
    const paid=await Promise.all([request.post(fixture.callback,nativeSigned(invoice)),request.post(fixture.callback,nativeSigned(invoice))]);for(const p of paid){expect(p.status(),await p.text()).toBe(200);expect(await p.text()).toBe('OK');}
    expect([400,409]).toContain((await request.post(fixture.callback,nativeSigned({...invoice,fiat_amount:'99.99'}))).status());
    await page.goto('/operation?id='+id+'&check');await expect(page).toHaveURL(/\/cabinet/,{timeout:15000});
  }
  // A response timeout never triggers another provider invoice automatically.
  const timeout=await request.post('/api/v1/deposit',{headers:{...auth,'Idempotency-Key':'paw-native-timeout'},data:{currency_id:facts[0].cid,amount:'25',memo:'Synthetic PAW timeout'}});expect(timeout.status()).toBe(201);const timeoutId=(await timeout.json()).data.operation.id;
  await page.goto('/operation?id='+timeoutId);const timeoutFields=await fields(page.locator('#deposit-payment-form'),'pay_btnpay');
  const failed=await page.request.post(page.url(),{data:timeoutFields.toString(),headers:{'Content-Type':'application/x-www-form-urlencoded','X-Fixture-Paw-Fail':'1'},maxRedirects:0});expect(failed.status()).toBe(503);expect(await failed.text()).toContain('did not confirm invoice creation');
  await page.goto('/operation?id='+timeoutId);const retry=await post(page.request,page.url(),await fields(page.locator('#deposit-payment-form'),'pay_btnpay'));expect(retry.status()).toBe(503);
  fs.writeFileSync(nativeArtifact,JSON.stringify({facts,timeoutId}),{mode:0o600});
});

test('native-restart: exact paid proof survives real app restart without another credit',async({request})=>{
  const {facts}=JSON.parse(fs.readFileSync(nativeArtifact,'utf8'));
  for(const fact of facts){const response=await request.post(fact.callback,nativeSigned(fact.invoice));expect(response.status(),await response.text()).toBe(200);expect(await response.text()).toBe('OK');}
});

test('content: localized FAQ categories, removal and language links without editorial markers',async({page})=>{
  await login(page);
  const label='{!ru!}Платежи{!en!}Payments{!!}';
  const configure=async(value)=>{
    await page.goto('/admin/faq/setup');
    const f=page.locator('form:has(textarea[name="_Cats"])');
    const button=await f.locator('button[name$="_btn"]').first().getAttribute('name');
    const values=await fields(f,button);values.set('_Cats',value);values.set('ShowCount','10');values.set('InBlock','3');
    const saved=await post(page.request,page.url(),values);expect(saved.status(),await saved.text()).toBe(302);
  };
  await configure('Payments\nRemoved');
  await page.goto('/admin/faq?add');
  const f=page.locator('form:has([name="fQuestion"])');
  await expect(f.locator('select[name="fCat"] option[value="Payments"]')).toHaveText('Payments');
  const button=await f.locator('button[name$="_btn"]').first().getAttribute('name');
  const values=await fields(f,button);values.set('fQuestion','FAQ markerless question');values.set('fAnswer','FAQ markerless answer');values.set('fCat','Payments');
  const created=await post(page.request,page.url(),values);expect(created.status(),await created.text()).toBe(302);
  await configure(label+'\nRemoved');
  await page.goto('/en/faq/');
  await expect(page.locator('[data-public-language="ru"]')).toHaveCount(1);
  await expect(page.locator('[data-public-language="en"]')).toHaveCount(1);
  await expect(page.getByRole('heading',{name:'Payments',exact:true})).toHaveCount(1);
  await page.goto('/ru/faq/');
  await expect(page.getByRole('heading',{name:'Платежи',exact:true})).toHaveCount(1);
  await expect(page.getByText('FAQ markerless question',{exact:true})).toHaveCount(1);
  await configure('');
  await page.goto('/en/faq/');
  await expect(page.getByRole('heading',{name:'Payments',exact:true})).toHaveCount(0);
  await expect(page.getByRole('heading',{name:'General questions',exact:true})).toHaveCount(1);
  await expect(page.getByText('FAQ markerless question',{exact:true})).toHaveCount(1);
});


test('wallets-rates: authenticated owner recipients, PIN, locks and exact admin rate save',async({page})=>{
  await login(page,'financial-user');await page.goto('/wallets');
  let f=page.locator('form:has(input[name="PIN"])');await expect(f).toHaveCount(1);
  const names=await f.locator('input[name$="[usdt_tron]"]:not([type="hidden"])').evaluateAll(es=>es.map(e=>e.name));
  expect(names.length).toBe(2);expect(new Set(names).size).toBe(2);
  const native=JSON.parse(fs.readFileSync(nativeArtifact,'utf8'));
  const usd='currency_'+native.facts.find(x=>x.invoice.fiat_currency==='USD').cid,eur='currency_'+native.facts.find(x=>x.invoice.fiat_currency==='EUR').cid;
  expect(names).toEqual(expect.arrayContaining([usd+'[usdt_tron]',eur+'[usdt_tron]']));
  const submit=async(changes,omit=[])=>{
    await page.goto('/wallets');f=page.locator('form:has(input[name="PIN"])');
    const button=await f.locator('button[type="submit"]').getAttribute('name');const body=await fields(f,button);
    for(const name of [...body.keys()])if(name.startsWith('currency_') && !name.startsWith(usd+'[') && !name.startsWith(eur+'['))body.delete(name);
    for(const prefix of omit)for(const name of [...body.keys()])if(name.startsWith(prefix+'['))body.delete(name);
    for(const [name,value]of Object.entries(changes))body.set(name,value);
    return post(page.request,'/wallets',body);
  };
  const tron='TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',eth='0x'+'b'.repeat(40);
  const invalid=await submit({PIN:'000000',[usd+'[usdt_tron]']:tron});expect(invalid.status()).toBe(200);
  await page.goto('/wallets');await expect(page.locator('input[name="'+usd+'[usdt_tron]"]:not([type="hidden"])')).toHaveValue('');
  expect((await submit({PIN:'123456',__Cert:'0'.repeat(64),[usd+'[usdt_tron]']:tron})).status()).toBe(403);
  expect((await submit({PIN:'123456',[usd+'[usdt_tron]']:tron,'currency_999999[usdt_tron]':tron})).status()).toBe(200);
  await page.goto('/wallets');await expect(page.locator('input[name="'+usd+'[usdt_tron]"]:not([type="hidden"])')).toHaveValue('');
  expect((await submit({PIN:'123456',[usd+'[usdt_tron]']:tron},[eur])).status()).toBe(302);
  await page.goto('/wallets');await expect(page.locator('input[name="'+usd+'[usdt_tron]"]:not([type="hidden"])')).toHaveAttribute('readonly','');
  await expect(page.locator('input[name="'+eur+'[usdc_eth]"]:not([type="hidden"])')).not.toHaveAttribute('readonly','');
  expect((await submit({PIN:'123456',[usd+'[usdt_tron]']:'T'+'1'.repeat(33),[eur+'[usdc_eth]']:eth,wuID:'999999'})).status()).toBe(200); // Invalid address remains invalid even for a locked group.
  expect((await submit({PIN:'123456',[usd+'[usdc_eth]']:'0x'+'c'.repeat(40),[eur+'[usdc_eth]']:eth,wuID:'999999'})).status()).toBe(302);
  await page.goto('/wallets');await expect(page.locator('input[name="'+usd+'[usdt_tron]"]:not([type="hidden"])')).toHaveValue(tron);
  await expect(page.locator('input[name="'+usd+'[usdc_eth]"]:not([type="hidden"])')).toHaveValue('');
  await expect(page.locator('input[name="'+eur+'[usdc_eth]"]:not([type="hidden"])')).toHaveValue(eth);
  await login(page,'paw-review-89');expect([302,403]).toContain((await page.request.get('/admin/balance/setup',{maxRedirects:0})).status());
  await login(page);
  const rateForm=async()=>{const response=await page.goto('/admin/balance/setup');fs.writeFileSync('/tmp/rates-page.html',await page.content());expect(response.status(),await page.locator('body').innerText()).toBe(200);return page.locator('form:has(input[name="RateBNB"])');};
  let r=await rateForm();await expect(page.locator('[data-currency-rates]')).toHaveCount(1);
  expect(await r.locator('input[name^="Rate"]').count()).toBeGreaterThan(25);
  const values=async()=>{r=await rateForm();return fields(r,await r.locator('button[name$="_btn"]').first().getAttribute('name'));};
  let body=await values();body.set('__Cert','0'.repeat(64));expect((await post(page.request,'/admin/balance/setup',body)).status()).toBe(403);
  for(const [field,value]of [['RegBonus','abc'],['RateDAI','1'],['RateBNB','1e9']]){
    body=await values();body.set(field,value);const response=await post(page.request,'/admin/balance/setup',body);expect(response.status()).toBe(200);expect(await response.text()).toContain('Некорректный курс');
  }
  body=await values();body.set('RateBNB','500.125000000000000001');body.set('UpdateBNBRate','1');
  const saved=await post(page.request,'/admin/balance/setup',body);expect(saved.status(),await saved.text()).toBe(302);
  r=await rateForm();await expect(r.locator('input[name="RateBNB"]')).toHaveValue('500.125000000000000001');
});

test('wallet-fault: later SQL failure leaves both currencies unchanged',async({page})=>{
  await login(page,'financial-user');await page.goto('/wallets');const f=page.locator('form:has(input[name="PIN"])');
  const body=await fields(f,await f.locator('button[type="submit"]').getAttribute('name'));
  const names=await f.locator('input[name$="[usdc_eth]"]:not([type="hidden"])').evaluateAll(es=>es.map(e=>e.name));expect(names.length).toBe(2);
  const prefixes=names.map(name=>name.split('[')[0]);
  for(const name of [...body.keys()])if(name.startsWith('currency_') && !prefixes.some(prefix=>name.startsWith(prefix+'[')))body.delete(name);
  for(const name of names)body.set(name,'0x'+'d'.repeat(40));body.set('PIN','123456');
  const response=await post(page.request,'/wallets',body);expect(response.status()).toBe(500);const html=await response.text();expect(html).not.toMatch(/SQLSTATE|PDOException|Fatal error|Stack trace|\/var\/www\/html/);expect(html).toMatch(/Changes were rolled back|Изменения отменены/);
});
