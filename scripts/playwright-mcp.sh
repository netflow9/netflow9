#!/bin/bash

#the Playwright MCP server (the web browser of the AI steps) as a system service, one per installation (several on one server: e.g. /home/app live,
#/home/dev development), each with its own:
#  - unit nfn-playwright-<the app's user>, user pwmcp-<user> (no login), folder /var/lib/playwright-mcp-<user> (the only one it writes)
#  - port: --port, else PLAYWRIGHT_MCP_PORT of app.env, else 8931; on 127.0.0.1 only (Streamable HTTP: http://localhost:<port>/mcp), headless,
#    a fresh in-memory browser profile per session; --allowed-hosts takes localhost:<port> and 127.0.0.1:<port> (otherwise 403 for 127.0.0.1)
#  - firewall table playwright_mcp_<user> (nftables, /etc/playwright-mcp/egress-<user>.nft): the user's new connections to private, loopback and
#    link-local addresses (169.254.169.254 too) are refused, the browser reaches only the public internet (Playwright's --blocked-origins is not
#    a security boundary: a second layer only)
#Shared by the installations: the @playwright/mcp package and the Chromium its Playwright was built for (in /opt/playwright/browsers), with its system libraries.
#install also: the polkit rule again when the app's services are installed (scripts/daemon.sh rule) (the Services page controls the unit), the server in mcp.json
#(scripts/playwright/mcp-server.json, its url with the port; an entry changed by hand is written again), a test; uninstall takes it out of mcp.json first.
#Run as root:
#   sudo scripts/playwright-mcp.sh install [--version=<x.y.z>] [--port=<n>]   install / update, start at boot, start now
#   sudo scripts/playwright-mcp.sh start | stop | restart | status | uninstall
#Then the accounts that may use it: Settings > Tool servers (or ./v mcpserver::enable --account=<id> --server=playwright)

set -euo pipefail

SELF=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")
BASE=$(cd "$(dirname "$SELF")/.." && pwd)
APP_USER=$(stat -c %U "$BASE/app")

#the @playwright/mcp version (Playwright 1.64; its Chromium is installed with it)
VERSION="0.0.83"
#the port of app.env (PLAYWRIGHT_MCP_PORT), else 8931; --port before both
PORT=$(sed -n "s/^PLAYWRIGHT_MCP_PORT=['\"]\{0,1\}\([0-9]*\).*/\1/p" "$BASE/app.env" 2>/dev/null | tail -1 || true)
PORT=${PORT:-8931}
#the installation's unit, user, folder and table
SERVICE=nfn-playwright-$APP_USER
#its former names: removed by install / uninstall when this installation wrote them (playwright-mcp: any)
OLD_SERVICES="playwright-mcp nfn-playwright"
SVC_USER=pwmcp-$APP_USER
HOME_DIR=/var/lib/playwright-mcp-$APP_USER
CONF_DIR=/etc/playwright-mcp
NFT_FILE=$CONF_DIR/egress-$APP_USER.nft
BROWSERS_PATH=/opt/playwright/browsers
UNIT=/etc/systemd/system/$SERVICE.service
NFT_TABLE=playwright_mcp_${APP_USER//-/_}

command="${1:-}"
[ -n "$command" ] && shift || true
while [ "$#" -gt 0 ]; do
    case $1 in
        --version=*) VERSION="${1#*=}" ;;
        --port=*) PORT="${1#*=}" ;;
        *) echo "unknown option: $1"; exit 1 ;;
    esac
    shift
done

usage() {
    echo "usage: sudo $0 {install [--version=<x.y.z>] [--port=<n>] | start | stop | restart | status | uninstall}"
    exit 1
}

need_root() {
    [ "$(id -u)" -eq 0 ] || { echo "run it as root: sudo $0 $command"; exit 1; }
}

#the app's console as the app's user (mcp.json is its file); the server's entry in mcp.json
MCP_ENTRY="$BASE/scripts/playwright/mcp-server.json"
as_app() {
    (cd "$BASE/app" && runuser -u "$APP_USER" -- env -i PATH="/usr/local/bin:/usr/bin:/bin" HOME="$BASE" "$BASE/app/v" "$@")
}

