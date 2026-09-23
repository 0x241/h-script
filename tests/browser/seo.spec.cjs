const { test, expect } = require('@playwright/test');

for (const javaScriptEnabled of [false, true]) {
  test(`public language links navigate with JavaScript ${javaScriptEnabled ? 'enabled' : 'disabled'}`, async ({ browser, baseURL }) => {
    const context = await browser.newContext({ javaScriptEnabled });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const root = baseURL.replace(/\/?$/, '/');
    await page.goto(root);
    const primary = await page.locator('html').getAttribute('lang');
    await page.goto(`${root}${primary}/contacts/`);
    const links = await page.locator('a[data-public-language]').evaluateAll(nodes => nodes.map(node => ({
      language: node.dataset.publicLanguage, url: node.href,
    })));
    expect(links.length, 'At least one translated contact version').toBeGreaterThan(0);
    for (const target of links) {
      // Opposite session preference must not affect destination metadata or visible language.
      await context.addCookies([{ name: 'lang', value: primary, url: root }]);
      const details = page.locator('details').filter({ has: page.locator('a[data-public-language]') });
      await details.locator('summary').click();
      const link = page.locator(`a[data-public-language="${target.language}"]`);
      await expect(link).toBeVisible();
      await expect(link).toHaveAttribute('hreflang', target.language);
      const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
      await link.click();
      const response = await navigation;
      expect(response.status()).toBe(200);
      expect(page.url()).toBe(target.url);
      await expect(page.locator('html')).toHaveAttribute('lang', target.language);
      await expect(page.locator('main')).toBeVisible();
      const canonical = new URL(target.url); canonical.protocol = 'https:';
      await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', canonical.href);
      await expect(page.locator(`link[rel="alternate"][hreflang="${target.language}"]`)).toHaveAttribute('href', canonical.href);
    }
    expect(errors).toEqual([]);
    await context.close();
  });
}
