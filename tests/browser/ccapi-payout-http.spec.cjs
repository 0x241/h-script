const {test,expect}=require('@playwright/test');
require('./capture-callbacks.cjs').captureCallbacks(test,'ccapi_v3_outgoing');
const fs=require('node:fs'),crypto=require('node:crypto');
const original='/tmp/ccapi-payout-original.json', secret='synthetic-ccapi-out-secret';
const sign=(p)=>{const b={...p};delete b.sign;return {...b,sign:crypto.createHash('sha1').update(Object.keys(b).sort().map(k=>b[k]).join(':')+':'+crypto.createHash('md5').update(secret).digest('hex')).digest('hex')};};
const event=(id,token='',change={})=>sign({'cryptocurrencyapi.net':'3',chain:'ethereum',currency:'ETH',type:'out',date:String(Math.floor(Date.now()/1000)),from:'0x'+'1'.repeat(40),to:'0x'+'2'.repeat(40),token,tokenContract:token?'0x'+'3'.repeat(40):'',amount:'9.000000',fee:'0.000001',txid:'a'.repeat(64),pos:'0',confirmation:'2',label:String(id),id:String(100000+id),...change});
const login=async(page,name='financial-admin')=>{await page.goto('/login');const f=page.locator('form[name="login_frm"]');await f.locator('[name="Login"]').fill(name);await f.locator('[name="Pass"]').fill('synthetic-financial-password');await f.locator('button[type=submit]').click();await expect(page).toHaveURL(/\/cabinet/);};
const send=(request,url,p)=>request.post(url,{data:JSON.stringify(p),headers:{'Content-Type':'application/json'}});
const safe=async(r)=>{const s=await r.text();expect(s).not.toContain(secret);expect(s).not.toMatch(/<b>(?:Warning|Fatal error|Notice)<\/b>/);return s;};
test('creation: real REST reserves native and token exactly once',async({request})=>{
 const ids=JSON.parse(fs.readFileSync('/tmp/ccapi-payout-ids.json','utf8')), saved={...ids,ops:[]};
 for(const cid of [ids.native,ids.token]){
  const post=()=>request.post('/api/v1/withdraw',{headers:{Authorization:'Bearer hs_'+'1'.repeat(64),'Idempotency-Key':'ccapi-out-'+cid},data:{currency_id:cid,amount:'10'}});
  const results=await Promise.all(Array.from({length:6},post));for(const r of results)expect(r.status(),await safe(r)).toBe(202);
  const id=(await results[0].json()).data.operation.id;for(const r of results)expect((await r.json()).data.operation.id).toBe(id);
  saved.ops.push(id);
 }
 fs.writeFileSync(original,JSON.stringify(saved),{mode:0o600});
});
test('pending: guarded admin status, token contract finality and parallel signed notifications',async({page,request})=>{
 const s=JSON.parse(fs.readFileSync(original,'utf8')),[native,token]=s.ops;
 const url=id=>'/balance/status?its=CCAPI_OUT&operation='+id+'&provider='+s.public+'&version=1';
 expect((await send(request,url(token),event(token,'DAI',{amount:'8.999999'}))).status()).toBe(400);
 expect((await send(request,url(token),event(token,'DAI',{tokenContract:'0x'+'4'.repeat(40)}))).status()).toBe(400);
 const bad=event(token,'DAI');bad.sign='0'.repeat(40);expect((await send(request,url(token),bad)).status()).toBe(400);
 expect((await send(request,url(token),event(token,'DAI',{confirmation:'1'}))).status()).toBe(200);
 await login(page);await page.goto('/admin/oper?id='+token);
 await expect(page.locator('dd').filter({hasText:'0x'+'1'.repeat(40)})).toHaveCount(1);
 expect(await page.content()).not.toContain('0x'+'9'.repeat(40));
 const f=page.locator('form:has(button[name="ccapi_payout_check_btn"])');await expect(f).toHaveCount(1);
 const fields=await f.evaluate(e=>Object.fromEntries(new FormData(e)));fields.ccapi_payout_check_btn='1';
 for(const change of [{session_csrf:'bad'},{__Cert:'0'.repeat(64)}]){const r=await page.request.post('/admin/oper?id='+token,{form:{...fields,...change},maxRedirects:0});expect(r.status(),await safe(r)).toBe(403);}
 const mixed=await page.request.post('/admin/oper?id='+token,{form:{...fields,data_btn:'1'},maxRedirects:0});expect(mixed.status(),await safe(mixed)).toBe(400);
 let checked=await page.request.post('/admin/oper?id='+token,{form:fields,maxRedirects:0});expect(checked.status(),await safe(checked)).toBe(302);
 await page.goto('/admin/oper?id='+token);await expect(page.getByText('Выплата CCAPI: ожидает подтверждения',{exact:true})).toBeVisible();
 await expect(page.locator('form[name="data"]')).toHaveCount(0);
 await page.screenshot({path:'/tmp/ccapi-payout-pending.png',fullPage:true});
 await page.goto('/admin/oper?id='+native);const nf=page.locator('form:has(button[name="ccapi_payout_check_btn"])');const n=await nf.evaluate(e=>Object.fromEntries(new FormData(e)));n.ccapi_payout_check_btn='1';
 checked=await page.request.post('/admin/oper?id='+native,{form:n,maxRedirects:0});expect(checked.status(),await safe(checked)).toBe(302);
 const payload=event(token,'DAI');s.tokenEvent=payload;s.nativeEvent=event(native,'',{pos:'3'});fs.writeFileSync('/tmp/ccapi-payout-completed.json',JSON.stringify(s),{mode:0o600});
 for(const r of await Promise.all(Array.from({length:8},()=>send(request,url(token),payload))))expect(r.status(),await safe(r)).toBe(200);
 expect((await send(request,url(native),s.nativeEvent)).status()).toBe(200);
 await page.goto('/admin/oper?id='+token);await expect(page.getByText('Выплата CCAPI: подтверждена',{exact:true})).toBeVisible();
});
test('restart: cached completion and exact out replay retain permanent proof',async({page,request})=>{
 const s=JSON.parse(fs.readFileSync(original,'utf8'));
 for(const [i,p]of [[0,s.nativeEvent],[1,s.tokenEvent]]){const r=await send(request,'/balance/status?its=CCAPI_OUT&operation='+s.ops[i]+'&provider='+s.public+'&version=1',p);expect(r.status(),await safe(r)).toBe(200);}
 await login(page);await page.goto('/admin/curr?id='+s.native);
 await expect(page.locator('html')).toHaveAttribute('lang','en');
 await expect(page.locator('select[name=cCASHOUTMode]')).toHaveValue('2');
 await expect(page.locator('select[name=cCASHOUTMode] option[value="2"]')).toHaveText('instant');
 await page.screenshot({path:'/tmp/ccapi-payout-settings.png',fullPage:true});
 await page.goto('/admin/oper?id='+s.ops[1]);await expect(page.getByText('Выплата CCAPI: подтверждена',{exact:true})).toBeVisible();
});
