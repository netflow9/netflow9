# CLD: 2026-11-01T19:00:00+02:00: the web browser of the AI steps (the browser profile of compose.yaml): @playwright/mcp and the Chromium its Playwright was built for (as
# scripts/playwright-mcp.sh installs them on a server), run by the user pwmcp; its entrypoint first loads a firewall table (nftables, as the server's unit's ExecStartPre)
# refusing the browser's connections to private, loopback and link-local addresses: the other containers, the host, the cloud metadata. Needs CAP_NET_ADMIN (compose.yaml)
FROM node:24-bookworm-slim

ARG PLAYWRIGHT_MCP_VERSION=0.0.83
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates nftables util-linux && rm -rf /var/lib/apt/lists/* \
 && npm install -g --no-audit --no-fund "@playwright/mcp@${PLAYWRIGHT_MCP_VERSION}" && npm cache clean --force \
 && core=$(node -e 'console.log(require("path").dirname(require.resolve("playwright-core/package.json", { paths: [ process.argv[1] ] })))' "$(npm root -g)/@playwright/mcp") \
 && node "$core/cli.js" install --with-deps chromium \
 && rm -rf /var/lib/apt/lists/* \
 && useradd -m -d /home/pwmcp -s /usr/sbin/nologin pwmcp

COPY docker/playwright/entrypoint.sh /usr/local/bin/playwright-entrypoint
RUN chmod 755 /usr/local/bin/playwright-entrypoint

EXPOSE 8931
ENTRYPOINT [ "playwright-entrypoint" ]
