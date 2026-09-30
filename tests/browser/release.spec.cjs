const { test, expect } = require('@playwright/test');

async function chooseConfiguratorLanguage(page, language) {
  const form = page.locator(`form:has(input[name="cfg_preference"][value="lang"]):has(input[name="value"][value="${language}"])`);
  await form.locator('xpath=ancestor::details/summary').click();
  await form.locator('button').click();
}

test('configurator password change verifies the current password and confirmation', async ({ page }) => {
  test.skip(test.info().project.name !== 'chromium', 'Password change uses the shared disposable configurator fixture');
  const original = 'synthetic-isolated-configurator';
  const replacement = 'synthetic-phase4-configurator-replacement';
  await page.goto('/_cfg?login');
  const login = page.locator('form:has(input[name="pass"]):has(button[name="bLogin"])');
  await login.locator('[name="pass"]').fill(original);
  await login.locator('[name="bLogin"]').click();
  try { await page.waitForURL(url => !url.search.includes('login'), { timeout: 5000 }); }
  catch { throw new Error(`Configurator login failed: ${(await page.locator('body').innerText()).slice(-500)}`); }

  const attempt = async (currentPass, newPass, confirmPass) => {
    await page.goto('/_cfg?pass');
    const form = page.locator('form:has(input[name="newPass"]):has(button[name="bSave"])');
    const csrf = await form.locator('[name="csrf"]').inputValue();
    return page.request.post('/_cfg?pass', {
      form: { csrf, currentPass, newPass, confirmPass, bSave: '1' }, maxRedirects: 0,
    });
  };

  await page.goto('/_cfg?pass');
  const form = page.locator('form:has(input[name="newPass"]):has(button[name="bSave"])');
  await expect(form.locator('input[name="currentPass"][required][autocomplete="current-password"]')).toBeVisible();
  await expect(form.locator('input[name="confirmPass"][required][autocomplete="new-password"]')).toBeVisible();

  expect((await attempt('wrong-current', replacement, replacement)).status()).toBe(302);
  await page.goto('/_cfg?pass');
  await expect(page.getByRole('alert').filter({ hasText: 'Current password is incorrect.' })).toBeVisible();

  expect((await attempt(original, replacement, 'mismatched-confirmation')).status()).toBe(302);
  await page.goto('/_cfg?pass');
  await expect(page.getByRole('alert').filter({ hasText: 'New passwords do not match.' })).toBeVisible();

  expect((await attempt(original, replacement, '')).status()).toBe(302);
  await page.goto('/_cfg?pass');
  await expect(page.getByRole('alert').filter({ hasText: 'New passwords do not match.' })).toBeVisible();

  const valid = page.locator('form:has(input[name="newPass"]):has(button[name="bSave"])');
  await valid.locator('[name="currentPass"]').fill(original);
  await valid.locator('[name="newPass"]').fill(replacement);
  await valid.locator('[name="confirmPass"]').fill(replacement);
  await valid.locator('[name="bSave"]').click();
  await expect(page).toHaveURL(/\?setup$/);

  expect((await attempt(original, original, original)).status()).toBe(302);
  await page.goto('/_cfg?pass');
  await expect(page.getByRole('alert').filter({ hasText: 'Current password is incorrect.' })).toBeVisible();
  expect((await attempt(replacement, original, original)).status()).toBe(302);
  await page.goto('/_cfg?pass');
  await expect(page.getByRole('status').filter({ hasText: 'Password saved!' })).toBeVisible();
});

test('configurator shares translations across login and authenticated sections', async ({ page }) => {
  await page.goto('/_cfg?login');
  await chooseConfiguratorLanguage(page, 'ru');
  await expect(page.locator('html')).toHaveAttribute('lang', 'ru');
  await expect(page.locator('h1')).toHaveText('Вход в конфигуратор');
  await page.locator('input[name="pass"]').fill('synthetic-wrong-password');
  await page.locator('[name="bLogin"]').click();
  await expect(page.getByText('Неверный пароль', { exact: true })).toBeVisible();
  await chooseConfiguratorLanguage(page, 'en');
  await expect(page.locator('h1')).toHaveText('Configurator sign in');
  await page.locator('input[name="pass"]').fill('synthetic-isolated-configurator');
  await Promise.all([
    page.waitForURL(url => !url.search.includes('login')),
    page.locator('[name="bLogin"]').click(),
  ]);
  for (const section of ['modules', 'security', 'backup', 'update', 'setup', 'pass']) {
    const response = await page.goto(`/_cfg?${section}`);
    expect(response.status()).toBe(200);
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.locator('#cfg-sidebar')).toBeAttached();
    expect(await page.locator('body').innerText()).not.toMatch(/configurator\.|[А-Яа-яЁё]/);
  }
  await verifyIntegrity(page);
  await page.goto('/_cfg?backup');
  await chooseConfiguratorLanguage(page, 'ru');
  await expect(page.locator('html')).toHaveAttribute('lang', 'ru');
  await expect(page.locator('body')).not.toContainText('configurator.');
});

