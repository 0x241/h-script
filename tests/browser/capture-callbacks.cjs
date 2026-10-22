const crypto = require('node:crypto');
const fs = require('node:fs');
const artifact = '/tmp/gateway-http-captured-wire.jsonl';

// Browser test observer only. Runners use a disposable database, an internal
// network and fixture.invalid; this file is excluded from production artifacts.
function captureCallbacks(test, profile) {
  test.beforeEach(async ({ request, page }, info) => {
    for (const context of new Set([request, page.request])) {
      const original = context.post.bind(context);
      context.post = async (url, options = {}) => {
        const response = await original(url, options);
        const path = new URL(url, 'http://fixture.invalid');
        if (path.pathname === '/balance/status') {
          if (path.hostname !== 'fixture.invalid') throw new Error('Callback capture requires an isolated fixture origin');
          const body = typeof options.data === 'string' ? options.data
            : options.data !== undefined ? JSON.stringify(options.data)
            : new URLSearchParams(options.form || {}).toString();
          const headers = Object.fromEntries(Object.entries(options.headers || {}).filter(([name]) => ['content-type','hmac'].includes(name.toLowerCase())));
          const record = {
            version: 1, origin: 'captured_synthetic_http', live_provider_capture: false,
            profile, scenario: info.title, method: 'POST', route: path.pathname + path.search,
            content_type: headers['Content-Type'] || headers['content-type'] || '',
            body, body_sha256: crypto.createHash('sha256').update(body).digest('hex'), headers,
            response_status: response.status(), response_body: await response.text(),
            correlation_id: response.headers()['x-request-id'] || '',
          };
          fs.appendFileSync(artifact, JSON.stringify(record) + '\n', {mode: 0o600});
        }
        return response;
      };
    }
  });
}
module.exports = {captureCallbacks, artifact};
