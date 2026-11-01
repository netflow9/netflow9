#!/bin/bash

#MongoDB on this machine (127.0.0.1:27017) for the app: its users, sign-in, the server's service.
#  users   while MongoDB does not require sign-in: NetFlow9's users with random passwords (scripts/mongo_users.js; run again: made again, with new passwords),
#          nfn_admin (root, for operators), nfn_app (readWrite on the app's database: MONGO_DB of app.env), nfn_haraka (role nfnHaraka: only what inbound mail needs).
#          Written: MONGO_URI and HARAKA_MONGO_URI into app.env, Haraka's uri into haraka/config/nfn.ini (when installed), each kept first as <file>.bak-<time>
#          and made readable by its owner only; the operators' URI into <app>/.mongodb-admin (root's: move it to a password manager, then delete it).
#          The passwords are never printed. Nothing changes for the running services until MongoDB requires sign-in (auth)
#  auth    sign-in required: authorization enabled in /etc/mongod.conf (kept first as mongod.conf.bak-<time>), mongod restarted, the app's user checked
#  secure  users, then auth, then the app's services restarted (they reconnect with their users)
#  start | stop | restart   mongod: the server's, every database on it (other installations' too)
#  status  mongod, sign-in on or off, whether the app signs in with its user
#CLD: 2026-11-01T20:30:00+02:00: commands, as scripts/nginx.sh and playwright-mcp.sh; named mongo.sh (was 3_mongo-auth-setup.sh: its users only; enabling sign-in was install.sh's)
#Run as root:
#   sudo scripts/mongo.sh secure | users | auth | start | stop | restart | status
#mm (/mm/index.php) signs in as nfn_admin, with the password in <app>/.mongodb-admin (the database field empty).

set -euo pipefail
umask 077

SELF_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
BASE=$(dirname "$SELF_DIR")
APP_USER=$(stat -c %U "$BASE/app")
LOCAL_MONGO="mongodb://127.0.0.1:27017"
MONGOD_CONF=/etc/mongod.conf
HARAKA_INI="$BASE/haraka/config/nfn.ini"

usage() {
    echo "usage: sudo $0 {secure | users | auth | start | stop | restart | status}"
    exit "${1:-1}"
}

command="${1:-}"
[ -n "$command" ] && shift || true
while [ "$#" -gt 0 ]; do
    case $1 in
        -h|--help) usage 0 ;;
        *) echo "unknown option: $1"; usage 1 ;;
    esac
    shift
done

need_root() {
    [ "$(id -u)" -eq 0 ] || { echo "run it as root: sudo $0 $command"; exit 1; }
}

need_env() {
    [ -f "$BASE/app.env" ] || { echo "no app.env: set the app up first (sudo $BASE/install.sh, or the setup in the browser)"; exit 1; }
}

#a value of app.env
env_value() {
    (set +u; . "$BASE/app.env" >/dev/null 2>&1; eval "echo \"\${$1:-}\"")
}

#MongoDB without sign-in: its users can be listed without signing in
auth_off() {
    mongosh --quiet "$LOCAL_MONGO/admin" --eval 'db.getUsers()' >/dev/null 2>&1
}

#the app signs in with MONGO_URI
app_signs_in() {
    mongosh --quiet "$(env_value MONGO_URI)" --eval 'db.runCommand({ ping: 1 }).ok' >/dev/null 2>&1
}

#the first line of file $1 matching the regex $2 replaced by the lines $3...; a line equal to $3 (the comment, from a previous run) is dropped.
#The file is rewritten in place (it keeps its owner and mode); nothing written when no line matches
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

