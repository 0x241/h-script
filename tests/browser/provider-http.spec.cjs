const {test,expect}=require('@playwright/test');
const crypto=require('node:crypto'),fs=require('node:fs');
const login=async(page,name='provider-admin')=>{
  await page.context().clearCookies();await page.context().route('**/*',r=>new URL(r.request().url()).hostname==='fixture.invalid'?r.continue():r.abort());
  await page.goto('/login');const f=page.locator('form[name="login_frm"]');await f.locator('[name="Login"]').fill(name);await f.locator('[name="Pass"]').fill('synthetic-financial-password');await f.locator('button[type="submit"]').click();await expect(page).toHaveURL(/\/cabinet/);
};
const fields=async(f,b)=>new URLSearchParams([...(await f.evaluate(e=>[...new FormData(e).entries()])),[b,'1']]);
const post=(request,url,body)=>request.post(url,{data:body.toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'},maxRedirects:0});
const send=(request,event,bad=false)=>{const raw=JSON.stringify(event),t=Math.floor(Date.now()/1000);return request.post('/balance/status?its=ST',{data:raw,headers:{'Content-Type':'application/json','Stripe-Signature':'t='+t+',v1='+(bad?'0'.repeat(64):crypto.createHmac('sha256','whsec_HTTPfixture123').update(t+'.'+raw).digest('hex'))}});};

// Exercise the ordinary authenticated CSRF language endpoint, including pages
// without editorial {!!} markers. Only the synthetic review user's locale changes.
const language = async(page,lang)=>{
  const url=page.url();const f=page.locator('form').filter({has:page.locator('input[name="lang"][value="'+lang+'"]')}).filter({has:page.locator('input[name="session_csrf"]')}).first();
  await expect(f).toHaveCount(1);const action=await f.getAttribute('action');
  const body=new URLSearchParams(await f.evaluate(e=>[...new FormData(e).entries()]));body.set('url',url);
  const r=await post(page.request,action,body);expect(r.status(),await r.text()).toBe(302);await page.goto(r.headers().location);
};
const configurationSnapshot=page=>page.locator('.hs-provider input,.hs-provider select').evaluateAll(es=>es.filter(e=>/^(?:credentials\[|currencies\[|assets\[|activation_scope\[|token_currencies\[|profile_id$|revision$|currency_id$|account_fields\[)/.test(e.name)).map(e=>[e.name,e.type,e.value,e.checked,e.disabled,e.readOnly]).sort((a,b)=>JSON.stringify(a).localeCompare(JSON.stringify(b))));

test('creation: shared Stripe setup, provider catalog and preserved native currency choices',async({page,request})=>{
  for(const url of ['/tests/fixtures/provider_http_prepend.php','/module/balance/admin/curr.provider.php','/tests/financial_shared_provider.php'])expect([403,404]).toContain((await request.get(url)).status());
  expect([302,403]).toContain((await request.get('/admin/curr?provider=STRIPE',{maxRedirects:0})).status());
  await login(page,'financial-user');expect([302,403]).toContain((await page.request.get('/admin/curr?provider=STRIPE',{maxRedirects:0})).status());
  await login(page);await page.goto('/admin/curr?add');const add=page.locator('form:has(select[name="PSys"])');
  await expect(add.locator('option')).toHaveCount(8);
  for(const connected of ['BANKWIRE','WEBMONEY','YOOMONEY','COINPAY'])await expect(add.locator('option[value="'+connected+'"]')).toHaveCount(0);
  await expect(add.locator('option[value="COINNEW"]')).toHaveText('CoinPayments NEW');await expect(add.locator('option[value="STRIPE"]')).toHaveText('Stripe');
  expect(await page.content()).not.toContain('ePayCore');
  for(const old of ['BNBAPI','ETHERAPI','XRPAPI','TRONAPI'])await expect(add.locator('option[value="'+old+'"]')).toHaveCount(0);
  for(const [kind,count] of [['CCAPI',12],['VOLET',11]]) {
    await page.goto('/admin/curr?provider='+kind);await expect(page.locator('input[name="currencies[]"]')).toHaveCount(count);
    if(kind==='CCAPI')await expect(page.getByText('Партнёрская программа',{exact:true})).toBeVisible();
    if(kind==='VOLET')await expect(page.locator('.hs-provider-partner a')).toHaveAttribute('href','https://volet.onelink.me/jJs2?pid=User_invite&deep_link_value=hnXVmIHc');
    const control=page.locator('input[name="currencies[]"]').first();await control.check();
    expect(await control.evaluate(e=>getComputedStyle(e).appearance)).toBe('none');
    expect(await control.evaluate(e=>e.getBoundingClientRect().width)).toBe(19);
    expect(await page.locator('.hs-provider-input').first().evaluate(e=>getComputedStyle(e).boxSizing)).toBe('border-box');
    await page.evaluate(()=>window.scrollTo(0,0));await page.screenshot({path:'/tmp/provider-'+kind.toLowerCase()+'.png',fullPage:true});
    await page.setViewportSize({width:390,height:844});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
    await page.setViewportSize({width:1440,height:1000});
  }
  await page.goto('/admin/curr?add&provider=COINPAY');await expect(page.locator('select[name="Gateway"] option[value="CPE"]')).toBeAttached();
  const variants=page.locator('form:has(select[name="Gateway"])');const stale=await fields(variants,'add_btn');stale.set('Gateway','CP');
  const duplicate=await post(page.request,page.url(),stale);expect(duplicate.status(),await duplicate.text()).toBe(200);expect(await duplicate.text()).toContain('Эта валюта уже подключена');
  await page.goto('/admin/curr?provider=STRIPE');await expect(page.locator('input[name="credentials[secret_key]"]')).toHaveValue('');
  expect(await page.content()).toContain('/balance/status?its=ST');
  const f=page.locator('form:has(input[name="credentials[secret_key]"])');await f.locator('[name="credentials[secret_key]"]').fill('sk_test_HTTPfixture123');await f.locator('[name="credentials[webhook_secret]"]').fill('whsec_HTTPfixture123');
  await f.locator('[name="currencies[]"][value="USD"]').check();await f.locator('[name="currencies[]"][value="EUR"]').check();
  const body=await fields(f,'provider_save_btn');const bad=new URLSearchParams(body);bad.set('__Cert','0'.repeat(64));expect((await post(page.request,page.url(),bad)).status()).toBe(403);
  const r=await post(page.request,page.url(),body);expect(r.status(),await r.text()).toBe(302);await page.goto(r.headers().location);
  expect(await page.content()).not.toContain('HTTPfixture123');await expect(page.locator('input[name="credentials[secret_key]"]')).toHaveValue('');
  await page.goto('/admin/currs?provider=STRIPE');const usd=page.getByRole('link',{name:'Stripe USD',exact:true});const url=await usd.getAttribute('href');await page.goto(url);
  const settings=page.locator('form[name="provider_currency_save"]');await expect(settings.locator('[name="cCASHINMode"] option')).toHaveCount(4);await expect(settings.locator('[name="cCASHOUTMode"] option')).toHaveCount(2);
  await settings.locator('[name="cDisabled"]').uncheck();await settings.locator('[name="cHidden"]').uncheck();await settings.locator('[name="cCASHINMode"]').selectOption('3');
  await settings.locator('[name="cBalMin"]').fill('2.1');const saved=await post(page.request,page.url(),await fields(settings,'provider_currency_save_btn'));expect(saved.status(),await saved.text()).toBe(302);
  await page.goto(saved.headers().location);await expect(page.locator('[name="cBalMin"]')).toHaveValue('2.100000');
});
test('creation: gateway filters preserve grouping, pagination and sorting without exposing credentials',async({page})=>{
  await login(page);
  await page.goto('/admin/currs?provider=STRIPE');
  const connection=page.locator('.hs-provider-connections').getByRole('link',{name:/Stripe/});await expect(connection).toBeVisible();await connection.click();await expect(page).toHaveURL(/provider=STRIPE$/);await expect(page.getByRole('heading',{name:'Подключение шлюза',exact:true})).toBeVisible();await page.goto('/admin/currs?provider=STRIPE');
  const chips=page.locator('.hs-provider-filters').first();await expect(chips.locator('[aria-current="page"]')).toHaveText('Stripe');
  await expect(page.locator('table tbody tr')).toHaveCount(2);
  await expect(page.locator('tbody')).toContainText('Stripe USD');await expect(page.locator('tbody')).toContainText('Stripe EUR');
  await expect(page.locator('tbody')).not.toContainText('Synthetic wire');
  const sort=page.locator('a[href*="sort=cID"]').first();expect(await sort.getAttribute('href')).toContain('provider=STRIPE');
  await sort.click();await expect(chips.locator('[aria-current="page"]')).toHaveText('Stripe');
  await page.goto('/admin/currs?provider=BANKWIRE&page=1');
  await expect(chips.locator('[aria-current="page"]')).toHaveText('BankWire');
  await expect(page.locator('table tbody tr')).toHaveCount(20);
  const second=page.locator('a[href*="page=2"][href*="provider=BANKWIRE"]').first();await expect(second).toBeVisible();await second.click();
  await expect(page.locator('table tbody tr')).toHaveCount(3);
  await expect(chips.locator('[aria-current="page"]')).toHaveText('BankWire');
  await page.setViewportSize({width:390,height:844});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  await page.goto('/admin/currs?provider[]=STRIPE');await expect(chips.locator('[aria-current="page"]')).toHaveText(/Все|All/);
  expect(await page.content()).not.toMatch(/(?:whsec_|sk_test_HTTPfixture|Warning|Fatal error)/);
});
test('creation: token currencies expose common network settings for two providers',async({page})=>{
  await login(page);
  for(const [kind,key] of [['CCAPI','CCTU'],['PAYKASSA','PKTU']]){
    await login(page);await page.goto('/admin/curr?provider='+kind);let f=page.locator('#provider-connection-save');
    if(kind==='CCAPI')await f.locator('[name="credentials[v3_key]"]').fill('synthetic-token-secret');
    else for(const [field,value] of Object.entries({merchant_id:'100',merchant_secret:'synthetic-token-sci',api_id:'200',api_secret:'synthetic-token-api'}))await f.locator('[name="credentials['+field+']"]').fill(value);
    if(kind==='PAYKASSA'){
      await expect(f.locator('[name="credentials[test_mode]"]')).toHaveCount(0);
      expect(await page.locator('body').innerText()).not.toMatch(/sandbox|тестов[а-я]*/i);
      expect(await f.locator('input[type="checkbox"][name="currencies[]"]').count()).toBeLessThan(25);
      for(const key of ['PKDG','PKXR','PKTN'])await expect(f.locator('[value="'+key+'"][name="currencies[]"]')).toHaveCount(1);for(const key of ['PKBT','PKTT','PKES'])await expect(f.locator('input[type="checkbox"][value="'+key+'"][name="currencies[]"]')).toHaveCount(0);
      await expect(page.locator('.hs-provider-partner')).toContainText('Кешбек выплачивается вручную после сверки транзакций в PayKassa.');
      await expect(f.locator('.hs-provider-network-note').filter({hasText:'При сохранении выполняется запрос'}).locator('.fa-circle-info')).toHaveCount(1);
      await expect(f.locator('.hs-provider-network-note').filter({hasText:'При сохранении выполняется запрос'})).toContainText('Средства не переводятся');
      await page.screenshot({path:'/tmp/paykassa-connection.png',fullPage:true});
    }
    let r;if(kind==='CCAPI'){await f.locator('[name="currencies[]"][value="ETH"]').check();await f.locator('[name="currencies[]"][value="TRX"]').check();}else {
      await f.locator('[name="currencies[]"][value="BTC"]').check();r=await post(page.request,page.url(),await fields(f,'provider_save_btn'));expect(r.status(),await r.text()).toBe(302);
      await login(page);await page.goto('/admin/curr?provider=PAYKASSA');
      await expect(page.getByRole('link',{name:'Токены по сетям',exact:true})).toHaveCount(0);
      await expect(page.getByText('Ethereum / TRON · общий счёт',{exact:true})).toHaveCount(0);
      for(const code of ['BTC','PKDG']){const card=page.locator('.hs-provider-currency').filter({has:page.locator('input[type="checkbox"][name="currencies[]"][value="'+code+'"]')});await expect(card.locator('a.is-network')).toHaveCount(0);}
      const bnb=page.locator('.hs-provider-currency').filter({has:page.locator('input[type="checkbox"][name="currencies[]"][value="PKBN"]')});await expect(bnb.getByRole('link',{name:'Токены BNB Smart Chain (BEP-20)',exact:true})).toHaveAttribute('href',/network=bsc$/);
      const selectedOrder=await page.locator('input[type="checkbox"][name="currencies[]"]').evaluateAll(es=>es.map(e=>Number(e.checked)));expect(selectedOrder).toEqual([...selectedOrder].sort((a,b)=>b-a));
      const rows=await page.locator('.hs-provider-currency-item > .hs-provider-currency').evaluateAll(es=>es.map(e=>{const r=e.getBoundingClientRect();return {top:Math.round(r.top),bottom:r.bottom};}));for(const row of rows){const same=rows.filter(r=>r.top===row.top).map(r=>r.bottom);expect(Math.max(...same)-Math.min(...same)).toBeLessThanOrEqual(1);}
      await page.screenshot({path:'/tmp/paykassa-unified-cards.png',fullPage:true});await page.setViewportSize({width:390,height:844});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);await page.setViewportSize({width:1440,height:1000});
      await page.getByRole('link',{name:'Настроить BTC',exact:true}).click();await expect(page.locator('form[name="provider_currency_save"] [name="cDisabled"]')).not.toBeChecked();await expect(page.locator('[name="cCASHINMode"]')).toHaveValue('0');await expect(page.getByRole('link',{name:'Кошельки и токены сети',exact:true})).toHaveCount(0);
      await page.goto('/admin/curr?provider=PAYKASSA&tab=network&network=ethereum');await expect(page.locator('input[type="checkbox"][name="currencies[]"][value="USDT"]')).toHaveCount(1);await expect(page.locator('.hs-provider-currency').filter({has:page.locator('input[type="checkbox"][value="USDT"]')})).toContainText('Ethereum (ERC-20) · общий счёт');await page.goto('/admin/curr?provider=PAYKASSA');await page.getByRole('link',{name:'Токены TRON (TRC-20)',exact:true}).click();await expect(page).toHaveURL(/tab=network&network=tron$/);await expect(page.locator('.hs-provider-network-note').filter({hasText:'общий баланс для Ethereum и TRON'})).toBeVisible();f=page.locator('#provider-connection-save');await expect(f.locator('[name="currencies[]"][value="BTC"]')).toHaveAttribute('type','hidden');await expect(f.locator('[name="credentials[merchant_id]"]')).toHaveValue('100');await expect(f.locator('input[type="password"]')).toHaveCount(0);await f.locator('[name="currencies[]"][value="USDT"]').check();
    }r=await post(page.request,page.url(),await fields(f,'provider_save_btn'));expect(r.status(),await r.text()).toBe(302);
    if(kind==='CCAPI'){
      for(const chain of ['ethereum','tron']){
        await page.goto('/admin/curr?provider=CCAPI');await expect(page.locator('[name="provider_token_refresh_btn"]')).toHaveCount(0);await expect(page.locator('[name="provider_manage_btndisable"]')).toHaveCount(0);
        const networkLink=page.locator('.hs-provider-currency').filter({has:page.getByRole('link',{name:'Настроить '+(chain==='ethereum'?'ETH':'TRX'),exact:true})}).getByRole('link',{name:'Кошельки и токены',exact:true});
        await networkLink.click();await expect(page).toHaveURL(/id=[0-9]+&tab=network$/);await expect(page.locator('select[name="network"]')).toHaveCount(0);
        await expect(page.locator('.hs-provider-badge')).toHaveText('Сеть подключена');
        for(const action of await page.locator('form[action]').evaluateAll(forms=>forms.map(f=>f.action)))expect(action).not.toContain('amp;');
        const refresh=page.locator('form:has(button[name="provider_token_refresh_btn"])');
        const action=await refresh.getAttribute('action');const first=await fields(refresh,'provider_token_refresh_btn');const invalid=new URLSearchParams(first);invalid.set('__Cert','0'.repeat(64));expect((await post(page.request,action,invalid)).status()).toBe(403);
        r=await post(page.request,action,first);expect(r.status(),await r.text()).toBe(302);await page.goto(r.headers().location);
        const add=page.locator('form[data-token-catalog]');await expect(add.locator('[name="tokens[]"]')).toHaveCount(chain==='ethereum'?2:1);const tokenRow=add.locator('[data-token-option]').first();expect(await tokenRow.locator('strong').evaluate(e=>e.getBoundingClientRect().left-e.previousElementSibling.getBoundingClientRect().right)).toBeGreaterThanOrEqual(10);
        await add.locator('[data-token-option]').filter({has:page.getByText('USDT',{exact:true})}).locator('input').check();
        if(chain==='ethereum'){
          await add.locator('[data-token-search]').fill('DAI');await expect(add.locator('[data-token-option]').filter({has:page.getByText('USDT',{exact:true})})).toBeHidden();
          await add.locator('[data-token-option]').filter({has:page.getByText('DAI',{exact:true})}).locator('input').check();await add.locator('[data-token-search]').fill('');
        }
        const chosen=await fields(add,'provider_token_add_btn');r=await post(page.request,page.url(),chosen);expect(r.status(),await r.text()).toBe(302);await page.goto(r.headers().location);
      }
      await page.goto('/admin/curr?provider=CCAPI&tab=setup&network=ethereum');
      const wallets=page.locator('form:has(button[name="provider_network_wallets_btn"])');await wallets.locator('[name="forward_to"]').fill('0x'+'3'.repeat(40));await wallets.locator('[name="payout_from"]').fill('0x'+'4'.repeat(40));
      await wallets.getByRole('button',{name:'Сохранить кошельки',exact:true}).click();await expect(page).toHaveURL(/id=[0-9]+&tab=network$/);await expect(page.locator('[name="forward_to"]')).toHaveValue('0x'+'3'.repeat(40));
      await page.screenshot({path:'/tmp/token-discovery.png',fullPage:true});
      await page.getByRole('link',{name:'Настроить DAI',exact:true}).click();
      await expect(page.getByRole('heading',{name:'Настройки валют',exact:true})).toHaveCount(0);
      let generic=page.locator('form[name="provider_currency_save"]');await expect(generic.locator('[name="cEXMode"] option')).toHaveCount(1);await expect(generic.locator('[name="cGIVEMode"] option')).toHaveCount(1);
      await generic.locator('[name="cDisabled"]').uncheck();await generic.locator('[name="cCASHINMode"]').selectOption('2');await generic.locator('[name="cCASHINComis"]').fill('10');
      r=await post(page.request,page.url(),await fields(generic,'provider_currency_save_btn'));expect(r.status(),await r.text()).toBe(302);await page.goto(r.headers().location);
      await page.getByRole('link',{name:'Кошельки и токены сети',exact:true}).click();await expect(page).toHaveURL(/id=[0-9]+&tab=network$/);
      await page.goto('/admin/curr?add');await expect(page.locator('select[name="PSys"] option[value="CCAPI"]')).toHaveCount(0);
      await page.goto('/admin/currs?provider=CCAPI');await expect(page.locator('tbody')).toContainText('CCAET USDT');await expect(page.locator('tbody')).toContainText('CCATT USDT');
      const usdtLinks=await page.getByRole('link',{name:/^CryptoCurrencyAPI.net USDT ·/}).all();expect(usdtLinks).toHaveLength(2);const urls=[];for(const link of usdtLinks)urls.push(await link.getAttribute('href'));
      for(const target of urls){await page.goto(target);await expect(page.locator('.hs-provider-scope')).toContainText('Настройки USDT');await expect(page.getByRole('heading',{name:'Сети приёма',exact:true})).toHaveCount(0);const settings=page.locator('form[name="provider_currency_save"]');await settings.locator('[name="cDisabled"]').uncheck();await settings.locator('[name="cCASHINMode"]').selectOption('2');await settings.locator('[name="cCASHINComis"]').fill('10');r=await post(page.request,page.url(),await fields(settings,'provider_currency_save_btn'));expect(r.status(),await r.text()).toBe(302);}
      continue;
    }
    await page.goto('/admin/curr?add');await expect(page.locator('select[name="PSys"] option[value="'+kind+'"]')).toHaveCount(0);
    await page.goto('/admin/currs?provider='+kind);if(kind==='CCAPI')await expect(page.locator('tbody')).toContainText('CCAET DAI');await page.getByRole('link',{name:(kind==='CCAPI'?'CryptoCurrencyAPI.net':'PayKassa')+' USDT',exact:true}).click();
    const settings=page.locator('form[name="provider_currency_save"]');await settings.locator('[name="cDisabled"]').uncheck();await settings.locator('[name="cHidden"]').uncheck();await settings.locator('[name="cCASHINMode"]').selectOption('2');await settings.locator('[name="cCASHINComis"]').fill('10');
    await expect(settings.locator('[name="cCASHOUTMode"] option')).toHaveCount(2);r=await post(page.request,page.url(),await fields(settings,'provider_currency_save_btn'));expect(r.status(),await r.text()).toBe(302);await page.goto(r.headers().location);
    const network=page.locator('form:has(input[name="payment_routes[]"])');await expect(network.locator('[name="payment_routes[]"]')).toHaveCount(2);await network.locator('[name="payment_routes[]"]').first().check();await network.locator('[name="payment_routes[]"]').last().check();
    const body=await fields(network,'provider_routes_save_btn');const bad=new URLSearchParams(body);bad.set('__Cert','0'.repeat(64));expect((await post(page.request,page.url(),bad)).status()).toBe(403);
    r=await post(page.request,page.url(),body);expect(r.status(),await r.text()).toBe(302);await page.goto(r.headers().location);await expect(page.locator('[name="payment_routes[]"]:checked')).toHaveCount(2);
    await page.setViewportSize({width:390,height:844});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);await page.setViewportSize({width:1440,height:1000});
    await page.screenshot({path:'/tmp/token-settings-'+kind.toLowerCase()+'.png',fullPage:true});

  }
});
test('creation: CoinPayments NEW discovers selected assets and keeps archived ledgers distinct',async({page})=>{
  await login(page);await page.goto('/admin/curr?provider=COINNEW');
  await expect(page.getByRole('heading',{name:'CoinPayments NEW',exact:true})).toBeVisible();
  let form=page.locator('form[name="coinnew_catalog"]');
  let response=await post(page.request,page.url(),await fields(form,'coinnew_catalog_btn'));expect(response.status(),await response.text()).toBe(200);await page.goto(page.url());
  await expect(page.locator('[data-token-option]')).toHaveCount(3);
  await expect(page.locator('[data-token-option]').filter({has:page.getByText('XRP',{exact:true})}).locator('input')).toBeDisabled();
  const save=async()=>{
    let f=page.locator('form[name="coinnew_save"]');
    await f.locator('[name="credentials[client_id]"]').fill('00000000-0000-0000-0000-000000000123');
    await f.locator('[name="credentials[client_secret]"]').fill('synthetic-new-secret');
    let setup=await post(page.request,page.url(),await fields(f,'coinnew_save_btn'));expect(setup.status(),await setup.text()).toBe(302);
    await page.goto('/admin/curr?provider=COINNEW&tab=tokens');f=page.locator('form[name="coinnew_save"]');await expect(f.locator('input[type="password"]')).toHaveCount(0);await expect(page.locator('form[name="coinnew_catalog"]')).toHaveCount(0);
    await f.locator('[name="assets[]"][value="4:0x'+'1'.repeat(40)+'"]').check();
    await f.locator('[name="assets[]"][value="9:T'+'1'.repeat(33)+'"]').check();
    const r=await post(page.request,page.url(),await fields(f,'coinnew_save_btn'));expect(r.status(),await r.text()).toBe(302);await page.goto(r.headers().location);
    expect(await page.content()).not.toContain('synthetic-new-secret');await page.goto('/admin/curr?provider=COINNEW&tab=tokens');
    return (await page.getByRole('link',{name:/Открыть валюту/}).evaluateAll(es=>es.map(e=>new URL(e.href).searchParams.get('id')))).sort();
  };
  const old=await save();expect(old).toHaveLength(2);
  await page.goto('/admin/curr?add');await expect(page.locator('option[value="COINNEW"]')).toHaveCount(0);
  await page.goto('/admin/curr?provider=COINNEW');
  const remove=page.locator('form[name="coinnew_disable"]');response=await post(page.request,page.url(),await fields(remove,'coinnew_disable_btn'));expect(response.status(),await response.text()).toBe(302);await page.goto(response.headers().location);
  const current=await save();expect(current.some(id=>old.includes(id))).toBe(false);
  await page.goto('/admin/currs?provider=COINNEW');
  await expect(page.locator('tbody tr')).toHaveCount(2);await page.goto('/admin/currs?provider=COINNEW&state=all');await expect(page.locator('tbody tr')).toHaveCount(4);await expect(page.getByRole('link',{name:/Operations in this currency|Операции этой валюты/})).toHaveCount(2);
  await page.goto('/admin/curr?id='+old[0]);await expect(page.getByRole('heading').last()).toContainText('Архив #'+old[0]);await expect(page.locator('form[name="coinnew_save"]')).toHaveCount(0);
  await page.getByRole('link',{name:/Operations in this currency|Операции этой валюты/}).click();await expect(page.locator('select[name="ocID"]')).toHaveValue(old[0]);
  await expect(page.locator('select[name="ocID"] option:checked')).toContainText('Архив #'+old[0]);
  await page.setViewportSize({width:390,height:844});await page.goto('/admin/curr?provider=COINNEW');expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
});
test('creation: unavailable PayKassa invoice renders reconciliation without another request',async({page,request})=>{
  await login(page);await page.goto('/admin/curr?provider=PAYKASSA');
  let f=page.locator('#provider-connection-save');await f.locator('[name="currencies[]"][value="PKTN"]').check();
  let r=await post(page.request,page.url(),await fields(f,'provider_save_btn'));expect(r.status(),await r.text()).toBe(302);await page.goto(r.headers().location);
  await page.getByRole('link',{name:'Настроить TON',exact:true}).click();
  f=page.locator('form[name="provider_currency_save"]');const currency=await f.locator('[name="cID"]').inputValue();
  await f.locator('[name="cDisabled"]').uncheck();await f.locator('[name="cHidden"]').uncheck();await f.locator('[name="cCASHINMode"]').selectOption('2');
  r=await post(page.request,page.url(),await fields(f,'provider_currency_save_btn'));expect(r.status(),await r.text()).toBe(302);
  const d=await request.post('/api/v1/deposit',{headers:{Authorization:'Bearer hs_'+'1'.repeat(64),'Idempotency-Key':'paykassa-unavailable-http'},data:{currency_id:Number(currency),amount:'100'}});expect(d.status(),await d.text()).toBe(201);
  const id=(await d.json()).data.operation.id;
  await page.goto('/admin/opers?currency_id='+currency);await expect(page.locator('select[name="ocID"]')).toHaveValue(currency);
  const sorting=await page.locator('a[href*="sort=oID"]').first().getAttribute('href');expect(new URL(sorting,'http://fixture.invalid').searchParams.get('currency_id')).toBe(currency);expect(sorting).not.toContain('amp;');
  await login(page,'financial-user');await page.goto('/operation?id='+id);
  const body=await fields(page.locator('#deposit-payment-form'),'pay_btnpay');
  for(let i=0;i<2;i++){r=await post(page.request,page.url(),body);const html=await r.text();expect(r.status(),html).toBe(503);expect(html).toContain('Invoice creation could not be confirmed');expect(html).not.toMatch(/Fatal error|Stack trace|synthetic-token-sci|invoice_reconciliation_required/);expect(html).not.toContain('name="pay_btnpay"');}
});

test('creation: gateway translations switch RU EN RU without changing connection controls',async({page})=>{
  await login(page);
  for(const path of ['?provider=PAYKASSA','?provider=PAYKASSA&tab=network&network=ethereum','?provider=PAYKASSA&tab=network&network=tron','?provider=CCAPI','?provider=CCAPI&network=ethereum','?provider=BANKWIRE','?provider=WEBMONEY','?provider=YOOMONEY','?provider=COINNEW']){
    await page.goto('/admin/curr'+path);const before=await configurationSnapshot(page);const ru=await page.locator('.hs-provider').innerText();expect(ru).toMatch(/[А-Яа-яЁё]/);
    await language(page,'en');await expect(page.locator('html')).toHaveAttribute('lang','en');const en=await page.locator('.hs-provider').innerText();expect(en).not.toMatch(/[А-Яа-яЁё]|gateway\.(?:admin|field|error)\./);expect(en).not.toBe(ru);expect(await configurationSnapshot(page)).toEqual(before);
    for(const secret of await page.locator('.hs-provider input[type="password"]').all())await expect(secret).toHaveValue('');
    await language(page,'ru');await expect(page.locator('html')).toHaveAttribute('lang','ru');expect(await page.locator('.hs-provider').innerText()).toBe(ru);expect(await configurationSnapshot(page)).toEqual(before);
  }
});

test('payment: per-network token currencies bind callbacks and independent wallets',async({page,request})=>{
  const saved=JSON.parse(fs.readFileSync('/tmp/provider-public.json','utf8'));const replay=[];
  for(const c of [...saved.tokens,...saved.catalog_tokens]){
    const deposit=await request.post('/api/v1/deposit',{headers:{Authorization:'Bearer hs_'+'1'.repeat(64),'Idempotency-Key':'token-http-'+c.cID},data:{currency_id:Number(c.cID),amount:'10'}});expect(deposit.status(),await deposit.text()).toBe(201);const id=(await deposit.json()).data.operation.id;
    await login(page,'financial-user');await page.goto('/operation?id='+id);const select=page.locator('select[name="payment_route"]');let code;if(c.cCID==='CCT'){await expect(select).toHaveCount(0);code=await page.locator('input[type="hidden"][name="payment_route"]').inputValue();}else{await expect(select).toBeVisible();await expect(select.locator('option')).toHaveCount(3);code=(c.cCID==='CCTU'?'ccapi':'paykassa')+'-usdt-tron';await select.selectOption(code);}const form=page.locator('#deposit-payment-form');const body=await fields(form,'pay_btnpay');expect(body.get('payment_route')).toBe(code);const badRoute=new URLSearchParams(body);badRoute.set('payment_route','unknown');expect((await post(page.request,page.url(),badRoute)).status()).toBe(400);
    const invoice=await post(page.request,page.url(),body);expect(invoice.status(),await invoice.text()).toBe(200);
    const switched=new URLSearchParams(body);switched.set('payment_route',(c.cCID==='CCTU'?'ccapi':'paykassa')+'-usdt-ethereum');if(c.cCID!=='CCT')expect((await post(page.request,page.url(),switched)).status()).toBe(409);
    let payload;const route=c.cTokenRoute?JSON.parse(c.cTokenRoute):null;
    if(c.cCID==='CCTU'||c.cCID==='CCT'){
      await page.goto('/operation?id='+id);await expect(page.locator('#native-payment-amount')).toHaveText('10.000000 '+c.cCurrID);await expect(page.locator('.hs-native-network')).toHaveText(c.cCID==='CCT'&&route.network!=='tron'?route.label:'TRON');await page.screenshot({path:'/tmp/token-native.png',fullPage:true});
      const p={'cryptocurrencyapi.net':3,chain:c.cCID==='CCT'?route.network:'tron',currency:c.cCID==='CCT'?route.wire:'TRX',type:'in',date:Math.floor(Date.now()/1000),from:null,to:c.cCID==='CCT'&&route.network!=='tron'?'0x'+'1'.repeat(40):'T'+'1'.repeat(33),token:c.cCurrID,tokenContract:c.cCID==='CCT'?route.contract:'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',amount:'10.000000',fee:'0.000001',txid:'a'.repeat(64),pos:0,confirmation:2,label:String(id)};
      const sign=(p)=>{const sorted=Object.keys(p).sort().map(k=>p[k]===null?'':String(p[k])).join(':');return {...p,sign:crypto.createHash('sha1').update(sorted+':'+crypto.createHash('md5').update('synthetic-token-secret').digest('hex')).digest('hex')};};
      expect((await request.post('/balance/status',{data:JSON.stringify(sign({...p,tokenContract:route&&route.network!=='tron'?'0x'+'2'.repeat(40):'T'+'2'.repeat(33)})),headers:{'Content-Type':'application/json'}})).status()).toBe(400);payload={type:'json',body:sign(p)};
    }else payload={type:'form',body:{private_hash:'synthetic-token-'+id,order_id:String(id),currency:'USDT',system:'TRON_TRC20'}};
    for(let j=0;j<3;j++){const r=await request.post('/balance/status',payload.type==='json'?{data:JSON.stringify(payload.body),headers:{'Content-Type':'application/json'}}:{form:payload.body});expect(r.status(),await r.text()).toBe(200);}
    replay.push(payload);
  }
  fs.writeFileSync('/tmp/token-replay.json',JSON.stringify(replay));
});
test('payment: real browser invoice, HMAC callbacks, conflicts, concurrency and exact retry',async({page,request})=>{
  const saved=JSON.parse(fs.readFileSync('/tmp/provider-public.json','utf8'));const usd=saved.currencies.find(c=>c.cCurrID==='USD');
  const deposit=await request.post('/api/v1/deposit',{headers:{Authorization:'Bearer hs_'+'1'.repeat(64),'Idempotency-Key':'stripe-http'},data:{currency_id:Number(usd.cID),amount:'1.01',memo:'Synthetic Stripe'}});expect(deposit.status(),await deposit.text()).toBe(201);const id=(await deposit.json()).data.operation.id;
  await login(page,'financial-user');await page.goto('/operation?id='+id);const pay=page.locator('form#deposit-payment-form');
  const r=await post(page.request,page.url(),await fields(pay,'pay_btnpay'));expect(r.status(),await r.text()).toBe(200);expect(await r.text()).toContain('https://checkout.stripe.com/c/pay/cs_test_HTTP'+id);expect(await r.text()).not.toContain('whsec_');
  const session={id:'cs_test_HTTP'+id,object:'checkout.session',mode:'payment',livemode:false,client_reference_id:String(id),currency:'usd',amount_total:101,status:'complete',payment_status:'paid',metadata:{operation:String(id),owner:'2',currency:String(usd.cID),provider:saved.profile.ppPublicID,version:'1'}};
  const event={id:'evt_HTTPfirst',object:'event',created:Math.floor(Date.now()/1000),livemode:false,type:'checkout.session.completed',data:{object:session}};
  expect((await send(request,event,true)).status()).toBe(401);
  expect((await send(request,{...event,data:{object:{...session,amount_total:102}}})).status()).toBe(400);
  expect((await send(request,{...event,data:{object:{...session,currency:'eur'}}})).status()).toBe(400);
  expect((await send(request,{...event,data:{object:{...session,payment_status:'unpaid'}}})).status()).toBe(200);
  const responses=await Promise.all(Array.from({length:8},()=>send(request,event)));expect(responses.map(r=>r.status()),await responses[0].text()).toEqual(Array(8).fill(200));
  for(const r of responses)expect(await r.text()).toBe('OK');
  expect((await send(request,{...event,id:'evt_HTTPsecond',type:'checkout.session.async_payment_succeeded'})).status()).toBe(200);
  expect((await send(request,{...event,id:'evt_ignored',type:'balance.available',data:{object:{object:'balance'}}})).status()).toBe(200);
  expect([409,400]).toContain((await send(request,{...event,data:{object:{...session,amount_total:102}}})).status());
  expect((await page.request.get('/admin/curr?id='+saved.retired_id,{maxRedirects:0})).status()).toBe(302); // ordinary owner cannot reach admin
  await login(page);expect((await page.request.get('/admin/curr?id='+saved.retired_id)).status()).toBe(410);
  await page.goto('/');await expect(page.locator('body')).toContainText('11');
  fs.writeFileSync('/tmp/provider-replay.json',JSON.stringify(event));
});
test('restart: original signed session replays after actual application restart',async({request})=>{
  const event=JSON.parse(fs.readFileSync('/tmp/provider-replay.json','utf8'));const r=await send(request,event);expect(r.status(),await r.text()).toBe(200);expect(await r.text()).toBe('OK');
});

test('restart: token routes retain original binding and credit exactly once',async({request})=>{for(const p of JSON.parse(fs.readFileSync('/tmp/token-replay.json','utf8'))){const r=await request.post('/balance/status',p.type==='json'?{data:JSON.stringify(p.body),headers:{'Content-Type':'application/json'}}:{form:p.body});expect(r.status(),await r.text()).toBe(200);}});


test('creation: legacy setup offers actual currencies and keeps bank settings separate',async({page})=>{
  await login(page,'financial-user');expect([302,403]).toContain((await page.request.get('/admin/curr?provider=BANKWIRE',{maxRedirects:0})).status());
  await login(page);
  for(const [kind,values] of [['BANKWIRE',['EUR','GBP','RUB']],['YOOMONEY',['YM','YMC']],['WEBMONEY',['USD']],['SBERBANK',['RUB']]]){
    await page.goto('/admin/curr?add&provider='+kind);await expect(page.locator('select[name="Gateway"]')).toHaveCount(0);
    for(const value of values)await expect(page.locator('input[name="assets[]"][value="'+value+'"]').first()).toBeAttached();
  }
  await page.goto('/admin/curr?provider=BANKWIRE');await expect(page.locator('input[name="assets[]"][value="BTC"]')).toHaveCount(0);await expect(page.locator('input[name="assets[]"][value="USD"]')).toBeEnabled();
  await expect(page.locator('.hs-provider-currency-actions a').first()).toHaveCSS('display','flex');
  await expect(page.getByRole('heading',{name:'Валюты учёта',exact:true})).toHaveCount(1);await expect(page.getByRole('heading',{name:'Подключённые валюты',exact:true})).toHaveCount(0);await expect(page.locator('input[name="assets[]"][value="USD"]')).toBeChecked();
  let f=page.locator('form[name="legacy_add"]');await f.locator('input[type="checkbox"][name="assets[]"][value="EUR"]').check();await f.locator('input[type="checkbox"][name="assets[]"][value="RUB"]').check();const body=await fields(f,'legacy_add_btn');
  const bad=new URLSearchParams(body);bad.set('__Cert','0'.repeat(64));expect((await post(page.request,page.url(),bad)).status()).toBe(403);
  bad.set('__Cert',body.get('__Cert'));bad.append('assets[]','BTC');expect((await post(page.request,page.url(),bad)).status()).toBe(200);
  await page.goto('/admin/curr?provider=BANKWIRE');f=page.locator('form[name="legacy_add"]');await f.locator('input[type="checkbox"][name="assets[]"][value="EUR"]').check();await f.locator('input[type="checkbox"][name="assets[]"][value="RUB"]').check();
  const saved=await post(page.request,page.url(),await fields(f,'legacy_add_btn'));expect(saved.status(),await saved.text()).toBe(302);await page.goto(saved.headers().location);
  const selectedOrder=await page.locator('input[type="checkbox"][name="assets[]"]').evaluateAll(es=>es.map(e=>Number(e.checked)));expect(selectedOrder).toEqual([...selectedOrder].sort((a,b)=>b-a));
  const bankIds={};
  for(const code of ['EUR','RUB']){
    const link=page.getByRole('link',{name:'Настроить '+code,exact:true});await link.click();f=page.locator('form:has(input[name="cCID"])');bankIds[code]=Number(await f.locator('[name="cID"]').inputValue());await expect(f.locator('input[type="checkbox"][name="cDisabled"]')).not.toBeChecked();
    await f.locator('input[type="checkbox"][name="cDisabled"]').uncheck();await f.locator('input[type="checkbox"][name="cHidden"]').uncheck();await f.locator('[name="cCASHINMode"]').selectOption('1');await f.locator('[name="P[acc]"]').fill('synthetic-bank-'+code);await f.locator('[name="P[cname]"]').fill('Beneficiary '+code);
    const button=await f.locator('button[type="submit"]').last().getAttribute('name');const r=await post(page.request,page.url(),await fields(f,button));expect(r.status(),await r.text()).toBe(302);await page.goto('/admin/curr?provider=BANKWIRE');
  }
  await page.setViewportSize({width:390,height:844});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  await login(page,'financial-user');await page.goto('/wallets');await expect(page.locator('[data-wallet-provider="BANKWIRE"]')).toHaveCount(1);
  const group=page.locator('details[data-wallet-provider="BANKWIRE"]');await group.locator('summary').click();const names=await group.locator('input[name^="currency_"]:not([type="hidden"])').evaluateAll(es=>es.map(e=>e.name));expect(new Set(names).size).toBe(names.length);
  const blocks=group.locator('[data-wallet-currency]');await expect(blocks).toHaveCount(2);for(const block of await blocks.all()){const fields=await block.locator('input[name^="currency_"]:not([type="hidden"])').evaluateAll(es=>es.map(e=>e.name.split('[')[0]));expect(new Set(fields).size).toBe(1);await expect(block.locator('input[name$="[bname]"]')).toHaveAttribute('placeholder','Bank name');}
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  const wf=page.locator('form:has([data-wallet-provider])'),button=await wf.locator('button[type="submit"]').getAttribute('name'),walletBody=await fields(wf,button);
  for(const name of [...walletBody.keys()])if(name.startsWith('currency_')&&!names.includes(name))walletBody.delete(name);
  const account=names.find(name=>name.endsWith('[acc]'));walletBody.set(account,'synthetic-user-bank');walletBody.set('PIN','123456');
  const firstWallet=await post(page.request,'/wallets',walletBody);expect(firstWallet.status(),await firstWallet.text()).toBe(302);
  await page.goto('/wallets');await expect(page.locator('input[name="'+account+'"]:not([type="hidden"])')).toHaveValue('synthetic-user-bank');
  const deposit=await page.request.post('/api/v1/deposit',{headers:{Authorization:'Bearer hs_'+'1'.repeat(64),'Idempotency-Key':'manual-bank-instructions'},data:{currency_id:bankIds.EUR,amount:'3.25'}});expect(deposit.status(),await deposit.text()).toBe(201);
  await page.goto('/operation?id='+(await deposit.json()).data.operation.id);const instructions=page.locator('[data-bank-payment]');await expect(instructions).toContainText('synthetic-bank-EUR');await expect(instructions).toContainText('3.25');await expect(instructions).not.toContainText('synthetic-bank-RUB');

});

test('creation: account parameters are scoped and connection and wallet groups stay compact',async({page})=>{
  await login(page);
  for(const [kind,id,values] of [['WEBMONEY',4,{WMID:'123456789012'}],['YOOMONEY',3,{id:'synthetic-client&<quoted>"',secretpass:'synthetic-client-secret',apipass:'synthetic-access-token'}]]){
    await page.goto('/admin/curr?provider='+kind);let form=page.locator('form[name="legacy_account_save"]');await expect(form).toHaveCount(1);
    for(const [key,value]of Object.entries(values))await form.locator('[name="account_fields['+key+']"]').fill(value);
    const initial=await fields(form,'legacy_account_save_btn'),bad=new URLSearchParams(initial);bad.set('__Cert','0'.repeat(64));expect((await post(page.request,page.url(),bad)).status()).toBe(403);
    const mixed=new URLSearchParams(initial);mixed.set('legacy_add_btn','1');expect((await post(page.request,page.url(),mixed)).status()).toBe(200);
    await page.goto('/admin/curr?provider='+kind);form=page.locator('form[name="legacy_account_save"]');for(const [key,value]of Object.entries(values))await form.locator('[name="account_fields['+key+']"]').fill(value);
    const r=await post(page.request,page.url(),await fields(form,'legacy_account_save_btn'));expect(r.status(),await r.text()).toBe(302);await page.goto(r.headers().location);
    expect(await page.content()).not.toContain('synthetic-access-token');expect(await page.content()).not.toContain('synthetic-client-secret');
    await expect(page.locator('[name="account_fields['+(kind==='WEBMONEY'?'WMID':'id')+']"]')).toHaveValue(kind==='WEBMONEY'?'123456789012':'synthetic-client&<quoted>"');
    await page.goto('/admin/curr?id='+id);form=page.locator('form:has(input[name="cCID"])');
    await expect(form.locator(kind==='WEBMONEY'?'[name="P[WMID]"]':'[name="PAPI[id]"]')).toHaveCount(0);
    const body=await fields(form,await form.locator('button[type="submit"]').last().getAttribute('name'));
    const forged=new URLSearchParams(body);forged.set(kind==='WEBMONEY'?'P[WMID]':'PAPI[id]','forged-old-form');expect((await post(page.request,page.url(),forged)).status()).toBe(200);
    await page.goto('/admin/curr?id='+id);form=page.locator('form:has(input[name="cCID"])');
    const saved=await post(page.request,page.url(),await fields(form,await form.locator('button[type="submit"]').last().getAttribute('name')));expect(saved.status(),await saved.text()).toBe(302);
    await page.goto('/admin/curr?provider='+kind);await expect(page.locator('[name="account_fields['+(kind==='WEBMONEY'?'WMID':'id')+']"]')).toHaveValue(kind==='WEBMONEY'?'123456789012':'synthetic-client&<quoted>"');
    if(kind==='YOOMONEY'){form=page.locator('form[name="legacy_account_save"]');for(const key of ['secretpass','apipass'])await expect(form.locator('[name="account_fields['+key+']"]')).toHaveValue('');const noop=await post(page.request,page.url(),await fields(form,'legacy_account_save_btn'));expect(noop.status(),await noop.text()).toBe(302);}
  }
  await page.goto('/admin/curr?provider=YOOMONEY');let cardForm=page.locator('form[name="legacy_add"]');await cardForm.locator('[name="assets[]"][value="YMC"]').check();const addedCard=await post(page.request,page.url(),await fields(cardForm,'legacy_add_btn'));expect(addedCard.status(),await addedCard.text()).toBe(302);await page.goto(addedCard.headers().location);
  await page.locator('.hs-provider-currency').filter({has:page.locator('[name="assets[]"][value="YMC"]')}).getByRole('link').click();await expect(page.locator('[name="cCID"]')).toHaveValue('YMC');await expect(page.locator('[name^="PAPI["]')).toHaveCount(0);
  await page.goto('/admin/currs');await page.setViewportSize({width:1920,height:1080});const connections=page.locator('.hs-provider-connections a');expect(await connections.count()).toBeGreaterThanOrEqual(5);
  const positions=await connections.evaluateAll(es=>es.slice(0,5).map(e=>({y:e.getBoundingClientRect().y,w:e.getBoundingClientRect().width})));expect(new Set(positions.map(p=>p.y)).size).toBe(1);expect(Math.max(...positions.map(p=>p.w))-Math.min(...positions.map(p=>p.w))).toBeLessThan(1);
  await page.screenshot({path:'/tmp/provider-connections-grid.png',fullPage:true});
  await page.goto('/admin/curr?provider=BANKWIRE');await page.screenshot({path:'/tmp/legacy-currencies.png',fullPage:true});
  await page.setViewportSize({width:390,height:844});await page.goto('/admin/currs');expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  await login(page,'financial-user');await page.goto('/wallets');const tokens=page.locator('[data-wallet-provider="CCAPI"]');await tokens.locator('summary').click();
  await expect(tokens.locator('[data-wallet-network="ethereum"]')).toHaveCount(1);await expect(tokens.locator('[data-wallet-network="tron"]')).toHaveCount(1);
  for(const section of await tokens.locator('[data-wallet-currency]').all()){const names=await section.locator('input:not([type="hidden"])').evaluateAll(es=>es.map(e=>e.name.split('[')[0]));expect(new Set(names).size).toBe(1);}
  await page.locator('[data-wallet-provider="BANKWIRE"] > summary').click();expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);await page.screenshot({path:'/tmp/wallets-currencies-mobile.png',fullPage:true});
  await page.setViewportSize({width:1440,height:1000});await page.evaluate(()=>scrollTo(0,0));await page.screenshot({path:'/tmp/wallets-currencies.png',fullPage:true});
});

test('creation: uniform membership archives and restores original rows without token cascade',async({page})=>{
  await login(page);await page.goto('/admin/curr?provider=BANKWIRE');
  let f=page.locator('form[name="legacy_add"]');await f.locator('[name="assets[]"][value="GBP"]').check();
  let r=await post(page.request,page.url(),await fields(f,'legacy_add_btn'));expect(r.status(),await r.text()).toBe(302);await page.goto(r.headers().location);
  let link=page.getByRole('link',{name:'Настроить GBP',exact:true});const original=new URL(await link.getAttribute('href'),'http://fixture.invalid').searchParams.get('id');
  f=page.locator('form[name="legacy_add"]');await f.locator('[name="assets[]"][value="GBP"]').uncheck();r=await post(page.request,page.url(),await fields(f,'legacy_add_btn'));expect(r.status(),await r.text()).toBe(302);
  await page.goto('/admin/currs?provider=BANKWIRE&state=archived');await expect(page.locator('tbody')).toContainText('#'+original);
  await page.goto('/admin/curr?id='+original);await expect(page.locator('form[name="edit_frm"]')).toHaveCount(0);await expect(page.getByRole('link',{name:'Общее подключение',exact:true})).toBeVisible();
  await page.goto('/admin/curr?provider=BANKWIRE');f=page.locator('form[name="legacy_add"]');await expect(f.locator('[name="assets[]"][value="GBP"]')).not.toBeChecked();await f.locator('[name="assets[]"][value="GBP"]').check();r=await post(page.request,page.url(),await fields(f,'legacy_add_btn'));expect(r.status(),await r.text()).toBe(302);await page.goto(r.headers().location);
  link=page.getByRole('link',{name:'Настроить GBP',exact:true});expect(new URL(await link.getAttribute('href'),'http://fixture.invalid').searchParams.get('id')).toBe(original);await link.click();await expect(page.locator('input[name="cDisabled"][type="checkbox"]')).not.toBeChecked();
  await page.goto('/admin/curr?provider=CCAPI');f=page.locator('#provider-connection-save');await expect(f.locator('[name="token_currencies[]"]')).toHaveCount(0);
  const ethCard=f.locator('.hs-provider-currency').filter({has:page.locator('[name="currencies[]"][value="ETH"]')});const networkUrl=await ethCard.getByRole('link',{name:'Кошельки и токены',exact:true}).getAttribute('href');
  await expect(f.locator('[name="currencies[]"][value="ETH"]')).toBeEnabled();await f.locator('[name="currencies[]"][value="ETH"]').uncheck();r=await post(page.request,page.url(),await fields(f,'provider_save_btn'));expect(r.status(),await r.text()).toBe(302);
  await page.goto(networkUrl);f=page.locator('form[name="provider_token_select"]');await expect(f).toBeVisible();expect(await f.locator('[name="token_currencies[]"]:checked').count()).toBe(2);
  r=await post(page.request,page.url(),await fields(f,'provider_token_select_btn'));expect(r.status(),await r.text()).toBe(302);
  await login(page);await page.goto('/admin/curr?provider=CCAPI');f=page.locator('#provider-connection-save');await f.locator('[name="currencies[]"][value="ETH"]').check();r=await post(page.request,page.url(),await fields(f,'provider_save_btn'));expect(r.status(),await r.text()).toBe(302);
  await page.goto('/');await expect(page.locator('#processed-counter')).toHaveCount(0);await expect(page.locator('#currencies-counter')).toBeVisible();
});

test('payment: archived original token acknowledges only its signed completed receipt',async({page,request})=>{
  const saved=JSON.parse(fs.readFileSync('/tmp/provider-public.json','utf8'));const token=saved.catalog_tokens[0];const replays=JSON.parse(fs.readFileSync('/tmp/token-replay.json','utf8'));const payload=replays.find(p=>p.type==='json'&&p.body.token===token.cCurrID&&p.body.chain===JSON.parse(token.cTokenRoute).network);
  expect(payload).toBeTruthy();await login(page);await page.goto('/admin/curr?provider=CCAPI&network='+JSON.parse(token.cTokenRoute).network);
  let f=page.locator('form[name="provider_token_select"]');await f.locator('[name="token_currencies[]"][value="'+token.cID+'"]').uncheck();const r=await post(page.request,page.url(),await fields(f,'provider_token_select_btn'));expect(r.status(),await r.text()).toBe(302);
  await page.goto('/admin/currs?provider=CCAPI&state=archived');await expect(page.locator('tbody')).toContainText('#'+token.cID);
  await login(page,'financial-user');for(const path of ['/cabinet','/operations','/wallets']){await page.goto(path);const row=page.locator('[data-archived-currency="'+token.cID+'"]');await expect(row,path+' -> '+page.url()).toBeVisible();await expect(row).toContainText('9.000000');await expect(row.locator('input,button,select')).toHaveCount(0);await expect(page.locator('input[name="'+token.cID+'[acc]"]')).toHaveCount(0);}
  await login(page);await page.goto('/operations');await expect(page.locator('[data-archived-currency="'+token.cID+'"]')).toHaveCount(0);
  const exact=await request.post('/balance/status',{data:JSON.stringify(payload.body),headers:{'Content-Type':'application/json'}});expect(exact.status(),await exact.text()).toBe(200);
  const forged=await request.post('/balance/status',{data:JSON.stringify({...payload.body,sign:'0'.repeat(40)}),headers:{'Content-Type':'application/json'}});expect(forged.status(),await forged.text()).toBe(401);
});
