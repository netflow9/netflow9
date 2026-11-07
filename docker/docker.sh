#!/bin/bash

#NetFlow9 with Docker Compose (docker/: the compose files, the images, the settings in docker/.env): the settings, the images, the containers.
#CLD: 2026-11-01T23:30:00+02:00: one script for the compose setup, as the other scripts (a command first); docker compose itself works as well,
#the files to use are in .env (COMPOSE_FILE), so `docker compose ps` etc. (from docker/) see the same setup
#Run as a user of the docker group (or root):
#   docker/docker.sh setup [--dev] [--tls] [--no-browser] [--no-mail]   .env written (random passwords, the folder owner's uid / gid), the submodules checked out;
#                                                                   an existing .env: its values kept, the passwords never changed
#CLD: 2026-11-01T23:50:00+02:00: the web browser (playwright) and inbound mail (haraka) by default; --no-browser / --no-mail leave them out
#CLD: 2026-11-07T15:40:00+02:00: setup asks (on a terminal) what an option did not say: development, TLS, the profiles, the ports, the mail domain,
#the public address, the containers' user; the defaults are an existing .env's values. Without a terminal: the options, .env's values, the defaults
#   docker/docker.sh build [service...]                           the images (all of the setup's, or the ones named)
#   docker/docker.sh install [setup's options]                    setup (asks, as above), build, start
#   docker/docker.sh start | stop | restart [service...]
#   docker/docker.sh status                                       the containers, the settings in use
#   docker/docker.sh logs [service...]                            followed (ctrl-c ends)
#   docker/docker.sh check                                        the check suite in the app container
#   docker/docker.sh uninstall                                    the containers removed, the volumes (database, config) kept
#   docker/docker.sh purge                                        the containers and every volume removed: all data gone (asks first)
#--dev: compose.dev.yaml (the working tree mounted, mailpit on 127.0.0.1:8025, the site on 127.0.0.1:8080); --tls: compose.tls.yaml (HTTPS, ssl/);
#with --dev, Haraka is published on 127.0.0.1:2525 (a server's MTA keeps 25)

set -euo pipefail

SELF_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
BASE=$(dirname "$SELF_DIR")
ENV_FILE="$SELF_DIR/.env"

usage() {
    echo "usage: $0 {setup | install [--[no-]dev] [--[no-]tls] [--browser | --no-browser] [--mail | --no-mail] | build [service...] | install | start | stop | restart [service...] | status | logs [service...] | check | uninstall | purge}"
    exit "${1:-1}"
}

command="${1:-}"
[ -n "$command" ] && shift || true
#CLD: 2026-11-07T15:40:00+02:00: empty: not given, setup asks (or takes .env's value, or the default)
DEV=
TLS=
BROWSER=
MAIL=
SERVICES=()
while [ "$#" -gt 0 ]; do
    case $1 in
        --dev) DEV=1 ;;
        --no-dev) DEV=0 ;;
        --tls) TLS=1 ;;
        --no-tls) TLS=0 ;;
        --browser) BROWSER=1 ;;
        --mail) MAIL=1 ;;
        --no-browser) BROWSER=0 ;;
        --no-mail) MAIL=0 ;;
        -h|--help) usage 0 ;;
        -*) echo "unknown option: $1"; usage 1 ;;
        *) SERVICES+=("$1") ;;
    esac
    shift
done

cd "$SELF_DIR"

need_docker() {
    command -v docker >/dev/null 2>&1 || { echo "docker is not installed"; exit 1; }
    docker compose version >/dev/null 2>&1 || { echo "the compose plugin is not installed (docker compose)"; exit 1; }
    if ! docker info >/dev/null 2>&1; then
        echo "the docker daemon is not reachable: as root, systemctl enable --now docker; usermod -aG docker $(id -un) (then log in again)"
        exit 1
    fi
}

need_env() {
    [ -f "$ENV_FILE" ] || { echo "no .env: run $0 setup [--dev] first"; exit 1; }
}

#a password for the connection strings: letters and digits only
password() {
    openssl rand -hex 24
}

#.env: key $1 set to $2 (the line replaced, or added)
set_env() {
    if grep -q "^$1=" "$ENV_FILE"; then
        sed -i "s|^$1=.*|$1=$2|" "$ENV_FILE"
    elif grep -q "^#$1=" "$ENV_FILE"; then
        sed -i "0,/^#$1=.*/s||$1=$2|" "$ENV_FILE"
    else
        echo "$1=$2" >> "$ENV_FILE"
    fi
}

get_env() {
    sed -n "s/^$1=//p" "$ENV_FILE" | tail -n1
}

#CLD: 2026-11-07T15:40:00+02:00: the questions: only on a terminal; read's prompt goes to stderr, the answer to stdout
INTERACTIVE=0
[ -t 0 ] && INTERACTIVE=1