make_users() {
    local users ts db app_uri haraka_uri haraka_comment
    need_env
    cd "$BASE"
    db=$(env_value MONGO_DB)
    export NFN_DB="${db:-netflow9-db}"

    #with sign-in on, the users cannot be listed: nothing is changed
    if ! users=$(mongosh --quiet "$LOCAL_MONGO/admin" --eval 'print(db.getUsers().users.map(u => u.user).join(", "))' 2>&1); then
        if grep -qiE 'auth|unauthorized' <<< "$users"; then
            echo "MongoDB requires sign-in (authorization is on): stopping (nothing changed)"
        else
            echo "MongoDB does not answer: $users"
        fi
        exit 1
    fi
    [ -z "$users" ] || echo "MongoDB does not require sign-in: NetFlow9's users are made again, with new passwords (admin users now: $users)"

    ts=$(date +%Y%m%dT%H%M%S)
    cp -p app.env "app.env.bak-$ts"
    chmod 600 "app.env.bak-$ts"
    if [ -f "$HARAKA_INI" ]; then
        cp -p "$HARAKA_INI" "$HARAKA_INI.bak-$ts"
        chmod 600 "$HARAKA_INI.bak-$ts"
    fi

    export NFN_ADMIN_PW=$(openssl rand -hex 24) NFN_APP_PW=$(openssl rand -hex 24) NFN_HARAKA_PW=$(openssl rand -hex 24)
    mongosh --quiet "$LOCAL_MONGO/admin" "$SELF_DIR/mongo_users.js"

    cat > .mongodb-admin <<EOF
# the MongoDB operators' account (role root): move it to a password manager and delete this file
NFN_ADMIN_URI='mongodb://nfn_admin:${NFN_ADMIN_PW}@127.0.0.1:27017/?authSource=admin'
EOF
    chmod 600 .mongodb-admin

    app_uri="mongodb://nfn_app:${NFN_APP_PW}@127.0.0.1:27017/?authSource=${NFN_DB}"
    put app.env '^MONGO_URI=' \
        "#MongoDB user nfn_app (readWrite on the app's database only)" \
        "MONGO_URI='${app_uri}'"
    #Haraka's URI in app.env too, for a Haraka installed later (scripts/haraka.sh install writes it into its nfn.ini)
    haraka_uri="mongodb://nfn_haraka:${NFN_HARAKA_PW}@127.0.0.1:27017/?authSource=${NFN_DB}"
    haraka_comment="#MongoDB user nfn_haraka (role nfnHaraka), for Haraka's haraka/config/nfn.ini (scripts/haraka.sh install writes it there)"
    grep -q '^HARAKA_MONGO_URI=' app.env || printf '\n%s\nHARAKA_MONGO_URI=\n' "$haraka_comment" >> app.env
    put app.env '^HARAKA_MONGO_URI=' "$haraka_comment" "HARAKA_MONGO_URI='${haraka_uri}'"
    chmod 600 app.env
    if [ -f "$HARAKA_INI" ]; then
        put "$HARAKA_INI" '^uri=' \
            "; MongoDB user nfn_haraka (role nfnHaraka: reads the address lookups, writes the message file and its job)" \
            "uri=${haraka_uri}"
        chmod 600 "$HARAKA_INI"
    fi

    #checked now (MongoDB checks a given password even while authorization is off)
    mongosh --quiet "$app_uri" --eval 'db.getSiblingDB(process.env.NFN_DB).accounts.findOne() ? print("nfn_app: signs in, reads accounts") : print("nfn_app: signs in")'
    echo "+ users made; backups: app.env.bak-$ts$([ -f "$HARAKA_INI" ] && echo " and haraka/config/nfn.ini.bak-$ts") (restore them to undo; the users can stay)"
}

#the lines of $@ under security: in $MONGOD_CONF (as indented as its other keys), or a new security: section; $1: the copy it is made from
conf_security() {
    local from="$1"
    shift
    if grep -q '^security:' "$from"; then
        ADD="$(printf '%s\n' "$@")" awk '{ print } /^security:/ { getline nxt; match(nxt, /^[ ]+/); ind = RSTART == 1 ? substr(nxt, 1, RLENGTH) : "  ";
               n = split(ENVIRON["ADD"], a, "\n"); for (i = 1; i <= n; i++) if (a[i] != "") print ind a[i]; print nxt }' "$from" > "$MONGOD_CONF"
    else
        cp -p "$from" "$MONGOD_CONF"
        printf '\nsecurity:\n' >> "$MONGOD_CONF"
        printf '  %s\n' "$@" >> "$MONGOD_CONF"
    fi
}

