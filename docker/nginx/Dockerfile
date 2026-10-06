# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Build do Angular (só usado na imagem de produção)
# ---------------------------------------------------------------------------
FROM node:24-alpine AS frontend-build

WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

# ---------------------------------------------------------------------------
# dev: só as configs; o Angular vem do container "node"
# ---------------------------------------------------------------------------
FROM nginx:1.29-alpine AS dev

COPY docker/nginx/snippets/ /etc/nginx/snippets/
COPY docker/nginx/conf.d/dev.conf /etc/nginx/conf.d/default.conf

# ---------------------------------------------------------------------------
# prod: SPA compilada servida como estático
# ---------------------------------------------------------------------------
FROM nginx:1.29-alpine AS prod

COPY docker/nginx/snippets/ /etc/nginx/snippets/
COPY docker/nginx/conf.d/prod.conf /etc/nginx/conf.d/default.conf
COPY --from=frontend-build /app/dist/jetcar/browser /usr/share/nginx/html
