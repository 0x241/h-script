const {test,expect}=require('@playwright/test');
const login=async page=>{
  await page.goto('/login');const form=page.locator('form[name="login_frm"]');
  await form.locator('[name="Login"]').fill('financial-user');
  await form.locator('[name="Pass"]').fill('synthetic-financial-password');
  await form.locator('button[type="submit"]').click();
  await expect(page).not.toHaveURL(/\/login/);
};
const calculate=async page=>{
  await page.goto('/calc?sum=1234%2C567891&cmpd=33.33&plan=0');
  await expect(page.locator('#calc_sum')).toHaveValue('1234.57');
  await expect(page.locator('#calc_cmpd')).toHaveValue('33.33');
  const oracle=require('/tests/fixtures/decimal_forecast_cases.json').forecasts.find(c=>c.amount==='1234.567891' && c.bonus==='1.23' && c.compound==='33.33' && c.periods===7);
  await expect(page.locator('body')).toContainText('$ '+oracle.expected.profit_sum);
  await expect(page.locator('body')).toContainText('$ '+oracle.expected.total_return_sum.replace(/\B(?=(\d{3})+\.)/g,' '));
  expect(await page.locator('form[name="calc"] img').count()).toBe(0);
  await expect(page.locator('form[name="calc"]')).toContainText('<img src=x onerror=alert(1)> Exact forecast');
};
test('actual calculator comma input, exact compound choices, grouped results and escaped plan',async({page})=>{
  await login(page);await calculate(page);
  await page.goto('/calc?sum=1e99999999&cmpd=33.330000000000000001');
  await expect(page.locator('#calc_sum')).toHaveValue('1.01');
  await expect(page.locator('#calc_cmpd')).toHaveValue('33.330000000000000001');
  await page.goto('/calc?sum=9007199254740993.005&cmpd=0');
  await expect(page.locator('#calc_sum')).toHaveValue('9007199254740993.01');
});
test('forecast work limit and exact stored principal in deposit detail',async({page})=>{
  await login(page);await page.goto('/calc?plan=1&sum=2');
  await expect(page.locator('body')).toContainText('The parameters exceed calculator limits');
  await page.goto('/deposit?id=10');
  await expect(page.locator('#deposit-progress-live')).toContainText('1234.000001');
});
test('restart keeps the same read-only forecast and original stored principal',async({page})=>{
  await login(page);await calculate(page);await page.goto('/deposit?id=10');
  await expect(page.locator('#deposit-progress-live')).toContainText('1234.000001');
});