#the system's Node.js (not root's own nvm: the package must be readable by the service's user)
system_node() {
    unset NVM_DIR NVM_BIN NVM_INC NPM_CONFIG_PREFIX npm_config_prefix
    export PATH="/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin"
    command -v node >/dev/null && command -v npm >/dev/null || { echo "Node.js and npm are needed (system-wide)"; exit 1; }
    case "$(npm root -g)" in
        /root/*|/home/*) echo "npm installs global packages into $(npm root -g), a user's own folder: use a system-wide Node.js"; exit 1 ;;
    esac
}

#the package's Playwright (playwright-core of @playwright/mcp): its folder
playwright_core() {
    node -e 'console.log(require("path").dirname(require.resolve("playwright-core/package.json", { paths: [ process.argv[1] ] })))' "$(npm root -g)/@playwright/mcp"
}

#the Chromium the package's Playwright was built for (its revision in browsers.json)
chromium_path() {
    local rev
    rev=$(node -e 'const b = require(process.argv[1] + "/browsers.json").browsers.find((x) => x.name === "chromium"); console.log(b ? b.revision : "")' "$(playwright_core)") || return 0
    [ -n "$rev" ] && [ -x "$BROWSERS_PATH/chromium-$rev/chrome-linux64/chrome" ] && echo "$BROWSERS_PATH/chromium-$rev/chrome-linux64/chrome"
    return 0
}

#Chromium's system libraries (Playwright's own install-deps knows only apt)
install_browser_libs() {
    if command -v dnf >/dev/null; then
        dnf install -y --setopt=install_weak_deps=False nss nspr atk at-spi2-atk at-spi2-core cups-libs libdrm libxkbcommon libXcomposite libXdamage libXfixes \
            libXrandr libXext libX11 libxcb libxshmfence mesa-libgbm pango cairo alsa-lib dbus-libs expat glib2 libgcc \
            liberation-fonts dejavu-sans-fonts google-noto-emoji-color-fonts
    elif command -v apt-get >/dev/null; then
        node "$(playwright_core)/cli.js" install-deps chromium
    else
        echo "! neither dnf nor apt-get: install Chromium's libraries yourself"
    fi
}

#the firewall table: the service user's NEW connections to these addresses are refused (answers to the clients of the server are not new: they pass).
#The name servers of /etc/resolv.conf stay reachable on port 53, even when they are private
write_nft() {
    local dns4="" dns6=""
    for ns in $(awk '/^nameserver/ { print $2 }' /etc/resolv.conf); do
        case "$ns" in
            *:*) dns6="$dns6${dns6:+, }$ns" ;;
            *) dns4="$dns4${dns4:+, }$ns" ;;
        esac
    done
    {
        echo "#written by $SELF"
        echo "table inet $NFT_TABLE"
        echo "delete table inet $NFT_TABLE"
        echo "table inet $NFT_TABLE {"
        echo "    chain output {"
        echo "        type filter hook output priority 0; policy accept;"
        [ -n "$dns4" ] && echo "        meta skuid \"$SVC_USER\" ip daddr { $dns4 } meta l4proto { tcp, udp } th dport 53 accept"
        [ -n "$dns6" ] && echo "        meta skuid \"$SVC_USER\" ip6 daddr { $dns6 } meta l4proto { tcp, udp } th dport 53 accept"
        echo "        meta skuid \"$SVC_USER\" ct state new ip daddr { 0.0.0.0/8, 10.0.0.0/8, 100.64.0.0/10, 127.0.0.0/8, 169.254.0.0/16, 172.16.0.0/12, 192.0.0.0/24, 192.168.0.0/16, 198.18.0.0/15, 224.0.0.0/4, 240.0.0.0/4 } reject"
        echo "        meta skuid \"$SVC_USER\" ct state new ip6 daddr { ::/128, ::1/128, ::ffff:0:0/96, 64:ff9b::/96, fc00::/7, fe80::/10, ff00::/8 } reject"
        echo "    }"
        echo "}"
    } > "$NFT_FILE"
    chmod 644 "$NFT_FILE"
}

#the units of its former names stopped and removed (they hold the same port): playwright-mcp always, nfn-playwright only when this installation wrote it
remove_old_unit() {
    local name old
    for name in $OLD_SERVICES; do
        old=/etc/systemd/system/$name.service
        [ -f "$old" ] || continue
        [ "$name" = "playwright-mcp" ] || [ "$(head -n1 "$old")" = "#written by $SELF" ] || continue
        echo "- the unit's former name: $name.service removed"
        systemctl disable --now "$name" 2>/dev/null || true
        rm -f "$old"
        systemctl daemon-reload
    done
}

#the other installations' browser units (they share the package)
other_units() {
    ls /etc/systemd/system/nfn-playwright-*.service 2>/dev/null | grep -v -x "$UNIT" || true
}

write_unit() {
    local chrome="$1"
    cat > "$UNIT" <<EOF
#written by $SELF
[Unit]
Description=Playwright MCP server (web browser for the AI steps of $BASE)
After=network-online.target
Wants=network-online.target

[Service]
User=$SVC_USER
Group=$SVC_USER
WorkingDirectory=$HOME_DIR
Environment=HOME=$HOME_DIR
Environment=PLAYWRIGHT_BROWSERS_PATH=$BROWSERS_PATH
#the firewall table first (as root: +), removed when the service stops
ExecStartPre=+/usr/sbin/nft -f $NFT_FILE
ExecStart=$(npm prefix -g)/bin/playwright-mcp --headless --isolated --host 127.0.0.1 --port $PORT --allowed-hosts localhost:$PORT,127.0.0.1:$PORT --executable-path $chrome --output-dir $HOME_DIR/output --output-max-size 209715200 --idle-timeout 300000 --timeout-navigation 30000 --blocked-origins "http://localhost;https://localhost;http://127.0.0.1;https://127.0.0.1;http://169.254.169.254"
ExecStopPost=+-/usr/sbin/nft delete table inet $NFT_TABLE
Restart=on-failure
RestartSec=5
#limits and isolation (no MemoryDenyWriteExecute: the browser's JavaScript engine needs it)
MemoryMax=3G
TasksMax=1024
NoNewPrivileges=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=$HOME_DIR
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes
LockPersonality=yes

[Install]
WantedBy=multi-user.target
EOF
    chmod 644 "$UNIT"
}

case "$command" in
    install)
        need_root
        system_node
        #the package first, then the Chromium of its Playwright and the browser's libraries
        npm install -g "@playwright/mcp@$VERSION"
        install_browser_libs
        chrome=$(chromium_path)
        if [ -z "$chrome" ]; then
            install -d -m 755 "$BROWSERS_PATH"
            PLAYWRIGHT_BROWSERS_PATH="$BROWSERS_PATH" node "$(playwright_core)/cli.js" install chromium
            chmod -R a+rX "$BROWSERS_PATH"
            chrome=$(chromium_path)
        fi
        [ -n "$chrome" ] || { echo "no Chromium for @playwright/mcp $VERSION in $BROWSERS_PATH"; exit 1; }
        echo "+ @playwright/mcp $VERSION, Chromium $chrome, port $PORT"
        command -v nft >/dev/null || dnf install -y nftables
        id "$SVC_USER" >/dev/null 2>&1 || useradd --system --home-dir "$HOME_DIR" --shell /sbin/nologin --comment "Playwright MCP server of $BASE" "$SVC_USER"
        install -d -o "$SVC_USER" -g "$SVC_USER" -m 750 "$HOME_DIR" "$HOME_DIR/output"
        install -d -m 755 "$CONF_DIR"
        write_nft
        /usr/sbin/nft -c -f "$NFT_FILE"
        remove_old_unit
        write_unit "$chrome"
        systemctl daemon-reload
        systemctl enable "$SERVICE"
        systemctl restart "$SERVICE"
        sleep 3
        systemctl --no-pager --lines=5 status "$SERVICE" || true
        #the Services page controls it (the rule of this installation's units, when they are installed), the server in mcp.json, a test
        if [ -f "/etc/polkit-1/rules.d/49-rail22-services-$APP_USER.rules" ]; then
            "$BASE/scripts/daemon.sh" rule
        fi
        if [ -f "$BASE/app.env" ]; then
            as_app mcpserver::add --id=playwright --file="$MCP_ENTRY" --url="http://localhost:$PORT/mcp"
            as_app mcpserver::test --server=playwright || echo "! the test failed: sudo $SELF status"
            echo "Done: http://127.0.0.1:$PORT/mcp, in mcp.json as \`playwright\`. The accounts that may use it: Settings > Tool servers (or ./v mcpserver::enable --account=<id> --server=playwright)"
        else
            echo "Done: http://127.0.0.1:$PORT/mcp. The app is not set up yet ($BASE/app.env): run this again after it, to add the server to mcp.json"
        fi
        ;;
    start|stop|restart)
        need_root
        systemctl "$command" "$SERVICE"
        ;;
    status)
        systemctl --no-pager --lines=10 status "$SERVICE" || true
        echo "--- firewall table:"
        /usr/sbin/nft list table inet "$NFT_TABLE" 2>/dev/null || echo "not loaded (root needed to list it, or the service is stopped)"
        ;;
    uninstall)
        need_root
        #the server out of mcp.json first (the AI steps no longer open sessions)
        if [ -f "$BASE/app.env" ]; then
            as_app mcpserver::remove --id=playwright --managed-by=scripts/playwright-mcp.sh || true
        fi
        system_node
        systemctl disable --now "$SERVICE" 2>/dev/null || true
        rm -f "$UNIT"
        systemctl daemon-reload
        remove_old_unit
        /usr/sbin/nft delete table inet "$NFT_TABLE" 2>/dev/null || true
        #the package only when no other installation's browser uses it
        if [ -z "$(other_units)" ]; then
            npm uninstall -g @playwright/mcp || true
            echo "Removed the service and the package; kept: user $SVC_USER, $HOME_DIR, $NFT_FILE (remove them by hand when no longer needed)"
        else
            echo "Removed the service (the package stays: $(other_units | xargs -n1 basename | tr '\n' ' ')use it); kept: user $SVC_USER, $HOME_DIR, $NFT_FILE"
        fi
        ;;
    *)
        usage
        ;;
esac
