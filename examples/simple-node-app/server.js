// Minimal example application for testing PrivateCloud deployments.
// No dependencies: builds in seconds. Behaviour can be changed with env vars:
//   APP_MESSAGE      text shown on the home page
//   FAIL_HEALTHCHECK when "1", /health returns 500 (simulates a broken release)
//   CRASH_ON_START   when "1", the process exits immediately
const http = require('http');
const os = require('os');

const VERSION = require('./package.json').version;
const port = Number(process.env.PORT || 3000);
const started = new Date();

if (process.env.CRASH_ON_START === '1') {
  console.error('CRASH_ON_START=1: exiting on purpose');
  process.exit(1);
}

const server = http.createServer((req, res) => {
  const t0 = Date.now();
  res.on('finish', () => {
    console.log(`${new Date().toISOString()} ${req.method} ${req.url} ${res.statusCode} ${Date.now() - t0}ms`);
  });

  if (req.url === '/health') {
    if (process.env.FAIL_HEALTHCHECK === '1') {
      console.error('health check failing on purpose (FAIL_HEALTHCHECK=1)');
      res.writeHead(500, { 'Content-Type': 'application/json' });
      return res.end(JSON.stringify({ status: 'failing' }));
    }
    res.writeHead(200, { 'Content-Type': 'application/json' });
    return res.end(JSON.stringify({ status: 'ok', version: VERSION }));
  }

  if (req.url === '/') {
    const message = process.env.APP_MESSAGE || 'Hello from PrivateCloud!';
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    return res.end(`<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Simple Node App</title>
<style>body{font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem;color:#0f172a}code{background:#f1f5f9;padding:.1rem .3rem;border-radius:.25rem}</style>
</head><body>
<h1>${escapeHtml(message)}</h1>
<p>Version <code>${VERSION}</code> · host <code>${escapeHtml(os.hostname())}</code> · up since ${started.toISOString()}</p>
<p>Database configured: <strong>${process.env.DATABASE_URL ? 'yes' : 'no'}</strong></p>
</body></html>`);
  }

  res.writeHead(404, { 'Content-Type': 'text/plain' });
  res.end('Not found');
});

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

server.listen(port, '0.0.0.0', () => console.log(`simple-node-app ${VERSION} listening on port ${port}`));

process.on('SIGTERM', () => {
  console.log('SIGTERM received, closing server');
  server.close(() => process.exit(0));
});
