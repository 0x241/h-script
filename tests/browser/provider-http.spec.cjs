const {test,expect}=require('@playwright/test');
const crypto=require('node:crypto'),fs=require('node:fs');
const login=async(page,name='provider-admin')=>{
  await page.context().clearCookies();await page.context().route('**/*',r=>new URL(r.request().url()).hostname==='fixture.invalid'?r.continue():r.abort());
  await page.goto('/login');const f=page.locator('form[name="login_frm"]');await f.locator('[name="Login"]').fill(name);await f.locator('[name="Pass"]').fill('synthetic-financial-password');await f.locator('button[type="submit"]').click();await expect(page).toHaveURL(/\/cabinet/);
};
const fields=async(f,b)=>new URLSearchParams([...(await f.evaluate(e=>[...new FormData(e).entries()])),[b,'1']]);
const post=(request,url,body)=>request.post(url,{data:body.toString(),headers:{'Content-Type':'application/x-www-form-urlencoded'},maxRedirects:0});
const send=(request,event,bad=false)=>{const raw=JSON.stringify(event),t=Math.floor(Date.now()/1000);return request.post('/balance/status?its=ST',{data:raw,headers:{'Content-Type':'application/json','Stripe-Signature':'t='+t+',v1='+(bad?'0'.repeat(64):crypto.createHmac('sha256','whsec_HTTPfixture123').update(t+'.'+raw).digest('hex'))}});};
test('creation: shared Stripe setup, provider catalog and preserved native currency choices',async({page,request})=>{
  for(const url of ['/tests/fixtures/provider_http_prepend.php','/module/balance/admin/curr.provider.php','/tests/financial_shared_provider.php'])expect([403,404]).toContain((await request.get(url)).status());
  expect([302,403]).toContain((await request.get('/admin/curr?provider=STRIPE',{maxRedirects:0})).status());
  await login(page,'financial-user');expect([302,403]).toContain((await page.request.get('/admin/curr?provider=STRIPE',{maxRedirects:0})).status());
  await login(page);await page.goto('/admin/curr?add');const add=page.locator('form:has(select[name="PSys"])');
  await expect(add.locator('option')).toHaveCount(11);await expect(add.locator('option[value="STRIPE"]')).toHaveText('Stripe');
  expect(await page.content()).not.toContain('ePayCore');
  for(const old of ['BNBAPI','ETHERAPI','XRPAPI','TRONAPI'])await expect(add.locator('option[value="'+old+'"]')).toHaveCount(0);
  for(const [kind,count] of [['CCAPI',12],['VOLET',11]]) {
    await page.goto('/admin/curr?provider='+kind);await expect(page.locator('input[name="currencies[]"]')).toHaveCount(count);
    if(kind==='CCAPI')await expect(page.getByText('Партнёрская программа',{exact:true})).toBeVisible();
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
  const chips=page.locator('.hs-provider-filters');await expect(chips.locator('[aria-current="page"]')).toHaveText('Stripe');
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
    await login(page);await page.goto('/admin/curr?provider='+kind);const f=page.locator('form:has(input[name="currencies[]"])');
    if(kind==='CCAPI')await f.locator('[name="credentials[v3_key]"]').fill('synthetic-token-secret');
    else for(const [field,value] of Object.entries({merchant_id:'100',merchant_secret:'synthetic-token-sci',api_id:'200',api_secret:'synthetic-token-api'}))await f.locator('[name="credentials['+field+']"]').fill(value);
    if(kind==='CCAPI'){await f.locator('[name="currencies[]"][value="ETH"]').check();await f.locator('[name="currencies[]"][value="TRX"]').check();}else await f.locator('[name="currencies[]"][value="USDT"]').check();let r=await post(page.request,page.url(),await fields(f,'provider_save_btn'));expect(r.status(),await r.text()).toBe(302);
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
      await page.locator('[data-network-token]').filter({has:page.getByText('DAI',{exact:true})}).click();
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
  await page.goto('/');await expect(page.locator('body')).toContainText('10');
  fs.writeFileSync('/tmp/provider-replay.json',JSON.stringify(event));
});
test('restart: original signed session replays after actual application restart',async({request})=>{
  const event=JSON.parse(fs.readFileSync('/tmp/provider-replay.json','utf8'));const r=await send(request,event);expect(r.status(),await r.text()).toBe(200);expect(await r.text()).toBe('OK');
});

test('restart: token routes retain original binding and credit exactly once',async({request})=>{for(const p of JSON.parse(fs.readFileSync('/tmp/token-replay.json','utf8'))){const r=await request.post('/balance/status',p.type==='json'?{data:JSON.stringify(p.body),headers:{'Content-Type':'application/json'}}:{form:p.body});expect(r.status(),await r.text()).toBe(200);}});