async function verifyIntegrity(page) {
  await page.goto('/_cfg?security');
  const replies = [];
  await page.route('**/_cfg?security', async route => {
    if (route.request().method() !== 'POST') return route.continue();
    const response = await route.fetch();
    if (response.headers()['content-type']?.includes('application/json')) replies.push(await response.json());
    await route.fulfill({ response });
  });
  await page.locator('[data-integrity-scan] button').click();
  await expect.poll(() => replies.some(r => r.status === 'completed'), { timeout: 25000 }).toBe(true);
  expect(replies.some(r => r.status === 'running')).toBe(true);
  expect(replies.at(-1).checked).toBe(replies.at(-1).total);
  expect(new Set(replies.map(r => r.csrf)).size).toBe(replies.length);
  const invalid = await page.request.post('/_cfg?security', {
    headers: { Accept: 'application/json' }, form: { securityAction: 'continue', csrf: 'invalid' },
  });
  expect(invalid.status()).toBe(409);
  expect((await invalid.json()).error).toBeTruthy();
  await expect(page.locator('[data-integrity-scan]')).toHaveAttribute('data-running', '0');
  await page.unroute('**/_cfg?security');
}

test('public pages and mobile login render with installed CSS/JS', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const response = await page.goto('/');
  expect(response.status()).toBe(200);
  await expect(page.locator('body')).not.toBeEmpty();
  expect(await page.locator('link[rel="stylesheet"]').count()).toBeGreaterThan(0);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/login');
  await expect(page.locator('link[href^="static/css/app.css"]')).toHaveCount(1);
  const css = await page.request.get('/static/css/app.css');
  expect(css.status()).toBe(200);
  expect(css.headers()['content-type']).toContain('text/css');
  expect((await css.body()).length).toBeGreaterThan(1000);
  await expect(page.locator('main')).toHaveCSS('display', 'flex');
  await expect(page.locator('form[name="login_frm"] input[name="Login"]')).toBeVisible();
  await expect(page.locator('input[name="Pass"]')).toBeVisible();
  expect(await page.evaluate(() => typeof window.htmx?.ajax)).toBe('function');
  expect(errors).toEqual([]);
});

test('login rejects bad password and preserves authenticated session', async ({ page }) => {
  await page.goto('/login');
  const form = page.locator('form[name="login_frm"]');
  await form.locator('[name="Login"]').fill('admin');
  await form.locator('[name="Pass"]').fill('synthetic-wrong-password');
  await form.locator('button[type="submit"]').click();
  await expect(form.locator('p.text-red-500')).toBeVisible();
  await form.locator('[name="Login"]').fill('admin');
  await form.locator('[name="Pass"]').fill('synthetic-isolated-admin');
  await Promise.all([page.waitForURL(url => !url.pathname.includes('login')), form.locator('button[type="submit"]').click()]);
  const response = await page.goto('/cabinet');
  expect(response.status()).toBe(200);
  expect(new URL(page.url()).pathname).toBe('/cabinet');
  await expect(page.locator('form[name="login_frm"]')).toHaveCount(0);
});

test('API routes reject missing bearer credentials with the v1 envelope', async ({ request }) => {
  for (const route of ['balance', 'user', 'operations']) {
    const response = await request.get(`/api/v1/${route}`);
    expect(response.status()).toBe(401);
    expect(response.headers()['content-type']).toContain('application/json');
    const body = await response.json();
    expect(body.success).toBe(false);
    expect(body.error.code).toBe('authentication_required');
    expect(body.meta.correlation_id).toMatch(/^[a-f0-9]{32}$/);
  }
});

test('collector emergency ingestion switch rejects writes before auth or payload handling', async ({ request }) => {
  for (const route of ['register', 'report', 'domain-verification']) {
    const response = await request.post(`/api/v1/installations/${route}`, { data: {} });
    expect(response.status()).toBe(503);
    expect((await response.json()).error.code).toBe('ingestion_disabled');
  }
});

test('backup URLs and anonymous configurator downloads are inaccessible', async ({ request }) => {
  for (const path of ['/backup/', '/backup/.htaccess', '/backup/probe.sql', '/backup/probe.sql.gz',
    '/backup/probe.json', '/backup/probe.txt', '/backup/probe.css', '/backup/probe.php',
    '/backup/recovery/reports/latest-backup.json', '/Backup/probe.txt', '/%62ackup/probe.txt',
    '/backup%2fprobe.txt', '/backup//probe.txt']) {
    const response = await request.get(path, { maxRedirects: 0 });
    expect([400, 403, 404]).toContain(response.status());
    expect(await response.text()).not.toContain('SYNTHETIC_BACKUP_SECRET');
  }
  const response = await request.post('/_cfg?backup', {
    form: { backupAction: 'download', backupId: 'a'.repeat(32), csrf: 'a'.repeat(64) }, maxRedirects: 0,
  });
  expect(response.status()).toBe(302);
  expect(response.headers().location).toContain('login');
  expect(response.headers()['content-disposition']).toBeUndefined();
  const mixed = await request.post('/_cfg?backup&login', {
    form: { backupAction: 'download', backupId: 'a'.repeat(32) }, maxRedirects: 0,
  });
  expect(mixed.headers()['content-disposition']).toBeUndefined();
  expect(await mixed.text()).not.toContain('SYNTHETIC_BACKUP_SECRET');
});

test('public domain proof never reflects caller-controlled challenges', async ({ request }) => {
  const response = await request.get('/api/v1/installations/domain-proof?nonce=ATTACKER_VALUE');
  expect(response.status()).toBe(404);
  expect((await response.json()).error.code).toBe('proof_unavailable');
  expect(response.headers()['cache-control']).toContain('no-store');
  const write = await request.post('/api/v1/installations/domain-proof', { data: { nonce: 'ATTACKER_VALUE' } });
  expect(write.status()).toBe(405);
});
