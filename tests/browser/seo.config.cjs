const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.',
  testMatch: 'seo.spec.cjs',
  outputDir: '/tmp/hscript-seo-browser-results',
  reporter: 'line', workers: 1, retries: 0, timeout: 30000,
  use: { baseURL: process.env.SEO_BASE_URL || 'http://hs.local', trace: 'off', screenshot: 'off', video: 'off' },
  projects: ['chromium', 'firefox', 'webkit'].map(browserName => ({ name: browserName, use: { browserName } })),
});
