#!/bin/bash

#CLD: 2026-10-29T01:30:00+02:00: new: writes the app's nginx site from scripts/nginx/app.conf (@BASE@: the app's folder, @SERVER_NAME@: the host of BASE_URL in app.env), over
#<app>/etc/nginx.conf (a link to /etc/nginx/conf.d/<user>.conf; made when missing), then checks it (nginx -t) and reloads nginx.
#The file there is kept as <file>.bak-<time> (not loaded by nginx: conf.d/*.conf only) and put back when the check fails; the changes are shown first.
#Run as root:
#   sudo scripts/0_nginx-install.sh [--server-name=<host>] [--dry-run]
#     --server-name   the site's host name (default: the host of BASE_URL in app.env)
#     --dry-run       show the changes only

set -euo pipefail

SELF_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
BASE=$(dirname "$SELF_DIR")
TEMPLATE="$SELF_DIR/nginx/app.conf"
LINK="$BASE/etc/nginx.conf"
#CLD: 2026-10-29T04:00:00+02:00: named after the app's user (was app.conf for every app: a second app on the server overwrote it; the user app: app.conf as before)
APP_USER=$(stat -c %U "$BASE/app")
DEFAULT_TARGET="/etc/nginx/conf.d/$APP_USER.conf"

SERVER_NAME=""
DRY_RUN=0

usage() {
    echo "usage: sudo $0 [--server-name=<host>] [--dry-run]"
    exit "${1:-0}"
}

#parse command line parameters
while [ "$#" -gt 0 ]; do
    case $1 in
        --server-name=*) SERVER_NAME="${1#*=}" ;;
        --dry-run) DRY_RUN=1 ;;
        -h|--help) usage 0 ;;
        *) echo "unknown option: $1"; usage 1 ;;
    esac
    shift
done

if [ "$DRY_RUN" -eq 0 ] && [ "$(id -u)" -ne 0 ]; then
    echo "run it as root: sudo $0"
    exit 1
fi
command -v nginx >/dev/null 2>&1 || { echo "nginx is not installed"; exit 1; }

#the host name: BASE_URL of app.env, unless given
if [ -z "$SERVER_NAME" ]; then
    [ -f "$BASE/app.env" ] || { echo "missing: $BASE/app.env (or give --server-name=<host>)"; exit 1; }
    url=$(set +u; . "$BASE/app.env" >/dev/null 2>&1; echo "${BASE_URL:-}")
    host="${url#*://}"
    host="${host%%/*}"
    SERVER_NAME="${host%:*}"
fi
if ! [[ $SERVER_NAME =~ ^[A-Za-z0-9.-]+$ ]]; then
    echo "no valid host name (BASE_URL in app.env: '${url:-}'): give --server-name=<host>"
    exit 1
fi

#the file: where <app>/etc/nginx.conf points, else conf.d/<the app's user>.conf
TARGET="$DEFAULT_TARGET"
if [ -L "$LINK" ] || [ -e "$LINK" ]; then
    TARGET=$(readlink -f "$LINK")
fi
echo "nginx site for $SERVER_NAME (app $BASE) -> $TARGET"

NEW=$(sed -e "s|@BASE@|$BASE|g" -e "s|@SERVER_NAME@|$SERVER_NAME|g" "$TEMPLATE")

for f in "$BASE/ssl/ssl.crt" "$BASE/ssl/ssl.key"; do
    [ -e "$f" ] || echo "warning: $f is missing: nginx -t will fail (put the certificate there)"
done

if [ -f "$TARGET" ] && [ "$NEW" = "$(cat "$TARGET")" ]; then
    echo "no change"
    exit 0
fi
if [ -f "$TARGET" ]; then
    diff -u --label "$TARGET" --label "$TARGET (new)" "$TARGET" <(printf '%s\n' "$NEW") || true
else
    echo "(new file)"
fi
if [ "$DRY_RUN" -eq 1 ]; then
    echo "dry run: nothing written"
    exit 0
fi

BACKUP=""
if [ -f "$TARGET" ]; then
    BACKUP="$TARGET.bak-$(date +%Y%m%dT%H%M%S)"
    cp -p "$TARGET" "$BACKUP"
    echo "+ kept the old one: $BACKUP"
fi
#written in place: the file keeps its owner and mode
printf '%s\n' "$NEW" > "$TARGET"

if ! nginx -t; then
    if [ -n "$BACKUP" ]; then
        cp -p "$BACKUP" "$TARGET"
        echo "nginx -t failed: the old file is back ($TARGET); nothing reloaded"
    else
        rm -f "$TARGET"
        echo "nginx -t failed: $TARGET removed; nothing reloaded"
    fi
    exit 1
fi
systemctl reload nginx
echo "+ nginx reloaded"

#the app's link to it, when missing
if [ ! -e "$LINK" ] && [ ! -L "$LINK" ]; then
    install -d -o "$APP_USER" -g "$APP_USER" "$BASE/etc"
    ln -s "$TARGET" "$LINK"
    chown -h "$APP_USER:$APP_USER" "$LINK"
    echo "+ $LINK -> $TARGET"
fi
echo "Done: https://$SERVER_NAME/"
