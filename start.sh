#!/usr/bin/env bash
# Run WhatsApp CRM on this computer (Mac / Linux).  Usage: ./start.sh [port]
# Starts the web server, opens the browser (installer on first run), then starts the
# background worker + scheduler once the app is installed. Ctrl+C stops everything.
set -euo pipefail
cd "$(dirname "$0")"
PORT="${1:-8000}"

command -v php >/dev/null || { echo "PHP is not installed. Install PHP 8.3+ first (macOS: brew install php)."; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || { echo "PHP 8.3 or newer is required (you have $(php -r 'echo PHP_VERSION;'))."; exit 1; }
mkdir -p storage/logs storage/sessions storage/mail

php -S "localhost:$PORT" -t public >storage/logs/server.log 2>&1 &
SERVER=$!
trap 'kill $SERVER ${WORKER:-} ${SCHED:-} 2>/dev/null; echo; echo "Stopped."' EXIT
sleep 1
URL="http://localhost:$PORT"
echo "WhatsApp CRM is running at $URL  (Ctrl+C to stop)"
if [ "$(uname)" = "Darwin" ]; then open "$URL" 2>/dev/null || true
elif command -v xdg-open >/dev/null; then xdg-open "$URL" >/dev/null 2>&1 || true
fi

if [ ! -f storage/installed.lock ] && ! grep -qE '^APP_KEY=\S+' .env 2>/dev/null; then
  echo "First run: finish the installer in your browser. Waiting…"
  while [ ! -f storage/installed.lock ]; do sleep 2; done
  echo "Installed."
fi

php bin/console queue:work &
WORKER=$!
( while true; do php bin/console schedule:run >/dev/null 2>&1; sleep 60; done ) &
SCHED=$!
echo "Background worker started (receives and sends WhatsApp messages)."
wait $SERVER
