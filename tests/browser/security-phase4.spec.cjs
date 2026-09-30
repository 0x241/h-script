const { test, expect } = require('@playwright/test');

const loginFixture = async (page, user) => {
  await page.goto('/login');
  const form = page.locator('form[name="login_frm"]');
  await form.locator('[name="Login"]').fill(user);
  await form.locator('[name="Pass"]').fill('synthetic-phase4-password');
  await form.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(/\/cabinet(?:\?|$)/);
};

test('public mutations reject GET and untrusted POST', async ({ page, request }) => {
  const expiredHtmx = await request.get('/cabinet', { headers: { 'HX-Request': 'true' }, maxRedirects: 0 });
  expect(expiredHtmx.status()).toBe(200);
  expect(expiredHtmx.headers()['hx-redirect']).toContain('/login');
  expect(await expiredHtmx.text()).toBe('');

  for (const [method, options] of [
    ['get', {}], ['post', {}], ['post', { headers: { 'X-HScript-Cron-Token': 'wrong' } }],
  ]) {
    const response = await request[method]('/cron', options);
    expect(response.status()).toBe(403);
  }

  await page.goto('/login');
  const themeForm = page.locator('form:has(input[name="session_csrf"]):has(input[name="theme"])').first();
  const action = await themeForm.getAttribute('action');
  const token = await themeForm.locator('input[name="session_csrf"]').inputValue();
  const lang = await themeForm.locator('input[name="lang"]').inputValue();
  const originalTheme = await themeForm.locator('input[name="theme"]').inputValue();
  const forgedGet = await page.request.get(`${action}?lang=${lang}&theme=${originalTheme}&_method=POST`);
  expect(forgedGet.status()).toBe(200);
  expect(forgedGet.headers()['set-cookie'] || '').not.toContain('theme=');

  for (const options of [
    { form: { lang, theme: originalTheme } },
    { form: { lang, theme: originalTheme, session_csrf: 'invalid' } },
    { headers: { Origin: 'https://attacker.invalid' }, form: { lang, theme: originalTheme } },
    { form: { lang, theme: originalTheme, session_csrf: [token, 'invalid'] } },
  ]) {
    const response = await page.request.post(action, options);
    expect(response.status()).toBe(403);
  }
  const stale = await request.post(action, { form: { lang, theme: originalTheme, session_csrf: token } });
  expect(stale.status()).toBe(403);

  await page.goto('/_cfg?login');
  const cfgGet = await page.request.get('/_cfg?login&theme=light&lang=ru');
  expect(cfgGet.status()).toBe(200);
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  const cfgBad = await page.request.post('/_cfg?login', {
    form: { cfg_preference: 'theme', value: 'light', return_section: 'login' }, maxRedirects: 0,
  });
  expect(cfgBad.status()).toBe(403);
});

