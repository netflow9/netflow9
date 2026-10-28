#!/bin/bash

#installs Haraka (inbound mail: the Email (inbound) trigger, plugins\InboundMail) into <app>/haraka (app.env HARAKA_DIR, when set), as
#scripts/haraka.sh and the InboundMail plugin expect it:
#  node_modules/haraka      Haraka itself, downloaded from GitHub (github.com/haraka/Haraka: a release, the latest by default), its dependencies by npm
#  node_modules/mongodb     the MongoDB driver of NetFlow9's plugin (3.x: plugins/nfn.js uses its API)
#  plugins/nfn.js, config/  NetFlow9's plugin and configuration (scripts/haraka/: @BASE@, @DOMAIN@, @MONGO_DB@ filled from app.env), then Haraka's default configuration
#  queue/, dkim/            empty folders (dkim/: + dkim_key_gen.sh, from scripts/haraka/dkim/)
#Nothing that is there is overwritten: a file / package already installed is kept (and listed as such); package.json / package-lock.json are not changed when they exist.
#CLD: 2026-10-28T21:30:00+02:00: run as root (was: as the app's user); it runs itself again as the app's user (the owner of <app>/app), with the system's Node.js: Haraka's files are that user's
#Run as root:
#   sudo scripts/2_haraka-install.sh [--version=<vX.Y.Z>] [--domain=<inbound domain>] [--dir=<folder>] [--dry-run]
#After: the MongoDB user's password (sudo scripts/3_mongo-auth-setup.sh, or set it in config/nfn.ini), DKIM keys (haraka -h dkim), then start it
#(sudo scripts/4_services-install.sh install, or scripts/haraka.sh start)

set -euo pipefail

SELF_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SELF="$SELF_DIR/$(basename "${BASH_SOURCE[0]}")"
BASE=$(dirname "$SELF_DIR")
#CLD: 2026-10-28T21:30:00+02:00: the arguments, for running again as the app's user
ARGS=("$@")
TEMPLATES="$SELF_DIR/haraka"
REPO="haraka/Haraka"
MONGODB_DRIVER="mongodb@^3.5.5"

VERSION=""
DOMAIN=""
DIR=""
DRY_RUN=0

usage() {
    echo "usage: $0 [--version=<vX.Y.Z>] [--domain=<inbound domain>] [--dir=<folder>] [--dry-run]"
    echo "  --version   the Haraka release (a tag of github.com/$REPO; default: the latest release)"
    echo "  --domain    the inbound mail domain (default: MAIL_INBOUND_DOMAIN of app.env)"
    echo "  --dir       where to install (default: HARAKA_DIR of app.env, else $BASE/haraka)"
    echo "  --dry-run   print what would be done"
    exit "${1:-0}"
}

#parse command line parameters
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

#runs a command, or only prints it (--dry-run)
run() {
    echo "+ $*"
    if [ "$DRY_RUN" -eq 0 ]; then
        "$@"
    fi
}

#CLD: 2026-10-28T21:30:00+02:00: as root (was: refused); root runs this script again as the app's user, with the system's Node.js (not root's own nvm)
if [ -z "${NFN_HARAKA_INSTALL_AS_APP_USER:-}" ]; then
    if [ "$(id -u)" -eq 0 ]; then
        APP_USER=$(stat -c %U "$BASE/app")
        if [ "$APP_USER" = "root" ]; then
            echo "$BASE/app is owned by root: Haraka's files must be the app's user's"
            exit 1
        fi
        echo "+ as $APP_USER"
        cd "$BASE"
        exec runuser -u "$APP_USER" -- env -u NVM_DIR -u NVM_BIN -u NVM_INC -u NPM_CONFIG_PREFIX -u npm_config_prefix \
            NFN_HARAKA_INSTALL_AS_APP_USER=1 PATH="/usr/local/bin:/usr/bin:/bin" "$SELF" "${ARGS[@]}"
    elif [ "$DRY_RUN" -eq 0 ]; then
        echo "run it as root: sudo $0 ${ARGS[*]}"
        exit 1
    fi
fi
for cmd in node npm curl tar sed; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "missing: $cmd"
        exit 1
    fi
done

#app.env: HARAKA_DIR, MAIL_INBOUND_DOMAIN, MONGO_DB (as scripts/haraka.sh reads it)
if [ ! -f "$BASE/app.env" ]; then
    echo "missing: $BASE/app.env"
    exit 1
