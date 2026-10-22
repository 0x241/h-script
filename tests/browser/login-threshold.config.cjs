const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.', testMatch: 'login-threshold.spec.cjs', outputDir: '/tmp/login-threshold-results',
  reporter: 'line', workers: 1, retries: 0, timeout: 60000,
  use: { baseURL: 'http://fixture.invalid', trace: 'off', screenshot: 'off', video: 'off' },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
