#!/usr/bin/env bash
#
# Checks that scripts/install.sh refuses unsafe input and environments before
# changing anything. Runs inside a throw-away ubuntu:24.04 container (CI):
#
#   docker run --rm -v "$PWD:/src:ro" ubuntu:24.04 bash /src/.github/ci/installer-guards.sh
set -uo pipefail

cp -r /src /opt/pc
cd /opt/pc || exit 1
rm -f .env
mkdir -p /run/systemd/system # the installer requires systemd; pretend it is present

failures=0
expect_refusal() { # description, expected message, command...
  local description="$1" expected="$2" output status
  shift 2
  output="$("$@" 2>&1)"; status=$?
  output="$(sed 's/\x1b\[[0-9;]*m//g' <<<"$output")"
  if (( status != 0 )) && grep -qF -- "$expected" <<<"$output"; then
    echo "ok   - $description"
  else
    echo "FAIL - $description (exit $status)"; sed 's/^/       | /' <<<"$output" | tail -8
    failures=$((failures + 1))
  fi
}

install=(./scripts/install.sh --domain cloud.acme-corp.io --email ops@acme-corp.io)

expect_refusal "placeholder domain" "is a placeholder or local name" ./scripts/install.sh --domain cloud.example.com --email ops@acme-corp.io
expect_refusal "invalid domain" "is not a valid domain name" ./scripts/install.sh --domain 'cloud;rm -rf /' --email ops@acme-corp.io
expect_refusal "missing email" "--email is required" ./scripts/install.sh --domain cloud.acme-corp.io
expect_refusal "placeholder administrator email" "is a placeholder address" "${install[@]}" --admin-email admin@example.com
expect_refusal "unsafe data directory" "--data-dir must be an absolute path" "${install[@]}" --data-dir '/var/lib/x;rm -rf /'
expect_refusal "unknown option" "Unknown option" "${install[@]}" --yes-really
expect_refusal "missing password file" "Cannot read --admin-password-file" "${install[@]}" --admin-password-file /nonexistent

# A development .env must be refused and left untouched.
cat > .env <<'ENV'
PC_DASHBOARD_DOMAIN=localhost
PC_DATA_DIR=/opt/pc/.data
APP_ENV=local
APP_DEBUG=true
APP_KEY=base64:ZGV2ZWxvcG1lbnQta2V5LW5vdC1mb3ItcHJvZHVjdGlvbg==
DB_PASSWORD=dev
PC_APPS_DB_ADMIN_PASSWORD=dev
SESSION_SECURE_COOKIE=false
PC_UID=0
PC_AUTO_HTTPS=off
PC_ALLOW_INSECURE_GIT=true
ENV
cp .env /tmp/dev.env
expect_refusal "development .env" "is not a valid production configuration" "${install[@]}"
if cmp -s .env /tmp/dev.env; then echo "ok   - development .env left unchanged"; else echo "FAIL - development .env was modified"; failures=$((failures + 1)); fi
rm -f .env

expect_refusal "non-root user" "Run this installer as root" su nobody -s /bin/bash -c "${install[*]}"

sed -i 's/^VERSION_ID=.*/VERSION_ID="22.04"/' /etc/os-release
expect_refusal "unsupported Ubuntu version" "supports Ubuntu 24.04 LTS only" "${install[@]}"

echo
if (( failures )); then
  echo "$failures installer guard check(s) failed"
  exit 1
fi
echo "All installer guard checks passed"
