# CLD: 2026-11-01T19:00:00+02:00: NetFlow9's inbound mail (the mail profile of compose.yaml): Haraka (a release from GitHub, as scripts/haraka.sh installs it) with
# NetFlow9's plugin and configuration (scripts/haraka/); nfn.ini and me are filled at each start from the environment (docker/haraka/entrypoint.sh)
FROM node:24-bookworm-slim

ARG HARAKA_VERSION=v3.3.4
# the MongoDB driver of nfn.js (its 3.x API)
ARG MONGODB_DRIVER=mongodb@^3.5.5

RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates openssl && rm -rf /var/lib/apt/lists/*

WORKDIR /srv/haraka
RUN npm init -y >/dev/null \
 && npm install --omit=dev --no-audit --no-fund "https://github.com/haraka/Haraka/archive/refs/tags/${HARAKA_VERSION}.tar.gz" "${MONGODB_DRIVER}" \
 && npm cache clean --force

# NetFlow9's plugin and configuration first, then Haraka's defaults for what they do not set, only those Haraka and the loaded plugins read (as scripts/haraka.sh: its own win)
COPY scripts/haraka/plugins /srv/haraka/plugins
COPY scripts/haraka/config /srv/haraka/config.nfn
COPY scripts/haraka/dkim /srv/haraka/dkim
COPY docker/haraka/entrypoint.sh /usr/local/bin/haraka-entrypoint
RUN for f in aliases connection.ini http.ini log.ini outbound.ini outbound.bounce_message outbound.bounce_message_html outbound.bounce_message_image plugins smtp.ini tls.ini; do \
      cp -n "node_modules/haraka/config/$f" config.nfn/ 2>/dev/null || true; done \
 && mkdir -p config/dkim queue bounces config.nfn/dkim \
 && chmod 755 /usr/local/bin/haraka-entrypoint dkim/dkim_key_gen.sh \
 && chown -R node:node /srv/haraka

USER node
EXPOSE 25
ENTRYPOINT [ "haraka-entrypoint" ]
CMD [ "haraka" ]