#yes / no: $1 the question, $2 the default (1 / 0); prints 1 / 0
ask_yn() {
    local answer hint
    [ "$2" -eq 1 ] && hint="Y/n" || hint="y/N"
    while true; do
        read -r -p "$1 [$hint] " answer
        case "${answer,,}" in
            "") echo "$2"; return ;;
            y|yes) echo 1; return ;;
            n|no) echo 0; return ;;
        esac
    done
}

#variable $1 (an option's): kept when the option was given, else asked ($3, default $2) or the default
pick_yn() {
    [ -z "${!1}" ] || return 0
    if [ "$INTERACTIVE" -eq 1 ]; then
        printf -v "$1" '%s' "$(ask_yn "$3" "$2")"
    else
        printf -v "$1" '%s' "$2"
    fi
}

#.env key $1: asked ($3), the default .env's value when the file was there before, else $2; set in .env
pick_env() {
    local value
    value=$([ "$EXISTING" -eq 1 ] && get_env "$1" || true)
    value=${value:-$2}
    if [ "$INTERACTIVE" -eq 1 ]; then
        local answer
        read -r -p "$3 [${value:-none}] " answer
        value=${answer:-$value}
    fi
    set_env "$1" "$value"
}

#a port someone listens on here (this setup's own containers too, when they run)
port_warn() {
    command -v ss >/dev/null 2>&1 || return 0
    if ss -ltnH "sport = :$1" 2>/dev/null | grep -q .; then
        echo "  port $1 is in use here ($2): fine if these are this setup's containers, else choose another (setup again)"
    fi
}

#$1 in the comma / colon separated list $2
in_list() {
    [[ ",${2//:/,}," == *",$1,"* ]]
}

EXISTING=0

setup() {
    local files profiles=() domain owner_uid owner_gid
    local d_dev=0 d_tls=0 d_browser=1 d_mail=1

    if [ -d "$BASE/.git" ] && command -v git >/dev/null 2>&1; then
        git -C "$BASE" submodule update --init
    fi
    [ -f "$BASE/app/pkgs/mongo-php-library/composer.json" ] || { echo "app/pkgs is empty: the submodules are needed (git submodule update --init)"; exit 1; }

    #CLD: 2026-11-07T15:40:00+02:00: an existing .env gives the defaults: its compose files, its profiles, its values
    if [ -f "$ENV_FILE" ]; then
        EXISTING=1
        files=$(get_env COMPOSE_FILE)
        echo ".env found: its values are the defaults (the passwords are kept)"
        in_list compose.dev.yaml "$files" && d_dev=1
        in_list compose.tls.yaml "$files" && d_tls=1
        in_list browser "$(get_env COMPOSE_PROFILES)" || d_browser=0
        in_list mail "$(get_env COMPOSE_PROFILES)" || d_mail=0
    fi

    pick_yn DEV "$d_dev" "Development (the working tree mounted, mailpit, local ports only)?"
    pick_yn TLS "$d_tls" "HTTPS here (the certificate in ssl/)?"
    pick_yn BROWSER "$d_browser" "Web browser for the AI steps (playwright)?"
    pick_yn MAIL "$d_mail" "Inbound mail (Haraka)?"

    files="compose.yaml"
    [ "$DEV" -eq 0 ] || files="$files:compose.dev.yaml"
    [ "$TLS" -eq 0 ] || files="$files:compose.tls.yaml"
    [ "$BROWSER" -eq 0 ] || profiles+=(browser)
    [ "$MAIL" -eq 0 ] || profiles+=(mail)
    profiles=$(IFS=,; echo "${profiles[*]:-}")

    if [ "$EXISTING" -eq 0 ]; then
        command -v openssl >/dev/null 2>&1 || { echo "openssl is needed for the passwords"; exit 1; }
        (umask 077; cp env.example "$ENV_FILE")
        set_env MONGO_ROOT_PW "$(password)"
        set_env NFN_APP_PW "$(password)"
        set_env NFN_HARAKA_PW "$(password)"
        echo ".env written (passwords generated; the database users are made from them at mongo's first start)"
    fi
    set_env COMPOSE_FILE "$files"
    set_env COMPOSE_PROFILES "$profiles"

    #CLD: 2026-11-07T16:00:00+02:00: the project's name (containers, volumes, images): the folder's, so two installations on one host (/home/dev, /home/app) stay apart
    pick_env COMPOSE_PROJECT_NAME "netflow9-$(basename "$BASE" | tr 'A-Z' 'a-z' | tr -c 'a-z0-9_\n-' '-')" "The compose project's name (its containers, volumes, images)"

    #CLD: 2026-11-07T15:40:00+02:00: the containers' user: the folder's owner (root setting up another user's home: that user, not root)
    owner_uid=$(stat -c %u "$BASE")
    owner_gid=$(stat -c %g "$BASE")
    pick_env NFN_UID "$owner_uid" "The containers' user id (the files' owner)"
    pick_env NFN_GID "$owner_gid" "The containers' group id"

    #development: the server's nginx keeps 80 (a production value of 80 changed too)
    if [ "$DEV" -eq 1 ] && [ "$(get_env HTTP_PORT)" = "80" ]; then
        set_env HTTP_PORT 8080
    fi
    if [ "$DEV" -eq 1 ]; then
        pick_env HTTP_PORT 8080 "The site's port (on 127.0.0.1)"
    else
        pick_env HTTP_PORT 80 "The site's port"
    fi
    port_warn "$(get_env HTTP_PORT)" "the site"
    if [ "$TLS" -eq 1 ]; then
        pick_env HTTPS_PORT 443 "The HTTPS port"
        port_warn "$(get_env HTTPS_PORT)" "HTTPS"
        local ssl
        ssl=$(get_env SSL_DIR)
        ssl=${ssl:-../ssl}
        [ -f "$ssl/ssl.crt" ] && [ -f "$ssl/ssl.key" ] || echo "  tls: put the certificate in $ssl/ssl.crt and $ssl/ssl.key"
    fi
    pick_env BASE_URL "" "The public address (e.g. https://flows.example.com; empty: asked by /setup)"

    if in_list browser "$profiles"; then
        set_env NFN_BROWSER_URL "http://playwright:8931/mcp"
    else
        set_env NFN_BROWSER_URL ""
    fi
    #a profile left out: its settings emptied too (HARAKA_HOST set without Haraka would offer the Email (inbound) step)
    if in_list mail "$profiles"; then
        set_env HARAKA_HOST haraka
        #Haraka does not start without the domain: this host's name by default (its MX record points to this host)
        domain=$(hostname -f 2>/dev/null || hostname)
        pick_env MAIL_INBOUND_DOMAIN "$domain" "The inbound mail domain (its MX record points here)"
        if [ "$DEV" -eq 1 ] && [ "$(get_env MAIL_PORT)" = "25" ]; then
            set_env MAIL_PORT 2525
        fi
        if [ "$DEV" -eq 1 ]; then
            pick_env MAIL_PORT 2525 "Haraka's port (on 127.0.0.1)"
        else
            pick_env MAIL_PORT 25 "Haraka's port (25 receives the internet's mail)"
        fi
        port_warn "$(get_env MAIL_PORT)" "mail"
    else
        set_env HARAKA_HOST ""
    fi

    #CLD: 2026-11-07T15:40:00+02:00: root setting up another user's folder: .env stays that user's (mode 600)
    if [ "$(id -u)" -eq 0 ] && [ "$owner_uid" -ne 0 ]; then
        chown "$owner_uid:$owner_gid" "$ENV_FILE"
    fi
    chmod 600 "$ENV_FILE"

    echo "  project: $(get_env COMPOSE_PROJECT_NAME)"
    echo "  files: $files"
    echo "  profiles: ${profiles:-(none)}"
    echo "  site: port $(get_env HTTP_PORT); containers' user $(get_env NFN_UID):$(get_env NFN_GID)"
}

