#!/bin/bash

#Haraka, the inbound mail of the Email (inbound) trigger (util\InboundMail), as a system service, nfn-haraka.service. One per server (port 25):
#the installation that has it wrote its unit; another installation's unit is left alone.
#install, as the app's user (Haraka's files are its), into <app>/haraka (HARAKA_DIR of app.env, when set):
#  node_modules/haraka      Haraka itself, a release from GitHub (github.com/haraka/Haraka; the latest by default), its dependencies by npm
#  node_modules/mongodb     the MongoDB driver of Rail22's plugin (3.x: plugins/nfn.js uses its API)
#  plugins/nfn.js, config/  Rail22's plugin and configuration (scripts/haraka/: @BASE@, @DOMAIN@, @MONGO_DB@, @DAEMON_PORT@ filled from app.env), then Haraka's defaults
#  queue/, dkim/, config/dkim/   (dkim/: dkim_key_gen.sh; config/dkim/<domain>/: the DKIM keys)
#Nothing that is there is overwritten (listed as kept), except Rail22's plugin code (plugins/nfn.js), replaced when it differs: run install again after an update of the app.
#Then, as root: the unit (Haraka runs as root for port 25; it forks and writes run/haraka.pid), started at boot and now; the polkit rule again when the app's
#services are installed (scripts/daemon.sh rule: the Services page controls it). uninstall removes the unit only: the files stay.
#CLD: 2026-11-01T21:30:00+02:00: commands, as scripts/playwright-mcp.sh; was scripts/2_haraka-install.sh (the files) and 4_services-install.sh (the unit; now daemon.sh); the old
#start / stop script (scripts/haraka.sh) moved to attic/
#Run as root:
#   sudo scripts/haraka.sh install [--version=<vX.Y.Z>] [--domain=<inbound domain>] [--dir=<folder>] [--dry-run]   install / update, start at boot, start now
#   sudo scripts/haraka.sh start | stop | restart | status | uninstall
#     --version   the Haraka release (a tag of github.com/haraka/Haraka; default: the latest release)
#     --domain    the inbound mail domain (default: MAIL_INBOUND_DOMAIN of app.env)
#     --dir       where to install (default: HARAKA_DIR of app.env, else <app>/haraka)
#     --dry-run   print what install would do (the files; no unit)
#Then: the MongoDB user's password (sudo scripts/mongo.sh users, or uri in config/nfn.ini), the DKIM keys (config/dkim/<domain>/)

set -euo pipefail

SELF_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SELF="$SELF_DIR/$(basename "${BASH_SOURCE[0]}")"
BASE=$(dirname "$SELF_DIR")
APP_USER=$(stat -c %U "$BASE/app")
#the arguments, for running install again as the app's user
ARGS=("$@")
TEMPLATES="$SELF_DIR/haraka"
REPO="haraka/Haraka"
MONGODB_DRIVER="mongodb@^3.5.5"
SERVICE=nfn-haraka
UNIT=/etc/systemd/system/$SERVICE.service
POLKIT_RULE=/etc/polkit-1/rules.d/49-rail22-services-$APP_USER.rules

VERSION=""
DOMAIN=""
DIR=""
DRY_RUN=0

usage() {
    echo "usage: sudo $0 {install [--version=<vX.Y.Z>] [--domain=<inbound domain>] [--dir=<folder>] [--dry-run] | start | stop | restart | status | uninstall}"
    exit "${1:-1}"
}

command="${1:-}"
[ -n "$command" ] && shift || true
while [ "$#" -gt 0 ]; do
    case $1 in
        --version=*) VERSION="${1#*=}" ;;
        --domain=*) DOMAIN="${1#*=}" ;;
        --dir=*) DIR="${1#*=}" ;;
        --dry-run) DRY_RUN=1 ;;
        -h|--help) usage 0 ;;
        *) echo "unknown option: $1"; usage 1 ;;
    esac
    shift
done

need_root() {
    [ "$(id -u)" -eq 0 ] || { echo "run it as root: sudo $0 $command"; exit 1; }
}

