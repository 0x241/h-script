const { test, expect } = require('@playwright/test');

async function waitForPageAssets(page) {
  // Finish each document's font loads before navigating away. WebKit reports
  // navigation-cancelled fonts as request failures, even when CSP allowed them.
  await page.waitForLoadState('load');
  await page.evaluate(async () => { await document.fonts.ready; });
}

async function enforceCandidate(page, violations) {
  let promoted = 0;
  const appOrigin = new URL(process.env.CSP_TEST_BASE_URL || 'http://hs.local').origin;
  page.on('console', message => {
    if (/content security policy|content-security-policy|refused to (load|execute|apply)/i.test(message.text()))
      violations.push(message.text());
  });
  page.on('pageerror', error => violations.push(error.message));
  page.on('requestfailed', request => {
    const reason = request.failure()?.errorText || '';
    if (new URL(request.url()).origin === appOrigin || /blocked.by.csp|content.security.policy/i.test(reason))
      violations.push(`${request.url()}: ${reason}`);
  });
  await page.route('**/*', async route => {
    const request = route.request();
    if (new URL(request.url()).origin !== appOrigin)
      return route.continue();
    if (request.method() !== 'GET') return route.continue();
    const response = await route.fetch({ maxRedirects: 0 });
    if (response.status() >= 300 && response.status() < 400) return route.continue();
    if (request.resourceType() !== 'document') return route.fulfill({ response });
    const headers = response.headers();
    const candidate = headers['content-security-policy-report-only'];
    if (!candidate) return route.fulfill({ response });
    delete headers['content-security-policy-report-only'];
    headers['content-security-policy'] = candidate;
    promoted++;
    return route.fulfill({ response, headers });
  });
  return () => promoted;
}

for (const [path, expectedStatus, expectedSelector] of [
  ['/ru/', 200, 'main'],
  ['/login', 200, 'form[name="login_frm"]'],
  ['/_cfg?login', 200, 'main'],
  ['/ru/not-found', 404, 'body'],
]) {
  test(`candidate CSP permits required anonymous UI on ${path}`, async ({ page }) => {
    const violations = [];
    const promoted = await enforceCandidate(page, violations);
    const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBe(expectedStatus);
    await expect(page.locator(expectedSelector)).toBeVisible();
    expect(promoted()).toBeGreaterThan(0);
    if (path !== '/ru/not-found') {
      expect(await page.evaluate(() => typeof window.htmx?.ajax)).toBe('function');
      expect(await page.evaluate(() => window.htmx.config.allowEval)).toBe(false);
      expect(await page.evaluate(() => window.htmx.config.allowScriptTags)).toBe(false);
      expect(await page.evaluate(() => window.htmx.config.includeIndicatorStyles)).toBe(false);
      await expect(page.locator('style')).toHaveCount(0);
    }
    await waitForPageAssets(page);
    await page.waitForTimeout(250);
    expect(violations).toEqual([]);
  });
}

test('response nonce rotates and ignores caller input', async ({ request }) => {
  const supplied = 'AAAAAAAAAAAAAAAAAAAAAAAA';
  const first = await request.get(`/login?csp_nonce=${supplied}`);
  const second = await request.get('/login');
  const nonce = response => response.headers()['content-security-policy-report-only']?.match(/'nonce-([^']+)'/)?.[1];
  expect(nonce(first)).toMatch(/^[A-Za-z0-9+/]{24}$/);
  expect(nonce(second)).toMatch(/^[A-Za-z0-9+/]{24}$/);
  expect(nonce(first)).not.toBe(supplied);
  expect(nonce(first)).not.toBe(nonce(second));
});

function adminCredentials() {
  const fixture = (process.env.CSP_TEST_BASE_URL || '').includes('fixture.invalid');
  return {
    login: process.env.CSP_TEST_ADMIN_LOGIN || (fixture ? 'admin' : ''),
    password: process.env.CSP_TEST_ADMIN_PASSWORD || (fixture ? 'synthetic-isolated-admin' : ''),
  };
}

async function loginAsAdmin(page, credentials) {
  await page.goto('/login', { waitUntil: 'domcontentloaded' });
  await page.locator('form[name="login_frm"] input[name="Login"]').fill(credentials.login);
  const password = page.locator('form[name="login_frm"] input[name="Pass"]');
  await password.fill(credentials.password);
  await page.locator('[data-hs-password-toggle]').click();
  await expect(password).toHaveAttribute('type', 'text');
  await page.locator('[data-hs-password-toggle]').click();
  await expect(password).toHaveAttribute('type', 'password');
  await waitForPageAssets(page);
  await page.locator('form[name="login_frm"] button[type="submit"]').click();
  await expect(page).toHaveURL(/\/cabinet(?:\?|$)/);
  await waitForPageAssets(page);
}

test('authenticated editors render with candidate CSP', async ({ page }) => {
  const credentials = adminCredentials();
  test.skip(!credentials.login || !credentials.password, 'Requires isolated admin fixture credentials');
  const violations = [];
  const promoted = await enforceCandidate(page, violations);
  await loginAsAdmin(page, credentials);
  for (const path of ['/admin/news?add', '/admin/faq?add']) {
    const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBe(200);
    expect(response.headers()['content-security-policy']).toContain("script-src-attr 'none'");
    await expect(page.locator('.ck-editor')).toBeVisible();
    await waitForPageAssets(page);
  }
  expect(promoted()).toBeGreaterThan(0);
  expect(violations).toEqual([]);
});

test('authenticated configurator works with candidate CSP', async ({ page, browserName }) => {
  // The isolated fixture shares one client IP; the configurator caps login POSTs across both browser suites.
  test.skip(browserName !== 'chromium', 'One authenticated engine keeps the shared fixture below its login limit');
  const fixture = (process.env.CSP_TEST_BASE_URL || '').includes('fixture.invalid');
  const password = process.env.CSP_TEST_CONFIGURATOR_PASSWORD || (fixture ? 'synthetic-isolated-configurator' : '');
  test.skip(!password, 'Requires isolated configurator fixture credentials');
  const violations = [];
  const promoted = await enforceCandidate(page, violations);
  await page.goto('/_cfg?login', { waitUntil: 'domcontentloaded' });
  await waitForPageAssets(page);
  const csrf = await page.locator('form:has([name="bLogin"]) [name="csrf"]').inputValue();
  const login = await page.request.post('/_cfg?login', {
    form: { csrf, pass: password, bLogin: '1' }, maxRedirects: 0,
  });
  expect(login.status()).toBe(302);
  for (const section of ['security', 'backup', 'update']) {
    const response = await page.goto(`/_cfg?${section}`, { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBe(200);
    await expect(page.locator('#cfg-sidebar')).toBeAttached();
    await waitForPageAssets(page);
  }
  expect(promoted()).toBeGreaterThan(0);
  expect(violations).toEqual([]);
});

test('payment route advertises scoped HTTPS form actions', async ({ request }) => {
  const response = await request.get('/operation?add=CASHIN', { maxRedirects: 0 });
  expect(response.headers()['content-security-policy-report-only']).toContain("form-action 'self' https:");
});
