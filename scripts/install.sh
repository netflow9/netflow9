#!/bin/bash

#CLD: 2026-10-29T03:00:00+02:00: new: NetFlow9's installer, as root. It asks the initial data (the public address, the database, the outgoing email, the first account) and
#the options (nginx, the web browser of the AI steps, inbound email, MongoDB's users and sign-in), checks everything it needs first, then runs the parts in order:
#  the nginx site (0_nginx-install.sh; a self-signed certificate when there is none), the app's setup (app/v setup::install as the app's user: the account, its owner,
#  app.env), Playwright (1_), Haraka (2_), MongoDB's users (3_) and authorization (/etc/mongod.conf, mongod restarted), the background services (4_)
#Run again: an app already set up keeps its settings and account (only the options are asked); what is already done is left as it is
#   sudo scripts/install.sh

set -euo pipefail

SELF_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
BASE=$(dirname "$SELF_DIR")
ENV_FILE="$BASE/app.env"
MONGOD_CONF=/etc/mongod.conf
LOCAL_MONGO="mongodb://127.0.0.1:27017"
SYSTEM_PATH="/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin"

[ "$(id -u)" -eq 0 ] || { echo "run it as root: sudo $0"; exit 1; }
[ -t 0 ] || { echo "it asks questions: run it in a terminal"; exit 1; }
APP_USER=$(stat -c %U "$BASE/app")
[ "$APP_USER" != "root" ] || { echo "$BASE/app is owned by root: the app's files must be its user's (chown -R <user>: $BASE)"; exit 1; }
trap 'echo; echo "stopped"; exit 130' INT

# ---------------------------------------------------------------------------------------------------------------
#asking: ask <var> <question> [default]; ask_secret <var> <question> [optional] (twice, not shown); ask_yes <var> <question> <y|n>
ask() {
    local answer
    read -r -p "  $2${3:+ [$3]}: " answer
    printf -v "$1" '%s' "${answer:-${3:-}}"
}

ask_secret() {
    local a b
    while true; do
        read -r -s -p "  $2: " a
        echo
        if [ -z "$a" ] && [ "${3:-}" = "optional" ]; then
            printf -v "$1" '%s' ""
            return
        fi
        [ -n "$a" ] || { echo "  it cannot be empty"; continue; }
        read -r -s -p "  $2, again: " b
        echo
        [ "$a" = "$b" ] && break
        echo "  they differ: once more"
    done
    printf -v "$1" '%s' "$a"
}

ask_yes() {
    local answer
    while true; do
        read -r -p "  $2 [$([ "$3" = y ] && echo Y/n || echo y/N)]: " answer
        answer=${answer:-$3}
        case ${answer,,} in
            y|yes) printf -v "$1" '%s' 1; return ;;
            n|no) printf -v "$1" '%s' 0; return ;;
        esac
    done
}

#a value of app.env
env_value() {
    (set +u; . "$ENV_FILE" >/dev/null 2>&1; eval "echo \"\${$1:-}\"")
}

