const { test, expect } = require('@playwright/test');
const password = 'synthetic-login-policy-password';
const login = async (page, name, pass = password, successful = true) => {
  await page.context().clearCookies();
  await page.goto('/login');
  const form = page.locator('form[name="login_frm"]');
  await form.locator('[name="Login"]').fill(name);
  await form.locator('[name="Pass"]').fill(pass);
  await form.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(successful ? /\/cabinet(?:\?|$)/ : /\/login(?:\?|$)/);
};
const fields = async (form) => form.evaluate(element => Object.fromEntries(new FormData(element)));
const ownerForm = page => page.locator('form:has(input[name="aFailedLoginThreshold"])').first();
const saveOwner = async (page, value, extra = {}) => {
  await page.goto('/account?tab=security');
  const form = ownerForm(page);
  await form.locator('[name="aFailedLoginThreshold"]').fill(value);
  const data = { ...(await fields(form)), ...extra };
  // FormData omits submit buttons; the controller's normal form trigger is required.
  data[await form.locator('button[type="submit"]').last().getAttribute('name')] = '1';
  return page.request.post('/account?tab=security', { form: data, maxRedirects: 0 });
};

test('owner settings enforce range, inheritance, CSRF and account ownership, and preserve failures', async ({ page, browser }) => {
  await login(page, 'policy-user');
  await page.goto('/account?tab=security');
  const form = ownerForm(page);
  await expect(form.locator('[name="aFailedLoginThreshold"]')).toHaveValue('0');
  await expect(form.locator('[name="aFailedLoginThreshold"]')).toHaveAttribute('type', 'number');
  const widths = await form.evaluate(element => ['aTimeOut', 'aFailedLoginThreshold'].map(name => element.querySelector('[name="' + name + '"]').getBoundingClientRect().width));
  expect(Math.abs(widths[0] - widths[1])).toBeLessThan(1);
  expect(await form.locator('[name="aFailedLoginThreshold"]').evaluate(element => Boolean(element.compareDocumentPosition(document.querySelector('[name="aTimeOut"]')) & Node.DOCUMENT_POSITION_PRECEDING))).toBe(true);
  await expect(form.locator('[data-login-policy]')).toHaveAttribute('data-global', '3');
  await expect(form.locator('[data-login-policy]')).toHaveAttribute('data-effective', '3');
  const forged = await fields(form);
  forged[await form.locator('button[type="submit"]').last().getAttribute('name')] = '1';
  forged.aFailedLoginThreshold = '1';
  delete forged.__Cert;
  expect((await page.request.post('/account?tab=security', { form: forged })).status()).toBe(403);
  const wrongCert = { ...forged, __Cert: 'invalid' };
  expect((await page.request.post('/account?tab=security', { form: wrongCert })).status()).toBe(403);
  expect((await saveOwner(page, '100', { auID: '5', uID: '5', uBFC: '0' })).status()).toBe(302);
  await page.goto('/account?tab=security');
  await expect(ownerForm(page).locator('[name="aFailedLoginThreshold"]')).toHaveValue('100');
  await expect(ownerForm(page).locator('[data-login-policy]')).toHaveAttribute('data-effective', '3');
  const invalid = await saveOwner(page, '101');
  expect(invalid.status()).toBe(200);
  expect(await invalid.text()).toContain('Enter a whole number from 0 to 100');
  expect((await saveOwner(page, '0')).status()).toBe(302);
  await page.goto('/account?tab=security');
  await expect(ownerForm(page).locator('[name="aFailedLoginThreshold"]')).toHaveValue('0');

  const attackContext = await browser.newContext();
  try {
    const attacker = await attackContext.newPage();
    await login(attacker, 'policy-user', 'wrong-password', false);
    await login(attacker, 'policy-user', 'wrong-password', false);
    expect((await saveOwner(page, '2')).status()).toBe(302);
    await login(attacker, 'policy-user', password, false);
    await expect(attacker).toHaveURL(/\/login\?brute_force/);
    await expect(attacker.locator('body')).toContainText('confirm');
  } finally { await attackContext.close(); }
  // A signed-in account cannot gain access to an administrator's AddInfo controller.
  const unauthorized = await page.request.post('/admin/account/user/addinfo?id=5', { form: { auID: '5', aFailedLoginThreshold: '1' }, maxRedirects: 0 });
  expect(unauthorized.status()).toBe(302);
});

