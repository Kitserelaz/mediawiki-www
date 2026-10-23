FROM node:22.22.0-bookworm-slim@sha256:dd9d21971ec4395903fa6143c2b9267d048ae01ca6d3ea96f16cb30df6187d94 AS dependencies
ARG DEBIAN_SNAPSHOT=20261015T000000Z
# The slim base lacks CA certificates. APT verifies Debian signatures while
# bootstrapping the CA bundle over HTTP, then subsequent requests use HTTPS.
RUN printf 'deb [check-valid-until=no] http://snapshot.debian.org/archive/debian/%s/ bookworm main\ndeb [check-valid-until=no] http://snapshot.debian.org/archive/debian/%s/ bookworm-updates main\ndeb [check-valid-until=no] http://snapshot.debian.org/archive/debian-security/%s/ bookworm-security main\n' "$DEBIAN_SNAPSHOT" "$DEBIAN_SNAPSHOT" "$DEBIAN_SNAPSHOT" > /etc/apt/sources.list \
    && rm -f /etc/apt/sources.list.d/debian.sources
RUN apt-get update && apt-get install -y --no-install-recommends python3 make g++ ca-certificates \
    && sed -i 's|http://snapshot.debian.org/|https://snapshot.debian.org/|g' /etc/apt/sources.list \
    && rm -rf /var/lib/apt/lists/* \
    && corepack enable && corepack prepare pnpm@10.11.0 --activate
WORKDIR /app
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN --mount=type=cache,id=pnpm,target=/root/.local/share/pnpm/store pnpm install --prod --frozen-lockfile
FROM node:22.22.0-bookworm-slim@sha256:dd9d21971ec4395903fa6143c2b9267d048ae01ca6d3ea96f16cb30df6187d94
WORKDIR /app
COPY --from=dependencies /app/node_modules ./node_modules
COPY --chown=node:node package.json index.js ./
COPY --chown=node:node db ./db
COPY --chown=node:node utils ./utils
COPY --chown=node:node views ./views
COPY --chown=node:node assets ./assets
RUN mkdir -p /app/data && chown node:node /app/data
ENV NODE_ENV=production APP_PORT=3000 DB_PATH=/app/data/count.db
USER node
EXPOSE 3000
HEALTHCHECK --interval=15s --timeout=3s CMD node -e "fetch('http://127.0.0.1:'+process.env.APP_PORT+'/heart-beat').then(r=>{if(!r.ok)process.exit(1)}).catch(()=>process.exit(1))"
CMD ["node", "index.js"]
ARG SOURCE_REVISION=unknown
LABEL org.opencontainers.image.source="https://github.com/Kitserelaz/mediawiki-www" \
      org.opencontainers.image.revision="${SOURCE_REVISION}"
