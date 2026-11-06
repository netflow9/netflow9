#!/bin/bash

#the app's nginx site, written from the template below (@BASE@: the app's folder, @SERVER_NAME@: the host of BASE_URL in app.env), over
#<app>/etc/nginx.conf (a link to /etc/nginx/conf.d/<user>.conf; made when missing), then checked (nginx -t) and nginx reloaded.
#The file there is kept as <file>.bak-<time> (not loaded by nginx: conf.d/*.conf only) and put back when the check fails; the changes are shown first.
#CLD: 2026-11-01T20:00:00+02:00: commands, as scripts/playwright-mcp.sh: install (nginx too), setup (the site only), start / stop / restart /
#reload / status / test / uninstall (the site out); the template inline. nginx is the server's: start / stop / restart apply to every site on it (other installations' too)
#Run as root:
#   sudo scripts/nginx.sh install [--server-name=<host>]              nginx (when missing), the site, nginx started at boot and now
#   sudo scripts/nginx.sh setup [--server-name=<host>] [--dry-run]    the site written again (after a change of the template or of BASE_URL)
#   sudo scripts/nginx.sh start | stop | restart | reload | status | test | uninstall
#     --server-name   the site's host name (default: the host of BASE_URL in app.env)
#     --dry-run       show the changes only

set -euo pipefail

SELF_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SELF="$SELF_DIR/$(basename "${BASH_SOURCE[0]}")"
BASE=$(dirname "$SELF_DIR")
LINK="$BASE/etc/nginx.conf"
#named after the app's user
APP_USER=$(stat -c %U "$BASE/app")
DEFAULT_TARGET="/etc/nginx/conf.d/$APP_USER.conf"

SERVER_NAME=""
DRY_RUN=0

usage() {
    echo "usage: sudo $0 {install [--server-name=<host>] | setup [--server-name=<host>] [--dry-run] | start | stop | restart | reload | status | test | uninstall}"
    exit "${1:-1}"
}

command="${1:-}"
[ -n "$command" ] && shift || true
while [ "$#" -gt 0 ]; do
    case $1 in
        --server-name=*) SERVER_NAME="${1#*=}" ;;
        --dry-run) DRY_RUN=1 ;;
        -h|--help) usage 0 ;;
        *) echo "unknown option: $1"; usage 1 ;;
    esac
    shift
done

need_root() {
    [ "$(id -u)" -eq 0 ] || { echo "run it as root: sudo $0 $command"; exit 1; }
}

need_nginx() {
    command -v nginx >/dev/null 2>&1 || { echo "nginx is not installed: sudo $0 install"; exit 1; }
}

#the file: where <app>/etc/nginx.conf points, else conf.d/<the app's user>.conf
target() {
    if [ -L "$LINK" ] || [ -e "$LINK" ]; then
        readlink -f "$LINK"
    else
        echo "$DEFAULT_TARGET"
    fi
}

#the host name: BASE_URL of app.env, unless given
server_name() {
    local url="" host
    if [ -z "$SERVER_NAME" ]; then
        [ -f "$BASE/app.env" ] || { echo "missing: $BASE/app.env (or give --server-name=<host>)"; exit 1; }
        url=$(set +u; . "$BASE/app.env" >/dev/null 2>&1; echo "${BASE_URL:-}")
        host="${url#*://}"
        host="${host%%/*}"
        SERVER_NAME="${host%:*}"
    fi
    if ! [[ $SERVER_NAME =~ ^[A-Za-z0-9.-]+$ ]]; then
        echo "no valid host name (BASE_URL in app.env: '$url'): give --server-name=<host>"
        exit 1
    fi
}

