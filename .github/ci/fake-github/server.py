#!/usr/bin/env python3
"""
Local GitHub simulation for PrivateCloud's end-to-end test.

This is NOT github.com. It is a small, GitHub-compatible stand-in backed by
REAL git repositories, so the test exercises real commits, real pushes and
real source archives without any external service:

  /api/...                 the GitHub REST API subset PrivateCloud uses
                           (user, repos, branches, commits, contents, tarball,
                           hooks), with public/private repositories, tokens
                           that can be revoked or lack permissions, and
                           rate limiting
  /codeload/...            source archives of exact commits (`git archive`),
                           served under a DIFFERENT host name, like
                           codeload.github.com: a client that forwards its
                           Authorization header there is recorded as a leak
  /git/<owner>/<repo>.git  git smart HTTP (`git http-backend`) for the test
                           developer's real `git push`; a post-receive hook
                           makes the server deliver signed push webhooks to
                           every registered hook, like GitHub does
  /_control/...            test control: create repositories and tokens,
                           change visibility/permissions, hold/reorder/
                           redeliver webhook deliveries, inspect requests

Standard library only (plus the git binary).
"""
import base64
import hashlib
import hmac
import json
import os
import re
import secrets
import ssl
import subprocess
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = os.environ.get("GIT_ROOT", "/srv/git")
PORT = int(os.environ.get("PORT", "8080"))
CODELOAD_HOST = os.environ.get("CODELOAD_HOST", "codeload-fake:8080")
DEVELOPER_PASSWORD = os.environ.get("DEVELOPER_PASSWORD", "developer-push-password")
ZERO = "0" * 40

lock = threading.RLock()
state = {
    "repos": {},          # full_name -> {"private": bool}
    "tokens": {},         # token -> {"revoked", "repos" (None = all), "contents", "hooks"}
    "hooks": {},          # full_name -> {id: {"url", "secret", "events", "active"}}
    "deliveries": [],     # {"id", "hook_id", "repo", "event", "headers", "body", "status", "response", "held"}
    "requests": [],       # {"method", "path", "credential": bool, "status"}
    "codeload_auth_leaks": 0,
    "rate_limited_until": 0,
    "hold": False,
}
tickets = {}  # one-time codeload tickets -> (full_name, sha, expires)
next_hook_id = [1000]


def git(repo_dir, *args, input_bytes=None):
    return subprocess.run(["git", "-C", repo_dir, *args], input=input_bytes, capture_output=True, check=False)


def repo_dir(full_name):
    return os.path.join(ROOT, full_name + ".git")


def commit_info(full_name, ref):
    out = git(repo_dir(full_name), "log", "-1", "--format=%H%x00%an%x00%aI%x00%B", ref + "^{commit}", "--")
    if out.returncode != 0 or not out.stdout:
        return None
    sha, author, date, message = out.stdout.decode().split("\0", 3)
    return {"sha": sha.strip(), "author": author, "date": date, "message": message.strip()}


def branches(full_name):
    out = git(repo_dir(full_name), "for-each-ref", "refs/heads", "--format=%(refname:short) %(objectname)")
    result = {}
    for line in out.stdout.decode().splitlines():
        name, sha = line.rsplit(" ", 1)
        result[name] = sha
    return result


def commit_json(info):
    return {
        "sha": info["sha"],
        "commit": {"message": info["message"], "author": {"name": info["author"], "date": info["date"]}},
        "author": {"login": re.sub(r"[^a-z0-9-]", "-", info["author"].lower())},
    }


def push_payload(full_name, ref, before, after):
    deleted = after == ZERO
    head = None if deleted else commit_info(full_name, after)
    forced = False
    if before != ZERO and not deleted:
        forced = git(repo_dir(full_name), "merge-base", "--is-ancestor", before, after).returncode != 0
    return {
        "ref": ref,
        "before": before,
        "after": after,
        "created": before == ZERO,
        "deleted": deleted,
        "forced": forced,
        "repository": {"full_name": full_name, "private": state["repos"][full_name]["private"], "clone_url": f"https://github.com/{full_name}.git"},
        "pusher": {"name": "developer"},
        "head_commit": None if head is None else {
            "id": head["sha"], "message": head["message"], "timestamp": head["date"], "author": {"name": head["author"]},
        },
        "commits": [] if head is None else [{"id": head["sha"], "message": head["message"]}],
    }


