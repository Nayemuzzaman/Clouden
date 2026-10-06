#!/usr/bin/env python3
"""post-receive hook of the fake GitHub's bare repositories: report the pushed
refs to the server, which delivers signed push webhooks (asynchronously, like
GitHub)."""
import json
import os
import sys
import urllib.request

root = os.environ.get("GIT_ROOT", "/srv/git")
git_dir = os.path.abspath(os.environ.get("GIT_DIR", "."))
repo = os.path.relpath(git_dir, root)[: -len(".git")]
updates = []
for line in sys.stdin:
    before, after, ref = line.split()
    updates.append({"before": before, "after": after, "ref": ref})
request = urllib.request.Request(
    os.environ.get("FAKE_GITHUB_URL", "http://127.0.0.1:8080") + "/_internal/pushed",
    data=json.dumps({"repo": repo, "updates": updates}).encode(),
    headers={"Content-Type": "application/json"},
    method="POST",
)
urllib.request.urlopen(request, timeout=30).read()