#the site's template: @BASE@ the app's folder, @SERVER_NAME@ the host name (a quoted heredoc: nginx's $variables stay as they are)
template() {
    cat <<'NGINX'
#the app's nginx site;
#written by scripts/nginx.sh, which fills in the app's folder and the host of BASE_URL
# ---------------------------------------------------------------------------------

#main server on http://, redirect to https://
server {
    server_name @SERVER_NAME@;
    listen 80;
    listen [::]:80;

    return 301 https://$host$request_uri;
}

#main server on https://
server {
    server_name @SERVER_NAME@;
    listen 443 ssl http2;
    listen [::]:443 ssl http2;

    ssl_certificate @BASE@/ssl/ssl.crt;
    ssl_certificate_key @BASE@/ssl/ssl.key;
    ssl_ciphers "EECDH+ECDSA+AESGCM EECDH+aRSA+AESGCM EECDH+ECDSA+SHA384 EECDH+ECDSA+SHA256 EECDH+aRSA+SHA384 EECDH+aRSA+SHA256 EECDH+aRSA+RC4 EECDH EDH+aRSA HIGH !RC4 !aNULL !eNULL !LOW !3DES !MD5 !EXP !PSK !SRP !DSS";
    ssl_prefer_server_ciphers on;
    ssl_protocols TLSv1.2 TLSv1.3;
    #resumed TLS sessions (no full handshake on reconnect)
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;

    #compression of the static files only
    #Not for the PHP responses: an HTML page holding a secret (the CSRF token) and echoing request input must not be compressed over TLS (BREACH); they are small anyway
    gzip_vary on;
    gzip_comp_level 5;
    gzip_min_length 1024;
    gzip_types text/css text/javascript application/javascript application/json image/svg+xml text/plain text/xml application/xml font/ttf application/vnd.ms-fontobject;

    client_max_body_size 100M;
    client_body_buffer_size   2048k;
    client_header_buffer_size  1024k;
    large_client_header_buffers 32 64k;

    fastcgi_buffer_size                 1024k;
    fastcgi_buffers                     64 64k;
    fastcgi_busy_buffers_size           2048k;
    fastcgi_temp_file_write_size        2048k;
    fastcgi_read_timeout                600;

    proxy_read_timeout                  600;
    proxy_connect_timeout               600;
    proxy_send_timeout                  600;

    root @BASE@/public_html;
    index index.php;
    access_log @BASE@/logs/app_access.log;
    error_log @BASE@/logs/app_error.log;

    fastcgi_param GATEWAY_INTERFACE CGI/1.1;
    fastcgi_param SERVER_SOFTWARE nginx;
    fastcgi_param QUERY_STRING $query_string;
    fastcgi_param REQUEST_METHOD $request_method;
    fastcgi_param CONTENT_TYPE $content_type;
    fastcgi_param CONTENT_LENGTH $content_length;
    fastcgi_param SCRIPT_FILENAME @BASE@/public_html$fastcgi_script_name;
    fastcgi_param SCRIPT_NAME $fastcgi_script_name;
    fastcgi_param REQUEST_URI $request_uri;
    fastcgi_param DOCUMENT_URI $document_uri;
    fastcgi_param DOCUMENT_ROOT @BASE@/public_html;
    fastcgi_param SERVER_PROTOCOL $server_protocol;
    fastcgi_param REMOTE_ADDR $remote_addr;
    fastcgi_param REMOTE_PORT $remote_port;
    fastcgi_param SERVER_ADDR $server_addr;
    fastcgi_param SERVER_PORT $server_port;
    fastcgi_param SERVER_NAME $server_name;
    fastcgi_param HTTPS $https;

    #static content
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|woff2|woff|eot|svg|ttf|txt|xml|html|map)$ {
        gzip on;
        expires max;
        add_header Pragma public;
        add_header Cache-Control must-revalidate;
        etag on;
    }

    location ~* /(.*\.pdf) {
        types { application/octet-stream .pdf; }
        default_type  application/octet-stream;
    }

    #compressed and cached as the other static files
    location ~* /(.*\.mjs) {
        types { text/javascript .mjs; }
        default_type  text/javascript;
        gzip on;
        expires max;
        add_header Pragma public;
        add_header Cache-Control must-revalidate;
        etag on;
    }

    #Let's encrypt settings
    location ^~ /.well-known/acme-challenge/ {
	default_type "text/plain";
    }

    location ~ \.php(/|$) {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+?\.php)(/.*)$;
        fastcgi_pass unix:@BASE@/run/php-fpm.sock;
        fastcgi_index index.php;
    }

    location / {
        try_files $uri @rewrite;
    }
    location @rewrite {
        rewrite ^/(.+)$ /index.php last;
    }

    #some security related headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload";
    add_header X-Content-Type-Options nosniff;
    add_header X-XSS-Protection "1; mode=block";
    add_header Referrer-Policy "origin";
}
NGINX
}

#the template filled in
site() {
    template | sed -e "s|@BASE@|$BASE|g" -e "s|@SERVER_NAME@|$SERVER_NAME|g"
}

#nginx itself, when missing (install.sh installs it with the other packages)
install_nginx() {
    command -v nginx >/dev/null 2>&1 && return 0
    if command -v dnf >/dev/null; then
        dnf install -y nginx
    elif command -v apt-get >/dev/null; then
        apt-get install -y nginx
    else
        echo "neither dnf nor apt-get: install nginx yourself"
        exit 1
    fi
    #nginx reads the web root (the group); the home is not world-readable
    usermod -a -G "$APP_USER" nginx 2>/dev/null || true
}

