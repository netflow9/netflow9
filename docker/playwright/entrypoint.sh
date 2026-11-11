#!/bin/bash
# as root: the firewall table (the user pwmcp's new connections to private / loopback / link-local addresses refused; Docker's DNS, 127.0.0.11,
# allowed), then the MCP server as pwmcp without capabilities (it cannot change the table). No table, no browser: it does not start without it
set -euo pipefail

PORT=${PLAYWRIGHT_MCP_PORT:-8931}
HOSTNAME_ALLOWED=${PLAYWRIGHT_MCP_HOST:-playwright}

nft -f - <<NFT
table inet playwright_mcp
delete table inet playwright_mcp
table inet playwright_mcp {
    chain output {
        type filter hook output priority 0; policy accept;
        meta skuid "pwmcp" ip daddr 127.0.0.11 accept
        meta skuid "pwmcp" ct state new ip daddr { 0.0.0.0/8, 10.0.0.0/8, 100.64.0.0/10, 127.0.0.0/8, 169.254.0.0/16, 172.16.0.0/12, 192.0.0.0/24, 192.168.0.0/16, 198.18.0.0/15, 224.0.0.0/4, 240.0.0.0/4 } reject
        meta skuid "pwmcp" ct state new ip6 daddr { ::/128, ::1/128, ::ffff:0:0/96, 64:ff9b::/96, fc00::/7, fe80::/10, ff00::/8 } reject
    }
}
NFT
echo "playwright: the firewall table is loaded (private addresses refused)"

chrome=$(find "$PLAYWRIGHT_BROWSERS_PATH" -type f -name chrome -path '*chromium-*' | head -n 1)
[ -n "$chrome" ] || { echo "playwright: no Chromium in $PLAYWRIGHT_BROWSERS_PATH"; exit 1; }
mkdir -p /home/pwmcp/output && chown pwmcp:pwmcp /home/pwmcp/output

exec setpriv --reuid=pwmcp --regid=pwmcp --init-groups --inh-caps=-all --bounding-set=-all --no-new-privs \
    env HOME=/home/pwmcp playwright-mcp --headless --isolated --host 0.0.0.0 --port "$PORT" --allowed-hosts "$HOSTNAME_ALLOWED:$PORT" \
    --executable-path "$chrome" --output-dir /home/pwmcp/output --output-max-size 209715200 --idle-timeout 300000 --timeout-navigation 30000 \
    --blocked-origins "http://localhost;https://localhost;http://127.0.0.1;https://127.0.0.1;http://169.254.169.254"