#sets KEY='value' in app.env, in place of its line (also a commented one: #KEY=...), else at the end; the file keeps its owner and mode
set_env() {
    local key=$1 value=$2 line out="" found=0
    while IFS= read -r line || [ -n "$line" ]; do
        if [ "$found" -eq 0 ] && [[ $line =~ ^#?${key}= ]]; then
            line="${key}='${value}'"
            found=1
        fi
        out+="$line"$'\n'
    done < "$ENV_FILE"
    [ "$found" -eq 1 ] || out+="${key}='${value}'"$'\n'
    printf '%s' "$out" > "$ENV_FILE"
}

#the app's console (app/v) as the app's user, with the system's PHP; stdin passes through
as_app() {
    (cd "$BASE/app" && runuser -u "$APP_USER" -- env -i PATH="$SYSTEM_PATH" HOME="$(getent passwd "$APP_USER" | cut -d: -f6)" "$BASE/app/v" "$@")
}

#the answers of the setup as JSON (setup::validate / setup::install read it on stdin); the secrets only in this process' environment
answers_json() {
    env A_URL="$URL" A_MONGO_URI="$MONGO_URI" A_MONGO_DB="$MONGO_DB" A_SMTP_HOST="$SMTP_HOST" A_SMTP_PORT="$SMTP_PORT" A_SMTP_SECURE="$SMTP_SECURE" \
        A_SMTP_USER="$SMTP_USER" A_SMTP_PASSWORD="$SMTP_PASSWORD" A_MAIL_FROM="$MAIL_FROM" A_ACCOUNT="$ACCOUNT_NAME" A_USER="$OWNER_USER" A_EMAIL="$OWNER_EMAIL" \
        A_FIRST="$OWNER_FIRST" A_LAST="$OWNER_LAST" A_PASSWORD="$OWNER_PASSWORD" \
        php -r '$e = fn($k) => (string)getenv($k);
            echo json_encode([
                "server" => [ "base_url" => $e("A_URL"), "mongo_uri" => $e("A_MONGO_URI"), "mongo_db" => $e("A_MONGO_DB"), "smtp_host" => $e("A_SMTP_HOST"),
                    "smtp_port" => $e("A_SMTP_PORT"), "smtp_secure" => $e("A_SMTP_SECURE"), "smtp_username" => $e("A_SMTP_USER"), "smtp_password" => $e("A_SMTP_PASSWORD"),
                    "mail_from" => $e("A_MAIL_FROM") ],
                "owner" => [ "account_name" => $e("A_ACCOUNT"), "username" => $e("A_USER"), "email" => $e("A_EMAIL"), "firstname" => $e("A_FIRST"),
                    "lastname" => $e("A_LAST"), "password" => $e("A_PASSWORD") ],
            ]);'
}

#the host of an address (https://host[:port]/...)
url_host() {
    local h="${1#*://}"
    h="${h%%/*}"
    echo "${h%:*}"
}

#MongoDB on this machine (127.0.0.1:27017) without sign-in: its users can be listed without signing in
mongo_auth_off() {
    mongosh --quiet "$LOCAL_MONGO/admin" --eval 'db.getUsers()' >/dev/null 2>&1
}

chromium_installed() {
    ls -d /opt/playwright/browsers/chromium-[0-9]*/chrome-linux64/chrome >/dev/null 2>&1
}

step() {
    echo
    echo "=== $*"
}

# ---------------------------------------------------------------------------------------------------------------
echo "NetFlow9 installer: the app in $BASE, its user $APP_USER"

#the folders the app writes to
for d in logs tmp run var; do
    [ -d "$BASE/$d" ] || install -d -o "$APP_USER" -g "$APP_USER" -m 750 "$BASE/$d"
done

SET_UP=0
[ -f "$ENV_FILE" ] && SET_UP=1

if [ "$SET_UP" -eq 1 ]; then
    URL=$(env_value BASE_URL)
    MONGO_URI=$(env_value MONGO_URI)
    echo "The app is already set up (app.env): its settings and account are kept; only the options are asked."
else
    command -v php >/dev/null || { echo "PHP is not installed (see the requirements: PHP 8.1 or later, with the mongodb extension)"; exit 1; }
    host_default=$(hostname -f 2>/dev/null || hostname)
    URL="https://$host_default"
    MONGO_URI="$LOCAL_MONGO/"
    MONGO_DB="netflow9-db"
    SMTP_HOST="127.0.0.1"
    SMTP_PORT="25"
    SMTP_SECURE_ANSWER="none"
    SMTP_USER=""
    MAIL_FROM=""
    ACCOUNT_NAME=""
    OWNER_USER="admin"
    OWNER_EMAIL=""
    OWNER_FIRST=""
    OWNER_LAST=""
    #asked until the app's own checks accept the answers (Enter keeps the answer given before)
    while true; do
        echo
        echo "The app (Enter keeps the answer in brackets)"
        ask URL "Public address of the site" "$URL"
        case $URL in http://*|https://*) ;; *) URL="https://$URL" ;; esac
        URL="${URL%/}"
        ask MONGO_URI "MongoDB connection string" "$MONGO_URI"
        ask MONGO_DB "Database name (a new or empty one)" "$MONGO_DB"
        echo
        echo "Outgoing email (sign-in links, invitations, the Send email step)"
        ask SMTP_HOST "SMTP server" "$SMTP_HOST"
        ask SMTP_PORT "SMTP port" "$SMTP_PORT"
        while true; do
            ask SMTP_SECURE_ANSWER "Encryption: none, tls or ssl" "$SMTP_SECURE_ANSWER"
            case ${SMTP_SECURE_ANSWER,,} in
                none|"") SMTP_SECURE=""; break ;;
                tls|ssl) SMTP_SECURE=${SMTP_SECURE_ANSWER,,}; break ;;
            esac
        done
        ask SMTP_USER "SMTP username (empty: none)" "$SMTP_USER"
        SMTP_PASSWORD=""
        [ -z "$SMTP_USER" ] || ask_secret SMTP_PASSWORD "SMTP password"
        ask MAIL_FROM "Emails come from" "${MAIL_FROM:-noreply@$(url_host "$URL")}"
        echo
        echo "The first account (its owner signs in with these)"
        ask ACCOUNT_NAME "Company or team" "$ACCOUNT_NAME"
        ask OWNER_USER "Username" "$OWNER_USER"
        ask OWNER_EMAIL "Email" "$OWNER_EMAIL"
        ask OWNER_FIRST "First name" "$OWNER_FIRST"
        ask OWNER_LAST "Last name" "$OWNER_LAST"
        ask_secret OWNER_PASSWORD "Password (letters, capitals, digits and special characters)"
        echo
        echo "Checking the answers and the database..."
        if answers_json | as_app setup::validate; then
            break
        fi
        echo "Once more: Enter keeps an answer."
    done
