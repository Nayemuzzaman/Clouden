// Acceptance test application for the real github.com + server test
// (docs/github-acceptance-test.md). No dependencies.
//
// Every response names the version, so an external request loop can tell
// exactly which release answered. The release is read from two files that
// scripts/acceptance/set-version.sh writes:
//   VERSION  the version name, for example "version-b"
//   HEALTH   when it contains "fail", every request (including /health) gets
//            503: the image builds and starts but never passes a health check
const fs = require('fs');
const http = require('http');

const read = (file) => {
  try {
    return fs.readFileSync(`${__dirname}/${file}`, 'utf8').trim();
  } catch {
    return '';
  }
};

const VERSION = read('VERSION') || 'version-unknown';
const HEALTHY = read('HEALTH') !== 'fail';
const port = Number(process.env.PORT || 3000);

const server = http.createServer((req, res) => {
  res.setHeader('Cache-Control', 'no-store');
  res.setHeader('X-Test-Version', VERSION);
  if (!HEALTHY) {
    res.writeHead(503, { 'Content-Type': 'text/plain' });
    return res.end(`${VERSION} unhealthy\n`);
  }
  if (req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    return res.end(JSON.stringify({ status: 'ok', version: VERSION }));
  }
  res.writeHead(200, { 'Content-Type': 'text/plain' });
  res.end(`${VERSION}\n`);
});

server.listen(port, '0.0.0.0', () => console.log(`${VERSION} listening on port ${port} (healthy: ${HEALTHY})`));

process.on('SIGTERM', () => server.close(() => process.exit(0)));
