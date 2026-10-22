const { test, expect } = require('@playwright/test');
require('./capture-callbacks.cjs').captureCallbacks(test, 'paw_native_off');
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
const manageForm=page=>page.locator('form:has(button[name="paw_profile_manage_btndisable"])');

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
  expect(await page.content()).toContain('Предварительный просмотр на примере USD');
  await expect(page.locator('form[name="paw_currency_save"], form:has(input[name="api_key"])')).toHaveCount(0);
  await expect(page.locator('[name="api_key"], [name="__Cert"]')).toHaveCount(0);
  await expect(page.locator('input[name="cName"]:not([type="hidden"])')).toBeDisabled();
  await expect(page.locator('input[name="cCASHINMin"]:not([type="hidden"])')).toBeDisabled();
  await expect(page.locator('select[name="cCASHINMode"] option')).toHaveText('отключено');
  await expect(page.getByRole('heading',{name:'Пополнение',exact:true})).toHaveCount(1);
  await expect(page.getByRole('heading',{name:'Снятие',exact:true})).toHaveCount(1);
  expect((await page.request.post('/admin/curr?provider=PAW&tab=settings',{form:{paw_currency_save_btn:'1',cID:'0',profile_id:'0',revision:'0'},maxRedirects:0})).status()).toBe(403);
  await page.goto('/admin/curr?provider=PAW');
  expect(await page.content()).toContain('Платежная система [PAW]');
  await expect(saveForm(page).locator('[name="api_key"]')).toHaveValue('');
  await saveForm(page).locator('[name="api_key"]').fill(key);
  await saveForm(page).locator('[name="fiats[]"][value="USD"]').check();
  await saveForm(page).locator('[name="fiats[]"][value="EUR"]').check();
  await saveForm(page).locator('[name="enabled"]').selectOption('1');
  const s=await fields(saveForm(page),'paw_profile_save_btn');
  const created=await post(page.request,'/admin/curr?provider=PAW',s); expect(created.status(),await created.text()).toBe(302);
  expect(created.headers().location).toContain('/admin/curr?provider=PAW&profile_id=');
  expect((await post(page.request,'/admin/curr?provider=PAW',s)).status()).toBe(403);
  await page.goto(created.headers().location); await expect(saveForm(page).locator('[name="api_key"]')).toHaveValue('');
  noSecrets(await page.content()); expect(await page.content()).toContain('Пополнение отключено');
  const cooldown=await post(page.request,page.url(),await fields(saveForm(page),'paw_profile_save_btn'));
  expect(cooldown.status()).toBe(200); expect(await cooldown.text()).toContain('через 30 секунд'); noSecrets(await cooldown.text());
  await page.goto('/admin/currs');
  const usdUrl=new URL(await page.getByRole('link',{name:'PAW Payments USD',exact:true}).getAttribute('href'),'http://fixture.invalid/');
  const usd=usdUrl.pathname+usdUrl.search;
  const eurUrl=new URL(await page.getByRole('link',{name:'PAW Payments EUR',exact:true}).getAttribute('href'),'http://fixture.invalid/');
  const eur=eurUrl.pathname+eurUrl.search;
  expect(usd).toMatch(/\/admin\/curr\?id=\d+/); expect(eur).toMatch(/\/admin\/curr\?id=\d+/);
  expect(await page.content()).not.toContain('/admin/paw');
  fs.writeFileSync(artifact,JSON.stringify({usd,eur}));
});