enable_auth() {
    local backup
    need_env
    [[ $(env_value MONGO_URI) == *@* ]] || { echo "app.env's MONGO_URI has no user: make the users first (sudo $0 users)"; exit 1; }
    [ -f "$MONGOD_CONF" ] || { echo "missing: $MONGOD_CONF"; exit 1; }
    if grep -qE '^[[:space:]]*authorization:[[:space:]]*enabled' "$MONGOD_CONF"; then
        echo "$MONGOD_CONF: authorization is enabled already"
    else
        backup="$MONGOD_CONF.bak-$(date +%Y%m%dT%H%M%S)"
        cp -p "$MONGOD_CONF" "$backup"
        if grep -qE '^[[:space:]]*authorization:[[:space:]]*disabled' "$MONGOD_CONF"; then
            sed -i -E 's/^([[:space:]]*authorization:[[:space:]]*)disabled/\1enabled/' "$MONGOD_CONF"
        else
            conf_security "$backup" "authorization: enabled"
        fi
        echo "+ $MONGOD_CONF: authorization enabled (the old one: $backup)"
    fi
    systemctl restart mongod
    for _ in $(seq 1 30); do
        app_signs_in && break
        sleep 1
    done
    if auth_off; then
        echo "! MongoDB still answers without signing in: check $MONGOD_CONF"
        exit 1
    elif app_signs_in; then
        echo "MongoDB requires sign-in; the app signs in with its user"
    else
        echo "! the app cannot sign in to MongoDB: check mongod (journalctl -u mongod) and MONGO_URI in app.env"
        exit 1
    fi
}

#the app's services that are running: restarted, to reconnect with their users
restart_clients() {
    local units="nfn-daemon-$APP_USER.service"
    [ -f "$HARAKA_INI" ] && units="$units nfn-haraka.service"
    # shellcheck disable=SC2086
    systemctl try-restart $units 2>/dev/null || true
    echo "+ restarted when running: $units"
}

case "$command" in
    secure)
        need_root
        make_users
        enable_auth
        restart_clients
        echo "Done. The operators' account (nfn_admin) is in $BASE/.mongodb-admin: move it to a password manager, then delete the file."
        ;;
    users)
        need_root
        make_users
        echo "Then: sudo $0 auth (sign-in required), and restart the app's services"
        ;;
    auth)
        need_root
        enable_auth
        restart_clients
        ;;
    start|stop|restart)
        need_root
        systemctl "$command" mongod
        ;;
    status)
        systemctl --no-pager --lines=5 status mongod || true
        echo "---"
        if auth_off; then
            echo "sign-in: off (anyone on this machine reaches every database): sudo $0 secure"
        elif mongosh --quiet "$LOCAL_MONGO/admin" --eval 'db.runCommand({ ping: 1 }).ok' >/dev/null 2>&1; then
            echo "sign-in: required"
        else
            echo "MongoDB does not answer on 127.0.0.1:27017"
        fi
        if [ -r "$BASE/app.env" ]; then
            if [[ $(env_value MONGO_URI) != *@* ]]; then
                echo "the app: no user in MONGO_URI"
            elif app_signs_in; then
                echo "the app: signs in with its user"
            else
                echo "the app: cannot sign in with MONGO_URI"
            fi
        fi
        [ ! -e "$BASE/.mongodb-admin" ] || echo "! $BASE/.mongodb-admin is still there: move it to a password manager, then delete it"
        ;;
    *)
        usage
        ;;
esac