fi
HOST=$(url_host "$URL")

# ---------------------------------------------------------------------------------------------------------------
echo
echo "Options"
ask_yes OPT_NGINX "Set up nginx for $HOST (the site, HTTPS)" y
OPT_SELFSIGNED=0
if [ "$OPT_NGINX" -eq 1 ] && [ ! -e "$BASE/ssl/ssl.crt" ]; then
    ask_yes OPT_SELFSIGNED "There is no certificate (ssl/ssl.crt): make a self-signed one for now (browsers warn; replace it with a real one later)" y
fi

if chromium_installed; then
    ask_yes OPT_BROWSER "The web browser for the AI steps (installed: its service)" y
else
    ask_yes OPT_BROWSER "The web browser for the AI steps (Playwright and Chromium, about 500 MB)" y
fi

MAIL_DOMAIN=""
[ "$SET_UP" -eq 1 ] && MAIL_DOMAIN=$(env_value MAIL_INBOUND_DOMAIN)
ask_yes OPT_MAIL "Inbound email: workflows started by emails (Haraka on port 25)" "$([ -n "$MAIL_DOMAIN" ] || [ -d "$BASE/haraka/node_modules/haraka" ] && echo y || echo n)"
if [ "$OPT_MAIL" -eq 1 ]; then
    ask MAIL_DOMAIN "The domain of its addresses (its MX record points to this server)" "${MAIL_DOMAIN:-$HOST}"
fi

OPT_MONGO=0
case $MONGO_URI in
    *@*) echo "  MongoDB: the app signs in with its own user already" ;;
    mongodb://127.0.0.1:27017*|mongodb://localhost:27017*)
        if mongo_auth_off; then
            ask_yes OPT_MONGO "Secure MongoDB: its own users with random passwords, sign-in required" y
        else
            echo "  MongoDB: it requires sign-in already"
        fi ;;
    *) echo "  MongoDB is not on this machine: secure it there (its users and sign-in)" ;;
esac
echo "  The background services (the workflow daemon$([ "$OPT_MAIL" -eq 1 ] && echo ", Haraka")$([ "$OPT_BROWSER" -eq 1 ] && echo ", the web browser")) are installed as systemd services."

