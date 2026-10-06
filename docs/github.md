# GitHub: production branch → live

A GitHub-backed project links one **production branch** (default `main`) to the live
deployment:

```
git push origin main
  → GitHub webhook (signature verified, delivery recorded once)
  → deployment of exactly the pushed commit (SHA)
  → exact commit downloaded → docker build → new container next to the live one
  → health check → Caddy switches traffic → recorded as production
```

The branch is the **desired** source; production is the **last commit that deployed
successfully**. A broken commit never replaces a healthy live version: if a build or a
health check fails, the previous version keeps serving and the dashboard says that the
branch is ahead of production.

> **V1 uses a personal access token (PAT).** A GitHub App — short-lived installation
> tokens, permissions granted per repository, no long-lived secret on the server — is the
> planned improvement (see *GitHub App migration* below).

## Connect GitHub

Public repositories deploy **without any token**. A token is needed for private
repositories and for creating the auto-deploy webhook automatically.

1. GitHub → **Settings → Developer settings → Personal access tokens → Fine-grained
   tokens → Generate new token**.
2. *Repository access*: **Only select repositories** — the ones you deploy.
3. *Expiration*: set one (e.g. 90 days) and a reminder to rotate it.
4. *Repository permissions* — exactly these, nothing else:

   | Permission | Access | Used for |
   | --- | --- | --- |
   | **Metadata** | Read-only | repository visibility, branches (GitHub always includes it) |
   | **Contents** | Read-only | reading commits and downloading the source of **private** repositories |
   | **Webhooks** | Read and write | creating, checking and removing the auto-deploy webhook (optional: you can add the webhook by hand) |

   No *Administration*, *Actions*, *Secrets*, *Pull requests* or write access to contents.
5. **PrivateCloud → Settings → GitHub**, paste the token, **Connect GitHub** (your
   password is confirmed first). PrivateCloud verifies it with `GET /user`.

The token is stored encrypted (Laravel `APP_KEY`), masked in the UI and never returned by
any API response. *Check connection* re-verifies it at any time.

Classic tokens with `repo` (+ `admin:repo_hook`) also work but grant far more access.

## Create a project

*New Project → GitHub repository*: pick a repository from the list (or type
`owner/repository` for any public repository), choose the **production branch**
(default `main`), configure domain, environment and database, and optionally turn on
**Auto deploy**. Before anything is created PrivateCloud checks that the repository is
readable and that the branch exists; GitHub's refusal is shown next to the field.
Then click **Deploy now**: PrivateCloud reads the head of the branch, pins that exact
commit, builds it and makes it live.

Changing the repository or the branch later (*Project → Settings → Source*) is verified
the same way first. Deployments still waiting for the old source are superseded, the
live version keeps running until the next successful deployment, and the webhook moves
to the new repository.

## How public and private repositories are read

| | Public repository | Private repository |
| --- | --- | --- |
| Credential used to read code | **none** (anonymous) | the saved token |
| If anonymous access is refused or rate limited | falls back to the token, and the repository is recorded as private when only the token can see it | — |
| Webhook management | needs the token (*Webhooks: Read and write*) | same |

The code of one exact commit is downloaded as an archive through the GitHub API
(`/repos/{owner}/{repo}/tarball/{sha}`). There is no `git clone` for GitHub
repositories, so there is no `.git` directory, no history, no credential helper, and no
token in a URL, a command line, a process list, a file, an image layer or a log. The
token travels only in the `Authorization` header to the GitHub API; the HTTP client
drops it when GitHub redirects the download to `codeload.github.com`.

Before the build PrivateCloud checks the extracted source: it contains no git metadata
and does not contain the token (if someone committed it, the deployment stops and says
so, without printing it). The token is never a build argument: build arguments come only
from the project's environment variables, and names that could change the build tooling
(`DOCKER_*`, `GIT_*`, `LD_*`, proxies, …) are refused.

If a public repository becomes private, it keeps deploying with the token (if the token
can read it); otherwise deployments fail with a clear message and production stays up.
If a private repository becomes public, *Check for new commits* records it and the token
is no longer used to read its code.

## The project page

The **GitHub** card shows repository, visibility, production branch, auto deploy,
webhook state, the **head of the branch** (commit, message, author, time), the
**production** commit, and the **sync status**:

