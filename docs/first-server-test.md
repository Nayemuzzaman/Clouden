# First real-server test (Vultr)

PrivateCloud V1 has been tested automatically and with real Docker on a workstation, but
not yet on a real VPS with public DNS, Let's Encrypt and GitHub. This procedure validates
a fresh test server **without moving any important application to it**. Use throw-away
names, a test repository and a test token; destroy everything afterwards.

Write down the result of every step (✅ / ❌ + output). Stop at the first ❌ that
affects security (exposed port, secret visible, wrong certificate).

## 0. Prepare (on your workstation)

- A domain you control, with two **new** test names, e.g. `pc-test.example.net`
  (dashboard) and `app-test.example.net` (application). Do not reuse production names.
- A **private** test repository on GitHub containing `examples/simple-node-app` (its
  `Dockerfile`, `server.js`, `package.json`) at the repository root, branch `main`.
- A **fine-grained** GitHub token, expiring in 7 days, with access to that one repository
  only: *Metadata: Read*, *Contents: Read*, *Webhooks: Read and write*.
- The branch to test: `feature/implementation` of
  `https://github.com/Nayemuzzaman/Clouden` (or, for an unpushed branch, a bundle:
  `git -C /path/to/Clouden bundle create /tmp/privatecloud.bundle feature/implementation`).

## 1. Create the server

1. Vultr → *Deploy* → *Cloud Compute (shared CPU)* → **Ubuntu 24.04 LTS x64**, 2 vCPU /
   4 GB (optionally repeat later with 1 vCPU / 2 GB to test the small-server path).
2. Add your **SSH key**. Leave IPv6 enabled (it exercises the AAAA/IPv6 paths).
3. Optional Vultr firewall group: allow 22/tcp (your IP only), 80/tcp, 443/tcp, 443/udp.
4. Note the IPv4 (and IPv6) address.

## 2. DNS

Create `A` records `pc-test` and `app-test` → the server's IPv4. Create `AAAA` records
→ the server's IPv6 only if you want to test IPv6 issuance; otherwise create **no** AAAA
record. Wait until both resolve:

```bash
dig +short A pc-test.example.net @1.1.1.1
dig +short AAAA pc-test.example.net @1.1.1.1
```

## 3. Prepare the server

```bash
ssh root@<ip>
apt-get update && apt-get -y upgrade && reboot      # then ssh in again
git clone -b feature/implementation https://github.com/Nayemuzzaman/Clouden.git /opt/privatecloud
# or, from a bundle copied with scp: git clone -b feature/implementation /root/privatecloud.bundle /opt/privatecloud
cd /opt/privatecloud && git log -1 --oneline        # the commit you expect
```

## 4. Install

```bash
sudo ./scripts/install.sh --domain pc-test.example.net --email you@example.net
```

Expect: no errors; the password prompt; the final summary without any secret; the
validation summary. Then:

```bash
ls -l /opt/privatecloud/.env                # -rw------- root root
grep -c . /opt/privatecloud/.env            # values exist; do NOT paste them anywhere
docker compose ps                           # 8 services, app "healthy"
cat /etc/cron.d/privatecloud; systemctl is-enabled privatecloud docker
```

Run the installer **again** with the same arguments: it must keep `.env`, report the
existing administrator and finish successfully.

## 5. Validate exposure and HTTPS

On the server:

```bash
sudo ./scripts/validate-install.sh          # expect 0 FAIL; HTTPS PASS once DNS is correct
docker logs privatecloud-caddy 2>&1 | grep -iE 'certificate obtained|error' | tail
```

From your workstation (not the server):

