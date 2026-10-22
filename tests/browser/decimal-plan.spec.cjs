const {test,expect}=require('@playwright/test');
const fs=require('node:fs');
const login=async(page,user='financial-admin')=>{
  await page.goto('/login');const f=page.locator('form[name="login_frm"]');
  await f.locator('[name="Login"]').fill(user);await f.locator('[name="Pass"]').fill('synthetic-financial-password');
  await f.locator('button[type="submit"]').click();await expect(page).not.toHaveURL(/\/login/);
};
const payload=async(f,button='button[name$="_btn"]')=>{
  const v=await f.evaluate(el=>Object.fromEntries(new FormData(el)));v[await f.locator(button).getAttribute('name')]='1';return v;
};
test('native plan rejects SQL rounding/overflow and preserves exact signed fields',async({page})=>{
  await login(page);
  const money={pName:'Decimal native form',pMin:'0,010001',pMax:'1e3',pPerc:'1.2300',pReturn:'-1',pBonus:'-0.01',pMinAdd:'-0.000001',pCompndMin:'33.33',pCompndMax:'33.33',pClComis:'100',pPer:'24',pNPer:'10'};
  for(const change of [{pMin:'0.0100001'},{pMax:'10000000'},{pPerc:'1.235'},{pBonus:'100000'},{pMinAdd:'0.0000001'},{pCompndMin:'33.335'},{pClComis:'100.01'}]) {
    await page.goto('/admin/plan?add');const fields={...await payload(page.locator('form:has(input[name="pName"])')),...money,...change};
    const response=await page.request.post('/admin/plan?add',{form:fields,maxRedirects:0});
    expect(response.status()).toBe(200);expect(await response.text()).toContain('text-red-');
  }
  await page.goto('/admin/plan?add');let fields={...await payload(page.locator('form:has(input[name="pName"])')),...money};
  expect((await page.request.post('/admin/plan?add',{form:{...fields,__Cert:'0'.repeat(64)},maxRedirects:0})).status()).toBe(403);
  const valid=await page.request.post('/admin/plan?add',{form:fields,maxRedirects:0});expect(valid.status(),await valid.text()).toBe(302);
  await page.goto(valid.headers().location);await page.goto('/admin/plan?id=3');
  await expect(page.locator('[name="pMin"]')).toHaveValue('0.010001');await expect(page.locator('[name="pBonus"]')).toHaveValue('-0.01');
});
test('native manual new precision guard rolls back key and original minimal receipt replays',async({page})=>{
  await login(page);await page.goto('/admin/charge');
  const fields=await payload(page.locator('form:has(input[name="ids[]"])'),'button[name$="_btndel"]');fields['ids[]']='2';fields['p[2]']='2.005';
  expect((await page.request.post('/admin/charge',{form:fields,maxRedirects:0})).status()).toBe(422);
  fields['p[2]']='2.50';const valid=await page.request.post('/admin/charge',{form:fields,maxRedirects:0});
  expect(valid.status(),await valid.text()).toBe(302);expect(valid.headers().location).toContain('done=1');
  const replay=await page.request.post('/admin/charge',{form:{...fields,'p[2]':'2.5e0'},maxRedirects:0});expect(replay.status()).toBe(302);expect(replay.headers().location).toBe(valid.headers().location);
  expect((await page.request.post('/admin/charge',{form:{...fields,'p[2]':'2.51'},maxRedirects:0})).status()).toBe(409);
  fs.writeFileSync('/evidence/manual.json',JSON.stringify({fields,location:valid.headers().location}));
  fs.writeFileSync('/evidence/session.json',JSON.stringify(await page.context().storageState()));
});
test('restart retains original manual form receipt and exact plan SQL strings',async({browser})=>{
  const saved=JSON.parse(fs.readFileSync('/evidence/manual.json'));
  const context=await browser.newContext({storageState:'/evidence/session.json'});const page=await context.newPage();
  const response=await page.request.post('http://fixture.invalid/admin/charge',{form:saved.fields,maxRedirects:0});
  expect(response.status()).toBe(302);expect(response.headers().location).toBe(saved.location);
  await page.goto('http://fixture.invalid/admin/plan?id=3');await expect(page.locator('[name="pMin"]')).toHaveValue('0.010001');
  await context.close();
});