test('authenticated pin and logout need POST plus the matching session token', async ({ page }) => {
  await page.goto('/login');
  const form = page.locator('form[name="login_frm"]');
  await form.locator('[name="Login"]').fill('admin');
  await form.locator('[name="Pass"]').fill('synthetic-isolated-admin');
  await form.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(/\/cabinet(?:\?|$)/);

  await page.goto('/login?out=user');
  await expect(page).toHaveURL(/\/cabinet(?:\?|$)/);
  const pin = page.locator('form:has(input[name="pin_action"][value="pin"]):has(input[name="pin_item"][value="overview"])');
  await expect(pin).toBeAttached();
  const token = await pin.locator('input[name="session_csrf"]').inputValue();
  const getPin = await page.request.get('/cabinet?cabinet_pin=overview');
  expect(getPin.status()).toBe(200);
  await expect(page.locator('form:has(input[name="pin_action"][value="unpin"]):has(input[name="pin_item"][value="overview"])')).toHaveCount(0);

  const invalid = await page.request.post('/cabinet', {
    form: { pin_action: 'pin', pin_item: 'overview', session_csrf: 'invalid' },
  });
  expect(invalid.status()).toBe(403);
  const crossSite = await page.request.post('/cabinet', {
    headers: { Origin: 'https://attacker.invalid' }, form: { pin_action: 'pin', pin_item: 'overview' },
  });
  expect(crossSite.status()).toBe(403);
  const stale = await page.context().browser().newContext();
  try {
    const response = await stale.request.post('/cabinet', {
      form: { pin_action: 'pin', pin_item: 'overview', session_csrf: token }, maxRedirects: 0,
    });
    expect(response.status()).toBe(302);
  } finally { await stale.close(); }

  await pin.locator('button').click();
  await expect(page.locator('form:has(input[name="pin_action"][value="unpin"]):has(input[name="pin_item"][value="overview"])')).toBeAttached();

  const badLogout = await page.request.post('/login', { form: { logout_btnlogout: '1' } });
  expect(badLogout.status()).toBeGreaterThanOrEqual(400);
  await page.goto('/cabinet');
  await expect(page.locator('form:has([name="logout_btnlogout"])')).toBeAttached();
  await page.locator('form:has([name="logout_btnlogout"]) button').click();
  await page.goto('/cabinet');
  await expect(page).toHaveURL(/\/login/);
});

test('roles 1, 10, 90, 99 and disabled accounts obey route authorization', async ({ browser }) => {
  for (const [login, adminAllowed] of [
    ['phase4-user', false], ['phase4-manager', false],
    ['phase4-admin', true], ['phase4-owner', true],
  ]) {
    const context = await browser.newContext();
    try {
      const page = await context.newPage();
      await page.goto('/login');
      const form = page.locator('form[name="login_frm"]');
      await form.locator('[name="Login"]').fill(login);
      await form.locator('[name="Pass"]').fill('synthetic-phase4-password');
      await form.locator('button[type="submit"]').click();
      await expect(page).toHaveURL(/\/cabinet(?:\?|$)/);
      const admin = await page.goto('/admin');
      if (adminAllowed) {
        expect(admin.status()).toBe(200);
        await expect(page).toHaveURL(/\/admin(?:\?|$)/);
      } else {
        await expect(page).not.toHaveURL(/\/admin(?:\?|$)/);
      }
    } finally { await context.close(); }
  }

  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    await page.goto('/login');
    const form = page.locator('form[name="login_frm"]');
    await form.locator('[name="Login"]').fill('phase4-disabled');
    await form.locator('[name="Pass"]').fill('synthetic-phase4-password');
    await form.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/login(?:\?|$)/);
    await expect(form).toBeVisible();
  } finally { await context.close(); }
});

test('ticket read receipts require owner and session CSRF', async ({ page, browser }) => {
  await page.goto('/login');
  const login = page.locator('form[name="login_frm"]');
  await login.locator('[name="Login"]').fill('phase4-user');
  await login.locator('[name="Pass"]').fill('synthetic-phase4-password');
  await login.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(/\/cabinet(?:\?|$)/);

  await page.goto('/ticket?add');
  const create = page.locator('form:has(input[name="tTopic"])');
  await create.locator('[name="tTopic"]').fill('Synthetic phase 4 receipt');
  await create.locator('[name="tText"]').fill('Disposable security fixture');
  await create.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(/\/ticket\?id=\d+/);
  const ticketId = new URL(page.url()).searchParams.get('id');
  const readForm = page.locator('form[data-hs-read-receipt]');
  await expect(readForm).toBeAttached();
  const token = await readForm.locator('[name="session_csrf"]').inputValue();
  const url = `/ticket?id=${ticketId}`;

  expect((await page.request.get(url)).status()).toBe(200);
  expect((await page.request.post(url, { form: { read_receipt: ticketId } })).status()).toBe(403);
  expect((await page.request.post(url, { form: { read_receipt: ticketId, session_csrf: 'bad' } })).status()).toBe(403);
  expect((await page.request.post(url, { form: { read_receipt: '999999999', session_csrf: token } })).status()).toBe(404);
  expect((await page.request.post(url, { form: { read_receipt: ticketId, session_csrf: token } })).status()).toBe(204);

  const other = await browser.newContext();
  try {
    const otherPage = await other.newPage();
    await otherPage.goto('/login');
    const otherLogin = otherPage.locator('form[name="login_frm"]');
    await otherLogin.locator('[name="Login"]').fill('phase4-manager');
    await otherLogin.locator('[name="Pass"]').fill('synthetic-phase4-password');
    await otherLogin.locator('button[type="submit"]').click();
    await expect(otherPage).toHaveURL(/\/cabinet(?:\?|$)/);
    const ownToken = await otherPage.locator('form:has(input[name="session_csrf"]) input[name="session_csrf"]').first().inputValue();
    const foreign = await otherPage.request.post(url, { form: { read_receipt: ticketId, session_csrf: ownToken } });
    expect(foreign.status()).toBe(404);
  } finally { await other.close(); }
});

