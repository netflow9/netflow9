#!/bin/bash
# NetFlow9: Run as root (sudo scripts/3_mongo-auth-setup.sh), while MongoDB still has authorization off.
#CLD: 2026-10-28T21:30:00+02:00: run as root (was: as the app user); the app's folder from the script's place; app.env, nfn.ini and their backups keep their owner (the app's user)
# - creates nfn_admin (root), nfn_app (readWrite on netflow9-db), nfn_haraka (role nfnHaraka) with random passwords
#   (run again while authorization is off: they are created again, with new passwords; when MongoDB requires sign-in, nothing changes)
# - writes the app's credentials into app.env (MONGO_URI) and Haraka's into haraka/config/nfn.ini (uri); backups next to them
# - writes the operators' URI to <app>/.mongodb-admin (600, root's: the web server's PHP cannot read it): move it to a password manager, then delete the file
# - makes app.env and nfn.ini readable by their owner only (600)
# Passwords are never printed. Nothing changes for the running services until MongoDB requires sign-in (root steps at the end).
#CLD: 2026-10-29T02:30:00+02:00: Haraka's URI also in app.env (HARAKA_MONGO_URI: 2_haraka-install.sh writes it into a new nfn.ini), nfn.ini only when Haraka is installed (was required);
#the database of app.env (MONGO_DB; was netflow9-db); --no-next-steps: the closing steps not printed (install.sh in the app's folder does them)
#   sudo scripts/3_mongo-auth-setup.sh [--no-next-steps]
#CLD: 2026-10-21T23:30:00+03:00: moved here from a session scratchpad; mm (public_html/mm) signs in with nfn_admin (its config.php needs no change); restarts from the scripts folder
set -euo pipefail
umask 077
HERE=$(dirname "$(readlink -f "$0")")
BASE=$(dirname "$HERE")
#CLD: 2026-10-28T21:30:00+02:00: as root only
[ "$(id -u)" -eq 0 ] || { echo "run it as root: sudo $0"; exit 1; }
cd "$BASE"
echo "app: $BASE"
NEXT_STEPS=1
[ "${1:-}" = "--no-next-steps" ] && NEXT_STEPS=0
[ -f app.env ] || { echo "no app.env: set the app up first (sudo $BASE/install.sh, or the setup in the browser)"; exit 1; }
#the app's database (app.env)
export NFN_DB=$(set +u; . ./app.env >/dev/null 2>&1; echo "${MONGO_DB:-netflow9-db}")
HARAKA_INI=haraka/config/nfn.ini


#CLD: 2026-10-28T23:00:00+02:00: MongoDB without authorization: the users are created again (were: any user stopped it); with authorization (the users cannot be listed without signing in): stop
if ! users=$(mongosh --quiet "mongodb://127.0.0.1:27017/admin" --eval 'print(db.getUsers().users.map(u => u.user).join(", "))' 2>&1); then
    if grep -qiE 'auth|unauthorized' <<< "$users"; then
        echo "MongoDB requires sign-in (authorization is on): stopping (nothing changed)"
    else
        echo "MongoDB does not answer: $users"
    fi
    exit 1
fi
if [ -n "$users" ]; then
    echo "MongoDB does not require sign-in: NetFlow9's users are created again, with new passwords (admin users now: $users)"
fi

TS=$(date +%Y%m%dT%H%M%S)
cp -p app.env "app.env.bak-$TS"
chmod 600 "app.env.bak-$TS"
if [ -f "$HARAKA_INI" ]; then
    cp -p "$HARAKA_INI" "$HARAKA_INI.bak-$TS"
    chmod 600 "$HARAKA_INI.bak-$TS"
fi

export NFN_ADMIN_PW=$(openssl rand -hex 24) NFN_APP_PW=$(openssl rand -hex 24) NFN_HARAKA_PW=$(openssl rand -hex 24)
mongosh --quiet "mongodb://127.0.0.1:27017/admin" "$HERE/mongo_users.js"

