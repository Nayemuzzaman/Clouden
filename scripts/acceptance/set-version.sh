#!/usr/bin/env bash
# Write one version of the acceptance test app into a local clone of the
# dedicated test repository and commit it. Nothing is pushed: review the commit,
# then run `git push origin main` yourself.
#
#   scripts/acceptance/set-version.sh <clone-dir> <name>
#
# <name> is a short lowercase label, used as "version-<name>":
#   c        the Docker build fails on purpose
#   e        builds and starts, but every request answers 503 (health check fails)
#   others   a working release (a, b, d, f, g1, g2, ...)
#
# See docs/github-acceptance-test.md.
set -euo pipefail

fail() { echo "error: $*" >&2; exit 1; }

[[ $# -eq 2 ]] || fail "usage: $0 <clone-dir> <name>"
repo="$1"
name="$2"
[[ "$name" =~ ^[a-z][a-z0-9-]{0,19}$ ]] || fail "the name must be a short lowercase label such as a, b or g1"
[[ -d "$repo/.git" ]] || fail "$repo is not a git clone"
[[ -z "$(git -C "$repo" status --porcelain)" ]] || fail "$repo has uncommitted changes; commit or discard them first"

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
template="$here/test-app"

cp "$template/server.js" "$template/README.md" "$repo/"
printf 'version-%s\n' "$name" > "$repo/VERSION"

case "$name" in
  c)
    printf 'ok\n' > "$repo/HEALTH"
    # Same image as every other version, plus one build step that always fails.
    awk '/^USER node$/ { print "RUN echo \"version-c fails its Docker build on purpose\" >&2 && exit 1" } { print }' \
      "$template/Dockerfile" > "$repo/Dockerfile"
    grep -q 'exit 1' "$repo/Dockerfile" || fail "could not write the broken Dockerfile"
    summary="Docker build fails on purpose"
    ;;
  e)
    printf 'fail\n' > "$repo/HEALTH"
    cp "$template/Dockerfile" "$repo/Dockerfile"
    summary="builds and starts, but every request answers 503"
    ;;
  *)
    printf 'ok\n' > "$repo/HEALTH"
    cp "$template/Dockerfile" "$repo/Dockerfile"
    summary="working release"
    ;;
esac

git -C "$repo" add -A
git -C "$repo" commit -q -m "version-$name: $summary"
echo "Committed $(git -C "$repo" rev-parse HEAD) on $(git -C "$repo" branch --show-current): version-$name ($summary)"
echo "Push it with: git -C $repo push origin HEAD"
