# GitHub

PrivateCloud deploys from GitHub using a personal access token stored encrypted on your
server. The token never reaches the browser.

## Connect GitHub

1. On GitHub: **Settings → Developer settings → Personal access tokens → Fine-grained
   tokens → Generate new token**.
2. *Repository access*: select the repositories you want to deploy (or all).
3. *Repository permissions*:
   - **Contents: Read-only** — download source code
   - **Metadata: Read-only** — list repositories and branches (always required)
   - **Webhooks: Read and write** — optional, lets PrivateCloud create the auto-deploy
     webhook for you
4. Copy the token, open **PrivateCloud → Settings → GitHub**, paste it and click
   **Connect GitHub** (your password is confirmed first).

PrivateCloud verifies the token immediately (`GET /user`) and shows the connected account.
Fine-grained tokens expire; when one does, deployments fail with "GitHub rejected the
access token" — create a new token and connect again.

Classic tokens with the `repo` scope (and `admin:repo_hook` for webhooks) also work, but
grant much broader access.

Without a token you can still deploy **public** repositories (subject to GitHub's
anonymous rate limit of 60 requests/hour per IP).

## Choose a repository and branch

*New Project → GitHub repository* lists your repositories; pick one and a branch. The
project page shows:

- **Latest commit** — the newest commit on the branch (*Check for new commits* refreshes it;
  pushes received by the webhook update it automatically).
- **Production commit** — the commit currently live.
- **Deploy Latest** — builds the newest commit. A banner appears when the two differ.

PrivateCloud downloads the exact commit as a tarball from the GitHub API, so the build
always matches the commit shown — even if someone pushes during the deployment.

## Auto deploy

*Project → Settings → Auto deploy*. When on, every push to the configured branch
triggers a deployment:

```
git push → GitHub webhook → signature verified → deployment queued → build → health check → live
```

- Pushes to other branches, other repositories, tag pushes and branch deletions are
  ignored (visible under *Recent deliveries*).
- Each GitHub delivery is processed once, even if GitHub redelivers it.
- If you push several times quickly, a newer push replaces a deployment that has not
  started yet; a deployment that is already running finishes first.
- Auto deploy is off by default. Deploy manually until the project deploys reliably.

**Automatic webhook**: with *Webhooks: Read and write* permission, enabling auto deploy
creates the webhook (and disabling it removes the webhook).

**Manual webhook**: otherwise, add it in the repository (*Settings → Webhooks → Add
webhook*):

| Field | Value |
| --- | --- |
| Payload URL | shown on the project's Settings page, `https://<dashboard>/api/v1/webhooks/github/<project-id>` |
| Content type | `application/json` |
| Secret | click *Reveal* next to "Secret" on the Settings page |
| Events | Just the push event |

GitHub sends a *ping* when the webhook is created; it shows as "Webhook connected".

The webhook URL uses `PC_DASHBOARD_DOMAIN` (or `PC_PUBLIC_URL`). GitHub must be able to
reach it over HTTPS with a valid certificate.

## Other git hosts

*New Project → Git URL* accepts any public `https://` repository (GitLab, Bitbucket,
Gitea…). Private repositories on other hosts are not supported in V1. Auto deploy can be
configured manually with any host that can send GitHub-compatible signed push payloads.

## Troubleshooting

| Message | Cause / fix |
| --- | --- |
| *Repository, branch or commit not found, or the GitHub token cannot access it* | Check the name and branch; for fine-grained tokens, make sure the repository is included in the token's repository access. |
| *GitHub rejected the access token* | The token expired or was revoked. Connect again. |
| *GitHub API rate limit reached* | Connect a token (5,000 requests/hour). |
| *GitHub could not be reached* | Outbound HTTPS from the server is blocked or GitHub is down. The live version keeps running; try again later. |
| Webhook deliveries show 401 in GitHub | The secret in GitHub does not match. Rotate it on the Settings page and update GitHub. |
