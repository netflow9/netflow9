#!/bin/bash

#installs Playwright globally (for every user of the machine): the CLI (npm -g @playwright/test), the browsers in one shared folder,
#their system libraries (dnf on AlmaLinux / RHEL: Playwright's --with-deps knows only apt), and PLAYWRIGHT_BROWSERS_PATH for every login (/etc/profile.d/playwright.sh).
# as root:
#   sudo scripts/1_playwright-install.sh [--version=<x.y.z>] [--browsers="chromium firefox"] [--path=<dir>] [--no-deps] [--dry-run]

set -euo pipefail

SELF_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
BASE=$(dirname "$SELF_DIR")

VERSION=""
#webkit is not supported on RHEL-like systems: only on request
BROWSERS="chromium"
BROWSERS_PATH="/opt/playwright/browsers"
DEPS=1
DRY_RUN=0

usage() {
    echo "usage: $0 [--version=<x.y.z>] [--browsers=\"chromium firefox webkit\"] [--path=<dir>] [--no-deps] [--dry-run]"
    echo "  --version   the Playwright version (default: the project's, from node_modules or package.json)"
    echo "  --browsers  the browsers to install (default: chromium; chromium-headless-shell and ffmpeg come with it)"
    echo "  --path      the shared browsers folder (default: /opt/playwright/browsers)"
    echo "  --no-deps   do not install the browsers' system libraries"
    echo "  --dry-run   print what would be done"
    exit "${1:-0}"
}

#parse command line parameters
while [ "$#" -gt 0 ]; do
    case $1 in
        --version=*) VERSION="${1#*=}" ;;
        --browsers=*) BROWSERS="${1#*=}" ;;
        --path=*) BROWSERS_PATH="${1#*=}" ;;
        --no-deps) DEPS=0 ;;
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

if [ "$DRY_RUN" -eq 0 ] && [ "$(id -u)" -ne 0 ]; then
    echo "run it as root (sudo $0 ...): it installs into $(npm config get prefix 2>/dev/null || echo /usr/local), $BROWSERS_PATH and /etc/profile.d"
    exit 1
fi

#CLD: 2026-10-22T21:00:00+03:00: the system's Node.js, not root's own (sudo kept root's nvm Node first in PATH: the package went to /root/.nvm/..., which other users cannot read)
unset NVM_DIR NVM_BIN NVM_INC NPM_CONFIG_PREFIX npm_config_prefix
export PATH="/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin"

command -v node >/dev/null && command -v npm >/dev/null || { echo "Node.js and npm are needed first (system-wide, in /usr/bin or /usr/local/bin)"; exit 1; }

#the global packages must be readable by every user
GLOBAL_ROOT=$(npm root -g)
case "$GLOBAL_ROOT" in
    /root/*|/home/*) echo "npm installs global packages into $GLOBAL_ROOT, a user's own folder: use a system-wide Node.js (npm config get prefix: /usr/local)"; exit 1 ;;
esac
echo "Node.js $(node -v) ($(command -v node)), global packages in $GLOBAL_ROOT"

#the project's version: the installed one, else the one package.json asks for (^1.64.0 -> 1.64.0)
if [ -z "$VERSION" ]; then
    if [ -f "$BASE/node_modules/@playwright/test/package.json" ]; then
        VERSION=$(node -p "require('$BASE/node_modules/@playwright/test/package.json').version")
    elif [ -f "$BASE/package.json" ]; then
        VERSION=$(node -p "const p = require('$BASE/package.json'); ((p.devDependencies || {})['@playwright/test'] || (p.dependencies || {})['@playwright/test'] || '').replace(/^[^0-9]*/, '')")
    fi
fi
[ -n "$VERSION" ] || VERSION="latest"
echo "Playwright $VERSION, browsers: $BROWSERS, in $BROWSERS_PATH"

#1. the browsers' system libraries
if [ "$DEPS" -eq 1 ]; then
    if command -v dnf >/dev/null; then
        #Chromium (and its headless shell)
        PKGS="nss nspr atk at-spi2-atk at-spi2-core cups-libs libdrm libxkbcommon libXcomposite libXdamage libXfixes libXrandr libXext libX11 libxcb
              libxshmfence mesa-libgbm pango cairo alsa-lib dbus-libs expat glib2 libgcc
              liberation-fonts dejavu-sans-fonts google-noto-emoji-color-fonts"
        #Firefox
        case " $BROWSERS " in *" firefox "*) PKGS="$PKGS gtk3 dbus-glib libXt libXcursor libXi libXrender libXtst" ;; esac
        #WebKit: built for Ubuntu; its libraries are partly missing on RHEL-like systems
        case " $BROWSERS " in *" webkit "*) PKGS="$PKGS gtk3 libsoup3 libsecret woff2 harfbuzz-icu libwebp libjpeg-turbo libpng gstreamer1 gstreamer1-plugins-base libxslt enchant2 hyphen libmanette libatomic"
            echo "warning: WebKit is not supported by Playwright on this system: it may not start" ;; esac
        # shellcheck disable=SC2086
        run dnf install -y --setopt=install_weak_deps=False $PKGS
    elif command -v apt-get >/dev/null; then
        #Debian / Ubuntu: Playwright installs them itself (step 3)
        :
    else
        echo "warning: neither dnf nor apt-get: install the browsers' libraries yourself (or use --no-deps)"
    fi
fi

#2. the CLI, for every user (playwright in $(npm prefix -g)/bin)
run npm install -g "@playwright/test@$VERSION"

#3. the browsers, in the shared folder
run mkdir -p "$BROWSERS_PATH"
WITH_DEPS=""
if [ "$DEPS" -eq 1 ] && ! command -v dnf >/dev/null && command -v apt-get >/dev/null; then
    WITH_DEPS="--with-deps"
fi
# shellcheck disable=SC2086
run env PLAYWRIGHT_BROWSERS_PATH="$BROWSERS_PATH" playwright install $WITH_DEPS $BROWSERS
#readable and runnable by every user
run chmod -R a+rX "$BROWSERS_PATH"

#4. every login finds the browsers (and the global module, for scripts run with node)
PROFILE=/etc/profile.d/playwright.sh
echo "+ write $PROFILE"
if [ "$DRY_RUN" -eq 0 ]; then
    cat > "$PROFILE" <<EOF
#installed by $SELF_DIR/$(basename "$0")
export PLAYWRIGHT_BROWSERS_PATH="$BROWSERS_PATH"
export NODE_PATH="$(npm root -g)\${NODE_PATH:+:\$NODE_PATH}"
EOF
    chmod 644 "$PROFILE"
fi

#5. check: the CLI, and Chromium starts (headless)
if [ "$DRY_RUN" -eq 0 ]; then
    playwright --version
    case " $BROWSERS " in *" chromium "*)
        PLAYWRIGHT_BROWSERS_PATH="$BROWSERS_PATH" NODE_PATH="$(npm root -g)" node -e "
            const { chromium } = require('@playwright/test');
            chromium.launch().then(async (b) => { const p = await b.newPage(); await p.setContent('<title>ok</title>'); console.log('chromium', b.version(), await p.title()); await b.close(); })
                .catch((e) => { console.error('chromium does not start:', e.message); process.exit(1); });
        " ;;
    esac
fi

echo "Done. Open a new login shell (or: . $PROFILE) for PLAYWRIGHT_BROWSERS_PATH; the project's tests then use the shared browsers."