show_status() {
    need_env
    echo "files: $(get_env COMPOSE_FILE)"
    echo "profiles: $(get_env COMPOSE_PROFILES)"
    local bind
    bind=$(get_env HTTP_BIND)
    #compose.dev.yaml publishes on 127.0.0.1 only
    [[ "$(get_env COMPOSE_FILE)" != *compose.dev.yaml* ]] || bind=127.0.0.1
    echo "site: http://${bind:-0.0.0.0}:$(get_env HTTP_PORT)/"
    docker compose ps -a
}

case "$command" in
    setup)
        setup
        ;;
    build)
        need_docker
        need_env
        docker compose build "${SERVICES[@]}"
        ;;
    install)
        need_docker
        #CLD: 2026-11-07T15:40:00+02:00: setup each time (an existing .env: its values offered, kept on enter)
        setup
        docker compose build
        docker compose up -d
        docker compose ps
        echo "the site: port $(get_env HTTP_PORT), /setup on the first visit (docker/docker.sh logs app daemon: the first start)"
        ;;
    start)
        need_docker
        need_env
        docker compose up -d "${SERVICES[@]}"
        ;;
    stop)
        need_docker
        need_env
        docker compose stop "${SERVICES[@]}"
        ;;
    restart)
        need_docker
        need_env
        docker compose restart "${SERVICES[@]}"
        ;;
    status)
        need_docker
        show_status
        ;;
    logs)
        need_docker
        need_env
        docker compose logs -f --tail=100 "${SERVICES[@]}"
        ;;
    check)
        need_docker
        need_env
        docker compose exec app app/v check::run
        ;;
    uninstall)
        need_docker
        need_env
        docker compose down --remove-orphans
        echo "containers removed; the volumes (database, config, logs) kept: $0 start brings it back"
        ;;
    purge)
        need_docker
        need_env
        read -r -p "remove the containers and every volume (the database, app.env with APP_SECRET, logs)? type yes: " answer
        [ "$answer" = "yes" ] || { echo "nothing removed"; exit 1; }
        docker compose down --remove-orphans -v
        ;;
    *)
        usage
        ;;
esac
