#!/bin/bash
# NetFlow9: Run as root (sudo scripts/3_mongo-auth-setup.sh), while MongoDB still has authorization off.
#CLD: 2026-10-28T21:30:00+02:00: run as root (was: as the app user); the app's folder from the script's place; app.env, nfn.ini and their backups keep their owner (the app's user)
# - creates nfn_admin (root), nfn_app (readWrite on netflow9-db), nfn_haraka (role nfnHaraka) with random passwords
# - writes the app's credentials into app.env (MONGO_URI) and Haraka's into haraka/config/nfn.ini (uri); backups next to them
# - writes the operators' URI to <app>/.mongodb-admin (600, root's: the web server's PHP cannot read it): move it to a password manager, then delete the file
# - makes app.env and nfn.ini readable by their owner only (600)
# Passwords are never printed. Nothing changes for the running services until MongoDB requires sign-in (root steps at the end).
#CLD: 2026-10-21T23:30:00+03:00: moved here from a session scratchpad; mm (public_html/mm) signs in with nfn_admin (its config.php needs no change); restarts from the scripts folder
set -euo pipefail
umask 077
HERE=$(dirname "$(readlink -f "$0")")
BASE=$(dirname "$HERE")
#CLD: 2026-10-28T21:30:00+02:00: as root only
[ "$(id -u)" -eq 0 ] || { echo "run it as root: sudo $0"; exit 1; }
cd "$BASE"
echo "app: $BASE"


if [ -n "$(mongosh --quiet admin --eval 'print(db.getUsers().users.length || "")')" ]; then
    echo "MongoDB already has users: stopping (nothing changed)"; exit 1
fi

TS=$(date +%Y%m%dT%H%M%S)
cp -p app.env "app.env.bak-$TS"
cp -p haraka/config/nfn.ini "haraka/config/nfn.ini.bak-$TS"
chmod 600 "app.env.bak-$TS" "haraka/config/nfn.ini.bak-$TS"

export NFN_ADMIN_PW=$(openssl rand -hex 24) NFN_APP_PW=$(openssl rand -hex 24) NFN_HARAKA_PW=$(openssl rand -hex 24)
mongosh --quiet "mongodb://127.0.0.1:27017/admin" "$HERE/mongo_users.js"

cat > .mongodb-admin <<EOF
# the MongoDB operators' account (role root): move it to a password manager and delete this file
NFN_ADMIN_URI='mongodb://nfn_admin:${NFN_ADMIN_PW}@127.0.0.1:27017/?authSource=admin'
EOF
chmod 600 .mongodb-admin

#CLD: 2026-10-28T22:00:00+02:00: in the shell (was Python): the first line of file $1 matching the regex $2 is replaced by the lines $3...;
#the file is rewritten in place (it keeps its owner and mode), nothing written when no line matches
put() {
    local file=$1 pattern=$2 line out="" found=0
    shift 2
    while IFS= read -r line || [ -n "$line" ]; do
        if [ "$found" -eq 0 ] && [[ $line =~ $pattern ]]; then
            out+=$(printf '%s\n' "$@")$'\n'
            found=1
        else
            out+="$line"$'\n'
        fi
    done < "$file"
    [ "$found" -eq 1 ] || { echo "$file: no line matches $pattern: not written"; exit 1; }
    printf '%s' "$out" > "$file"
}
put app.env '^MONGO_URI=' \
    "#CLD: 2026-10-21T17:30:00+03:00: MongoDB user nfn_app (readWrite on netflow9-db only; the database requires sign-in, see SOC2.md)" \
    "MONGO_URI='mongodb://nfn_app:${NFN_APP_PW}@127.0.0.1:27017/?authSource=netflow9-db'"
put haraka/config/nfn.ini '^uri=' \
    "; CLD: 2026-10-21T17:30:00+03:00: MongoDB user nfn_haraka (role nfnHaraka: reads the address lookups, writes the message file and its job)" \
    "uri=mongodb://nfn_haraka:${NFN_HARAKA_PW}@127.0.0.1:27017/?authSource=netflow9-db"
chmod 600 app.env haraka/config/nfn.ini

# the credentials are checked now (MongoDB checks a given password even while authorization is off)
. ./app.env >/dev/null 2>&1 || true
mongosh --quiet "$MONGO_URI" --eval 'db.getSiblingDB("netflow9-db").accounts.findOne() ? print("nfn_app: signs in, reads accounts") : print("nfn_app: signs in")'
#CLD: 2026-10-28T22:15:00+02:00: the site's address from app.env (BASE_URL; was app.netflow9.io), asked of this server
url="${BASE_URL:-}"
url="${url%/}"
if [ -n "$url" ]; then
    scheme="${url%%://*}"
    hostport="${url#*://}"
    hostport="${hostport%%/*}"
    host="${hostport%:*}"
    if [ "$host" != "$hostport" ]; then
        port="${hostport##*:}"
    elif [ "$scheme" = "http" ]; then
        port=80
    else
        port=443
    fi
    code=$(curl -sk -o /dev/null -w '%{http_code}' --resolve "$host:$port:127.0.0.1" "$url/site/login" || true)
    echo "web $url/site/login with the new URI: $code (expect 200)"
else
    echo "no BASE_URL in app.env: the web check is skipped"
fi

#CLD: 2026-10-28T21:30:00+02:00: the next steps for root (this script runs as root now); the services restarted by systemd (scripts/4_services-install.sh), else by the old scripts
cat <<EOF

Done. Backups: app.env.bak-* and haraka/config/nfn.ini.bak-* (restore one of them to undo; the users can stay).

Then:
  1. /etc/mongod.conf: replace the line \`#security:\` with
         security:
           authorization: enabled
  2. systemctl restart mongod
  3. restart the long-running clients so they reconnect with their users:
         systemctl restart nfn-daemon nfn-haraka     (installed by scripts/4_services-install.sh)
     or, without the units (from the scripts folder: they load ../app.env relative to it):
         cd $BASE/scripts && ./haraka.sh restart && ./daemon.sh restart
  4. check: mongosh --quiet mongodb://127.0.0.1:27017/netflow9-db --eval 'db.users.findOne()'   -> must fail (requires authentication)

mm (/mm/index.php): sign in as nfn_admin, with the password in $BASE/.mongodb-admin (leave the database field empty).
EOF
