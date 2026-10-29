#!/bin/bash

#CLD: 2026-10-29T03:00:00+02:00: new: NetFlow9's installer, as root. It asks the initial data (the public address, the database, the outgoing email, the first account) and
#the options (nginx, the web browser of the AI steps, inbound email, MongoDB's users and sign-in), checks everything it needs first, then runs the parts in order:
#  the nginx site (0_nginx-install.sh; a self-signed certificate when there is none), the app's setup (app/v setup::install as the app's user: the account, its owner,
#  app.env), Playwright (1_), Haraka (2_), MongoDB's users (3_) and authorization (/etc/mongod.conf, mongod restarted), the background services (4_)
#Run again: an app already set up keeps its settings and account (only the options are asked); what is already done is left as it is
#CLD: 2026-10-29T04:00:00+02:00: it sets up the server too (was qsm_scripts: nfn_install_alma9.sh, nfn_create_vhost.v1.sh, nfn_install_node.sh), from anywhere (was: from the
#app's folder): on AlmaLinux / RHEL 9 it installs the packages that are missing (EPEL; Remi's PHP 8.5 with the app's extensions; nginx; MongoDB 8.3; Redis; certbot; Node.js:
#the newest stream of the distribution), creates the app's user and its home, clones the app (the repository, with its submodules) there, writes the PHP-FPM pool, php.ini
#and logrotate (scripts/system/), the certificate (Let's Encrypt, else self-signed), then the steps above. The firewall is not touched; SELinux enforcing: set to permissive
#when allowed (nginx and PHP-FPM serve the home folder)
#   sudo bash install.sh        (e.g. curl -fsSLO https://raw.githubusercontent.com/netflow9/netflow9/main/install.sh)

set -euo pipefail

REPO_DEFAULT="https://github.com/netflow9/netflow9.git"
BRANCH_DEFAULT="main"
PHPV="85"
PHP_ETC="/etc/opt/remi/php$PHPV"
PHP_FPM="php$PHPV-php-fpm"
#the PHP packages (Remi): the app's extensions (opcache is built in)
#CLD: 2026-10-29T05:00:00+02:00: without php$PHPV-syspaths (/usr/bin/php): installed on its own, it may replace the distribution's PHP (asked)
PHP_PACKAGES="php$PHPV php$PHPV-runtime php$PHPV-php-cli php$PHPV-php-common php$PHPV-php-fpm php$PHPV-php-gd php$PHPV-php-gmp php$PHPV-php-mbstring
    php$PHPV-php-mysqlnd php$PHPV-php-pdo php$PHPV-php-process php$PHPV-php-sodium php$PHPV-php-tidy php$PHPV-php-xml php$PHPV-php-pecl-event php$PHPV-php-pecl-igbinary
    php$PHPV-php-pecl-imap php$PHPV-php-pecl-mailparse php$PHPV-php-pecl-mongodb2 php$PHPV-php-pecl-msgpack php$PHPV-php-pecl-redis6 php$PHPV-php-pecl-zip"
#CLD: 2026-10-29T06:00:00+02:00: the Database step's drivers (recommended; installed when missing, an unavailable one skipped): PostgreSQL, SQL Server (pdo_dblib: FreeTDS;
#sqlsrv would need Microsoft's ODBC driver), Firebird, ODBC. Oracle's pdo_oci needs Oracle's Instant Client: not in these repositories
PHP_DB_PACKAGES="php$PHPV-php-pgsql php$PHPV-php-pdo-dblib php$PHPV-php-pdo-firebird php$PHPV-php-odbc"
MONGODB_VERSION="8.3"
MONGOD_CONF=/etc/mongod.conf
LOCAL_MONGO="mongodb://127.0.0.1:27017"
SYSTEM_PATH="/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin"
export PATH="$SYSTEM_PATH"

[ "$(id -u)" -eq 0 ] || { echo "run it as root: sudo bash $0"; exit 1; }
[ -t 0 ] || { echo "it asks questions: run it in a terminal"; exit 1; }
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

#a command as the app's user, in its home, with the system's PATH; stdin passes through
as_user() {
    (cd "$BASE" && runuser -u "$APP_USER" -- env -i PATH="$SYSTEM_PATH" HOME="$BASE" "$@")
}