fi
ENV_HARAKA_DIR=$(set +u; . "$BASE/app.env" >/dev/null 2>&1; echo "${HARAKA_DIR:-}")
ENV_DOMAIN=$(set +u; . "$BASE/app.env" >/dev/null 2>&1; echo "${MAIL_INBOUND_DOMAIN:-}")
ENV_MONGO_DB=$(set +u; . "$BASE/app.env" >/dev/null 2>&1; echo "${MONGO_DB:-}")
DIR="${DIR:-${ENV_HARAKA_DIR:-$BASE/haraka}}"
DIR="${DIR%/}"
DOMAIN="${DOMAIN:-$ENV_DOMAIN}"
MONGO_DB="${ENV_MONGO_DB:-netflow9-db}"
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

#copies $1 to $2 when $2 is not there ($3: fill the @...@ of NetFlow9's templates; $4: a kept file is not listed)
put() {
    local src=$1 dest=$2 fill=${3:-0} quiet=${4:-0}
    if [ -e "$dest" ]; then
        [ "$quiet" -eq 1 ] || KEPT+=("${dest#$DIR/}")
        return
    fi
    CREATED+=("${dest#$DIR/}")
    if [ "$fill" -eq 1 ]; then
        echo "+ fill $src > $dest"
        if [ "$DRY_RUN" -eq 0 ]; then
            sed -e "s|@BASE@|$BASE|g" -e "s|@DOMAIN@|$DOMAIN|g" -e "s|@MONGO_DB@|$MONGO_DB|g" "$src" > "$dest"
            chmod --reference="$src" "$dest"
        fi
    else
        run cp -p "$src" "$dest"
    fi
}

#copies the files of folder $1 into folder $2, the missing ones only ($3: fill, $4: quiet)
put_tree() {
    local src=$1 dest=$2 fill=${3:-0} quiet=${4:-0} f rel
    while IFS= read -r -d '' f; do
        rel=${f#$src/}
        if [ -d "$f" ]; then
            [ -d "$dest/$rel" ] || run mkdir -p "$dest/$rel"
        else
            put "$f" "$dest/$rel" "$fill" "$quiet"
        fi
    done < <(find "$src" -mindepth 1 -print0 | sort -z)
}

#the folders
for d in "$DIR" "$DIR/config" "$DIR/plugins" "$DIR/queue" "$DIR/dkim"; do
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
  "description": "NetFlow9's inbound mail (Haraka; scripts/2_haraka-install.sh)",
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

#NetFlow9's plugin and configuration first (they win over Haraka's defaults of the same name), then Haraka's defaults (those already there not listed)
put_tree "$TEMPLATES/plugins" "$DIR/plugins" 1
put_tree "$TEMPLATES/config" "$DIR/config" 1
#CLD: 2026-10-29T01:00:00+02:00: + the DKIM keys generator (dkim/dkim_key_gen.sh), as it is
put_tree "$TEMPLATES/dkim" "$DIR/dkim" 0
if [ -d "$DIR/node_modules/haraka/config" ]; then
    put_tree "$DIR/node_modules/haraka/config" "$DIR/config" 0 1
elif [ "$DRY_RUN" -eq 0 ]; then
    echo "warning: $DIR/node_modules/haraka/config not found: Haraka's default configuration not copied"
fi
#nfn.ini holds the database password: its owner only
if [[ " ${CREATED[*]} " == *" config/nfn.ini "* ]]; then
    run chmod 600 "$DIR/config/nfn.ini"
fi

echo
echo "created (${#CREATED[@]}):"
for f in "${CREATED[@]}"; do echo "  $f"; done
echo "kept, already there (${#KEPT[@]}):"
for f in "${KEPT[@]}"; do echo "  $f"; done
echo
echo "next:"
if grep -q 'CHANGE_ME' "$DIR/config/nfn.ini" 2>/dev/null; then
    echo "  - the MongoDB user nfn_haraka: sudo scripts/3_mongo-auth-setup.sh (writes its password into config/nfn.ini), or set uri there"
fi
#CLD: 2026-10-29T01:00:00+02:00: the keys by dkim/dkim_key_gen.sh (was: Haraka's help only)
echo "  - DKIM keys for $DOMAIN (config/dkim.ini): cd $DIR/dkim && ./dkim_key_gen.sh $DOMAIN (then publish the record of $DOMAIN/dns; help: $DIR/node_modules/haraka/bin/haraka -h dkim)"
echo "  - start it: sudo scripts/4_services-install.sh install (systemd), or scripts/haraka.sh start; Haraka listens on port 25"
