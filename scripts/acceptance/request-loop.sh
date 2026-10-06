#!/usr/bin/env bash
# Send requests to a live application continuously and count what happened.
# Run it from a machine OUTSIDE the server (a laptop or another VPS), so the
# result covers DNS, the public network, the firewall and Caddy.
#
#   scripts/acceptance/request-loop.sh <url> [seconds] [log-file]
#
# seconds: how long to run (default 0 = until Ctrl-C). Every request has a
# 5-second limit. A request succeeds only with HTTP 200. Version changes are
# printed as they happen (the test app answers with its version name). Each
# failure is written to the log file (default ./request-loop-<time>.log).
# Exit status: 0 when every request succeeded, 1 otherwise.
set -uo pipefail

[[ $# -ge 1 && $# -le 3 ]] || { echo "usage: $0 <url> [seconds] [log-file]" >&2; exit 2; }
url="$1"
duration="${2:-0}"
log="${3:-./request-loop-$(date +%Y%m%d-%H%M%S).log}"
[[ "$duration" =~ ^[0-9]+$ ]] || { echo "seconds must be a whole number" >&2; exit 2; }
command -v curl >/dev/null || { echo "curl is required" >&2; exit 2; }

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
: > "$tmp/versions"

total=0 ok=0 http_fail=0 conn_fail=0 timeouts=0
last_version=""
start=$(date +%s)
echo "Request loop against $url (started $(date -u +%FT%TZ), failures logged to $log)"

summary() {
  local end
  end=$(date +%s)
  echo
  echo "== Request loop summary =="
  echo "url:                 $url"
  echo "duration:            $((end - start)) s"
  echo "total requests:      $total"
  echo "successful (200):    $ok"
  echo "failed (non-200):    $http_fail"
  echo "connection errors:   $conn_fail"
  echo "timeouts (>5 s):     $timeouts"
  echo "versions served (requests per version):"
  sort "$tmp/versions" | uniq -c | sed 's/^/  /'
  [[ $((http_fail + conn_fail + timeouts)) -eq 0 ]]
}

stop=0
trap 'stop=1' INT TERM

while [[ $stop -eq 0 ]]; do
  if [[ "$duration" -gt 0 && $(( $(date +%s) - start )) -ge "$duration" ]]; then
    break
  fi
  total=$((total + 1))
  code="$(curl -sS -o "$tmp/body" -w '%{http_code}' --max-time 5 -H 'Cache-Control: no-cache' "$url" 2>"$tmp/err")"
  rc=$?
  # Stopped (Ctrl-C) while this request was running: it was cut off by the stop, not by the server.
  if [[ $stop -eq 1 ]]; then
    total=$((total - 1))
    break
  fi
  now="$(date -u +%FT%TZ)"
  if [[ $rc -eq 28 ]]; then
    timeouts=$((timeouts + 1))
    echo "$now timeout: $(head -c 200 "$tmp/err")" >> "$log"
  elif [[ $rc -ne 0 ]]; then
    conn_fail=$((conn_fail + 1))
    echo "$now curl exit $rc: $(head -c 200 "$tmp/err")" >> "$log"
  elif [[ "$code" != 200 ]]; then
    http_fail=$((http_fail + 1))
    echo "$now HTTP $code: $(head -c 200 "$tmp/body" | tr '\n' ' ')" >> "$log"
  else
    ok=$((ok + 1))
    version="$(head -n1 "$tmp/body" | tr -d '\r' | head -c 60)"
    echo "$version" >> "$tmp/versions"
    if [[ "$version" != "$last_version" ]]; then
      echo "$now now serving: $version"
      last_version="$version"
    fi
  fi
  sleep 0.2
done

trap - INT TERM
summary