def sign(secret, body):
    return "sha256=" + hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()


def send_delivery(delivery):
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE  # PrivateCloud's Caddy uses its internal CA in this test
    request = urllib.request.Request(delivery["url"], data=delivery["body"], headers=delivery["headers"], method="POST")
    try:
        with urllib.request.urlopen(request, timeout=900, context=ctx) as response:
            delivery["status"], delivery["response"] = response.status, response.read().decode(errors="replace")[:2000]
    except urllib.error.HTTPError as e:
        delivery["status"], delivery["response"] = e.code, e.read().decode(errors="replace")[:2000]
    except Exception as e:  # noqa: BLE001
        delivery["status"], delivery["response"] = 0, str(e)
    delivery["delivered_at"] = time.time()


def deliver(full_name, event, payload, delivery_id=None):
    """Queue one delivery per registered hook and send it asynchronously (unless held)."""
    body = json.dumps(payload).encode()
    created = []
    with lock:
        for hook_id, hook in state["hooks"].get(full_name, {}).items():
            if not hook["active"] or event not in hook["events"] and event != "ping":
                continue
            delivery = {
                "id": delivery_id or str(uuid.uuid4()),
                "hook_id": hook_id,
                "repo": full_name,
                "event": event,
                "url": hook["url"],
                "body": body,
                "headers": {
                    "Content-Type": "application/json",
                    "User-Agent": "GitHub-Hookshot/e2e",
                    "X-GitHub-Event": event,
                    "X-GitHub-Delivery": None,
                    "X-GitHub-Hook-ID": str(hook_id),
                    "X-Hub-Signature-256": sign(hook["secret"], body),
                },
                "status": None,
                "response": None,
                "held": state["hold"],
                "after": payload.get("after"),
                "ref": payload.get("ref"),
            }
            delivery["headers"]["X-GitHub-Delivery"] = delivery["id"]
            state["deliveries"].append(delivery)
            created.append(delivery)
    for delivery in created:
        if not delivery["held"]:
            threading.Thread(target=send_delivery, args=(delivery,), daemon=True).start()
    return created


