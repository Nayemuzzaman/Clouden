# PrivateCloud acceptance test app

A throwaway application for testing PrivateCloud's production branch → live
workflow against real github.com. It contains no secrets and no real data.

Every response is the version name (`version-a`, `version-b`, ...). Each commit
on `main` is written by `scripts/acceptance/set-version.sh` from the PrivateCloud
repository:

| Version | What it does |
|---|---|
| `c` | The Docker build fails on purpose. |
| `e` | The image builds and starts, but every request (including `/health`) answers 503. |
| any other | A working release. |

PrivateCloud project settings: port `3000`, health check path `/health`.
