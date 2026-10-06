#!/usr/bin/env bash
# On the server: download one commit archive from real GitHub exactly as a
# deployment does (PrivateCloud's GitHubClient + the saved connection) and show
# every request it sent, with whether the Authorization header was attached.
# GitHub redirects archive downloads to codeload.github.com; the token must reach
# api.github.com only. The token itself is never printed.
#
#   sudo scripts/acceptance/trace-tarball-redirect.sh <owner/repo> <full-commit-sha>
#
# Exit status: 0 on PASS, 1 otherwise.
set -euo pipefail

[[ $# -eq 2 ]] || { echo "usage: $0 <owner/repo> <full-commit-sha>" >&2; exit 2; }
[[ "$1" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || { echo "the repository must look like owner/name" >&2; exit 2; }
[[ "$2" =~ ^[0-9a-f]{40}$ ]] || { echo "the commit must be a full 40-character SHA" >&2; exit 2; }

container="${PC_APP_CONTAINER:-privatecloud-app}"
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

out="$(docker exec -e PC_TRACE_REPO="$1" -e PC_TRACE_SHA="$2" "$container" \
  php artisan tinker --execute "$(cat "$here/trace-tarball-redirect.php")" 2>&1)"
echo "$out"
grep -q '^RESULT: PASS' <<< "$out"