test('management: ordinary currency id keeps shared key blank and preserves history on rotation, disable and retire',async({page})=>{
  const ids=JSON.parse(fs.readFileSync(artifact,'utf8')); await login(page); await page.goto(ids.usd+'&tab=setup');
  expect(page.url()).toContain(ids.usd); expect(await page.content()).toContain('Платежная система [PAW] — USD');
  noSecrets(await page.content()); await expect(saveForm(page).locator('[name="api_key"]')).toHaveValue('');
  const s=await fields(saveForm(page),'paw_profile_save_btn'), m=await fields(manageForm(page),'paw_profile_manage_btndisable');
  for(const cert of ['', '0'.repeat(64),m.get('__Cert')]) {
    const forged=new URLSearchParams(s); forged.set('__Cert',cert); forged.set('api_key','sk_sandbox_POST_SECRET_CANARY');
    const r=await post(page.request,ids.usd,forged); expect(r.status()).toBe(403); noSecrets(await r.text());
  }
  for(const url of [ids.usd+'&testapi',ids.usd+'&tab=info',ids.usd+'&paw_profile_manage_btnremove=1&profile_id=1&revision=1']) {
    const r=await page.request.get(url,{maxRedirects:0}); expect(r.status()).toBe(200); noSecrets(await r.text());
    if(url.includes('tab=info')) expect(await r.text()).toContain('Кешбек выплачивается вручную после сверки транзакций в PAW.');
  }
  await page.goto(ids.usd);
  const currency=()=>page.locator('form[name="paw_currency_save"]');
  await expect(currency().locator('select[name="cCASHINMode"]')).toBeDisabled();
  await expect(currency().locator('select[name="cCASHOUTMode"]')).toBeDisabled();
  await expect(currency().locator('select[name="cCASHINMode"] option')).toHaveText('отключено');
  await expect(currency().locator('select[name="cCASHOUTMode"] option')).toHaveText('отключён');
  await expect(currency().locator('input[name="cName"]')).toHaveValue('PAW Payments USD');
  await expect(page.getByRole('link',{name:'Настройки USD',exact:true})).toHaveAttribute('aria-current','page');
  const settings=await fields(currency(),'paw_currency_save_btn');
  const badCert=new URLSearchParams(settings);badCert.set('__Cert',s.get('__Cert'));
  expect((await post(page.request,ids.usd+'&tab=settings',badCert)).status()).toBe(403);
  const forgedMode=new URLSearchParams(settings);forgedMode.set('cCASHINMode','2');
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
  await page.goto(ids.usd+'&tab=setup'); await saveForm(page).locator('[name="api_key"]').fill('sk_sandbox_HTTP_KEY_CANARY_rotated');
  const rotated=await post(page.request,ids.usd,await fields(saveForm(page),'paw_profile_save_btn'));
  expect(rotated.status(),await rotated.text()).toBe(302); expect(rotated.headers().location).toContain(ids.usd);
  await page.goto(ids.eur+'&tab=setup'); await expect(saveForm(page).locator('[name="api_key"]')).toHaveValue('');
  expect(await manageForm(page).locator('[name="revision"]').inputValue()).toBe('3');
  const disable=await fields(manageForm(page),'paw_profile_manage_btndisable');
  expect((await post(page.request,ids.eur,disable)).status()).toBe(302);
  expect((await post(page.request,ids.eur,disable)).status()).toBe(403);
  await page.goto(ids.usd); const stale=await fields(manageForm(page),'paw_profile_manage_btndisable'); stale.set('revision','2');
  const conflict=await post(page.request,ids.usd,stale); expect(conflict.status()).toBe(200); expect(await conflict.text()).toContain('Настройки уже изменились');
  const callback=await page.request.post('/balance/status?its=PAW',{data:'{"order_id":"untrusted","status":"success","fiat_amount":25}',headers:{'Content-Type':'application/json'}});
  expect(callback.status()).toBe(400);
  const deposit=await page.request.post('/api/v1/deposit',{headers:{Authorization:'Bearer hs_'+'1'.repeat(64),'Idempotency-Key':'paw-common-disabled'},data:{currency_id:Number(new URL('http://fixture.invalid'+ids.usd).searchParams.get('id')),amount:'25',memo:'Synthetic'}});
  expect(deposit.status()).toBe(422);
  await page.goto(ids.usd);
  const retired=await post(page.request,ids.usd,await fields(manageForm(page),'paw_profile_manage_btnremove'));
  expect(retired.status()).toBe(302); expect(retired.headers().location).toContain('/admin/currs');
  await page.goto(ids.usd); expect(await page.content()).toContain('Этот профиль удалён'); noSecrets(await page.content());
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
