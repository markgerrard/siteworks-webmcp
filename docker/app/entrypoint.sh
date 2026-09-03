#!/bin/bash
set -e

# Ensure Laravel/Livewire writable dirs are owned by www-data at runtime.
# Needed whenever storage/ ends up mounted or otherwise disconnected from the
# image's build-time chown (e.g. a bind-mounted repo, or a volume mounted
# over storage/). Catches the classic Livewire tempnam → /tmp fallback
# warning when the compiled cache dirs are root-owned (e.g. after a sudo
# docker exec artisan run).
mkdir -p \
    /var/www/html/storage/framework/cache \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views/livewire/classes \
    /var/www/html/storage/framework/views/livewire/views \
    /var/www/html/storage/framework/views/livewire/scripts \
    /var/www/html/storage/framework/views/livewire/styles \
    /var/www/html/storage/framework/views/livewire/placeholders \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache || true
chmod -R ug+rwX /var/www/html/storage /var/www/html/bootstrap/cache || true

# Vite manifest recovery guard.
#
# The image build runs `npm run build:${SURFACE}` so a freshly-built image
# always ships with public/build-${BUILD_DIR}/manifest.json baked in. If the
# container's /var/www/html ends up overlaid with a copy that doesn't have
# that manifest (e.g. a bind-mounted worktree after a fresh checkout,
# container recreate, or branch switch that dropped the build-* artefacts),
# the container can be left with no manifest where the image had one —
# causing Illuminate\Foundation\ViteManifestNotFoundException at first
# request.
#
# This guard runs only when the manifest is missing, and is a no-op
# whenever an up-to-date manifest is already present. It is a recovery
# mechanism, NOT a runtime build step: environments that always serve the
# image-baked build output never need it, and a missing manifest there
# indicates a broken image — the guard will attempt rebuild and fail
# loudly if node_modules / dependencies aren't available, which is the
# right failure mode.
SURFACE="${SURFACE:-all}"
case "$SURFACE" in
    all)            BUILD_DIR=build ;;
    agents)         BUILD_DIR=build-agents ;;
    customer)       BUILD_DIR=build-customer ;;
    site-public)    BUILD_DIR=build-site-public ;;
    editor-preview) BUILD_DIR=build-editor-preview ;;
    *)              echo "[entrypoint] Unknown SURFACE=$SURFACE — refusing to start" >&2; exit 1 ;;
esac

# Per-surface nginx server config.
# site-public and all: /robots.txt rewrites to index.php so RobotsController
# wins over public/robots.txt (try_files $uri would otherwise serve the static
# file). SURFACE=all registers site.robots via routes/site-public.php.
# agents / customer / editor-preview: default conf — static robots preserved
# (those surfaces do not register site.robots; a shared force-to-PHP would 404).
# Prefer worktree paths (dev bind-mount picks up conf edits without rebuild);
# fall back to image-baked copies under /etc/nginx/sites-available/.
if [ "$SURFACE" = "site-public" ] || [ "$SURFACE" = "all" ]; then
    if [ -f /var/www/html/docker/app/nginx.site-public.conf ]; then
        NGINX_SRC=/var/www/html/docker/app/nginx.site-public.conf
    else
        NGINX_SRC=/etc/nginx/sites-available/nginx.site-public
    fi
else
    if [ -f /var/www/html/docker/app/nginx.conf ]; then
        NGINX_SRC=/var/www/html/docker/app/nginx.conf
    else
        NGINX_SRC=/etc/nginx/sites-available/nginx.default
    fi
fi
cp "$NGINX_SRC" /etc/nginx/sites-available/default
echo "[entrypoint] nginx config for SURFACE=${SURFACE}: $NGINX_SRC"

# Bind-mounted worktree confs are host-editable. A malformed one must not
# crash-loop nginx under supervisord — fall back to the image-baked copy.
if ! nginx -t; then
    if [ "$SURFACE" = "site-public" ] || [ "$SURFACE" = "all" ]; then
        NGINX_FALLBACK=/etc/nginx/sites-available/nginx.site-public
    else
        NGINX_FALLBACK=/etc/nginx/sites-available/nginx.default
    fi
    echo "[entrypoint] ERROR: nginx -t failed for ${NGINX_SRC}; falling back to ${NGINX_FALLBACK}" >&2
    cp "$NGINX_FALLBACK" /etc/nginx/sites-available/default
    if ! nginx -t; then
        echo "[entrypoint] ERROR: fallback ${NGINX_FALLBACK} also failed nginx -t — refusing to start" >&2
        exit 1
    fi
    echo "[entrypoint] nginx config fell back to ${NGINX_FALLBACK}"
fi

MANIFEST="/var/www/html/public/${BUILD_DIR}/manifest.json"
if [ ! -f "$MANIFEST" ]; then
    echo "[entrypoint] Vite manifest missing at $MANIFEST — rebuilding for SURFACE=${SURFACE}..."
    cd /var/www/html || exit 1
    if [ ! -d node_modules ]; then
        npm ci --prefer-offline
    fi
    npm run "build:${SURFACE}"
    # Chown the rebuilt build-* dir to match the owner of /var/www/html.
    # Without this, a subsequent `npm run build:${SURFACE}` on a bind-mounted
    # host copy fails with EACCES because vite tries to rmSync root-owned
    # build artefacts. Idempotent when the owner is already correct.
    BIND_OWNER=$(stat -c '%u:%g' /var/www/html)
    chown -R "$BIND_OWNER" "/var/www/html/public/${BUILD_DIR}" 2>/dev/null || true
fi

if [ "${DEMO_MODE:-}" = "1" ] || [ "${DEMO_MODE:-}" = "true" ]; then
    echo "[entrypoint] DEMO_MODE on — ensuring APP_KEY, SQLite schema, seed, storage link"
    mkdir -p /var/www/html/storage/demo /var/www/html/storage/app/public /var/www/html/storage/app/private
    DEMO_KEY_FILE=/var/www/html/storage/demo/.app_key
    if [ -z "${APP_KEY:-}" ]; then
        if [ -s "$DEMO_KEY_FILE" ]; then
            APP_KEY="$(tr -d '\r\n' < "$DEMO_KEY_FILE")"
            export APP_KEY
            echo "[entrypoint] loaded APP_KEY from $DEMO_KEY_FILE"
        else
            # key:generate --force writes .env, which is not possible on a
            # read-only image. Persist the key next to the sqlite file.
            APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
            printf '%s\n' "$APP_KEY" > "$DEMO_KEY_FILE"
            export APP_KEY
            echo "[entrypoint] generated APP_KEY into $DEMO_KEY_FILE"
        fi
    fi
    if [ ! -f /var/www/html/storage/demo/demo.sqlite ]; then
        touch /var/www/html/storage/demo/demo.sqlite
    fi
    chown -R www-data:www-data /var/www/html/storage/demo /var/www/html/storage/app/public /var/www/html/storage/app/private || true
    php artisan migrate --force --path=database/migrations-demo
    php artisan demo:seed
    php artisan storage:link --force
    chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache || true
    if [ -e /var/www/html/public/storage ]; then
        chown -h www-data:www-data /var/www/html/public/storage || true
    fi
fi

exec "$@"