#where Haraka is: --dir, else HARAKA_DIR of app.env, else <app>/haraka
haraka_dir() {
    local d="$DIR"
    [ -n "$d" ] || d=$(set +u; . "$BASE/app.env" >/dev/null 2>&1; echo "${HARAKA_DIR:-}")
    d="${d:-$BASE/haraka}"
    echo "${d%/}"
}

#the unit is this installation's (written by one of its scripts), or not there
own_unit() {
    [ ! -f "$UNIT" ] || [[ $(head -n1 "$UNIT") == "#written by $BASE/scripts/"* ]]
}

need_own_unit() {
    own_unit || { echo "$SERVICE.service is another installation's ($(head -n1 "$UNIT")): left as it is"; exit 1; }
}

#runs a command, or only prints it (--dry-run)
run() {
    echo "+ $*"
    if [ "$DRY_RUN" -eq 0 ]; then
        "$@"
    fi
}

#copies $1 to $2 when $2 is not there ($3: fill the @...@ of Rail22's templates; $4: a kept file is not listed; $5: replace $2 when it differs, listed as updated)
put() {
    local src=$1 dest=$2 fill=${3:-0} quiet=${4:-0} replace=${5:-0}
    if [ -e "$dest" ]; then
        if [ "$replace" -eq 1 ]; then
            local new
            if [ "$fill" -eq 1 ]; then
                new=$(sed -e "s|@BASE@|$BASE|g" -e "s|@DOMAIN@|$DOMAIN|g" -e "s|@MONGO_DB@|$MONGO_DB|g" -e "s|@DAEMON_PORT@|$DAEMON_PORT|g" "$src"; echo x)
            else
                new=$(cat "$src"; echo x)
            fi
            if [ "$new" != "$(cat "$dest"; echo x)" ]; then
                UPDATED+=("${dest#$DIR/}")
                echo "+ update $dest"
                [ "$DRY_RUN" -eq 1 ] || printf '%s' "${new%x}" > "$dest"
                return
            fi
        fi
        [ "$quiet" -eq 1 ] || KEPT+=("${dest#$DIR/}")
        return
    fi
    CREATED+=("${dest#$DIR/}")
    if [ "$fill" -eq 1 ]; then
        echo "+ fill $src > $dest"
        if [ "$DRY_RUN" -eq 0 ]; then
            sed -e "s|@BASE@|$BASE|g" -e "s|@DOMAIN@|$DOMAIN|g" -e "s|@MONGO_DB@|$MONGO_DB|g" -e "s|@DAEMON_PORT@|$DAEMON_PORT|g" "$src" > "$dest"
            chmod --reference="$src" "$dest"
        fi
    else
        run cp -p "$src" "$dest"
    fi
}

