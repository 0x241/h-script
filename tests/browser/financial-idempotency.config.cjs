const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.', testMatch: 'financial-idempotency.spec.cjs', outputDir: '/tmp/financial-http-results',
  reporter: 'line', workers: 1, retries: 0, timeout: 60000,
  use: { baseURL: 'http://fixture.invalid', trace: 'off', screenshot: 'off', video: 'off' },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
