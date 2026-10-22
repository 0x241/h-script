const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.', testMatch: 'decimal-plan.spec.cjs', outputDir: '/tmp/decimal-plan-results',
  reporter: 'line', workers: 1, retries: 0, timeout: 60000,
  use: { baseURL: 'http://fixture.invalid', trace: 'off', screenshot: 'off', video: 'off' },
});
