#!/bin/sh
set -eu

CSP_TEST_BASE_URL=http://fixture.invalid npx --no-install playwright test --config=csp.config.cjs
npx --no-install playwright test --config=playwright.config.cjs
npx --no-install playwright test --config=security-phase4.config.cjs
