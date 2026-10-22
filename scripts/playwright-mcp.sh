#!/bin/bash

#CLD: 2026-10-22T22:00:00+03:00: new: the Playwright MCP server (a web browser for the AI steps: the LocalMCP plugin, server `playwright`) as a system service:
#  - its own user (pwmcp), no login, writing only in /var/lib/playwright-mcp
#  - on 127.0.0.1:8931 only (Streamable HTTP: http://127.0.0.1:8931/mcp), headless, a fresh in-memory browser profile per session
#  - the shared Chromium of scripts/playwright-install.sh (run that first)
#  - a firewall table (nftables) refusing the user's new connections to private, loopback and link-local addresses (the cloud metadata 169.254.169.254 too): the browser reaches
#    only the public internet (Playwright's own --blocked-origins is not a security boundary: kept as a second layer only)
#Run as root:
#   sudo scripts/playwright-mcp.sh install [--version=<x.y.z>] [--port=<n>]   install / update, start at boot, start now
#   sudo scripts/playwright-mcp.sh start | stop | restart | status | uninstall
#Then: ./v LocalMCP::servers::test, and ./v LocalMCP::servers::enable --account=<id> --server=playwright

set -euo pipefail

SELF=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")

#the @playwright/mcp version: tested with the shared Chromium (scripts/playwright-install.sh: Playwright 1.64)
VERSION="0.0.83"
PORT=8931
SERVICE=playwright-mcp
SVC_USER=pwmcp
HOME_DIR=/var/lib/playwright-mcp
CONF_DIR=/etc/playwright-mcp
BROWSERS_PATH=/opt/playwright/browsers
UNIT=/etc/systemd/system/$SERVICE.service
NFT_TABLE=playwright_mcp

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

#the system's Node.js (not root's own nvm: the package must be readable by the service's user)
system_node() {
    unset NVM_DIR NVM_BIN NVM_INC NPM_CONFIG_PREFIX npm_config_prefix
    export PATH="/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin"
    command -v node >/dev/null && command -v npm >/dev/null || { echo "Node.js and npm are needed (system-wide)"; exit 1; }
    case "$(npm root -g)" in
        /root/*|/home/*) echo "npm installs global packages into $(npm root -g), a user's own folder: use a system-wide Node.js"; exit 1 ;;
    esac
}

#the newest shared Chromium
chromium_path() {
    ls -d "$BROWSERS_PATH"/chromium-[0-9]*/chrome-linux64/chrome 2>/dev/null | sort -V | tail -1
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
    } > "$CONF_DIR/egress.nft"
    chmod 644 "$CONF_DIR/egress.nft"
}

write_unit() {
    local chrome="$1"
    cat > "$UNIT" <<EOF
#written by $SELF
[Unit]
Description=Playwright MCP server (web browser for the AI steps)
After=network-online.target
Wants=network-online.target

[Service]
User=$SVC_USER
Group=$SVC_USER
WorkingDirectory=$HOME_DIR
Environment=HOME=$HOME_DIR
Environment=PLAYWRIGHT_BROWSERS_PATH=$BROWSERS_PATH
#the firewall table first (as root: +), removed when the service stops
ExecStartPre=+/usr/sbin/nft -f $CONF_DIR/egress.nft
ExecStart=$(npm prefix -g)/bin/playwright-mcp --headless --isolated --host 127.0.0.1 --port $PORT --executable-path $chrome --output-dir $HOME_DIR/output --output-max-size 209715200 --idle-timeout 300000 --timeout-navigation 30000 --blocked-origins "http://localhost;https://localhost;http://127.0.0.1;https://127.0.0.1;http://169.254.169.254"
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
        chrome=$(chromium_path)
        [ -n "$chrome" ] || { echo "no shared Chromium in $BROWSERS_PATH: run scripts/playwright-install.sh first"; exit 1; }
        echo "+ @playwright/mcp $VERSION, Chromium $chrome, port $PORT"
        command -v nft >/dev/null || dnf install -y nftables
        id "$SVC_USER" >/dev/null 2>&1 || useradd --system --home-dir "$HOME_DIR" --shell /sbin/nologin --comment "Playwright MCP server" "$SVC_USER"
        install -d -o "$SVC_USER" -g "$SVC_USER" -m 750 "$HOME_DIR" "$HOME_DIR/output"
        install -d -m 755 "$CONF_DIR"
        npm install -g "@playwright/mcp@$VERSION"
        write_nft
        /usr/sbin/nft -c -f "$CONF_DIR/egress.nft"
        write_unit "$chrome"
        systemctl daemon-reload
        systemctl enable "$SERVICE"
        systemctl restart "$SERVICE"
        sleep 3
        systemctl --no-pager --lines=5 status "$SERVICE" || true
        echo "Done: http://127.0.0.1:$PORT/mcp. Check it: ./v LocalMCP::servers::test (from $(dirname "$SELF")/../app)"
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
        system_node
        systemctl disable --now "$SERVICE" 2>/dev/null || true
        rm -f "$UNIT"
        systemctl daemon-reload
        /usr/sbin/nft delete table inet "$NFT_TABLE" 2>/dev/null || true
        npm uninstall -g @playwright/mcp || true
        echo "Removed the service and the package; kept: user $SVC_USER, $HOME_DIR, $CONF_DIR (remove them by hand when no longer needed)"
        ;;
    *)
        usage
        ;;
esac