test('administrator impersonation requires POST, CSRF and a lower active role', async ({ page }) => {
  await page.goto('/login');
  const login = page.locator('form[name="login_frm"]');
  await login.locator('[name="Login"]').fill('phase4-admin');
  await login.locator('[name="Pass"]').fill('synthetic-phase4-password');
  await login.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(/\/cabinet(?:\?|$)/);

  await page.goto('/admin/account/users');
  const ownerHref = await page.getByRole('link', { name: 'phase4-owner', exact: true }).first().getAttribute('href');
  const userHref = await page.getByRole('link', { name: 'phase4-user', exact: true }).first().getAttribute('href');
  const ownerId = new URL(ownerHref, page.url()).searchParams.get('id');
  const userId = new URL(userHref, page.url()).searchParams.get('id');

  const forgedGet = await page.request.get(`${ownerHref}&login`, { maxRedirects: 0 });
  expect(forgedGet.status()).toBe(200);
  await page.goto(userHref);
  const impersonate = page.locator('form:has([name="impersonate_btnlogin"])');
  await expect(impersonate).toBeAttached();
  const cert = await impersonate.locator('[name="__Cert"]').inputValue();
  const bad = await page.request.post(ownerHref, {
    form: { impersonate_btnlogin: '1', uID: ownerId, __Cert: 'bad' }, maxRedirects: 0,
  });
  expect(bad.status()).toBe(403);
  const higher = await page.request.post(ownerHref, {
    form: { impersonate_btnlogin: '1', uID: ownerId, __Cert: cert }, maxRedirects: 0,
  });
  expect(higher.status()).toBe(403);
  await page.goto('/admin');
  await expect(page).toHaveURL(/\/admin(?:\?|$)/);

  await page.goto(userHref);
  const edit = page.locator('form:has(input[name="uLevel"])').first();
  const editName = await edit.getAttribute('name');
  const editCert = await edit.locator('[name="__Cert"]').inputValue();
  const promotion = await page.request.post(userHref, {
    form: { [`${editName}_btn`]: '1', uID: userId, uLevel: '99', __Cert: editCert }, maxRedirects: 0,
  });
  expect(promotion.status()).toBe(403);
  await page.goto(userHref);
  const fresh = page.locator('form:has([name="impersonate_btnlogin"])');
  await expect(fresh.locator('[name="uID"]')).toHaveValue(userId);
  await fresh.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(/\/cabinet(?:\?|$)/);
  await page.goto('/admin');
  await expect(page).not.toHaveURL(/\/admin(?:\?|$)/);
});