cat > .mongodb-admin <<EOF
# the MongoDB operators' account (role root): move it to a password manager and delete this file
NFN_ADMIN_URI='mongodb://nfn_admin:${NFN_ADMIN_PW}@127.0.0.1:27017/?authSource=admin'
EOF
chmod 600 .mongodb-admin

#CLD: 2026-10-28T22:00:00+02:00: in the shell (was Python): the first line of file $1 matching the regex $2 is replaced by the lines $3...;
#the file is rewritten in place (it keeps its owner and mode), nothing written when no line matches
#CLD: 2026-10-28T23:00:00+02:00: a line equal to $3 (the comment, from a previous run) is dropped: not repeated at each run
put() {
    local file=$1 pattern=$2 line out="" found=0
    shift 2
    while IFS= read -r line || [ -n "$line" ]; do
        if [ "$line" = "$1" ]; then
            continue
        elif [ "$found" -eq 0 ] && [[ $line =~ $pattern ]]; then
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
    "#CLD: 2026-10-21T17:30:00+03:00: MongoDB user nfn_app (readWrite on the app's database only; the database requires sign-in, see SOC2.md)" \
    "MONGO_URI='mongodb://nfn_app:${NFN_APP_PW}@127.0.0.1:27017/?authSource=${NFN_DB}'"
#CLD: 2026-10-29T02:30:00+02:00: Haraka's URI kept in app.env too (a line added the first time), for Haraka installed later (2_haraka-install.sh)
HARAKA_URI="mongodb://nfn_haraka:${NFN_HARAKA_PW}@127.0.0.1:27017/?authSource=${NFN_DB}"
HARAKA_COMMENT="#MongoDB user nfn_haraka (role nfnHaraka), for Haraka's haraka/config/nfn.ini (scripts/2_haraka-install.sh writes it there)"
grep -q '^HARAKA_MONGO_URI=' app.env || printf '\n%s\nHARAKA_MONGO_URI=\n' "$HARAKA_COMMENT" >> app.env
put app.env '^HARAKA_MONGO_URI=' "$HARAKA_COMMENT" "HARAKA_MONGO_URI='${HARAKA_URI}'"
chmod 600 app.env
if [ -f "$HARAKA_INI" ]; then
    put "$HARAKA_INI" '^uri=' \
        "; CLD: 2026-10-21T17:30:00+03:00: MongoDB user nfn_haraka (role nfnHaraka: reads the address lookups, writes the message file and its job)" \
        "uri=${HARAKA_URI}"
    chmod 600 "$HARAKA_INI"
fi

# the credentials are checked now (MongoDB checks a given password even while authorization is off)
. ./app.env >/dev/null 2>&1 || true
mongosh --quiet "$MONGO_URI" --eval 'db.getSiblingDB(process.env.NFN_DB).accounts.findOne() ? print("nfn_app: signs in, reads accounts") : print("nfn_app: signs in")'
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
[ "$NEXT_STEPS" -eq 1 ] || exit 0
cat <<EOF

Done. Backups: app.env.bak-* and (with Haraka) haraka/config/nfn.ini.bak-* (restore one of them to undo; the users can stay).

Then:
  1. /etc/mongod.conf: replace the line \`#security:\` with
         security:
           authorization: enabled
  2. systemctl restart mongod
  3. restart the long-running clients so they reconnect with their users:
         systemctl restart nfn-daemon nfn-haraka     (installed by scripts/4_services-install.sh)
     or, without the units (from the scripts folder: they load ../app.env relative to it):
         cd $BASE/scripts && ./haraka.sh restart && ./daemon.sh restart
  4. check: mongosh --quiet mongodb://127.0.0.1:27017/$NFN_DB --eval 'db.users.findOne()'   -> must fail (requires authentication)

mm (/mm/index.php): sign in as nfn_admin, with the password in $BASE/.mongodb-admin (leave the database field empty).
EOF
