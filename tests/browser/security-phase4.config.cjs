const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: '.',
  testMatch: 'security-phase4.spec.cjs',
  outputDir: '/tmp/hscript-phase4-browser-results',
  reporter: 'line',
  workers: 1,
  retries: 0,
  timeout: 30000,
  use: { baseURL: 'http://fixture.invalid', trace: 'off', screenshot: 'off', video: 'off' },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