# ---------------------------------------------------------------------------------------------------------------
#everything needed, before anything changes
step "Checking what is needed"
problems=()
for cmd in php mongosh openssl systemctl runuser; do
    command -v "$cmd" >/dev/null 2>&1 || problems+=("$cmd is not installed")
done
if ! command -v pkaction >/dev/null 2>&1 || [ ! -d /etc/polkit-1/rules.d ]; then
    problems+=("polkit is not installed (the administration manages the services through it): dnf install polkit")
fi
if [ "$OPT_NGINX" -eq 1 ]; then
    command -v nginx >/dev/null 2>&1 || problems+=("nginx is not installed: dnf install nginx")
fi
if [ "$OPT_BROWSER" -eq 1 ] || [ "$OPT_MAIL" -eq 1 ]; then
    PATH="$SYSTEM_PATH" command -v node >/dev/null 2>&1 && PATH="$SYSTEM_PATH" command -v npm >/dev/null 2>&1 \
        || problems+=("Node.js and npm (system-wide) are needed for the web browser and inbound email: dnf install nodejs npm")
fi
if [ "$OPT_MONGO" -eq 1 ] && [ ! -f "$MONGOD_CONF" ]; then
    problems+=("$MONGOD_CONF not found: MongoDB's sign-in cannot be turned on")
fi
if [ "$SET_UP" -eq 0 ] && ! as_app setup::checks; then
    problems+=("the app's prerequisites (above, marked !)")
fi
if [ "${#problems[@]}" -gt 0 ]; then
    echo
    echo "Missing:"
    for p in "${problems[@]}"; do echo "  - $p"; done
    echo "Nothing was changed. Install them, then run this again."
    exit 1
fi
echo "OK"

echo
echo "It will:"
[ "$OPT_NGINX" -eq 1 ] && echo "  - set up nginx for $HOST$([ "$OPT_SELFSIGNED" -eq 1 ] && echo ", with a self-signed certificate")"
[ "$SET_UP" -eq 0 ] && echo "  - set up the app: the account \"$ACCOUNT_NAME\", its owner $OWNER_USER, app.env"
[ "$OPT_BROWSER" -eq 1 ] && echo "  - install the web browser for the AI steps"
[ "$OPT_MAIL" -eq 1 ] && echo "  - install inbound email for @$MAIL_DOMAIN"
[ "$OPT_MONGO" -eq 1 ] && echo "  - give MongoDB its users and turn on sign-in (mongod is restarted)"
echo "  - install and start the background services"
ask_yes GO "Go on?" y
[ "$GO" -eq 1 ] || { echo "Nothing was changed."; exit 0; }

# ---------------------------------------------------------------------------------------------------------------
if [ "$OPT_NGINX" -eq 1 ]; then
    step "nginx"
    if [ "$OPT_SELFSIGNED" -eq 1 ]; then
        install -d -o "$APP_USER" -g "$APP_USER" -m 755 "$BASE/ssl"
        openssl req -x509 -newkey rsa:2048 -nodes -days 825 -subj "/CN=$HOST" -addext "subjectAltName=DNS:$HOST" \
            -keyout "$BASE/ssl/selfsigned.key" -out "$BASE/ssl/selfsigned.crt" 2>/dev/null
        chmod 600 "$BASE/ssl/selfsigned.key"
        ln -sfn selfsigned.crt "$BASE/ssl/ssl.crt"
        ln -sfn selfsigned.key "$BASE/ssl/ssl.key"
        chown -h "$APP_USER:$APP_USER" "$BASE/ssl/selfsigned.key" "$BASE/ssl/selfsigned.crt" "$BASE/ssl/ssl.crt" "$BASE/ssl/ssl.key"
        echo "+ a self-signed certificate for $HOST in ssl/ (ssl.crt, ssl.key point to it)"
    fi
    "$SELF_DIR/0_nginx-install.sh" --server-name="$HOST"
fi