| Status | Meaning |
| --- | --- |
| **Synced** | production runs the head of the branch |
| **Out of sync** | the branch has a newer commit (not deployed yet, or production was intentionally rolled back) |
| **Deploying \<sha\>** | a deployment is running; the current version keeps serving |
| **Deployment failed** | the latest commit of the branch failed to deploy; the previous version is still running |
| **Unknown** | PrivateCloud has not read the branch yet, or cannot read it |

The branch head is what PrivateCloud last learned from a push webhook or from asking
GitHub (*Check for new commits*, *Deploy Latest*). PrivateCloud does not poll GitHub.

The **Production** card shows what is live: commit, branch, message, when it was
deployed, and whether it matches the branch.

## Deployments

Every deployment is tied to an **immutable commit SHA** when it is created and records
repository, visibility, branch, full SHA, message, author, trigger (webhook, manual,
rollback, redeploy), the GitHub delivery id, start, completion and duration. The fetched
tree is verified to be that commit. Images are tagged `pc-<project>:<deployment number>`
and labelled with the commit.

States: `queued → cloning (fetching source) → building → starting → health_checking →
routing → success`, or `failed`, `cancelled`, `superseded` (replaced before it started).
Rollbacks and redeploys reuse an existing image and skip fetching and building.

A deployment becomes production only after the container started, passed its health
check, Caddy accepted the new route, and that was recorded in the database. Nothing
before that (webhook received, source fetched, image built, container started) changes
production.

### Deploy Latest

Reads the current head of the production branch and deploys exactly that commit. If
production already runs it, the dashboard says so and offers **Redeploy current version**
(restart with the current settings) or **Rebuild from source**. If that commit is
already being deployed (for example by a webhook), the running deployment is reused.
Deploy Latest is available whether auto deploy is on or off; both use the same pipeline.

### Auto deploy

*Project → Settings → Auto deploy*. When on, a push to the production branch deploys
the pushed commit.

- Only `push` events to `refs/heads/<production branch>` deploy. Other branches
  (`develop`, `feature/*`, `release/*` …), tags, other repositories and branch deletions
  are recorded and ignored.
- The HMAC-SHA256 signature (`X-Hub-Signature-256`) is checked against the raw body
  with a constant-time comparison before anything is parsed; invalid signatures get
  `401`, are not stored, and are written to the audit log (event, delivery id, reason;
  at most 10 per project and hour; never the body, headers or secret).
- Each delivery is processed once: the delivery id and the SHA-256 of the signed body
  are unique, so a GitHub redelivery or a replay is answered `duplicate`.
- A push of a commit that is already being deployed reuses that deployment; a push of
  the commit that is already live does nothing.
- If deliveries arrive out of order, an older push that arrives after a newer one is
  ignored, so production never moves backwards. A force-push is a new push and deploys.

### Rapid pushes, locking

One deployment of a project runs at a time (a per-project lock held by the deployment
job, plus an atomic claim of each deployment, plus Caddy's own lock for routing
changes). At most one deployment **waits**: if B is building when C and D are pushed,
C is superseded by D before it starts, B finishes, then D deploys. A deployment that has
started is never skipped. Manual deploys, webhooks, rollbacks and redeploys all go
through the same queue, so they cannot race each other.

### Failed deployments

Build failure, health-check failure, a start that crashes, a GitHub error: production is
not touched. The failed candidate container is removed, the image of the failed build is
deleted, and the project shows **Deployment failed** with the commit, the step, the
useful part of the log, the time and the version that is still running, with *View build
log*, *Retry* (the same commit) and *Open production*.

### Rollback

*Deployments → Rollback* starts the image of an earlier successful deployment, health
checks it and switches traffic. It does **not** change GitHub: the branch keeps its
head, and the project shows **Rolled back · out of sync**.

PrivateCloud records the rollback as intentional and remembers the branch head it rolled
back from. That commit is not redeployed automatically (not even if GitHub redelivers its
push). Auto deploy resumes with the next **new** push, or when you click **Deploy Latest**
/ **Deploy main again**.

### Data

Code deployments never create, recreate or delete the project's PostgreSQL database or
its volumes. Every deployment, rollback and redeploy mounts the same volumes and receives
the project's current environment variables; a commit cannot change PrivateCloud-managed
configuration.

## Webhooks

