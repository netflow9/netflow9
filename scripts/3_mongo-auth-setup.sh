#!/bin/bash
# NetFlow9: Run as root (sudo scripts/3_mongo-auth-setup.sh), while MongoDB still has authorization off.
#CLD: 2026-10-28T21:30:00+02:00: run as root (was: as the app user); the app's folder from the script's place (was /home/app); app.env, nfn.ini and their backups keep their owner (the app's user)
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

NFN_BASE="$BASE" python3 -I - <<'PY'
import os, re
base = os.environ['NFN_BASE']
app_pw, haraka_pw = os.environ['NFN_APP_PW'], os.environ['NFN_HARAKA_PW']
def put(path, pattern, text):
    s = open(path).read()
    s, n = re.subn(pattern, lambda m: text, s, count=1, flags=re.M)
    assert n == 1, path
    #rewritten in place: the file keeps its owner
    open(path, 'w').write(s)
put(base + '/app.env', r'^MONGO_URI=.*$',
    "#CLD: 2026-10-21T17:30:00+03:00: MongoDB user nfn_app (readWrite on netflow9-db only; the database requires sign-in, see SOC2.md)\n"
    f"MONGO_URI='mongodb://nfn_app:{app_pw}@127.0.0.1:27017/?authSource=netflow9-db'")
put(base + '/haraka/config/nfn.ini', r'^uri=.*$',
    "; CLD: 2026-10-21T17:30:00+03:00: MongoDB user nfn_haraka (role nfnHaraka: reads the address lookups, writes the message file and its job)\n"
    f"uri=mongodb://nfn_haraka:{haraka_pw}@127.0.0.1:27017/?authSource=netflow9-db")
PY
chmod 600 app.env haraka/config/nfn.ini

# the credentials are checked now (MongoDB checks a given password even while authorization is off)
. ./app.env >/dev/null 2>&1 || true
mongosh --quiet "$MONGO_URI" --eval 'db.getSiblingDB("netflow9-db").accounts.findOne() ? print("nfn_app: signs in, reads accounts") : print("nfn_app: signs in")'
code=$(curl -sk -o /dev/null -w '%{http_code}' --resolve app.netflow9.io:443:127.0.0.1 https://app.netflow9.io/site/login)
echo "web /site/login with the new URI: $code (expect 200)"

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