test('bulk group assignment respects administrator role and form certificate', async ({ page }) => {
  await loginFixture(page, 'phase4-admin');
  await page.goto('/admin/account/users');
  const ownerRow = page.locator('tr').filter({ has: page.getByRole('link', { name: 'phase4-owner', exact: true }) }).first();
  const userRow = page.locator('tr').filter({ has: page.getByRole('link', { name: 'phase4-user', exact: true }) }).first();
  const ownerId = await ownerRow.locator('input[name="ids[]"]').inputValue();
  const userId = await userRow.locator('input[name="ids[]"]').inputValue();
  const originalOwnerGroup = await ownerRow.locator('td').nth(2).innerText();
  const bulk = page.locator('form:has(button[name$="_btnsetgroup"])');
  const buttonName = await bulk.locator('button[name$="_btnsetgroup"]').getAttribute('name');
  const cert = await bulk.locator('[name="__Cert"]').inputValue();
  const deniedGroup = 'forged-owner-group';

  const missingCsrf = await page.request.post('/admin/account/users', {
    form: { 'ids[]': ownerId, Group: deniedGroup, [buttonName]: '1' }, maxRedirects: 0,
  });
  expect(missingCsrf.status()).toBe(403);
  const higherRole = await page.request.post('/admin/account/users', {
    form: { 'ids[]': [ownerId, userId], Group: deniedGroup, [buttonName]: '1', __Cert: cert }, maxRedirects: 0,
  });
  expect(higherRole.status()).toBe(403);
  await page.goto('/admin/account/users');
  await expect(page.locator('tr').filter({ has: page.getByRole('link', { name: 'phase4-owner', exact: true }) }).first().locator('td').nth(2)).toHaveText(originalOwnerGroup);
  await expect(page.locator('tr').filter({ has: page.getByRole('link', { name: 'phase4-user', exact: true }) }).first().locator('td').nth(2)).not.toHaveText(deniedGroup);

  const allowed = page.locator('form:has(button[name$="_btnsetgroup"])');
  const allowedCert = await allowed.locator('[name="__Cert"]').inputValue();
  const ownGroup = 'phase4-allowed-group';
  const duplicateCert = await page.request.post('/admin/account/users', {
    form: { 'ids[]': userId, Group: 'forged-duplicate-cert', [buttonName]: '1', __Cert: [allowedCert, 'bad'] }, maxRedirects: 0,
  });
  expect(duplicateCert.status()).toBe(403);
  const lowerRole = await page.request.post('/admin/account/users', {
    form: { 'ids[]': userId, Group: ownGroup, [buttonName]: '1', __Cert: allowedCert }, maxRedirects: 0,
  });
  expect(lowerRole.status()).toBe(302);
  const replay = await page.request.post('/admin/account/users', {
    form: { 'ids[]': userId, Group: 'forged-replayed-cert', [buttonName]: '1', __Cert: allowedCert }, maxRedirects: 0,
  });
  expect(replay.status()).toBe(403);
  await page.goto('/admin/account/users');
  await expect(page.locator('tr').filter({ has: page.getByRole('link', { name: 'phase4-user', exact: true }) }).first().locator('td').nth(2)).toHaveText(ownGroup);
});