def public_delivery(d):
    return {k: v for k, v in d.items() if k not in ("body", "headers")} | {"payload": json.loads(d["body"])}


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):  # quiet; requests are recorded in state
        pass

    # ------------------------------------------------------------ helpers
    def body(self):
        if self.headers.get("Transfer-Encoding", "").lower() == "chunked":
            data = b""
            while True:
                size = int(self.rfile.readline().strip() or b"0", 16)
                if size == 0:
                    self.rfile.readline()
                    return data
                data += self.rfile.read(size)
                self.rfile.readline()
        length = int(self.headers.get("Content-Length") or 0)
        return self.rfile.read(length) if length else b""

    def send(self, status, data=None, headers=None, raw=None, content_type="application/json"):
        payload = raw if raw is not None else (b"" if data is None else json.dumps(data).encode())
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(payload)))
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(payload)
        if hasattr(self, "_record"):
            self._record["status"] = status

    def token(self):
        auth = self.headers.get("Authorization")
        if not auth:
            return None
        return re.sub(r"^(bearer|token)\s+", "", auth, flags=re.I)

    def can_see(self, full_name, grant):
        repo = state["repos"].get(full_name)
        if repo is None:
            return False
        if not repo["private"]:
            return True
        return grant is not None and (grant["repos"] is None or full_name in grant["repos"])

    # ------------------------------------------------------------ routing
    def do_GET(self):
        self.route("GET")

    def do_POST(self):
        self.route("POST")

    def do_PATCH(self):
        self.route("PATCH")

    def do_DELETE(self):
        self.route("DELETE")

    def route(self, method):
        parsed = urllib.parse.urlsplit(self.path)
        path = parsed.path
        try:
            if path.startswith("/git/"):
                return self.git_http(method, parsed)
            if path.startswith("/codeload/"):
                return self.codeload(parsed)
            if path.startswith("/_control/") or path.startswith("/_internal/"):
                return self.control(method, path, parsed)
            if path.startswith("/api/"):
                return self.api(method, path[4:], parsed)
            return self.send(404, {"message": "Not Found"})
        except BrokenPipeError:
            pass
        except Exception as e:  # noqa: BLE001
            self.send(500, {"message": f"fake-github error: {e}"})

    # ------------------------------------------------------------ REST API
    def api(self, method, path, parsed):
        token = self.token()
        record = {"method": method, "path": path, "credential": token is not None, "status": None, "at": time.time()}
        self._record = record
        with lock:
            state["requests"].append(record)
            if state["rate_limited_until"] > time.time():
                return self.send(403, {"message": "API rate limit exceeded"}, {"X-RateLimit-Remaining": "0", "X-RateLimit-Reset": str(int(state["rate_limited_until"]))})
            grant = None
            if token is not None:
                grant = state["tokens"].get(token)
                if grant is None or grant["revoked"]:
                    return self.send(401, {"message": "Bad credentials"})

        if path == "/user":
            return self.send(200, {"login": "e2e-developer", "name": "E2E Developer", "avatar_url": None}, {"X-OAuth-Scopes": ""}) if token else self.send(401, {"message": "Requires authentication"})
        if path == "/user/repos":
            visible = [n for n in state["repos"] if token and self.can_see(n, grant)]
            return self.send(200, [{"full_name": n, "private": state["repos"][n]["private"], "default_branch": "main", "description": None, "pushed_at": None} for n in visible])

        m = re.match(r"^/repos/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)(/.*)?$", path)
        if not m:
            return self.send(404, {"message": "Not Found"})
        full_name, rest = m.group(1), m.group(2) or ""
        if not self.can_see(full_name, grant):
            return self.send(404, {"message": "Not Found"})
        private = state["repos"][full_name]["private"]
        if rest.startswith("/hooks"):
            return self.hooks(method, full_name, rest, grant)
        if rest and private and grant is not None and not grant["contents"]:
            return self.send(403, {"message": "Resource not accessible by personal access token"})

        if rest == "":
            return self.send(200, {"full_name": full_name, "private": private, "visibility": "private" if private else "public", "default_branch": "main"})
        if rest == "/branches":
            return self.send(200, [{"name": n, "commit": {"sha": s}} for n, s in branches(full_name).items()])
        if rest.startswith("/branches/"):
            name = urllib.parse.unquote(rest[len("/branches/"):])
            sha = branches(full_name).get(name)
            if sha is None:
                return self.send(404, {"message": "Branch not found"})
            return self.send(200, {"name": name, "commit": commit_json(commit_info(full_name, sha))})
        if rest.startswith("/commits/"):
            ref = urllib.parse.unquote(rest[len("/commits/"):])
            info = commit_info(full_name, ref) if re.match(r"^[A-Za-z0-9._/-]+$", ref) else None
            if info is None:
                return self.send(422, {"message": f"No commit found for SHA: {ref}"})
            return self.send(200, commit_json(info))
        if rest == "/contents":
            ref = urllib.parse.parse_qs(parsed.query).get("ref", ["main"])[0]
            out = git(repo_dir(full_name), "ls-tree", "--name-only", ref, "--")
            return self.send(200, [{"name": n, "type": "file"} for n in out.stdout.decode().splitlines()])
        m = re.match(r"^/tarball/([0-9a-f]{40})$", rest)
        if m:
            if commit_info(full_name, m.group(1)) is None:
                return self.send(404, {"message": "Not Found"})
            ticket = secrets.token_urlsafe(16)
            tickets[ticket] = (full_name, m.group(1), time.time() + 300)
            location = f"http://{CODELOAD_HOST}/codeload/{full_name}/legacy.tar.gz/{m.group(1)}?ticket={ticket}"
            return self.send(302, None, {"Location": location})
        return self.send(404, {"message": "Not Found"})

    def hooks(self, method, full_name, rest, grant):
        if grant is None or not grant["hooks"]:
            return self.send(403 if grant else 404, {"message": "Resource not accessible by personal access token"})
        hooks = state["hooks"].setdefault(full_name, {})
        if rest == "/hooks" and method == "GET":
            return self.send(200, [{"id": i, "active": h["active"], "events": h["events"], "config": {"url": h["url"]}} for i, h in hooks.items()])
        if rest == "/hooks" and method == "POST":
            data = json.loads(self.body() or b"{}")
            with lock:
                hook_id = next_hook_id[0]
                next_hook_id[0] += 1
                hooks[hook_id] = {"url": data["config"]["url"], "secret": data["config"]["secret"], "events": data.get("events", ["push"]), "active": data.get("active", True)}
            self.send(201, {"id": hook_id, "active": True})
            deliver(full_name, "ping", {"zen": "Design for failure.", "hook_id": hook_id, "repository": {"full_name": full_name}})
            return None
        m = re.match(r"^/hooks/(\d+)$", rest)
        if m:
            hook_id = int(m.group(1))
            if hook_id not in hooks:
                return self.send(404, {"message": "Not Found"})
            if method == "DELETE":
                del hooks[hook_id]
                return self.send(204, raw=b"")
            h = hooks[hook_id]
            return self.send(200, {"id": hook_id, "active": h["active"], "events": h["events"], "config": {"url": h["url"]}, "last_response": {"code": None, "status": "unused", "message": None}})
        return self.send(404, {"message": "Not Found"})

    # ------------------------------------------------------------ codeload
    def codeload(self, parsed):
        if self.headers.get("Authorization"):
            with lock:
                state["codeload_auth_leaks"] += 1
        m = re.match(r"^/codeload/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/legacy\.tar\.gz/([0-9a-f]{40})$", parsed.path)
        ticket = urllib.parse.parse_qs(parsed.query).get("ticket", [""])[0]
        entry = tickets.pop(ticket, None)
        if not m or entry is None or entry[0] != m.group(1) or entry[1] != m.group(2) or entry[2] < time.time():
            return self.send(404, {"message": "Not Found"})
        full_name, sha = m.group(1), m.group(2)
        prefix = full_name.replace("/", "-") + "-" + sha[:7] + "/"
        out = git(repo_dir(full_name), "archive", "--format=tar.gz", "--prefix=" + prefix, sha)
        if out.returncode != 0:
            return self.send(500, {"message": out.stderr.decode()})
        return self.send(200, raw=out.stdout, content_type="application/x-gzip")

    # ------------------------------------------------------------ git smart HTTP (test developer)
    def git_http(self, method, parsed):
        m = re.match(r"^/git/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)\.git(/.*)$", parsed.path)
        if not m or m.group(1) not in state["repos"]:
            return self.send(404, {"message": "Not Found"})
        auth = self.headers.get("Authorization", "")
        authorized = False
        if auth.lower().startswith("basic "):
            user, _, password = base64.b64decode(auth[6:]).decode(errors="replace").partition(":")
            authorized = hmac.compare_digest(password, DEVELOPER_PASSWORD)
        if not authorized:  # every git operation needs the developer's credentials
            return self.send(401, {"message": "Authentication required"}, {"WWW-Authenticate": 'Basic realm="fake-github"'})
        env = {
            "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
            "GIT_PROJECT_ROOT": ROOT,
            "GIT_HTTP_EXPORT_ALL": "1",
            "PATH_INFO": "/" + m.group(1) + ".git" + m.group(2),
            "REMOTE_USER": "developer",
            "REMOTE_ADDR": self.client_address[0],
            "REQUEST_METHOD": method,
            "QUERY_STRING": parsed.query,
            "CONTENT_TYPE": self.headers.get("Content-Type", ""),
            "GIT_PROTOCOL": self.headers.get("Git-Protocol", ""),
            "HTTP_CONTENT_ENCODING": self.headers.get("Content-Encoding", ""),
            "FAKE_GITHUB_URL": f"http://127.0.0.1:{PORT}",
        }
        body = self.body() if method == "POST" else b""
        proc = subprocess.run(["git", "http-backend"], input=body, env=env, capture_output=True, check=False)
        head, _, payload = proc.stdout.partition(b"\r\n\r\n")
        if not _:
            head, _, payload = proc.stdout.partition(b"\n\n")
        status, headers, content_type = 200, {}, "application/octet-stream"
        for line in head.decode(errors="replace").splitlines():
            key, _, value = line.partition(":")
            if key.lower() == "status":
                status = int(value.strip().split()[0])
            elif key.lower() == "content-type":
                content_type = value.strip()
            elif key:
                headers[key] = value.strip()
        return self.send(status, raw=payload, headers=headers, content_type=content_type)

    # ------------------------------------------------------------ control
    def control(self, method, path, parsed):
        data = json.loads(self.body() or b"{}") if method in ("POST", "PATCH") else {}
        if path == "/_internal/pushed":
            # called by the post-receive hook of a bare repository
            full_name = data["repo"]
            for update in data["updates"]:
                if update["ref"].startswith("refs/heads/") or update["ref"].startswith("refs/tags/"):
                    deliver(full_name, "push", push_payload(full_name, update["ref"], update["before"], update["after"]))
            return self.send(200, {"ok": True})
        if path == "/_control/repos" and method == "POST":
            full_name, private = data["full_name"], bool(data.get("private"))
            directory = repo_dir(full_name)
            os.makedirs(os.path.dirname(directory), exist_ok=True)
            if not os.path.isdir(directory):
                subprocess.run(["git", "init", "-q", "--bare", "-b", "main", directory], check=True)
                git(directory, "config", "http.receivepack", "true")
                git(directory, "config", "receive.denyDeleteCurrent", "ignore")
                hook = os.path.join(directory, "hooks", "post-receive")
                with open(hook, "w") as f:
                    f.write("#!/bin/sh\nexec python3 /app/post_receive.py\n")
                os.chmod(hook, 0o755)
            state["repos"][full_name] = {"private": private}
            return self.send(201, {"full_name": full_name, "private": private})
        m = re.match(r"^/_control/repos/([^/]+/[^/]+)$", path)
        if m and method == "PATCH":
            state["repos"][m.group(1)]["private"] = bool(data["private"])
            return self.send(200, state["repos"][m.group(1)])
        if path == "/_control/tokens" and method == "POST":
            state["tokens"][data["token"]] = {"revoked": bool(data.get("revoked", False)), "repos": data.get("repos"), "contents": data.get("contents", True), "hooks": data.get("hooks", True)}
            return self.send(201, {"ok": True})
        m = re.match(r"^/_control/tokens/(.+)$", path)
        if m and method == "PATCH":
            state["tokens"][m.group(1)].update({k: v for k, v in data.items() if k in ("revoked", "repos", "contents", "hooks")})
            return self.send(200, {"ok": True})
        if path == "/_control/ratelimit" and method == "POST":
            state["rate_limited_until"] = time.time() + float(data.get("seconds", 0))
            return self.send(200, {"until": state["rate_limited_until"]})
        if path == "/_control/hold" and method == "POST":
            state["hold"] = bool(data.get("on"))
            return self.send(200, {"hold": state["hold"]})
        if path == "/_control/release" and method == "POST":
            held = [d for d in state["deliveries"] if d["held"] and d["status"] is None]
            if data.get("order") == "reverse":
                held.reverse()
            for d in held:
                d["held"] = False
                send_delivery(d)  # sequentially, in the requested order
            return self.send(200, {"released": [d["id"] for d in held]})
        m = re.match(r"^/_control/redeliver/(.+)$", path)
        if m and method == "POST":
            original = next(d for d in state["deliveries"] if d["id"] == m.group(1))
            copy = dict(original, headers=dict(original["headers"]), held=False, status=None, response=None)
            if data.get("new_id"):  # GitHub's "Redeliver" button sends the same payload with a new GUID
                copy["id"] = str(uuid.uuid4())
                copy["headers"]["X-GitHub-Delivery"] = copy["id"]
            with lock:
                state["deliveries"].append(copy)
            send_delivery(copy)
            return self.send(200, public_delivery(copy))
        if path == "/_control/deliver" and method == "POST":
            payload = push_payload(data["repo"], data["ref"], data["before"], data["after"])
            created = deliver(data["repo"], "push", payload)
            return self.send(200, {"deliveries": [d["id"] for d in created]})
        if path == "/_control/state" and method == "GET":
            with lock:
                return self.send(200, {
                    "repos": state["repos"],
                    "hooks": {r: {i: {k: v for k, v in h.items() if k != "secret"} for i, h in hs.items()} for r, hs in state["hooks"].items()},
                    "deliveries": [public_delivery(d) for d in state["deliveries"]],
                    "requests": state["requests"],
                    "codeload_auth_leaks": state["codeload_auth_leaks"],
                })
        return self.send(404, {"message": "Not Found"})


if __name__ == "__main__":
    os.makedirs(ROOT, exist_ok=True)
    print(f"fake-github listening on :{PORT} (simulation, not github.com)", flush=True)
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