#copies the files of folder $1 into folder $2, the missing ones only ($3: fill, $4: quiet; $5: replace those that differ)
put_tree() {
    local src=$1 dest=$2 fill=${3:-0} quiet=${4:-0} replace=${5:-0} f rel
    while IFS= read -r -d '' f; do
        rel=${f#$src/}
        if [ -d "$f" ]; then
            [ -d "$dest/$rel" ] || run mkdir -p "$dest/$rel"
        else
            put "$f" "$dest/$rel" "$fill" "$quiet" "$replace"
        fi
    done < <(find "$src" -mindepth 1 -print0 | sort -z)
}

install_files() {
    for cmd in node npm curl tar sed; do
        if ! command -v "$cmd" >/dev/null 2>&1; then
            echo "missing: $cmd"
            exit 1
        fi
    done

    #app.env: HARAKA_DIR, MAIL_INBOUND_DOMAIN, MONGO_DB, HARAKA_MONGO_URI, DAEMON_PORT
    if [ ! -f "$BASE/app.env" ]; then
        echo "missing: $BASE/app.env"
        exit 1
    fi
    ENV_HARAKA_DIR=$(set +u; . "$BASE/app.env" >/dev/null 2>&1; echo "${HARAKA_DIR:-}")
    ENV_DOMAIN=$(set +u; . "$BASE/app.env" >/dev/null 2>&1; echo "${MAIL_INBOUND_DOMAIN:-}")
    ENV_MONGO_DB=$(set +u; . "$BASE/app.env" >/dev/null 2>&1; echo "${MONGO_DB:-}")
    #Haraka's MongoDB user, when scripts/mongo.sh users has made it (HARAKA_MONGO_URI): written into a new config/nfn.ini
    ENV_HARAKA_URI=$(set +u; . "$BASE/app.env" >/dev/null 2>&1; echo "${HARAKA_MONGO_URI:-}")
    #the daemon's port (DAEMON_PORT, default 9000): nfn.ini job_port
    DAEMON_PORT=$(set +u; . "$BASE/app.env" >/dev/null 2>&1; echo "${DAEMON_PORT:-9000}")
    DIR="${DIR:-${ENV_HARAKA_DIR:-$BASE/haraka}}"
    DIR="${DIR%/}"
    DOMAIN="${DOMAIN:-$ENV_DOMAIN}"
    MONGO_DB="${ENV_MONGO_DB:-rail22-db}"
    if [ -z "$DOMAIN" ]; then
        echo "no inbound mail domain: set MAIL_INBOUND_DOMAIN in app.env, or --domain=<domain>"
        exit 1
    fi

    #the release: the latest, unless named
    if [ -z "$VERSION" ]; then
        VERSION=$(curl -fsSL "https://api.github.com/repos/$REPO/releases/latest" | sed -n 's/^ *"tag_name": *"\([^"]*\)".*/\1/p' | head -1)
        if [ -z "$VERSION" ]; then
            echo "the latest release of $REPO was not found: name one with --version=<vX.Y.Z>"
            exit 1
        fi
    fi
    case $VERSION in
        v*) ;;
        *) VERSION="v$VERSION" ;;
    esac

    echo "Haraka $VERSION into $DIR (domain $DOMAIN, database $MONGO_DB)"

    CREATED=()
    KEPT=()
    UPDATED=()

    #the folders
    for d in "$DIR" "$DIR/config" "$DIR/config/dkim" "$DIR/plugins" "$DIR/queue" "$DIR/dkim"; do
        [ -d "$d" ] || run mkdir -p "$d"
    done

    #package.json: a new one names Haraka's release and the driver; an existing one is kept
    if [ ! -e "$DIR/package.json" ]; then
        CREATED+=("package.json")
        echo "+ write $DIR/package.json"
        if [ "$DRY_RUN" -eq 0 ]; then
            cat > "$DIR/package.json" <<EOF
{
  "name": "haraka_local",
  "description": "Rail22's inbound mail (Haraka; scripts/haraka.sh)",
  "version": "0.0.1",
  "private": true,
  "dependencies": {
    "haraka": "https://github.com/$REPO/archive/refs/tags/$VERSION.tar.gz",
    "mongodb": "${MONGODB_DRIVER#mongodb@}"
  },
  "license": "MIT"
}
EOF
        fi
    else
        KEPT+=("package.json")
    fi

    #the packages that are missing: Haraka (the release's tarball from GitHub) and the driver
    SPECS=()
    if [ -d "$DIR/node_modules/haraka" ]; then
        KEPT+=("node_modules/haraka ($(sed -n 's/^ *"version": *"\([^"]*\)".*/\1/p' "$DIR/node_modules/haraka/package.json" | head -1))")
    else
        SPECS+=("https://github.com/$REPO/archive/refs/tags/$VERSION.tar.gz")
        CREATED+=("node_modules/haraka ($VERSION)")
    fi
    if [ -d "$DIR/node_modules/mongodb" ]; then
        KEPT+=("node_modules/mongodb")
    else
        SPECS+=("$MONGODB_DRIVER")
        CREATED+=("node_modules/mongodb")
    fi
    if [ "${#SPECS[@]}" -gt 0 ]; then
        #an existing package.json / package-lock.json is kept as it is (--no-save; the lock file restored after, should npm write it anyway)
        LOCK_BACKUP=""
        if [ -e "$DIR/package-lock.json" ] && [ "$DRY_RUN" -eq 0 ]; then
            LOCK_BACKUP=$(mktemp)
            cp -p "$DIR/package-lock.json" "$LOCK_BACKUP"
        fi
        if [[ " ${CREATED[*]} " == *" package.json "* ]]; then
            run npm --prefix "$DIR" install --omit=dev --no-audit --no-fund
        else
            run npm --prefix "$DIR" install --omit=dev --no-audit --no-fund --no-save "${SPECS[@]}"
        fi
        if [ -n "$LOCK_BACKUP" ]; then
            cp -p "$LOCK_BACKUP" "$DIR/package-lock.json"
            rm -f "$LOCK_BACKUP"
        fi
    fi

    #Rail22's plugin and configuration first (they win over Haraka's defaults of the same name), then Haraka's defaults (those already there not listed)
    #the plugin's code replaced when it differs (an update of the app brings a new nfn.js)
    put_tree "$TEMPLATES/plugins" "$DIR/plugins" 1 0 1
    put_tree "$TEMPLATES/config" "$DIR/config" 1
    #the DKIM keys generator (dkim/dkim_key_gen.sh), as it is
    put_tree "$TEMPLATES/dkim" "$DIR/dkim" 0
    if [ -d "$DIR/node_modules/haraka/config" ]; then
        put_tree "$DIR/node_modules/haraka/config" "$DIR/config" 0 1
    elif [ "$DRY_RUN" -eq 0 ]; then
        echo "warning: $DIR/node_modules/haraka/config not found: Haraka's default configuration not copied"
    fi
    #nfn.ini holds the database password: its owner only
    if [[ " ${CREATED[*]} " == *" config/nfn.ini "* ]]; then
        run chmod 600 "$DIR/config/nfn.ini"
        #Haraka's MongoDB user of app.env (HARAKA_MONGO_URI), instead of the template's CHANGE_ME
        if [ -n "$ENV_HARAKA_URI" ]; then
            echo "+ $DIR/config/nfn.ini: uri from HARAKA_MONGO_URI (app.env)"
            if [ "$DRY_RUN" -eq 0 ]; then
                uri_line="uri=$ENV_HARAKA_URI"
                out=""
                while IFS= read -r line || [ -n "$line" ]; do
                    [[ $line == uri=* ]] && line=$uri_line
                    out+="$line"$'\n'
                done < "$DIR/config/nfn.ini"
                printf '%s' "$out" > "$DIR/config/nfn.ini"
            fi
        fi
    fi

    echo
    echo "created (${#CREATED[@]}):"
    for f in "${CREATED[@]}"; do echo "  $f"; done
    echo "updated (${#UPDATED[@]}):"
    for f in "${UPDATED[@]}"; do echo "  $f"; done
    echo "kept, already there (${#KEPT[@]}):"
    for f in "${KEPT[@]}"; do echo "  $f"; done
}

write_unit() {
    local dir="$1" node
    node=$(PATH=/usr/local/bin:/usr/bin:/bin command -v node || true)
    [ -n "$node" ] || { echo "no system-wide node (/usr/bin/node): Haraka's unit not written"; exit 1; }
    cat > "$UNIT" <<EOF
#written by $SELF
[Unit]
Description=Rail22 inbound mail (Haraka of $BASE)
After=network-online.target mongod.service
Wants=network-online.target

[Service]
#as root: port 25; Haraka forks (config/smtp.ini daemonize=true) and writes its PID file
Type=forking
PIDFile=$BASE/run/haraka.pid
ExecStart=$node $dir/node_modules/haraka/bin/haraka -c $dir
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
    chmod 644 "$UNIT"
}

#a Haraka started by hand (its PID file, without the unit): stopped, the unit takes over
stop_pidfile() {
    local file="$BASE/run/haraka.pid" pid
    [ -s "$file" ] || return 0
    pid=$(tr -dc '0-9' < "$file")
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
        echo "+ stopping Haraka started by hand (pid $pid)"
        kill "$pid"
        for _ in $(seq 1 30); do
            kill -0 "$pid" 2>/dev/null || break
            sleep 1
        done
        kill -0 "$pid" 2>/dev/null && kill -9 "$pid"
    fi
    rm -f "$file"
}

#the Services page controls the unit: the polkit rule written again, when the app's services are installed
write_rule() {
    if [ -f "$POLKIT_RULE" ]; then
        "$BASE/scripts/daemon.sh" rule
    fi
}

case "$command" in
    install)
        if [ -n "${NFN_HARAKA_INSTALL_AS_APP_USER:-}" ] || [ "$DRY_RUN" -eq 1 ]; then
            install_files
            exit 0
        fi
        need_root
        [ "$APP_USER" != "root" ] || { echo "$BASE/app is owned by root: Haraka's files must be the app's user's"; exit 1; }
        need_own_unit
        #the files as the app's user, with the system's Node.js (not root's own nvm)
        echo "+ as $APP_USER"
        (cd "$BASE" && runuser -u "$APP_USER" -- env -u NVM_DIR -u NVM_BIN -u NVM_INC -u NPM_CONFIG_PREFIX -u npm_config_prefix \
            NFN_HARAKA_INSTALL_AS_APP_USER=1 PATH="/usr/local/bin:/usr/bin:/bin" "$SELF" "${ARGS[@]}")
        dir=$(haraka_dir)
        write_unit "$dir"
        systemctl daemon-reload
        systemctl is-active --quiet "$SERVICE" || stop_pidfile
        systemctl enable "$SERVICE"
        systemctl restart "$SERVICE"
        sleep 2
        systemctl --no-pager --lines=5 status "$SERVICE" || true
        write_rule
        echo
        echo "Done: Haraka listens on port 25 (open it in the firewall). Next:"
        if grep -q 'CHANGE_ME' "$dir/config/nfn.ini" 2>/dev/null; then
            echo "  - the MongoDB user nfn_haraka: sudo scripts/mongo.sh users (writes its password into config/nfn.ini, restarts Haraka), or set uri there"
        fi
        echo "  - DKIM keys for the domains mail is sent from: cd $dir/config/dkim && ../../dkim/dkim_key_gen.sh <domain> (then publish the record of <domain>/dns; restart Haraka)"
        ;;
    start|stop|restart)
        need_root
        need_own_unit
        systemctl "$command" "$SERVICE"
        ;;
    status)
        systemctl --no-pager --lines=10 status "$SERVICE" || true
        own_unit || echo "! the unit is another installation's: $(head -n1 "$UNIT")"
        dir=$(haraka_dir)
        echo "--- $dir"
        if [ ! -d "$dir/node_modules/haraka" ]; then
            echo "not installed: sudo $0 install"
        else
            echo "Haraka $(sed -n 's/^ *"version": *"\([^"]*\)".*/\1/p' "$dir/node_modules/haraka/package.json" | head -1)"
            if [ -r "$dir/config/nfn.ini" ]; then
                if grep -q 'CHANGE_ME' "$dir/config/nfn.ini"; then echo "! nfn.ini: no MongoDB password yet (sudo scripts/mongo.sh users)"; fi
                echo "inbound domain: $(sed -n 's/^domain=//p' "$dir/config/nfn.ini" | head -1)"
            fi
            keys=$(ls "$dir/config/dkim" 2>/dev/null | tr '\n' ' ' || true)
            echo "DKIM keys (config/dkim/): ${keys:-none}"
        fi
        ss -ltnH 'sport = :25' 2>/dev/null | grep -q . && echo "port 25: listening" || echo "port 25: nothing listens"
        ;;
    uninstall)
        need_root
        need_own_unit
        systemctl disable --now "$SERVICE" 2>/dev/null || true
        rm -f "$UNIT"
        systemctl daemon-reload
        write_rule
        echo "Removed the service; kept: $(haraka_dir) (remove it by hand when no longer needed)"
        ;;
    *)
        usage
        ;;
esac
