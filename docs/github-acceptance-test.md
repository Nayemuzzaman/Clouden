# Real github.com + Vultr acceptance test

The production branch → live workflow passes its local tests (unit, feature and an
end-to-end run against a **simulated** GitHub). This runbook is the test against
real github.com, a real Vultr server and real Let's Encrypt certificates that must
pass before the `feature/github-main-to-live` pull request is merged.

Rules for the whole test:

- Use a **disposable** server, dedicated **test** repositories and **test** hostnames.
  Do not deploy an important application and do not move a production domain.
- No real customer or user data, anywhere.
- The GitHub token is entered **only** in the dashboard (*Settings → GitHub*). It never
  goes into a shell command, the repository, an issue or PR, a log, a screenshot or
  this document. If a terminal is unavoidable, read it with `read -rs`.
- A case is **PASS** only when it passed on the real systems. If something fails, do not
  work around it by hand: reproduce it, fix the cause, add a regression test, run the local
  suite, push to the PR branch, wait for CI, then repeat the case.

Tools (all in [`scripts/acceptance/`](../scripts/acceptance)):

| Script | Where it runs | What it does |
|---|---|---|
| `set-version.sh <clone> <name>` | your workstation | Writes and commits version `<name>` of the test app (`c` = broken build, `e` = failing health check). Never pushes. |
| `request-loop.sh <url> [seconds]` | **another machine** | Requests the app continuously; counts successes, non-200s, connection errors and timeouts; prints every version change. |
| `port-check.sh <ip> [--full]` | **another machine** | Probes which TCP ports really answer (expected: 22, 80, 443 only). |
| `cert-check.sh <host>` | **another machine** | Verifies the certificate with the normal trust store: trusted issuer (not Caddy's local CA), hostname, chain, dates. |
| `trace-tarball-redirect.sh <owner/repo> <sha>` | the server | Downloads one commit archive exactly as a deployment does and shows, per request, whether the Authorization header was sent. |

## 1. Test repositories

Create two **new, empty** repositories in your account (no README), for example
`privatecloud-acceptance-public` (**public**) and `privatecloud-acceptance-private`
(**private**). Then, on your workstation, with this repository checked out:

```bash
for v in public private; do
  mkdir -p ~/acceptance/$v && git -C ~/acceptance/$v init -b main
  git -C ~/acceptance/$v remote add origin git@github.com:<you>/privatecloud-acceptance-$v.git
  scripts/acceptance/set-version.sh ~/acceptance/$v a
  git -C ~/acceptance/$v push -u origin main
done
```

`set-version.sh` copies the test app (Node, no dependencies, `Dockerfile` included) and
commits it. Every HTTP response is the version name (`version-a`, ...), so a request from
anywhere shows which commit is live. Project settings: port `3000`, health check path
`/health`.

## 2. Temporary GitHub token

GitHub → *Settings → Developer settings → Fine-grained personal access tokens → Generate
new token*:

- **Name**: `privatecloud-acceptance` · **Expiration**: the shortest that covers the test
  (custom, 1–2 days; at most 7).
- **Repository access**: *Only select repositories* → the two acceptance repositories.
  Nothing else.
- **Repository permissions**: exactly *Metadata: Read-only*, *Contents: Read-only*,
  *Webhooks: Read and write*. Nothing else, no account permissions. Do not add
  permissions to make a failing case pass: a failure there is a finding.

Copy the token straight into PrivateCloud (§4), not into a file or a chat. **Revoke it when
the test ends** (§9).

## 3. Server and DNS

Follow [first-server-test.md](first-server-test.md) §0–§3, with these changes:

- Vultr: Ubuntu **24.04 LTS** x64. 1 vCPU / 2 GB is acceptable for this test; record what
  you used.
- DNS: `A` records for a dashboard test name and an app test name (for example
  `pc-test.<your-domain>` and `app-test.<your-domain>`) → the server's IPv4. No `AAAA`
  unless you are deliberately testing IPv6. Do not reuse a production name.
- Clone **this** branch:
  `git clone -b feature/github-main-to-live https://github.com/Nayemuzzaman/Clouden.git /opt/privatecloud`

Record the baseline (and again after the first builds):

```bash
nproc; free -m; df -h /; docker stats --no-stream
```

## 4. Acceptance sequence

Start `request-loop.sh https://app-test.<your-domain>/` on **another machine** before
step 10 and leave it running through step 37 (restart it after the reboot). Note the time of
each step so the loop's version changes can be matched to it.

The *Result* column is filled in during the test: PASS / FAIL with what was observed.

| # | Step | Expected | Result |
|---|---|---|---|
| 1 | `sudo ./scripts/install.sh --domain pc-test.<domain> --email <you>` | Completes; ends with `validate-install.sh` 0 FAIL | |
| 2 | Run the installer again (same arguments) | Idempotent: no new secrets, data intact, 0 FAIL | |
| 3 | `sudo ./scripts/validate-install.sh` | 0 FAIL | |
| 4 | `cert-check.sh pc-test.<domain>` (another machine) | RESULT: PASS (Let's Encrypt issuer) | |
| 5 | `port-check.sh <server-ip> --full` (another machine) | RESULT: PASS (only 22, 80, 443) | |
| 6 | Sign in, sign out, wrong password, session expiry | As in first-server-test.md §6 | |
| 7 | **Public, no token yet**: create a project from `privatecloud-acceptance-public`, branch `main`, domain `app-test.<domain>`, port 3000, health `/health` | Project created; repository shown as public | |
| 8 | *Deploy Latest* | `version-a` live; deployment SHA = `git rev-parse HEAD` of the public clone | |
| 9 | `cert-check.sh app-test.<domain>` | RESULT: PASS | |
| 10 | Add the webhook by hand ([github.md → Webhooks](github.md#webhooks)), turn Auto deploy on; GitHub *ping* | Ping delivery 200 | |
| 11 | `set-version.sh ~/acceptance/public b` + push | Delivery 200 in GitHub; deployment trigger *Webhook*; its SHA = pushed SHA; `version-b` live; status **Synced** | |
| 12 | `set-version.sh … c` + push | Build fails; `version-b` keeps answering; project says the latest commit failed | |
| 13 | `set-version.sh … d` + push | `version-d` live | |
| 14 | `set-version.sh … e` + push | Health check fails; `version-d` keeps answering; failed container removed | |
| 15 | *Deployments* → roll back to the `version-b` deployment | `version-b` live; GitHub `main` still at E; status **Rolled back · out of sync** | |
| 16 | GitHub → webhook → *Recent deliveries* → *Redeliver* the E push | Response `ignored`; nothing deploys; `version-b` stays | |
| 17 | `set-version.sh … f` + push | `version-f` live automatically; status **Synced** | |
| 18 | *Redeliver* the F push | Response `duplicate`; no second deployment | |
| 19 | Change the webhook secret in GitHub, push `g1` | Delivery 401; nothing deploys; audit log `webhook.rejected`. Restore the secret, *Check webhook*, *Deploy Latest* | |
| 20 | Push a commit to another branch, and a tag | Both `ignored`; nothing deploys | |
| 21 | Rapid pushes: `set-version … g2`, push, `… g3`, push, `… g4`, push (within ~10 s) | Production ends on `version-g4`; intermediate waiting deployments *Superseded*; no failures in the loop | |
| 22 | **Private**: *Settings → GitHub* → paste the token → *Check connection* | Connected; permissions shown; token masked and never shown again | |
| 23 | Create a project from `privatecloud-acceptance-private` (domain e.g. `app2-test.<domain>` or switch the loop to it) | Repository shown as private; access OK | |
| 24 | Repeat 8 and 11–21 on the private repository (Auto deploy on now creates the webhook through the token) | Same results as public | |
| 25 | `sudo scripts/acceptance/trace-tarball-redirect.sh <you>/privatecloud-acceptance-private <sha of HEAD>` | Request 1 `api.github.com` SENT; request 2 `codeload.github.com` not sent; RESULT: PASS | |
| 26 | Delete the webhook in GitHub → *Check webhook* | Re-created; only PrivateCloud's own hook touched | |
| 27 | Remove *Contents* from the token (edit it in GitHub), *Deploy Latest* with a new commit | Fails at fetching with the missing-permission message; production stays up. Restore *Contents: Read-only* | |
| 28 | Remove the private repository from the token's repository access, push | "can no longer access"; production stays up. Restore access | |
| 29 | Make the private repository public, then private again, *Check for new commits* + deploy each time | Keeps deploying; visibility follows | |
| 30 | (Optional) delete `main` on a test repo, then recreate it | Project shows branch missing; production stays up | |
| 31 | **Revoke** the token in GitHub, push | Deployment fails at fetching; *GitHub connection needs attention*; one notification; production stays up | |
| 32 | Create a new token (same minimal permissions), connect it, *Deploy Latest* | Live on the newest commit | |
| 33 | `docker restart privatecloud-worker` during a deployment of a new commit | Deployment recovers or is marked interrupted; production stays up; retry succeeds | |
| 34 | `sudo systemctl restart docker` | See §5 | |
| 35 | `sudo reboot` | See §6 | |
| 36 | `port-check.sh <server-ip> --full` again | RESULT: PASS | |
| 37 | Stop the request loop | Totals recorded in §7 | |

## 5. Docker daemon restart

Record the state before:

```bash
docker ps --format '{{.Names}} {{.Status}}' | sort
docker ps --filter label=privatecloud.managed=true --format '{{.Names}} {{.Label "privatecloud.project"}}' | sort
docker exec privatecloud-app php artisan privatecloud:production-status
```

Then `sudo systemctl restart docker`, wait for the services, and check:

- every control-plane service is back and healthy (`docker compose ps`);
- the platform database answers (sign in works) and the application database has its data;
- Caddy is back; both test domains answer over HTTPS (the request loop shows the gap, if
  any: record it);
- `privatecloud:production-status` exits 0 (branch head, recorded production, container and
  route agree; drifted routes are repaired by the reconciler);
- exactly one application container per project (compare the labelled list with *before*);
  no project, database or volume is missing; no container of another installation or
  unrelated container was touched.

## 6. Full reboot

`sudo reboot`, then check the same list as §5, plus: the GitHub connection is still
configured, Auto deploy is still on, the webhook still delivers (push one more working
commit and see it go live).

## 7. Results

Copy this into the pull request when the test is complete. Fill in only what was actually
observed.

```text
Server: Vultr <plan> (<vCPU>/<RAM>/<disk>), Ubuntu 24.04, region <region>
Resource use: idle RAM <...> MB, peak during build <...> MB, disk after test <...>

REAL GITHUB
Public repo:        PASS / FAIL / NOT TESTED
Private repo:       PASS / FAIL / NOT TESTED
Webhook:            PASS / FAIL
Duplicate:          PASS / FAIL
Forged signature:   PASS / FAIL
Rapid pushes:       PASS / FAIL
Token revocation:   PASS / FAIL
codeload redirect:  PASS / FAIL

REAL VULTR
Install / Re-run / Validation script / Firewall / HTTPS / App deployment /
Docker restart / Full reboot:  PASS / FAIL each

MAIN → LIVE (per repository)
A, B, C (build fails, B survives), D, E (health fails, D survives), rollback to B,
F (auto deploy resumes): observed result each

REQUEST CONTINUITY (external machine, per run of request-loop.sh)
total / successful / failed (non-200) / connection errors / timeouts

FINDINGS
<every problem found, its fix commit, and the re-test result>
```

## 8. If something fails

Collect, without secrets: the deployment log from the dashboard,
`docker logs --tail 200 privatecloud-worker`, `privatecloud:production-status --json`, the
GitHub delivery's response code and body (not the request headers). Then follow the rule
at the top: fix, regression test, CI, re-test.

## 9. Cleanup

1. **Revoke the token** (GitHub → fine-grained tokens → the acceptance token → *Delete*).
2. Turn Auto deploy off / delete the projects; check in GitHub that no PrivateCloud webhook
   remains on the test repositories (delete any that the project page reports as orphaned).
3. Destroy the Vultr server and delete the test DNS records.
4. Archive or delete the acceptance repositories.