With *Webhooks: Read and write*, turning auto deploy on creates the webhook
(`push` events, JSON, the project's secret, SSL verification on) and stores its id.
*Settings → Check webhook* re-creates it if it was deleted in GitHub or points at an old
address. Turning auto deploy off, changing the repository, or deleting the project
deletes **only that webhook, by id** — other webhooks of the repository are never
touched. If it cannot be deleted (GitHub unreachable, token without permission), it is
recorded as **orphaned** (audit log + notification + webhook state on the project) so
you can delete it in GitHub; until then it only receives `401`/`404` replies.

The secret is generated per project (40 random characters), stored encrypted, shown only
after a password confirmation, and can be rotated.

Manual webhook (no token, or other hosts sending GitHub-compatible payloads):

| Field | Value |
| --- | --- |
| Payload URL | shown on the project's Settings page: `https://<dashboard>/api/v1/webhooks/github/<project-id>` |
| Content type | `application/json` |
| Secret | *Reveal* next to "Secret" on the Settings page |
| Events | Just the push event |

GitHub must reach `PC_DASHBOARD_DOMAIN` (or `PC_PUBLIC_URL`) over HTTPS with a valid
certificate.

## When GitHub says no

The live application is never stopped because of GitHub. The dashboard shows what to fix:

| Situation | What you see | What happens |
| --- | --- | --- |
| Token expired / revoked | *GitHub connection needs attention* + *Reconnect GitHub* | deployments fail safely; GitHub calls with that token pause for 5 minutes after a rejection (*Check connection* or a new token resumes immediately); one notification |
| Token lacks *Contents: Read* | "needs the *Contents: Read* permission" | same |
| Repository not in the token's access, renamed, deleted, or made private without access | "PrivateCloud can no longer access …" | same |
| Production branch deleted | *Production branch unavailable* + *Choose branch* | production keeps running; pushing the branch again or choosing another branch fixes it |
| Rate limit | "GitHub API rate limit reached … until HH:MM" | no GitHub request is sent until the reset time |
| GitHub unreachable / 5xx | "GitHub could not be reached" | try again later |

## Not supported in V1

- **Git submodules**: the commit archive contains no submodule contents. A repository
  whose `.gitmodules` points at an empty directory fails at *Fetching source* with
  "This repository uses Git submodules". Copy the code into the repository instead.
- **Git LFS**: archives contain only pointer files unless GitHub is set to include LFS
  objects in archives. Pointer files are detected (when `.gitattributes` uses
  `filter=lfs`) and the deployment fails with an explanation.
- Private repositories on other git hosts (the *Git URL* source is public `https://` only).

## Retention

- Source: each deployment builds in its own directory (`<data>/builds/deployment-<id>`,
  named by internal id only), deleted when the deployment ends; leftovers after a crash
  are removed when the worker restarts and by the daily cleanup.
- Images: the newest *N* successful images per project (Settings → image retention,
  default 5) are kept for rollback; the live image is never removed; a failed build's
  image is removed immediately.
- Volumes and databases are never touched by any cleanup.

## Reconciliation

`docker compose exec app php artisan privatecloud:production-status [project]` compares
the branch head, the recorded production deployment, the running container and the
Caddy route, and lists every mismatch (exit code 1 on errors). The same report is
`GET /api/v1/projects/{project}/production`. Every minute `privatecloud:reconcile`
applies the only automatic repair: if the Caddy route does not point at the recorded
production container while that container is running, routing is re-rendered from the
database — only while it can take the project's deployment lock, so never during a
deployment. Everything else is reported.

## GitHub App migration

All GitHub calls go through `App\Services\Source\GitHubClient`, and the credential is
chosen in one place (`SourceFetcher::withGitHub`). A GitHub App replaces the saved PAT
with a short-lived installation token per repository there; webhooks would then come from
the App (one secret) instead of per-project hooks.

## Troubleshooting

| Message | Cause / fix |
| --- | --- |
| *Repository, branch or commit not found, or the GitHub token cannot access it* | Check the name; for fine-grained tokens make sure the repository is selected in the token's repository access. |
| *The branch "main" does not exist* | Choose the right production branch in Settings. |
| *GitHub rejected the access token (expired or revoked)* | Connect a new token in Settings. |
| *The GitHub token cannot read this repository's code* | Add *Contents: Read* to the fine-grained token. |
| *GitHub API rate limit reached* | Wait until the time shown, or connect a token (5,000 requests/hour). |
| Push did not deploy | *Project → Settings → Auto deploy → Recent deliveries* shows each delivery and why it was ignored (other branch, already live, rolled back, older push …). |
| Webhook deliveries show 401 in GitHub | The secret in GitHub does not match. Rotate it on the Settings page and *Check webhook* (or update GitHub). |
