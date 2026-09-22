const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.',
  testMatch: 'release.spec.cjs',
  outputDir: '/tmp/hscript-browser-results',
  reporter: 'line',
  workers: 1,
  retries: 0,
  timeout: 30000,
  use: { baseURL: 'http://fixture.invalid', trace: 'off', screenshot: 'off', video: 'off' },
  projects: ['chromium', 'firefox', 'webkit'].map(browserName => ({ name: browserName, use: { browserName } })),
});