test('administrator saves respect roles, CSRF, demo boundaries and the independent counter', async ({ page }) => {
  await login(page, 'policy-admin');
  await page.goto('/admin/account/user/addinfo?id=5');
  const form = ownerForm(page);
  await expect(form.locator('[name="aFailedLoginThreshold"]')).toHaveValue('0');
  await expect(form.locator('[name="aFailedLoginThreshold"]')).toHaveAttribute('min', '0');
  await expect(form.locator('[name="aFailedLoginThreshold"]')).toHaveAttribute('max', '100');
  const data = await fields(form);
  data[await form.locator('button[type="submit"]').last().getAttribute('name')] = '1';
  expect((await page.request.post('/admin/account/user/addinfo?id=5', { form: { ...data, __Cert: 'invalid', aFailedLoginThreshold: '100' } })).status()).toBe(403);
  await page.goto('/admin/account/user/addinfo?id=5');
  const fresh = ownerForm(page);
  const valid = await fields(fresh);
  valid[await fresh.locator('button[type="submit"]').last().getAttribute('name')] = '1';
  const saved = await page.request.post('/admin/account/user/addinfo?id=5', { form: { ...valid, aFailedLoginThreshold: '100', uBFC: '0' }, maxRedirects: 0 });
  expect(saved.status()).toBe(302);
  await page.goto('/admin/account/user/addinfo?id=5');
  await expect(ownerForm(page).locator('[name="aFailedLoginThreshold"]')).toHaveValue('100');
  await expect(page.locator('body')).toContainText('3 (Global threshold: 3)');
  await expect(page.locator('label[for$="_LoginPolicy"]')).toHaveCount(0);

  for (const value of ['0', '100']) {
    await page.goto('/admin/account/user/addinfo?id=5');
    const inherit = await fields(ownerForm(page));
    inherit[await ownerForm(page).locator('button[type="submit"]').last().getAttribute('name')] = '1';
    expect((await page.request.post('/admin/account/user/addinfo?id=5', { form: { ...inherit, aFailedLoginThreshold: value }, maxRedirects: 0 })).status()).toBe(302);
    await page.goto('/admin/account/user/addinfo?id=5');
    await expect(ownerForm(page).locator('[name="aFailedLoginThreshold"]')).toHaveValue(value || '0');
  }

  for (const target of ['7', '8']) {
    await page.goto('/admin/account/user/addinfo?id=5');
    const posted = await fields(ownerForm(page));
    posted[await ownerForm(page).locator('button[type="submit"]').last().getAttribute('name')] = '1';
    expect((await page.request.post('/admin/account/user/addinfo?id=5', { form: { ...posted, auID: target, aFailedLoginThreshold: '1' } })).status()).toBe(403);
  }
  await page.goto('/admin/account/user/addinfo?id=2');
  const demo = await fields(ownerForm(page));
  demo[await ownerForm(page).locator('button[type="submit"]').last().getAttribute('name')] = '1';
  const denied = await page.request.post('/admin/account/user/addinfo?id=2', { form: { ...demo, aFailedLoginThreshold: '1' }, maxRedirects: 0 });
  expect(denied.status()).toBe(302);
  expect(denied.headers().location).toContain('/admin/account/user/addinfo');
});

test('demo owner cannot change a protected demo account', async ({ page }) => {
  await login(page, 'policy-demo');
  const response = await saveOwner(page, '1');
  expect(response.status()).toBe(302);
  await page.goto('/account?tab=security');
  await expect(ownerForm(page).locator('[name="aFailedLoginThreshold"]')).toHaveValue('0');
});


test('global zero is shown as disabled and a personal setting opts in', async ({ page }) => {
  await login(page, 'policy-owner');
  await page.goto('/admin/setup/security');
  const form = page.locator('form:has(input[name="BFC"])').first();
  const data = await fields(form);
  data[await form.locator('button[type="submit"]').last().getAttribute('name')] = '1';
  expect((await page.request.post('/admin/setup/security', { form: { ...data, BFC: '0' }, maxRedirects: 0 })).status()).toBe(302);
  await page.goto('/account?tab=security');
  await expect(ownerForm(page).locator('[data-login-policy]')).toHaveAttribute('data-global', '0');
  await expect(ownerForm(page).locator('[data-login-policy]')).toHaveAttribute('data-effective', '0');
  await expect(ownerForm(page).locator('[data-login-policy]')).toContainText('Disabled');
  expect((await saveOwner(page, '1')).status()).toBe(302);
  await page.goto('/account?tab=security');
  await expect(ownerForm(page).locator('[data-login-policy]')).toHaveAttribute('data-global', '0');
  await expect(ownerForm(page).locator('[data-login-policy]')).toHaveAttribute('data-effective', '1');
  expect((await saveOwner(page, '0')).status()).toBe(302);
});