if [ "$SET_UP" -eq 0 ]; then
    step "The app"
    answers_json | as_app setup::install
    MONGO_DB=$(env_value MONGO_DB)
fi

if [ "$OPT_MAIL" -eq 1 ]; then
    set_env MAIL_INBOUND_DOMAIN "$MAIL_DOMAIN"
fi

if [ "$OPT_BROWSER" -eq 1 ]; then
    step "The web browser for the AI steps"
    if chromium_installed; then
        echo "Playwright and Chromium are installed already"
    else
        "$SELF_DIR/1_playwright-install.sh"
    fi
fi

if [ "$OPT_MAIL" -eq 1 ]; then
    step "Inbound email (Haraka)"
    "$SELF_DIR/2_haraka-install.sh" --domain="$MAIL_DOMAIN"
fi

MONGO_CHANGED=0
if [ "$OPT_MONGO" -eq 1 ]; then
    step "MongoDB: users and sign-in"
    "$SELF_DIR/3_mongo-auth-setup.sh" --no-next-steps
    MONGO_CHANGED=1
    if grep -qE '^[[:space:]]*authorization:[[:space:]]*enabled' "$MONGOD_CONF"; then
        echo "$MONGOD_CONF: authorization is enabled already"
    else
        backup="$MONGOD_CONF.bak-$(date +%Y%m%dT%H%M%S)"
        cp -p "$MONGOD_CONF" "$backup"
        #authorization under security: (as indented as its other keys), or a new security: section
        if grep -q '^security:' "$MONGOD_CONF"; then
            awk '{ print } /^security:/ { getline nxt; match(nxt, /^[ ]+/); ind = RSTART == 1 ? substr(nxt, 1, RLENGTH) : "  ";
                   print ind "authorization: enabled"; print nxt }' "$backup" > "$MONGOD_CONF"
        else
            printf '\nsecurity:\n  authorization: enabled\n' >> "$MONGOD_CONF"
        fi
        echo "+ $MONGOD_CONF: authorization enabled (the old one: $backup)"
    fi
    systemctl restart mongod
    APP_MONGO_URI=$(env_value MONGO_URI)
    for _ in $(seq 1 30); do
        mongosh --quiet "$APP_MONGO_URI" --eval 'db.runCommand({ ping: 1 }).ok' >/dev/null 2>&1 && break
        sleep 1
    done
    if mongo_auth_off; then
        echo "! MongoDB still answers without signing in: check $MONGOD_CONF"
    elif mongosh --quiet "$APP_MONGO_URI" --eval 'db.runCommand({ ping: 1 }).ok' >/dev/null 2>&1; then
        echo "MongoDB requires sign-in; the app signs in with its user"
    else
        echo "! the app cannot sign in to MongoDB: check mongod (journalctl -u mongod) and MONGO_URI in app.env"
    fi
fi

step "The background services"
"$SELF_DIR/4_services-install.sh" install $([ "$OPT_BROWSER" -eq 1 ] || echo --no-playwright)
#the services running before reconnect with the new MongoDB users
[ "$MONGO_CHANGED" -eq 0 ] || systemctl try-restart nfn-daemon.service nfn-haraka.service || true

# ---------------------------------------------------------------------------------------------------------------
step "Done"
echo "Open $URL$([ "$SET_UP" -eq 0 ] && echo " and sign in as $OWNER_USER")."
[ "$OPT_SELFSIGNED" -eq 1 ] && echo "The certificate is self-signed: browsers warn. Put a real one in $BASE/ssl/ (ssl.crt, ssl.key), then: systemctl reload nginx"
[ "$OPT_MONGO" -eq 1 ] && echo "MongoDB's operators' account (nfn_admin) is in $BASE/.mongodb-admin (root's): move it to a password manager, then delete the file."
if [ "$OPT_MAIL" -eq 1 ]; then
    echo "Inbound email: point the MX record of $MAIL_DOMAIN to this server, open port 25; DKIM keys: cd $BASE/haraka/dkim && ./dkim_key_gen.sh $MAIL_DOMAIN"
fi
echo "Run this again to add an option later."