```bash
nmap -Pn -p 22,80,443,2019,2375,2376,5432,6379,8000 <ip>           # only 22, 80, 443 open
nmap -Pn -6 -p 22,80,443,2019,2375,2376,5432,6379,8000 <ipv6>      # same over IPv6
curl -sI http://pc-test.example.net | head -3                       # 308 → https
curl -sI https://pc-test.example.net | grep -iE 'strict-transport|content-security'
echo | openssl s_client -connect pc-test.example.net:443 -servername pc-test.example.net 2>/dev/null | openssl x509 -noout -issuer -dates   # Let's Encrypt
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: other.invalid' http://<ip>/   # 404
curl -sI -H 'Origin: https://evil.example' https://pc-test.example.net/api/v1/auth/csrf | grep -i access-control   # nothing
```

## 6. Sign-in and sessions

1. Open `https://pc-test.example.net`, sign in. Browser dev tools → cookies:
   `Secure`, `HttpOnly` (session), `SameSite=Strict`.
2. Six wrong passwords in a row → "Too many requests".
3. Sign in from a second browser, change the password in the first one → the second
   browser is signed out on its next request.
4. *Settings → Audit log* shows the logins, the failures and the password change.

## 7. GitHub

1. *Settings → GitHub* → paste the token → connected, account shown; the token never
   appears in the page or in the browser's network responses.
2. *New Project* lists the private test repository and its branches.

## 8. Deploy from GitHub (the main path)

1. *New Project*: GitHub repository = the test repo, branch `main`, domain
   `app-test.example.net`, *Create a PostgreSQL database* on, port `3000`, health check
   path `/health`, a secret variable `API_TOKEN=test-secret-value-123`. Create, then
   *Deploy now*.
2. Watch *Queued → Fetching source → Building → Starting → Health checking → Routing →
   Successful*. The build log must not show `test-secret-value-123` (it shows `[secret]`
   if printed).
3. `https://app-test.example.net/health` answers with a valid Let's Encrypt certificate
   (*Domains* shows *HTTPS active*; issuance can take a minute).
4. Start a request loop from your workstation and keep it running during steps 9–10:

   ```bash
   while true; do curl -s -o /dev/null -w '%{http_code} ' https://app-test.example.net/health; sleep 0.2; done
   ```

## 9. Updates, failures, rollback

1. Push a visible change to `main` → *Check for new commits* → *Deploy Latest* →
   Successful; the loop shows no errors.
2. Add `FAIL_HEALTHCHECK=1` → *Redeploy* → fails at *Health checking*; the live version
   keeps answering; the failed container and its image are gone (`docker ps -a`,
   `docker images | grep pc-`). Remove the variable.
3. Push a broken Dockerfile → fails at *Building* with the failing step shown; live
   version unaffected. Revert the commit.
4. *Deployments → Rollback* to an earlier successful deployment → Successful; then deploy
   latest again.

## 10. Auto deploy: `main` → live with real github.com

> The complete ordered test, with a test app, an external request loop and port, certificate
> and redirect checks, is [github-acceptance-test.md](github-acceptance-test.md).

These are the steps the local end-to-end test ([`.github/ci/github-flow-test.sh`](../.github/ci/github-flow-test.sh))
covers against a **simulated** GitHub; here they run against github.com. Keep the request
loop from step 8 running and note any non-200.

1. *Settings → Auto deploy* on → the webhook appears in the GitHub repository (*Settings →
   Webhooks*) pointing at `https://<dashboard>/api/v1/webhooks/github/…`; its *ping*
   delivery returns 200 and the project shows *Webhook: Connected*.
2. `git push origin main` with a visible change → a deployment starts within seconds
   (trigger *Webhook (git push)*), the commit SHA on the deployment equals the pushed
   commit, it succeeds, the status shows **Synced**, the loop shows no errors.
3. Push a broken commit (e.g. `RUN false` in the Dockerfile) → **Deployment failed**, the
   previous version still answers, the project says the latest commit failed and which
   version is still running.
4. Push a fix → it goes live.
5. *Deployments → Rollback* to an earlier version → that version answers; the project shows
   **Rolled back · out of sync**; GitHub → webhook → *Recent deliveries* → *Redeliver* the
   last push → response `ignored` (rolled back), nothing deploys.