#the app's console (app/v) as the app's user
as_app() {
    as_user "$BASE/app/v" "$@"
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

#CLD: 2026-10-29T07:00:00+02:00: the lines of $@ under security: in $MONGOD_CONF (as indented as its other keys), or a new security: section; $1: the copy it is made from
#(was in the authorization step only; Percona's encryption at rest adds its keys there too)
mongod_conf_security() {
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

#the packages of $@ that are not installed
missing_packages() {
    local p
    for p in "$@"; do
        rpm -q --quiet "$p" || echo "$p"
    done
}

#installs the packages of $@ that are missing
install_packages() {
    local missing
    missing=$(missing_packages "$@" | tr '\n' ' ')
    if [ -n "${missing// /}" ]; then
        echo "+ dnf install $missing"
        # shellcheck disable=SC2086
        dnf -y install $missing
    fi
}

#the domain's addresses include one of this server's
domain_points_here() {
    local a
    for a in $(getent ahosts "$1" 2>/dev/null | awk '{ print $1 }' | sort -u); do
        hostname -I 2>/dev/null | tr ' ' '\n' | grep -qxF "$a" && return 0
    done
    return 1
}

step() {
    echo
    echo "=== $*"
}

# ---------------------------------------------------------------------------------------------------------------
. /etc/os-release
if ! command -v dnf >/dev/null 2>&1 || [ "${VERSION_ID%%.*}" != "9" ] || ! [[ " ${ID:-} ${ID_LIKE:-} " == *" rhel "* || " ${ID:-} ${ID_LIKE:-} " == *" centos "* ]]; then
    echo "This installer is for AlmaLinux / Rocky / RHEL 9 (this is ${PRETTY_NAME:-unknown})."
    exit 1
fi
echo "NetFlow9 installer (${PRETTY_NAME})"

#CLD: 2026-10-29T05:15:00+02:00: an installed MongoDB server is kept (MongoDB's packages, Percona Server for MongoDB, ...: the package of /usr/bin/mongod); mongosh to match it
MONGOD_PKG=""
if [ -x /usr/bin/mongod ]; then
    MONGOD_PKG=$(rpm -qf --qf '%{NAME}' /usr/bin/mongod 2>/dev/null || true)
    [[ $MONGOD_PKG == *" "* ]] && MONGOD_PKG="mongod"
fi

#CLD: 2026-10-29T05:30:00+02:00: what is installed, detected before the questions and shown in one line
PHP_LABEL="${PHPV:0:1}.${PHPV:1}"
#the distribution's PHP (php-common, ...): it conflicts with php$PHPV-syspaths (both are /usr/bin/php, which the app's console and daemon run)
DISTRO_PHP=""
DISTRO_PHP_VERSION=""
if ! rpm -q --quiet "php$PHPV-syspaths"; then
    DISTRO_PHP=$(rpm -qa 'php-*' --qf '%{NAME}\n' 2>/dev/null | sort -u | tr '\n' ' ' || true)
    DISTRO_PHP="${DISTRO_PHP% }"
    [ -z "$DISTRO_PHP" ] || DISTRO_PHP_VERSION=$(rpm -q --qf '%{VERSION}' php-common 2>/dev/null || true)
fi
found=()
missing=()
# shellcheck disable=SC2086
if [ -z "$(missing_packages $PHP_PACKAGES "php$PHPV-syspaths")" ]; then found+=("PHP $PHP_LABEL"); else missing+=("PHP $PHP_LABEL"); fi
[ -z "$DISTRO_PHP" ] || found+=("the distribution's PHP $DISTRO_PHP_VERSION")
rpm -q --quiet nginx && found+=("nginx") || missing+=("nginx")
if [[ $MONGOD_PKG == percona-* ]]; then
    found+=("MongoDB (Percona)")
elif [ -n "$MONGOD_PKG" ]; then
    found+=("MongoDB")
else
    missing+=("MongoDB")
fi
rpm -q --quiet redis && found+=("Redis") || missing+=("Redis")
command -v node >/dev/null 2>&1 && found+=("Node.js $(node -v 2>/dev/null)") || missing+=("Node.js")
rpm -q --quiet certbot && found+=("certbot") || missing+=("certbot")
echo "Found: $( [ "${#found[@]}" -gt 0 ] && (IFS=,; echo "${found[*]}" | sed 's/,/, /g') || echo nothing)."
[ "${#missing[@]}" -eq 0 ] || echo "Missing (installed when needed): $(IFS=,; echo "${missing[*]}" | sed 's/,/, /g')."

#an app next to this script (the installer of an installed app): its user and address are the defaults
SELF_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
DOMAIN_DEFAULT=$(hostname -f 2>/dev/null || hostname)
USER_DEFAULT=""
if [ -d "$SELF_DIR/app" ] && [ -f "$SELF_DIR/app/v.php" ]; then
    USER_DEFAULT=$(stat -c %U "$SELF_DIR")
    if [ -f "$SELF_DIR/app.env" ]; then
        ENV_FILE="$SELF_DIR/app.env"
        DOMAIN_DEFAULT=$(url_host "$(env_value BASE_URL)")
    fi
fi

echo
echo "The server (Enter keeps the answer in brackets)"
ask DOMAIN "The site's domain (its DNS record points to this server)" "$DOMAIN_DEFAULT"
DOMAIN="${DOMAIN#*://}"
DOMAIN="${DOMAIN%%/*}"
[[ $DOMAIN =~ ^[A-Za-z0-9.-]+$ ]] || { echo "not a domain: $DOMAIN"; exit 1; }
if [ -z "$USER_DEFAULT" ]; then
    USER_DEFAULT=$(echo "${DOMAIN%%.*}" | tr -cd 'a-z0-9_-')
fi
while true; do
    ask APP_USER "The system user NetFlow9 runs as (its home holds the app)" "$USER_DEFAULT"
    [[ $APP_USER =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] && [ "$APP_USER" != "root" ] && break
    echo "  lowercase letters, digits, - and _ (not root)"
done
#CLD: 2026-10-29T04:30:00+02:00: || true: a new user is not found by getent (exit 2: with pipefail it stopped the script, silently)
BASE=$(getent passwd "$APP_USER" | cut -d: -f6 || true)
BASE="${BASE:-/home/$APP_USER}"
ENV_FILE="$BASE/app.env"
SCRIPTS="$BASE/scripts"

SET_UP=0
[ -f "$ENV_FILE" ] && SET_UP=1
CLONE=0
REPO="$REPO_DEFAULT"
BRANCH="$BRANCH_DEFAULT"
if [ ! -d "$BASE/.git" ]; then
    CLONE=1
    ask REPO "The NetFlow9 repository" "$REPO"
    ask BRANCH "Its branch" "$BRANCH"
fi

echo
echo "Options"
OPT_LOCAL_MONGO=1
if [ "$SET_UP" -eq 1 ]; then
    case $(env_value MONGO_URI) in *127.0.0.1*|*localhost*) ;; *) OPT_LOCAL_MONGO=0 ;; esac
else
    if [ -n "$MONGOD_PKG" ]; then
        ask_yes OPT_LOCAL_MONGO "MongoDB on this server (installed: $MONGOD_PKG; else you give the connection string of yours)" y
    else
        ask_yes OPT_LOCAL_MONGO "MongoDB on this server (MongoDB $MONGODB_VERSION is installed; else you give the connection string of yours)" y
    fi
fi

OPT_LE=0
LE_EMAIL=""
if [ ! -e "$BASE/ssl/ssl.crt" ] || [ "$(readlink "$BASE/ssl/ssl.crt" 2>/dev/null)" = "selfsigned.crt" ] || [ "$(readlink "$BASE/ssl/ssl.crt" 2>/dev/null)" = "$BASE/ssl/snakeoil.crt" ]; then
    if domain_points_here "$DOMAIN"; then
        ask_yes OPT_LE "A Let's Encrypt certificate for $DOMAIN (port 80 must be reachable; else a self-signed one)" y
    else
        echo "  $DOMAIN does not point to this server yet: a self-signed certificate (run this again later for Let's Encrypt)"
    fi
    [ "$OPT_LE" -eq 0 ] || ask LE_EMAIL "Email for Let's Encrypt's notices" ""
fi

if chromium_installed; then
    ask_yes OPT_BROWSER "The web browser for the AI steps (installed: its service)" y
else
    ask_yes OPT_BROWSER "The web browser for the AI steps (Playwright and Chromium, about 500 MB)" y
fi

MAIL_DOMAIN=""
[ "$SET_UP" -eq 1 ] && MAIL_DOMAIN=$(env_value MAIL_INBOUND_DOMAIN)
ask_yes OPT_MAIL "Inbound email: workflows started by emails (Haraka on port 25)" "$([ -n "$MAIL_DOMAIN" ] || [ -d "$BASE/haraka/node_modules/haraka" ] && echo y || echo n)"
[ "$OPT_MAIL" -eq 0 ] || ask MAIL_DOMAIN "The domain of its addresses (its MX record points to this server)" "${MAIL_DOMAIN:-$DOMAIN}"

OPT_MONGO=0
if [ "$OPT_LOCAL_MONGO" -eq 1 ]; then
    if [ "$SET_UP" -eq 1 ] && [[ $(env_value MONGO_URI) == *@* ]]; then
        echo "  MongoDB: the app signs in with its own user already"
    elif command -v mongosh >/dev/null 2>&1 && systemctl is-active --quiet mongod && ! mongo_auth_off; then
        echo "  MongoDB: it requires sign-in already"
    else
        ask_yes OPT_MONGO "Secure MongoDB: its own users with random passwords, sign-in required" y
    fi
fi

#CLD: 2026-10-29T07:00:00+02:00: Percona Server for MongoDB on this server: its data encrypted at rest (WiredTiger's encryption, a key file), not asked. Only while its data
#folder is empty: mongod does not start with encryption on files written without it (existing data: mongodump, an empty dbPath, encryption on, mongorestore)
OPT_MONGO_ENCRYPT=0
MONGO_ENCRYPT_NOTE=""
MONGO_KEY_FILE=/etc/mongod-encryption.key
if [ "$OPT_LOCAL_MONGO" -eq 1 ] && [[ $MONGOD_PKG == percona-* ]]; then
    if grep -qE '^[[:space:]]*enableEncryption:[[:space:]]*true' "$MONGOD_CONF" 2>/dev/null; then
        echo "  MongoDB: its data is encrypted already"
    else
        MONGO_DBPATH=$(awk '/^[[:space:]]*dbPath:/ { gsub(/["\047]/, "", $2); print $2; exit }' "$MONGOD_CONF" 2>/dev/null || true)
        MONGO_DBPATH="${MONGO_DBPATH:-/var/lib/mongo}"
        if [ -e "$MONGO_DBPATH/WiredTiger" ]; then
            MONGO_ENCRYPT_NOTE="MongoDB's data ($MONGO_DBPATH) is not encrypted: it has data already, which cannot be encrypted in place (mongodump, an empty $MONGO_DBPATH, run this again, mongorestore)."
            echo "  $MONGO_ENCRYPT_NOTE"
        else
            OPT_MONGO_ENCRYPT=1
        fi
    fi
fi

#CLD: 2026-10-29T05:00:00+02:00: the distribution's PHP (php-common, ...) conflicts with php$PHPV-syspaths (both are /usr/bin/php, which the app's console and daemon run): replaced
#only when allowed (dnf --allowerasing removes those packages and what needs them)
OPT_REPLACE_PHP=0
if [ -n "$DISTRO_PHP" ]; then
    ask_yes OPT_REPLACE_PHP "The distribution's PHP $DISTRO_PHP_VERSION is installed and must make way for PHP $PHP_LABEL: remove it" n
    if [ "$OPT_REPLACE_PHP" -eq 0 ]; then
        echo "Nothing was changed: NetFlow9 needs PHP $PHP_LABEL. Remove the distribution's PHP ($DISTRO_PHP), or allow it here."
        exit 1
    fi
fi

OPT_SELINUX=0
if [ "$(getenforce 2>/dev/null || echo Disabled)" = "Enforcing" ]; then
    ask_yes OPT_SELINUX "SELinux is enforcing: nginx and PHP-FPM cannot serve the app from $BASE. Set it to permissive" y
fi

echo
echo "It will:"
#CLD: 2026-10-29T05:30:00+02:00: only what is missing and needed by the answers (was: every package named, installed or not)
to_install=()
for m in "${missing[@]}"; do
    case $m in
        certbot) [ "$OPT_LE" -eq 1 ] && to_install+=("$m") ;;
        Node.js) { [ "$OPT_BROWSER" -eq 1 ] || [ "$OPT_MAIL" -eq 1 ]; } && to_install+=("$m") ;;
        MongoDB) [ "$OPT_LOCAL_MONGO" -eq 1 ] && to_install+=("$m") ;;
        *) to_install+=("$m") ;;
    esac
