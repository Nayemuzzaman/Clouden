#!/usr/bin/env bash
# Check from OUTSIDE the server which TCP ports really answer. A firewall rule
# is not proof: Docker can publish ports around ufw, so test the public address.
#
#   scripts/acceptance/port-check.sh <public-ip-or-hostname> [--full]
#
# Expected open: 22 (SSH), 80 and 443 (Caddy). Everything else must be closed or
# filtered. --full also runs `nmap -Pn -p-` (all 65535 TCP ports) when nmap is
# installed. UDP 443 (HTTP/3) is published on purpose and is not tested here.
# Exit status: 0 when the result matches the expectation, 1 otherwise.
set -uo pipefail

[[ $# -ge 1 ]] || { echo "usage: $0 <public-ip-or-hostname> [--full]" >&2; exit 2; }
host="$1"
full="${2:-}"

expected_open="22 80 443"
# port:what must never be reachable from the internet
must_be_closed="
5432:PostgreSQL
6379:Redis
2375:Docker API (plain)
2376:Docker API (TLS)
2019:Caddy admin API
8000:control plane (FrankenPHP, internal)
8080:internal HTTP
9000:PHP-FPM / internal
3000:application container port
3001:application container port
5000:application container port
8443:alternative HTTPS
"

# Connect with bash's /dev/tcp and give up after 3 seconds (filtered ports hang).
probe() {
  ( exec 3<>"/dev/tcp/$host/$1" ) 2>/dev/null &
  local pid=$!
  ( sleep 3; kill "$pid" 2>/dev/null ) &
  local watchdog=$!
  wait "$pid" 2>/dev/null
  local rc=$?
  kill "$watchdog" 2>/dev/null
  wait "$watchdog" 2>/dev/null
  return $rc
}

problems=0
echo "TCP port check of $host from $(hostname) at $(date -u +%FT%TZ)"
for port in $expected_open; do
  if probe "$port"; then
    echo "  open      $port   (expected)"
  else
    echo "  CLOSED    $port   expected open"
    problems=$((problems + 1))
  fi
done
while IFS=: read -r port what; do
  [[ -n "$port" ]] || continue
  if probe "$port"; then
    echo "  OPEN      $port   $what: must NOT be reachable"
    problems=$((problems + 1))
  else
    echo "  closed    $port   $what"
  fi
done <<< "$must_be_closed"

if [[ "$full" == --full ]]; then
  if command -v nmap >/dev/null; then
    echo
    echo "nmap -Pn -p- $host (open ports only):"
    nmap -Pn -p- --open "$host" | grep -E '^[0-9]+/tcp' | sed 's/^/  /'
    unexpected="$(nmap -Pn -p- --open "$host" | grep -E '^[0-9]+/tcp' | cut -d/ -f1 | grep -vxE '22|80|443' || true)"
    if [[ -n "$unexpected" ]]; then
      echo "  unexpected open ports: $(echo "$unexpected" | tr '\n' ' ')"
      problems=$((problems + 1))
    fi
  else
    echo "nmap is not installed; skipped the full scan"
  fi
fi

echo
if [[ $problems -eq 0 ]]; then
  echo "RESULT: PASS (only the expected ports answer)"
else
  echo "RESULT: FAIL ($problems problem(s) above)"
  exit 1
fi