6. Push a new commit → auto deploy resumes and it goes live.
7. Push to another branch and push a tag → deliveries answered `ignored`, nothing deploys.
8. *Redeliver* the last push again → `duplicate`; no second deployment.
9. Push three commits in quick succession (three `git push`es) → production ends on the
   last one; intermediate waiting deployments show *Superseded*.
10. Change the webhook secret on GitHub and push → delivery gets 401, nothing deploys, the
    audit log shows `webhook.rejected`. Restore it (*Rotate* in PrivateCloud and *Check
    webhook*).
11. Repeat 2–4 with a **public** repository and no token connected (or check in the
    GitHub token's settings that it was not used for the public repository's code).
12. Delete the webhook in GitHub → *Project → Settings → Check webhook* re-creates it.
13. Make the private test repository public and back → deployments keep working; the
    project shows the visibility after *Check for new commits*.

## 11. Database, backups, restore

1. *Database → Open tables & SQL*: create a table, insert rows, run `SELECT`; a `DROP`
   asks for confirmation.
2. *Backups → Backup Now* (project) → database and volume backups *Completed* with size
   and checksum. Download one (password confirmation).
3. Change data, then *Restore* the backup → a "safety backup before restore" appears; the
   data is back.
4. `sudo ./scripts/backup-platform.sh` → "verified"; files in
   `/var/lib/privatecloud/backups/platform` are `root` / `600`.

## 12. Recovery

1. Start a deployment and, while it is *Building*, run `docker kill privatecloud-worker`
   then `docker start privatecloud-worker` → the deployment becomes *failed —
   interrupted*; the live app never stops answering; a new deployment starts at once.
2. `sudo systemctl restart docker` → with live-restore the app keeps answering; the
   dashboard is back within a minute; *Server* shows all services green.
3. `sudo reboot` → after boot (allow 2 minutes): dashboard and app answer over HTTPS,
   `sudo ./scripts/validate-install.sh` has 0 FAIL, the project shows *Running*.

## 13. Disk and memory pressure (optional but recommended)

1. Fill the disk until less than 2 GB is free:
   `fallocate -l <size> /var/tmp/fill` → *Deploy Latest* fails before building with "Not
   enough free disk space"; *Backup Now* fails with a clear message; the dashboard warns
   about disk usage. `rm /var/tmp/fill`.
2. Set the project's RAM limit to 64 MB and redeploy → observe whether the app starts or
   is reported as killed for exceeding its memory limit; the databases and the dashboard
   stay up. Restore the limit.

## 14. Token failure

1. Revoke the GitHub token on GitHub, then push → the deployment fails at *Fetching
   source*; the project shows *GitHub connection needs attention*; *Settings → GitHub*
   shows "GitHub rejected the saved token" and you get one notification; the live app
   keeps running. Create a new token, connect it, *Deploy Latest*.
2. Create a fine-grained token **without** *Contents: Read* → *Deploy Latest* explains the
   missing permission. Then one without access to the repository → "can no longer
   access". Reconnect the correct token.

## 15. Update procedure

On your workstation, push a trivial commit to the branch (or, when testing from a
bundle, create a new bundle and copy it over the old one with `scp`). On the server: `cd /opt/privatecloud && sudo ./scripts/update.sh` → waits for running
work, backs up, builds, restarts, ends healthy; the app answered throughout.

## 16. Deletion and teardown

1. Delete the project keeping the database → `app-test.example.net` returns 404, the
   container and network are gone, the database is listed as standalone. Delete the
   database.
2. Destroy the Vultr instance, delete the DNS records, revoke the token, delete the test
   repository's webhook if it is still there.

## Results

All steps passing means V1 is ready for a cautious first production use: start with one
non-critical application, keep off-server backups from day one, and keep the previous
hosting available until it has run for a while.
