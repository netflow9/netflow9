#!/bin/bash
# CLD: 2026-11-01T19:00:00+02:00: the start of Rail22's containers (docker/Dockerfile), as the image's user nfn:
#   php-fpm   (app)     the setup from NFN_SETUP_JSON when there is no app.env yet (else the browser's /setup), the indexes (sys::indexes: an update's new ones), the web browser's
#                       entry in mcp.json (NFN_BROWSER_URL, the browser profile), then PHP-FPM on :9000
#   daemon    (daemon)  waits for app.env (the setup), then the workflow daemon (app/v.php daemon::run)
#   anything else       run as it is (e.g. docker compose exec app app/v check::run)
# app.env, plugins.json and mcp.json are in NFN_CONFIG_DIR (the config volume; V::configDir)
set -euo pipefail

cd "$BASE"
CONSOLE=(php -d display_errors=stderr -f "$BASE/app/v.php" --)
ENV_FILE="$NFN_CONFIG_DIR/app.env"

mkdir -p "$BASE/logs" "$BASE/tmp" "$BASE/run" "$BASE/var/php/session" "$BASE/var/php/wsdlcache" "$BASE/var/php/opcache" 2>/dev/null || true

case "${1:-php-fpm}" in
    php-fpm)
        if [ ! -f "$ENV_FILE" ] && [ -n "${NFN_SETUP_JSON:-}" ]; then
            echo "nfn: no app.env: the setup from NFN_SETUP_JSON"
            if [ -f "$NFN_SETUP_JSON" ]; then
                "${CONSOLE[@]}" sys::install < "$NFN_SETUP_JSON" || echo "nfn: the setup failed (above): finish it in the browser (/setup)"
            else
                printf '%s' "$NFN_SETUP_JSON" | "${CONSOLE[@]}" sys::install || echo "nfn: the setup failed (above): finish it in the browser (/setup)"
            fi
        fi
        if [ -f "$ENV_FILE" ]; then
            "${CONSOLE[@]}" sys::indexes | tail -n 1 || echo "nfn: sys::indexes failed (above)"
            if [ -n "${NFN_BROWSER_URL:-}" ]; then
                "${CONSOLE[@]}" mcpserver::add --id=playwright --file="$BASE/scripts/playwright/mcp-server.json" --url="$NFN_BROWSER_URL" \
                    --service=playwright --managed-by=docker/entrypoint.sh >/dev/null && echo "nfn: the web browser in mcp.json ($NFN_BROWSER_URL)" \
                    || echo "nfn: the web browser was not added to mcp.json (above)"
            fi
        else
            echo "nfn: not set up yet: open the site in a browser (/setup)"
        fi
        exec /opt/remi/php85/root/usr/sbin/php-fpm -F -y "$PHP_ETC/php-fpm.conf"
        ;;
    daemon)
        until [ -f "$ENV_FILE" ]; do
            echo "nfn: the daemon waits for the setup (no app.env yet)"
            sleep 15
        done
        exec php -d display_errors=stderr -d session.save_path=/tmp -f "$BASE/app/v.php" -- daemon::run --debug
        ;;
    *)
        exec "$@"
        ;;
esac
