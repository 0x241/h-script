const {test,expect}=require('@playwright/test');
const fs=require('node:fs');
const login=async(page,user)=>{await page.goto('/login');const f=page.locator('form[name="login_frm"]');await f.locator('[name="Login"]').fill(user);await f.locator('[name="Pass"]').fill('synthetic-financial-password');await f.locator('button[type="submit"]').click();await expect(page).not.toHaveURL(/\/login/);};
test('initial native deposit and AJAX keep full precision until final display',async({page})=>{
 await login(page,'financial-user');await page.goto('/deposit?add=DEPO');await expect(page.locator('#deposit-profit-summary')).toContainText('0.00');
 const response=await page.request.get('/ajax?module=depo&do=calc&Plan=1&Sum=0.49');expect(response.status()).toBe(200);
 const html=await response.text();expect(html).toContain('+0.00');expect(html).toContain('+0.49');expect(html).toContain('0.98');
 await page.locator('[name="Sum"]').fill('0.49');await expect(page.locator('#deposit-profit-summary')).toContainText('+0.49');
 const malformed=await page.request.get('/ajax?module=depo&do=calc&Plan=1&Sum=1e999999');expect(await malformed.text()).toContain('0.00');
 fs.writeFileSync('/evidence/user.json',JSON.stringify(await page.context().storageState()));
});
test('collector landing and actual collector Twig preserve wide exact cents',async({page})=>{
 await login(page,'financial-admin');await page.goto('/');await expect(page.locator('body')).toContainText('9,007,199,254,740,993.01');
 await page.goto('/admin/setup/collector');await expect(page.locator('body')).toContainText('900 719 925 474 099.30');await expect(page.locator('body')).toContainText('123 456 789 012.12');
 fs.writeFileSync('/evidence/admin.json',JSON.stringify(await page.context().storageState()));
});
test('restart retains exact public totals and simple AJAX preview',async({browser})=>{
 const c=await browser.newContext({storageState:'/evidence/admin.json'});const p=await c.newPage();await p.goto('http://fixture.invalid/');await expect(p.locator('body')).toContainText('9,007,199,254,740,993.01');await p.goto('http://fixture.invalid/admin/setup/collector');await expect(p.locator('body')).toContainText('123 456 789 012.12');await c.close();
 const u=await browser.newContext({storageState:'/evidence/user.json'});const a=await u.request.get('http://fixture.invalid/ajax?module=depo&do=calc&Plan=1&Sum=0.49');expect(await a.text()).toContain('+0.49');await u.close();
});
