# simple-node-app

A dependency-free Node.js application used to test PrivateCloud deployments.

- `GET /` — page showing the version and whether a database is configured
- `GET /health` — health endpoint (configure the project's health check path to `/health`)

Environment variables:

| Variable | Effect |
| --- | --- |
| `PORT` | Port to listen on (set automatically by PrivateCloud) |
| `APP_MESSAGE` | Heading shown on the home page |
| `FAIL_HEALTHCHECK=1` | `/health` returns 500 — the deployment fails and the previous version keeps running |
| `CRASH_ON_START=1` | The process exits immediately — the deployment fails at startup |

To simulate a **build failure**, break the `Dockerfile` (for example `RUN exit 1`) and push.
