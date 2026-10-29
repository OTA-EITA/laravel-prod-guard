#!/usr/bin/env bash
# Live probes against a fresh Laravel 13 app served by `php artisan serve`.
# Meant to run ONLY on a disposable GitHub-hosted runner from
# .github/workflows/laravel-lab.yml. The "secrets" below are made-up markers.
#
# Usage: probe.sh <app dir> <results.md>
set -uo pipefail

APP=$1
OUT=$2
cd "$APP" || exit 2
BASE=http://127.0.0.1:8000

COOKIE_SECRET=cookie-secret-5e2a
TOKEN_SECRET=token-secret-91fb
PASS_SECRET=pass-secret-c3d8
BOUND=bound-value-7d1c

{
  echo "| Probe | Expected | Observed | Result |"
  echo "|---|---|---|---|"
} > "$OUT"

row() { # probe expected observed pass|fail|info
  local mark
  case $4 in pass) mark="as expected" ;; fail) mark="**differs**" ;; *) mark="info" ;; esac
  printf '| %s | %s | `%s` | %s |\n' "$1" "$2" "$3" "$mark" >> "$OUT"
}
check() { if [ "$2" = "$3" ]; then row "$1" "$2" "$3" pass; else row "$1" "$2" "$3" fail; fi; }
setenv() { # KEY VALUE: replace or append in .env
  if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
}
appkey() { grep '^APP_KEY=' .env | cut -d= -f2-; }

# `php artisan serve` reads .env once at start, so restart it after every change.
serve() {
  pkill -f 'artisan serve' 2>/dev/null; pkill -f 'php -S 127.0.0.1:8000' 2>/dev/null; sleep 1
  nohup php artisan serve --host 127.0.0.1 --port 8000 --no-reload >> serve.log 2>&1 &
  for _ in $(seq 1 30); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 "$BASE/up")" = 200 ] && return 0
    sleep 1
  done
  echo "server did not come up" >&2
}
middleware() { # insert one line into the withMiddleware closure of bootstrap/app.php
  sed -i "s|->withMiddleware(function (Middleware \$middleware): void {|&\n        $1|" bootstrap/app.php
}

fail_page() {
  curl -s -o page.html -w '%{http_code}' --max-time 20 -X POST "$BASE/lab/fail" \
    -H "Cookie: laravel_session=$COOKIE_SECRET" \
    -H "Authorization: Bearer $TOKEN_SECRET" \
    --data-urlencode "email=lab@example.com" --data-urlencode "password=$PASS_SECRET"
}
shows() { if grep -q -- "$1" page.html; then echo shown; else echo hidden; fi; }

# --- 1. Error page with APP_DEBUG=true in production -------------------------
middleware "\$middleware->validateCsrfTokens(except: ['lab/*']);"
setenv APP_ENV production
setenv APP_DEBUG true
serve
code=$(fail_page)
check "APP_DEBUG=true: POST /lab/fail status" "500" "$code"
cp page.html debug-page.html
check "APP_DEBUG=true: Cookie header on the page" "shown" "$(shows "$COOKIE_SECRET")"
check "APP_DEBUG=true: Authorization header on the page" "shown" "$(shows "$TOKEN_SECRET")"
check "APP_DEBUG=true: password from the request body" "shown" "$(shows "$PASS_SECRET")"
check "APP_DEBUG=true: SQL with the bound value" "shown" "$(shows "$BOUND")"
row "APP_DEBUG=true: APP_KEY on the page" "not part of the renderer" "$(shows "$(appkey | cut -c8-30)")" info
row "APP_DEBUG=true: app path on the page" "stack trace has paths" "$(shows "$PWD")" info

# --- 2. Same request with APP_DEBUG=false --------------------------------------
setenv APP_DEBUG false
serve
code=$(fail_page)
check "APP_DEBUG=false: POST /lab/fail status" "500" "$code"
check "APP_DEBUG=false: any of the markers on the page" "hidden" "$(if grep -q -E "$COOKIE_SECRET|$TOKEN_SECRET|$PASS_SECRET|$BOUND" page.html; then echo shown; else echo hidden; fi)"

# --- 3. CORS ------------------------------------------------------------------
cors() {
  curl -s -o /dev/null -D - --max-time 10 -H 'Origin: https://evil.example' "$BASE/api/lab" \
    | tr -d '\r' | awk -F': ' 'tolower($1)=="access-control-allow-origin"{o=$2} tolower($1)=="access-control-allow-credentials"{c=$2} END{printf "origin=%s credentials=%s", (o==""?"-":o), (c==""?"-":c)}'
}
check "CORS default (config not published)" "origin=* credentials=-" "$(cors)"
php artisan config:publish cors --no-interaction >/dev/null 2>&1
sed -i "s/'supports_credentials' => false,/'supports_credentials' => true,/" config/cors.php
serve
check "CORS allowed_origins ['*'] + supports_credentials true" "origin=https://evil.example credentials=true" "$(cors)"

# --- 4. Trusted proxies -------------------------------------------------------
client() {
  curl -s --max-time 10 -H 'X-Forwarded-For: 203.0.113.9' -H 'X-Forwarded-Host: evil.example' \
    -H 'X-Forwarded-Proto: https' "$BASE/lab/client" | jq -c . 2>/dev/null
}
check "Forwarded headers, default TrustProxies" '{"ip":"127.0.0.1","secure":false,"host":"127.0.0.1"}' "$(client)"
middleware "\$middleware->trustProxies(at: '*');"
serve
check "Forwarded headers, trustProxies(at: '*')" '{"ip":"203.0.113.9","secure":true,"host":"evil.example"}' "$(client)"

# --- 5. Telescope -------------------------------------------------------------
if [ -d vendor/laravel/telescope ]; then
  setenv APP_DEBUG false
  setenv APP_ENV local
  serve
  check "Telescope /telescope with APP_ENV=local, not logged in" "200" "$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$BASE/telescope")"
  setenv APP_ENV production
  serve
  check "Telescope /telescope with APP_ENV=production, not logged in" "403" "$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$BASE/telescope")"
fi

echo "probe.sh done" >&2