#writes the site, checks it, reloads nginx (when running); 0 also when nothing changed
write_site() {
    local target new backup=""
    server_name
    target=$(target)
    echo "nginx site for $SERVER_NAME (app $BASE) -> $target"
    new=$(site)

    for f in "$BASE/ssl/ssl.crt" "$BASE/ssl/ssl.key"; do
        [ -e "$f" ] || echo "warning: $f is missing: nginx -t will fail (put the certificate there)"
    done

    if [ -f "$target" ] && [ "$new" = "$(cat "$target")" ]; then
        echo "no change"
    else
        if [ -f "$target" ]; then
            diff -u --label "$target" --label "$target (new)" "$target" <(printf '%s\n' "$new") || true
        else
            echo "(new file)"
        fi
        if [ "$DRY_RUN" -eq 1 ]; then
            echo "dry run: nothing written"
            return 0
        fi

        if [ -f "$target" ]; then
            backup="$target.bak-$(date +%Y%m%dT%H%M%S)"
            cp -p "$target" "$backup"
            echo "+ kept the old one: $backup"
        fi
        #written in place: the file keeps its owner and mode
        printf '%s\n' "$new" > "$target"

        if ! nginx -t; then
            if [ -n "$backup" ]; then
                cp -p "$backup" "$target"
                echo "nginx -t failed: the old file is back ($target); nothing reloaded"
            else
                rm -f "$target"
                echo "nginx -t failed: $target removed; nothing reloaded"
            fi
            exit 1
        fi
        if systemctl is-active --quiet nginx; then
            systemctl reload nginx
            echo "+ nginx reloaded"
        fi
    fi

    #the app's link to it, when missing
    if [ ! -e "$LINK" ] && [ ! -L "$LINK" ]; then
        install -d -o "$APP_USER" -g "$APP_USER" "$BASE/etc"
        ln -s "$target" "$LINK"
        chown -h "$APP_USER:$APP_USER" "$LINK"
        echo "+ $LINK -> $target"
    fi
}

case "$command" in
    install)
        need_root
        install_nginx
        write_site
        systemctl enable nginx >/dev/null 2>&1
        systemctl is-active --quiet nginx || systemctl start nginx
        echo "Done: https://$SERVER_NAME/"
        ;;
    setup)
        [ "$DRY_RUN" -eq 1 ] || need_root
        need_nginx
        write_site
        [ "$DRY_RUN" -eq 1 ] || echo "Done: https://$SERVER_NAME/"
        ;;
    start|stop|restart|reload)
        need_root
        need_nginx
        #a reload / restart with a broken configuration would fail (restart: nginx stays down)
        if [ "$command" != "stop" ]; then
            nginx -t
        fi
        systemctl "$command" nginx
        ;;
    status)
        systemctl --no-pager --lines=5 status nginx || true
        target=$(target)
        echo "--- the site: $target"
        if [ ! -f "$target" ]; then
            echo "not written: sudo $0 setup"
        elif SERVER_NAME=$(sed -n 's/^[[:space:]]*server_name[[:space:]]\+\([^;]*\);.*/\1/p' "$target" | head -1) && [ -n "$SERVER_NAME" ] \
                && [ "$(site)" = "$(cat "$target")" ]; then
            echo "$SERVER_NAME, as the template"
        else
            echo "${SERVER_NAME:-?}, differs from the template: sudo $0 setup --dry-run"
        fi
        [ -L "$LINK" ] && echo "link: $LINK -> $(readlink "$LINK")" || echo "link: $LINK missing"
        if [ -r "$BASE/ssl/ssl.crt" ]; then
            echo "certificate: $(openssl x509 -noout -subject -enddate -in "$BASE/ssl/ssl.crt" 2>/dev/null | tr '\n' ' ')"
        else
            echo "certificate: $BASE/ssl/ssl.crt missing (or not readable)"
        fi
        ;;
    test)
        need_root
        need_nginx
        nginx -t
        ;;
    uninstall)
        #the app's site only: nginx stays (other sites, other installations)
        need_root
        need_nginx
        target=$(target)
        if [ -f "$target" ]; then
            backup="$target.bak-$(date +%Y%m%dT%H%M%S)"
            mv "$target" "$backup"
            echo "- $target (kept as $backup)"
            if nginx -t && systemctl is-active --quiet nginx; then
                systemctl reload nginx
                echo "+ nginx reloaded"
            fi
        else
            echo "no site: $target"
        fi
        if [ -L "$LINK" ]; then
            rm -f "$LINK"
            echo "- $LINK"
        fi
        ;;
    *)
        usage
        ;;
esac
