#!/bin/bash
# CLD: 2026-11-01T19:00:00+02:00: Haraka's start in Docker: config/ from config.nfn/ (the image's) with NetFlow9's settings filled from the environment (compose.yaml):
#   MAIL_INBOUND_DOMAIN   the inbound addresses' domain (nfn.ini domain and forward_from, me)
#   MONGO_DB, NFN_HARAKA_PW   the database and the password of its user nfn_haraka (scripts/mongo_users.js), on the mongo container
#   DAEMON_HOST, DAEMON_PORT  where the daemon takes NEWJOB (nfn.ini job_host / job_port)
# config/dkim/ is a volume (the DKIM keys: docker compose run --rm -w /srv/haraka/config/dkim haraka /srv/haraka/dkim/dkim_key_gen.sh <domain>)
set -euo pipefail
cd /srv/haraka

: "${MAIL_INBOUND_DOMAIN:?MAIL_INBOUND_DOMAIN is not set (.env)}"
: "${NFN_HARAKA_PW:?NFN_HARAKA_PW is not set (.env)}"
MONGO_DB=${MONGO_DB:-netflow9-db}

# the image's configuration, the keys kept (a volume)
find config -mindepth 1 -maxdepth 1 ! -name dkim -exec rm -rf {} +
cp -r config.nfn/. config/
fill() {
    sed -i -e "s|@BASE@|/srv/haraka|g" -e "s|@DOMAIN@|$MAIL_INBOUND_DOMAIN|g" -e "s|@MONGO_DB@|$MONGO_DB|g" -e "s|@DAEMON_PORT@|${DAEMON_PORT:-9000}|g" "$1"
}
for f in config/nfn.ini config/me config/smtp.ini; do
    [ -f "$f" ] && fill "$f"
done
# in the foreground, the log to the container's output
sed -i -e '/^daemonize=/d' -e '/^daemon_log_file=/d' -e '/^daemon_pid_file=/d' config/smtp.ini
pw=$(node -e 'process.stdout.write(encodeURIComponent(process.argv[1]))' "$NFN_HARAKA_PW")
sed -i -e "s|^uri=.*|uri=mongodb://nfn_haraka:${pw}@${MONGO_HOST:-mongo}:27017/?authSource=${MONGO_DB}|" \
       -e "s|^job_host=.*|job_host=${DAEMON_HOST:-daemon}|" \
       -e "s|^bounce_dir=.*|bounce_dir=/srv/haraka/bounces|" config/nfn.ini

case "${1:-haraka}" in
    haraka) exec node node_modules/haraka/bin/haraka -c /srv/haraka ;;
    *) exec "$@" ;;
esac
