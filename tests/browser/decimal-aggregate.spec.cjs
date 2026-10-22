const {test,expect}=require('@playwright/test');
const login=async(page,user)=>{
  await page.goto('/login'); const form=page.locator('form[name="login_frm"]');
  await form.locator('[name="Login"]').fill(user);
  await form.locator('[name="Pass"]').fill('synthetic-financial-password');
  await form.locator('button[type="submit"]').click();
  await expect(page).not.toHaveURL(/\/login/);
};
const statistics=async page=>{
  await login(page,'financial-admin'); await page.goto('/admin');
  await expect(page.locator('body')).toContainText('10 010 000 000.00');
  await page.goto('/admin/depo/info');
  const total=page.locator('details tbody tr').filter({has:page.locator('td', {hasText:'USD'})}).last();
  await expect(total).toContainText('10009999999.998999');
  const disabled=page.locator('details tbody tr').filter({hasText:'Disabled historical USD'});
  await expect(disabled).toContainText('2.005001');
  await expect(disabled).toContainText('10009999999.998999');
  const form=page.locator('form').filter({has:page.locator('[name="D1"]')});
  await form.locator('[name="D1"]').fill('2025-01-01');
  await form.locator('[name="D2"]').fill('2025-01-01');
  await form.locator('button[type="submit"]').click();
  const results=page.locator('table').last();
  await expect(results.locator('tr').filter({hasText:'Пополнение'})).toContainText('10009999999.998999');
  await expect(results.locator('tr').last()).toContainText('10009999996.993998');
  await form.locator('[name="PSys"]').selectOption('4');
  await form.locator('button[type="submit"]').click();
  await expect(page.locator('table').last()).toContainText('10009999999.998999');
};
test('actual admin dashboard/history/disabled currency/date selection retain exact totals', async({page})=>{await statistics(page);});
test('actual cabinet read renders original six-place SQL operations',async({page})=>{
  await login(page,'financial-user');await page.goto('/cabinet');
  await expect(page.locator('body')).toContainText('0.000001');
});
test('restart retains exact administrator aggregates and period selection',async({page})=>{await statistics(page);});