done
[ "${#to_install[@]}" -eq 0 ] || echo "  - install $(IFS=,; echo "${to_install[*]}" | sed 's/,/, /g')"
[ "$OPT_REPLACE_PHP" -eq 1 ] && echo "  - remove the distribution's PHP $DISTRO_PHP_VERSION ($DISTRO_PHP)"
[ "$OPT_SELINUX" -eq 1 ] && echo "  - set SELinux to permissive"
id "$APP_USER" >/dev/null 2>&1 || echo "  - create the user $APP_USER (home $BASE)"
[ "$CLONE" -eq 1 ] && echo "  - clone $REPO ($BRANCH) into $BASE"
echo "  - set up PHP-FPM, nginx and the certificate for $DOMAIN$([ "$OPT_LE" -eq 1 ] && echo " (Let's Encrypt)")"
[ "$SET_UP" -eq 0 ] && echo "  - set up the app (it asks the database, the outgoing email and the first account)"
[ "$OPT_BROWSER" -eq 1 ] && echo "  - install the web browser for the AI steps"
[ "$OPT_MAIL" -eq 1 ] && echo "  - install inbound email for @$MAIL_DOMAIN"
[ "$OPT_MONGO" -eq 1 ] && echo "  - give MongoDB its users and turn on sign-in"
[ "$OPT_MONGO_ENCRYPT" -eq 1 ] && echo "  - encrypt MongoDB's data at rest (Percona; its key: $MONGO_KEY_FILE)"
echo "  - install and start the background services"
echo "  The firewall is not changed: open 80 and 443$([ "$OPT_MAIL" -eq 1 ] && echo ", 25") yourself."
ask_yes GO "Go on?" y
[ "$GO" -eq 1 ] || { echo "Nothing was changed."; exit 0; }

# ---------------------------------------------------------------------------------------------------------------
step "System packages"
if [ "$OPT_SELINUX" -eq 1 ]; then
    setenforce 0
    sed -i 's/^SELINUX=enforcing/SELINUX=permissive/' /etc/selinux/config
    echo "+ SELinux permissive"
fi
install_packages epel-release dnf-plugins-core
install_packages git curl wget tar openssl polkit
if ! rpm -q --quiet remi-release; then
    dnf -y install https://rpms.remirepo.net/enterprise/remi-release-9.rpm
fi
dnf config-manager --set-enabled remi >/dev/null
# shellcheck disable=SC2086
install_packages $PHP_PACKAGES nginx redis
#the Database step's drivers: each one that can be installed (strict=0, --skip-broken: one missing from the repositories does not stop the others)
# shellcheck disable=SC2086
db_missing=$(missing_packages $PHP_DB_PACKAGES | tr '\n' ' ')
if [ -n "${db_missing// /}" ]; then
    echo "+ dnf install $db_missing(the Database step's drivers)"
    # shellcheck disable=SC2086
    dnf -y install --setopt=strict=0 --skip-broken $db_missing || echo "! some of the Database step's drivers were not installed (above)"
fi
#/usr/bin/php: PHP $PHPV's (in place of the distribution's, when allowed above)
if ! rpm -q --quiet "php$PHPV-syspaths"; then
    if [ "$OPT_REPLACE_PHP" -eq 1 ]; then
        #CLD: 2026-10-29T05:45:00+02:00: the distribution's PHP removed first (was dnf install --allowerasing: one transaction cannot turn its /etc/php.d folder into
        #php$PHPV-syspaths' link, "file /etc/php.d ... conflicts"); what it leaves there (edited files kept by rpm) is moved aside, then php$PHPV-syspaths
        echo "+ dnf remove $DISTRO_PHP"
        # shellcheck disable=SC2086
        dnf -y remove $DISTRO_PHP
        ts=$(date +%Y%m%dT%H%M%S)
        for f in /etc/php.d /etc/php.ini; do
            if [ -e "$f" ] && [ ! -L "$f" ]; then
                mv "$f" "$f.distro-$ts"
                echo "+ left by the distribution's PHP: $f, kept as $f.distro-$ts"
            fi
        done
        install_packages "php$PHPV-syspaths"
    else
        install_packages "php$PHPV-syspaths"
    fi
fi
#CLD: 2026-10-29T05:15:00+02:00: MongoDB's own server only when none is installed (Percona Server for MongoDB conflicts with mongodb-org: kept, with Percona's mongosh)
mongodb_repo() {
    local repo="/etc/yum.repos.d/mongodb-org-$MONGODB_VERSION.repo"
    [ -f "$repo" ] && return 0
    cat > "$repo" <<EOF
[mongodb-org-$MONGODB_VERSION]
name=MongoDB Repository
baseurl=https://repo.mongodb.org/yum/redhat/9/mongodb-org/$MONGODB_VERSION/x86_64/
gpgcheck=1
enabled=1
gpgkey=https://pgp.mongodb.com/server-8.0.asc
EOF
    echo "+ $repo"
}
if [ "$OPT_LOCAL_MONGO" -eq 1 ] && [ -z "$MONGOD_PKG" ]; then
    mongodb_repo
    install_packages mongodb-org
elif [ -n "$MONGOD_PKG" ]; then
    echo "MongoDB: $MONGOD_PKG is installed: kept"
fi
#CLD: 2026-10-29T07:00:00+02:00: Percona's encryption at rest, before mongod's first start (it then writes its files encrypted): a key file (32 random bytes, base64), made
#once per server, read by mongod only
#SECURITY TODO: a static key, kept on the same server as the data: it protects the data files, backups of them and old disks taken away, not the data from root here,
#and it is never rotated. Make it more secure: keep the key in a key manager (Percona: HashiCorp Vault, security.vault, or a KMIP server, security.kmip) instead of a file,
#and rotate it regularly (Vault / KMIP: mongod --rotateMasterKey; a key file: each replica set member resynced with a new key). Keep a copy of the key away from this
#server: without it the data cannot be read
if [ "$OPT_MONGO_ENCRYPT" -eq 1 ]; then
    if [ ! -s "$MONGO_KEY_FILE" ]; then
        (umask 077; openssl rand -base64 32 > "$MONGO_KEY_FILE")
        echo "+ $MONGO_KEY_FILE (MongoDB's encryption key)"
    fi
    chown mongod:mongod "$MONGO_KEY_FILE"
    chmod 600 "$MONGO_KEY_FILE"
    backup="$MONGOD_CONF.bak-$(date +%Y%m%dT%H%M%S)"
    cp -p "$MONGOD_CONF" "$backup"
    mongod_conf_security "$backup" "enableEncryption: true" "encryptionKeyFile: $MONGO_KEY_FILE" "encryptionCipherMode: AES256-CBC"
    echo "+ $MONGOD_CONF: encryption at rest (the old one: $backup)"
fi
if [ "$OPT_LOCAL_MONGO" -eq 1 ]; then
    systemctl enable --now mongod || { echo "! mongod did not start: journalctl -u mongod, $MONGOD_CONF"; exit 1; }
fi
#mongosh (the app's checks, MongoDB's users): Percona's with Percona's server, else MongoDB's
if ! command -v mongosh >/dev/null 2>&1; then
    if [[ $MONGOD_PKG == percona-* ]]; then
        install_packages percona-mongodb-mongosh
    else
        mongodb_repo
        install_packages mongodb-mongosh
    fi
fi
[ "$OPT_LE" -eq 0 ] || install_packages certbot
if [ "$OPT_BROWSER" -eq 1 ] || [ "$OPT_MAIL" -eq 1 ]; then
    if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
        #the newest Node.js stream of the distribution
        #CLD: 2026-10-29T04:30:00+02:00: || true: no module streams (dnf fails) is not an error: the distribution's own nodejs then
        stream=$(dnf -q module list nodejs 2>/dev/null | awk '$1 == "nodejs" && $2 ~ /^[0-9]+$/ { print $2 }' | sort -n | tail -1 || true)
        if [ -n "$stream" ]; then
            dnf -y module reset nodejs
            dnf -y module enable "nodejs:$stream"
        fi
        dnf -y install nodejs npm
    fi
    echo "Node.js $(node -v)"
fi
systemctl enable --now redis
#the default pool is off: each app has its own (below)
[ ! -f "$PHP_ETC/php-fpm.d/www.conf" ] || mv "$PHP_ETC/php-fpm.d/www.conf" "$PHP_ETC/php-fpm.d/www.conf.disabled"
[ -e /etc/php-fpm.d ] || ln -s "$PHP_ETC/php-fpm.d" /etc/php-fpm.d

# ---------------------------------------------------------------------------------------------------------------
step "The user $APP_USER and the app's files"
if ! id "$APP_USER" >/dev/null 2>&1; then
    useradd -U -m -d "$BASE" "$APP_USER"
    echo "+ user $APP_USER, home $BASE"
fi
#nginx reads the web root (the group); the home is not world-readable
usermod -a -G "$APP_USER" nginx
chmod 750 "$BASE"
if [ "$CLONE" -eq 1 ]; then
    #into the home as it is (its dotfiles stay: the repository tracks only the app's files)
    as_user git init -q
    as_user git remote add origin "$REPO" 2>/dev/null || as_user git remote set-url origin "$REPO"
    as_user git fetch -q origin "$BRANCH"
    as_user git checkout -q -f -B "$BRANCH" "origin/$BRANCH"
    as_user git submodule update -q --init --recursive
    echo "+ $REPO ($BRANCH) in $BASE"
else
    echo "the app's files are there already ($BASE/.git): not changed (update them with git pull)"
fi
for d in logs tmp run var var/php/session var/php/wsdlcache var/php/opcache etc ssl; do
    [ -d "$BASE/$d" ] || install -d -o "$APP_USER" -g "$APP_USER" -m 750 "$BASE/$d"
done

step "PHP-FPM"
#php.ini: NetFlow9's (the package's kept as php.ini.installer_save), with the server's time zone
if [ ! -f "$PHP_ETC/php.ini.installer_save" ]; then
    mv "$PHP_ETC/php.ini" "$PHP_ETC/php.ini.installer_save"
    tz=$(timedatectl show -p Timezone --value 2>/dev/null || echo UTC)
    sed -e "s|^date.timezone = .*|date.timezone = ${tz:-UTC}|" "$SCRIPTS/system/php.ini" > "$PHP_ETC/php.ini"
    echo "+ $PHP_ETC/php.ini (time zone ${tz:-UTC})"
fi
#the app's pool (when no pool listens on its socket yet)
socket="$BASE/run/php-fpm.sock"
if ! grep -qsF "listen = $socket" "$PHP_ETC"/php-fpm.d/*.conf; then
    pool="$PHP_ETC/php-fpm.d/$APP_USER.conf"
    sed -e "s#Q_POOL_SOCK#$socket#g" -e "s#Q_POOL_HOME#$BASE#g" -e "s/Q_POOL_NAME/$APP_USER/g" -e "s/Q_POOL_MAX_CHILDREN/25/g" \
        -e "s/Q_POOL_START_SERVERS/5/g" -e "s/Q_POOL_MIN_SPARE/2/g" -e "s/Q_POOL_MAX_SPARE/10/g" "$SCRIPTS/system/fpm-pool.conf" > "$pool"
    ln -sfn "$pool" "$BASE/etc/fpm-pool.conf"
    chown -h "$APP_USER:$APP_USER" "$BASE/etc/fpm-pool.conf"
    echo "+ $pool"
fi
systemctl enable "$PHP_FPM" >/dev/null 2>&1
systemctl restart "$PHP_FPM"
#logrotate (when none rotates its logs yet)
if ! grep -qsF "$BASE/logs/" /etc/logrotate.d/*; then
    sed -e "s#Q_HOME#$BASE#g" "$SCRIPTS/system/logrotate.conf" > "/etc/logrotate.d/nfn-$APP_USER"
    echo "+ /etc/logrotate.d/nfn-$APP_USER"
fi

step "nginx and the certificate"
#a self-signed certificate first (nginx needs one to start; Let's Encrypt replaces it)
if [ ! -e "$BASE/ssl/ssl.crt" ]; then
    openssl req -x509 -newkey rsa:2048 -nodes -days 825 -subj "/CN=$DOMAIN" -addext "subjectAltName=DNS:$DOMAIN" \
        -keyout "$BASE/ssl/selfsigned.key" -out "$BASE/ssl/selfsigned.crt" 2>/dev/null
    chmod 600 "$BASE/ssl/selfsigned.key"
    ln -sfn selfsigned.crt "$BASE/ssl/ssl.crt"
    ln -sfn selfsigned.key "$BASE/ssl/ssl.key"
    chown -h "$APP_USER:$APP_USER" "$BASE/ssl/selfsigned.key" "$BASE/ssl/selfsigned.crt" "$BASE/ssl/ssl.crt" "$BASE/ssl/ssl.key"
    echo "+ a self-signed certificate for $DOMAIN"
fi
"$SCRIPTS/0_nginx-install.sh" --server-name="$DOMAIN"
systemctl enable nginx >/dev/null 2>&1
systemctl is-active --quiet nginx || systemctl start nginx
if [ "$OPT_LE" -eq 1 ]; then
    #http-01 through the site (port 80 redirects to https, which serves .well-known/acme-challenge from the web root)
    if certbot certonly --webroot -w "$BASE/public_html" -d "$DOMAIN" --non-interactive --agree-tos \
            $([ -n "$LE_EMAIL" ] && echo "-m $LE_EMAIL" || echo "--register-unsafely-without-email") --deploy-hook "systemctl reload nginx"; then
        ln -sfn "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" "$BASE/ssl/ssl.crt"
        ln -sfn "/etc/letsencrypt/live/$DOMAIN/privkey.pem" "$BASE/ssl/ssl.key"
        chown -h "$APP_USER:$APP_USER" "$BASE/ssl/ssl.crt" "$BASE/ssl/ssl.key"
        systemctl enable --now certbot-renew.timer >/dev/null 2>&1 || true
        systemctl reload nginx
        echo "+ Let's Encrypt certificate for $DOMAIN (renewed by certbot-renew.timer)"
    else
        OPT_LE=0
        echo "! Let's Encrypt failed (above): the self-signed certificate stays; run this again once $DOMAIN points here and port 80 is open"
    fi
fi

# ---------------------------------------------------------------------------------------------------------------
if [ "$SET_UP" -eq 0 ]; then
    step "The app"
    as_app setup::checks || { echo "a required prerequisite is missing (above, marked !): fix it, then run this again"; exit 1; }
    URL="https://$DOMAIN"
    MONGO_URI="$LOCAL_MONGO/"
    MONGO_DB="netflow9-db"
    SMTP_HOST="127.0.0.1"
    SMTP_PORT="25"
    SMTP_SECURE_ANSWER="none"
    SMTP_USER=""
    MAIL_FROM="noreply@$DOMAIN"
    ACCOUNT_NAME=""
    OWNER_USER="admin"
    OWNER_EMAIL="$LE_EMAIL"
    OWNER_FIRST=""
    OWNER_LAST=""
    #asked until the app's own checks accept the answers (Enter keeps the answer given before)
    while true; do
        echo
        #CLD: 2026-10-29T05:15:00+02:00: MongoDB here that requires sign-in already (e.g. Percona's, set up before): its connection string with a user (was: always without one)
        if [ "$OPT_LOCAL_MONGO" -eq 1 ] && mongo_auth_off; then
            echo "The database: MongoDB on this server, $MONGO_URI"
        elif [ "$OPT_LOCAL_MONGO" -eq 1 ]; then
            echo "MongoDB on this server requires sign-in: a user that may create the app's database (readWrite on it, or more)"
            [ "$MONGO_URI" != "$LOCAL_MONGO/" ] || MONGO_URI="mongodb://<user>:<password>@127.0.0.1:27017/?authSource=admin"
            ask MONGO_URI "MongoDB connection string" "$MONGO_URI"
        else
            ask MONGO_URI "MongoDB connection string" "mongodb://"
        fi
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
        ask MAIL_FROM "Emails come from" "$MAIL_FROM"
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
    answers_json | as_app setup::install
fi
URL=$(env_value BASE_URL)

if [ "$OPT_MAIL" -eq 1 ]; then
    set_env MAIL_INBOUND_DOMAIN "$MAIL_DOMAIN"
fi

if [ "$OPT_BROWSER" -eq 1 ]; then
    step "The web browser for the AI steps"
    if chromium_installed; then
        echo "Playwright and Chromium are installed already"
    else
        "$SCRIPTS/1_playwright-install.sh"
    fi
fi

if [ "$OPT_MAIL" -eq 1 ]; then
    step "Inbound email (Haraka)"
    "$SCRIPTS/2_haraka-install.sh" --domain="$MAIL_DOMAIN"
fi

MONGO_CHANGED=0
if [ "$OPT_MONGO" -eq 1 ] && mongo_auth_off; then
    step "MongoDB: users and sign-in"
    "$SCRIPTS/3_mongo-auth-setup.sh" --no-next-steps
    MONGO_CHANGED=1
    if grep -qE '^[[:space:]]*authorization:[[:space:]]*enabled' "$MONGOD_CONF"; then
        echo "$MONGOD_CONF: authorization is enabled already"
    else
        backup="$MONGOD_CONF.bak-$(date +%Y%m%dT%H%M%S)"
        cp -p "$MONGOD_CONF" "$backup"
        mongod_conf_security "$backup" "authorization: enabled"
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
"$SCRIPTS/4_services-install.sh" install $([ "$OPT_BROWSER" -eq 1 ] || echo --no-playwright)
#the services running before reconnect with the new MongoDB users
[ "$MONGO_CHANGED" -eq 0 ] || systemctl try-restart nfn-daemon.service nfn-haraka.service || true

# ---------------------------------------------------------------------------------------------------------------
step "Done"
echo "Open $URL$([ "$SET_UP" -eq 0 ] && echo " and sign in as $OWNER_USER")."
if [ "$(readlink "$BASE/ssl/ssl.crt")" = "selfsigned.crt" ]; then
    echo "The certificate is self-signed: browsers warn. Run this again once $DOMAIN points here for Let's Encrypt, or put yours in $BASE/ssl/ (ssl.crt, ssl.key)."
fi
if [ "$OPT_MONGO_ENCRYPT" -eq 1 ]; then
    echo "MongoDB's data is encrypted with the key in $MONGO_KEY_FILE: keep a copy of it away from this server (without it the data cannot be read); a static key: move it to a key manager (Vault / KMIP) and rotate it."
fi
[ -z "$MONGO_ENCRYPT_NOTE" ] || echo "$MONGO_ENCRYPT_NOTE"
[ "$MONGO_CHANGED" -eq 1 ] && echo "MongoDB's operators' account (nfn_admin) is in $BASE/.mongodb-admin (root's): move it to a password manager, then delete the file."
if [ "$OPT_MAIL" -eq 1 ]; then
    echo "Inbound email: point the MX record of $MAIL_DOMAIN to this server; DKIM keys: cd $BASE/haraka/dkim && ./dkim_key_gen.sh $MAIL_DOMAIN"
fi
echo "The firewall was not changed: open 80 and 443$([ "$OPT_MAIL" -eq 1 ] && echo ", and 25 for inbound email") if it is on."
echo "The installer is also in $BASE/install.sh: run it again to add an option later."