test('operation filters paginate all matching records, keep the filter and preserve account ownership', async ({ page }) => {
  await login(page, 'ui-user');
  await page.goto('/operations');
  const tabs = page.getByRole('navigation', { name: 'Transaction history' });
  const rows = page.locator('tr[data-operation]');
  for (const [caption, type, allowed] of [['Deposits', 'in', ['CASHIN']], ['Withdrawals', 'out', ['CASHOUT']], ['Accruals', 'calc', ['CALCIN', 'REF', 'BONUS']]]) {
    await tabs.getByRole('link', { name: caption, exact: true }).click();
    await expect(page).toHaveURL(new RegExp('type=' + type + '&page=1$'));
    await expect(rows).toHaveCount(10);
    expect((await rows.evaluateAll(elements => elements.map(element => element.dataset.operation))).every(operation => allowed.includes(operation))).toBe(true);
    await expect(tabs.getByRole('link', { name: caption, exact: true })).toHaveAttribute('aria-current', 'page');
    const pager = page.locator('nav').last();
    expect(await pager.innerText()).not.toMatch(/&(?:gt|lt);/);
    const firstIds = await rows.locator('td:first-child a').evaluateAll(elements => elements.map(element => element.href));
    await pager.getByRole('link', { name: 'Page 2', exact: true }).first().click();
    await expect(page).toHaveURL(new RegExp('type=' + type + '&page=2$'));
    expect((await rows.locator('td:first-child a').evaluateAll(elements => elements.map(element => element.href))).some(id => firstIds.includes(id))).toBe(false);
    expect((await rows.evaluateAll(elements => elements.map(element => element.dataset.operation))).every(operation => allowed.includes(operation))).toBe(true);
    await expect(page.locator('body')).not.toContainText('Other account operation');
  }
  await tabs.getByRole('link', { name: 'All', exact: true }).click();
  await expect(page).toHaveURL(/type=all&page=1$/);
  expect((await rows.evaluateAll(elements => elements.map(element => element.dataset.operation))).includes('TR')).toBe(true);
  await page.goto('/operations?type[]=out&ouID=5&page=1');
  await expect(tabs.getByRole('link', { name: 'All', exact: true })).toHaveAttribute('aria-current', 'page');
  await expect(page.locator('body')).not.toContainText('Other account operation');
});

test('manual cron requires administrator, CSRF and demo authorization, and uses the authenticated POST form', async ({ page }) => {
  await login(page, 'ui-user');
  expect((await page.request.post('/admin/cron', { form: { cron_admin_setup_frm_btnrun: '1' }, maxRedirects: 0 })).status()).toBe(302);
  expect((await page.request.get('/cron')).status()).toBe(403);
  expect((await page.request.post('/cron')).status()).toBe(403);
  await login(page, 'policy-admin');
  await page.goto('/admin/cron');
  let form = page.locator('[data-cron-run]');
  let data = await fields(form);
  data[await form.locator('button').getAttribute('name')] = '1';
  expect((await page.request.post('/admin/cron', { form: data })).status()).toBe(403);
  await login(page, 'policy-owner');
  await page.goto('/admin/cron');
  form = page.locator('[data-cron-run]');
  data = await fields(form);
  data[await form.locator('button').getAttribute('name')] = '1';
  expect((await page.request.post('/admin/cron', { form: { ...data, __Cert: 'invalid' } })).status()).toBe(403);
  await page.goto('/admin/cron');
  form = page.locator('[data-cron-run]');
  const replay = await fields(form);
  replay[await form.locator('button').getAttribute('name')] = '1';
  await form.getByRole('button', { name: 'Вызвать вручную' }).click();
  await expect(page.locator('[data-cron-result]')).toHaveAttribute('data-cron-result', 'success');
  for (const module of ['balance', 'depo', 'integrity', 'telemetry'])
    await expect(page.locator('[data-cron-module="' + module + '"]')).toContainText('Только что');
  expect((await page.request.post('/admin/cron', { form: replay })).status()).toBe(403);
  expect((await page.request.post('/cron', { headers: { 'X-HScript-Cron-Token': 'synthetic-isolated-cron-token-00000001' } })).status()).toBe(200);
  await page.goto('/admin/cron');
  const settings = page.locator('form:has(input[name="Enabled"])');
  const forgedTimers = await fields(settings);
  forgedTimers[await settings.locator('button[type="submit"]').getAttribute('name')] = '1';
  expect((await page.request.post('/admin/cron', { form: { ...forgedTimers, Enabled: '1', balance: '0', depo: '0', integrity: '0', telemetry: '0' }, maxRedirects: 0 })).status()).toBe(302);
  await page.goto('/admin/cron');
  await settings.locator('[name="Enabled"]').uncheck();
  const [settingsResponse] = await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/admin/cron') && response.request().method() === 'POST'),
    settings.locator('button[type="submit"]').click()
  ]);
  expect(settingsResponse.status()).toBe(302);
  await page.goto('/admin/cron');
  await expect(settings.locator('[name="Enabled"]')).not.toBeChecked();
  await page.locator('[data-cron-run]').getByRole('button', { name: 'Вызвать вручную' }).click();
  await expect(page.locator('[data-cron-result]')).toHaveAttribute('data-cron-result', 'disabled');
});
