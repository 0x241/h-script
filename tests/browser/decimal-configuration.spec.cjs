const {test,expect}=require('@playwright/test');
const fs=require('node:fs');
const login=async(page)=>{await page.goto('/login');const f=page.locator('form[name="login_frm"]');await f.locator('[name="Login"]').fill('financial-admin');await f.locator('[name="Pass"]').fill('synthetic-financial-password');await f.locator('button[type="submit"]').click();await expect(page).not.toHaveURL(/\/login/);};
const payload=async f=>{const fields=await f.evaluate(e=>Object.fromEntries(new FormData(e)));fields[await f.locator('button[name$="_btn"]').getAttribute('name')]='1';return fields;};
test('currency actual form refuses truncation and preserves each distinct signed range',async({page})=>{
 await login(page);
 for(const change of [{cCASHINMin:'0.0000001'},{cCASHOUTComis:'0.001'},{cTRComis:'10000'},{cEXIn:'10000'},{cEXOut:'-0.0000001'}]) {
  await page.goto('/admin/curr?id=1');const fields={...await payload(page.locator('form:has(input[name="cID"])')),...change};
  const r=await page.request.post('/admin/curr?id=1',{form:fields,maxRedirects:0});expect(r.status()).toBe(200);expect(await r.text()).toContain('недопустимая точность');
 }
 await page.goto('/admin/curr?id=1');const fields={...await payload(page.locator('form:has(input[name="cID"])')),cCASHINMin:'-0,000001',cCASHOUTComis:'9999.99',cEXIn:'-9999.999999',cEXOut:'1e-6',cCASHOUTLimit:'9999999.999999'};
 expect((await page.request.post('/admin/curr?id=1',{form:{...fields,__Cert:'0'.repeat(64)},maxRedirects:0})).status()).toBe(403);
 const r=await page.request.post('/admin/curr?id=1',{form:fields,maxRedirects:0});expect(r.status(),await r.text()).toBe(302);
 await page.goto('/admin/curr?id=1');await expect(page.locator('[name="cCASHINMin"]')).toHaveValue('-0.000001');await expect(page.locator('[name="cEXIn"]')).toHaveValue('-9999.999999');
 fs.writeFileSync('/evidence/session.json',JSON.stringify(await page.context().storageState()));
});
test('calendar actual form rejects excess precision and accepts prior above100 rule',async({page})=>{
 await login(page);
 for(const value of ['1.005','100000','-0.01']) {
  await page.goto('/admin/day?add');const f=page.locator('form:has(input[name="cTS"])');const fields={...await payload(f),cTS:'25.10.2026',cType:'1',cPerc:value};
  const r=await page.request.post('/admin/day?add',{form:fields,maxRedirects:0});expect(r.status()).toBe(200);expect(await r.text()).toContain('точностью до 2');
 }
 await page.goto('/admin/day?add');const fields={...await payload(page.locator('form:has(input[name="cTS"])')),cTS:'25.10.2026',cType:'1',cPerc:'99999,99'};
 const r=await page.request.post('/admin/day?add',{form:fields,maxRedirects:0});expect(r.status(),await r.text()).toBe(302);
});
test('restart preserves exact currency/calendar settings without financial mutation',async({browser})=>{
 const context=await browser.newContext({storageState:'/evidence/session.json'});const page=await context.newPage();
 await page.goto('http://fixture.invalid/admin/curr?id=1');await expect(page.locator('[name="cEXIn"]')).toHaveValue('-9999.999999');
 await page.goto('http://fixture.invalid/admin/days');await expect(page.locator('body')).toContainText('99999.99');await context.close();
});
