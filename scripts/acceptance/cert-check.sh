#!/usr/bin/env bash
# Verify a site's HTTPS certificate from OUTSIDE the server, with this machine's
# normal trust store (no -k, no extra CA): publicly trusted issuer, the right
# hostname, a complete chain and valid dates. Caddy's local CA is rejected.
#
#   scripts/acceptance/cert-check.sh <hostname>
#
# Exit status: 0 when every check passes, 1 otherwise.
set -uo pipefail

[[ $# -eq 1 ]] || { echo "usage: $0 <hostname>" >&2; exit 2; }
host="$1"
for tool in openssl curl; do
  command -v "$tool" >/dev/null || { echo "$tool is required" >&2; exit 2; }
done

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
problems=0
check() { if eval "$2"; then echo "  ok    $1"; else echo "  FAIL  $1"; problems=$((problems + 1)); fi; }

echo "Certificate check of $host from $(hostname) at $(date -u +%FT%TZ)"

# 1. A normal client: curl verifies the chain and the hostname with the system trust store.
check "curl connects with full verification (chain + hostname)" \
  "curl -sS -o /dev/null --max-time 15 'https://$host/' 2>'$tmp/curl.err'"
[[ -s "$tmp/curl.err" ]] && sed 's/^/        /' "$tmp/curl.err"

# 2. The certificate itself.
openssl s_client -connect "$host:443" -servername "$host" -verify_hostname "$host" -showcerts </dev/null >"$tmp/s_client" 2>&1
openssl x509 -noout -subject -issuer -dates <"$tmp/s_client" >"$tmp/info" 2>/dev/null
openssl x509 -noout -text <"$tmp/s_client" >"$tmp/text" 2>/dev/null
sed 's/^/        /' "$tmp/info"
grep -A1 'Subject Alternative Name' "$tmp/text" | tail -n1 | sed 's/^ */        SAN: /'

check "OpenSSL verifies the chain and hostname (Verify return code: 0)" "grep -q 'Verify return code: 0 (ok)' '$tmp/s_client'"
check "issuer is not Caddy's local CA" "! grep -qi 'Caddy Local Authority' '$tmp/info'"
check "certificate names $host" "grep -A1 'Subject Alternative Name' '$tmp/text' | grep -qE 'DNS:($host|\\*\\.${host#*.})(,|\$)'"
check "certificate is valid now (not expired)" "openssl x509 -noout -checkend 0 <'$tmp/s_client' >/dev/null 2>&1"
check "certificate is valid for at least 7 more days" "openssl x509 -noout -checkend 604800 <'$tmp/s_client' >/dev/null 2>&1"
check "server sends at least one intermediate certificate" "[ \$(grep -c 'BEGIN CERTIFICATE' '$tmp/s_client') -ge 2 ]"

echo
if [[ $problems -eq 0 ]]; then
  echo "RESULT: PASS"
else
  echo "RESULT: FAIL ($problems check(s) failed)"
  exit 1
fi