test('a valid form certificate cannot manage another owner’s API token', async ({ browser, request }) => {
  test.setTimeout(60000);
  const owner = await browser.newContext();
  const peer = await browser.newContext();
  try {
    const ownerPage = await owner.newPage();
    const peerPage = await peer.newPage();
    await loginFixture(ownerPage, 'phase4-user');
    await ownerPage.goto('/account?tab=api');
    const issue = ownerPage.locator('form[name="account_api_create"]');
    await issue.locator('[name="name"]').fill('Owner API token');
    await issue.locator('[name="scopes[]"][value="*"]').check();
    await issue.locator('button[type="submit"]').click();
    const secret = await ownerPage.locator('#account-api-new-secret').inputValue();
    const victimId = await ownerPage.locator('form[name="account_api_manage"] button[name="account_api_manage_btnrevoke"]').first().getAttribute('value');
    const authorized = () => request.get('/api/v1/user', { headers: { Authorization: `Bearer ${secret}` } });
    expect((await authorized()).status()).toBe(200);

    const ownerMissingCsrf = await ownerPage.request.post('/account?tab=api', {
      form: { account_api_manage_btnrevoke: victimId }, maxRedirects: 0,
    });
    expect(ownerMissingCsrf.status()).toBe(403);

    await loginFixture(peerPage, 'phase4-peer');
    await peerPage.goto('/account?tab=api');
    const peerIssue = peerPage.locator('form[name="account_api_create"]');
    await peerIssue.locator('[name="name"]').fill('Peer API token');
    await peerIssue.locator('button[type="submit"]').click();
    const manage = peerPage.locator('form[name="account_api_manage"]');
    const cert = await manage.locator('[name="__Cert"]').inputValue();
    const forgedRevoke = await peerPage.request.post('/account?tab=api', {
      form: { account_api_manage_btnrevoke: victimId, __Cert: cert }, maxRedirects: 0,
    });
    expect(forgedRevoke.status()).toBe(302);
    expect((await authorized()).status()).toBe(200);

    await peerPage.goto('/account?tab=api');
    const freshCert = await peerPage.locator('form[name="account_api_manage"] [name="__Cert"]').inputValue();
    const forgedUpdate = await peerPage.request.post('/account?tab=api', {
      form: {
        account_api_manage_btnsave: victimId, __Cert: freshCert,
        [`tokens[${victimId}][name]`]: 'Stolen token',
        [`tokens[${victimId}][state]`]: 'paused',
        [`tokens[${victimId}][scopes][]`]: 'user:read',
      }, maxRedirects: 0,
    });
    expect(forgedUpdate.status()).toBe(302);
    expect((await authorized()).status()).toBe(200);
  } finally { await owner.close(); await peer.close(); }
});

test('collector service tokens stay bound to their issuing account', async ({ browser }) => {
  test.setTimeout(60000);
  const owner = await browser.newContext();
  const peer = await browser.newContext();
  try {
    const ownerPage = await owner.newPage();
    const peerPage = await peer.newPage();
    await loginFixture(ownerPage, 'phase4-manager');
    await ownerPage.goto('/account?tab=service');
    const ownerIssue = ownerPage.locator('form[name="account_service_token_create"]');
    await expect(ownerIssue).toBeAttached();
    await ownerIssue.locator('[name="name"]').fill('Owner service token');
    await ownerIssue.locator('button[type="submit"]').click();
    const victimId = await ownerPage.locator('form[name="account_service_token_manage"] button[name="account_service_token_manage_btnrevoke"]').first().getAttribute('value');

    await loginFixture(peerPage, 'phase4-manager-peer');
    await peerPage.goto('/account?tab=service');
    const peerIssue = peerPage.locator('form[name="account_service_token_create"]');
    await peerIssue.locator('[name="name"]').fill('Peer service token');
    await peerIssue.locator('button[type="submit"]').click();
    const peerManage = peerPage.locator('form[name="account_service_token_manage"]');
    const cert = await peerManage.locator('[name="__Cert"]').inputValue();
    const forgedReissue = await peerPage.request.post('/account?tab=service', {
      form: {
        account_service_token_manage_btnreissue: victimId, __Cert: cert,
        [`tokens[${victimId}][name]`]: 'Stolen service token',
      }, maxRedirects: 0,
    });
    expect(forgedReissue.status()).toBe(302);
    await ownerPage.goto('/account?tab=service');
    await expect(ownerPage.locator(`button[name="account_service_token_manage_btnrevoke"][value="${victimId}"]`)).toBeAttached();

    await peerPage.goto('/account?tab=service');
    const freshCert = await peerPage.locator('form[name="account_service_token_manage"] [name="__Cert"]').inputValue();
    const forgedRevoke = await peerPage.request.post('/account?tab=service', {
      form: { account_service_token_manage_btnrevoke: victimId, __Cert: freshCert }, maxRedirects: 0,
    });
    expect(forgedRevoke.status()).toBe(302);
    await ownerPage.goto('/account?tab=service');
    await expect(ownerPage.locator(`button[name="account_service_token_manage_btnrevoke"][value="${victimId}"]`)).toBeAttached();
  } finally { await owner.close(); await peer.close(); }
});
