#!/bin/bash

#NetFlow9 with Docker Compose (compose.yaml next to app/): the settings (.env), the images, the containers.
#CLD: 2026-11-01T23:30:00+02:00: one script for the compose setup, as the other scripts (a command first); docker compose itself works as well,
#the files to use are in .env (COMPOSE_FILE), so `docker compose ps` etc. see the same setup
#Run as a user of the docker group (or root):
#   scripts/docker.sh setup [--dev] [--tls] [--no-browser] [--no-mail]   .env written (random passwords, your uid / gid), the submodules checked out;
#                                                                   an existing .env: only the files and profiles changed
#CLD: 2026-11-01T23:50:00+02:00: the web browser (playwright) and inbound mail (haraka) by default (were --browser, --mail); --no-browser / --no-mail leave them out
#   scripts/docker.sh build [service...]                           the images (all of the setup's, or the ones named)
#   scripts/docker.sh install [setup's options]                    setup when there is no .env, build, start
#   scripts/docker.sh start | stop | restart [service...]
#   scripts/docker.sh status                                       the containers, the settings in use
#   scripts/docker.sh logs [service...]                            followed (ctrl-c ends)
#   scripts/docker.sh check                                        the check suite in the app container
#   scripts/docker.sh uninstall                                    the containers removed, the volumes (database, config) kept
#   scripts/docker.sh purge                                        the containers and every volume removed: all data gone (asks first)
#--dev: compose.dev.yaml (the working tree mounted, mailpit on 127.0.0.1:8025, the site on 127.0.0.1:8080); --tls: compose.tls.yaml (HTTPS, ssl/);
#with --dev, Haraka is published on 127.0.0.1:2525 (a server's MTA keeps 25)

set -euo pipefail

SELF_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
BASE=$(dirname "$SELF_DIR")
ENV_FILE="$BASE/.env"

usage() {
    echo "usage: $0 {setup | install [--dev] [--tls] [--no-browser] [--no-mail] | build [service...] | install | start | stop | restart [service...] | status | logs [service...] | check | uninstall | purge}"
    exit "${1:-1}"
}

command="${1:-}"
[ -n "$command" ] && shift || true
DEV=0
TLS=0
BROWSER=1
MAIL=1
SERVICES=()
while [ "$#" -gt 0 ]; do
    case $1 in
        --dev) DEV=1 ;;
        --tls) TLS=1 ;;
        --no-browser) BROWSER=0 ;;
        --no-mail) MAIL=0 ;;
        -h|--help) usage 0 ;;
        -*) echo "unknown option: $1"; usage 1 ;;
        *) SERVICES+=("$1") ;;
    esac
    shift
done

cd "$BASE"

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

setup() {
    local files="compose.yaml" profiles=() domain
    [ "$DEV" -eq 0 ] || files="$files:compose.dev.yaml"
    [ "$TLS" -eq 0 ] || files="$files:compose.tls.yaml"
    [ "$BROWSER" -eq 0 ] || profiles+=(browser)
    [ "$MAIL" -eq 0 ] || profiles+=(mail)
    profiles=$(IFS=,; echo "${profiles[*]:-}")

    if [ -d .git ] && command -v git >/dev/null 2>&1; then
        git submodule update --init
    fi
    [ -f app/pkgs/mongo-php-library/composer.json ] || { echo "app/pkgs is empty: the submodules are needed (git submodule update --init)"; exit 1; }

    if [ ! -f "$ENV_FILE" ]; then
        command -v openssl >/dev/null 2>&1 || { echo "openssl is needed for the passwords"; exit 1; }
        (umask 077; cp docker/env.example "$ENV_FILE")
        set_env MONGO_ROOT_PW "$(password)"
        set_env NFN_APP_PW "$(password)"
        set_env NFN_HARAKA_PW "$(password)"
        set_env NFN_UID "$(id -u)"
        set_env NFN_GID "$(id -g)"
        #development: the server's nginx keeps 80
        [ "$DEV" -eq 0 ] || set_env HTTP_PORT 8080
        echo ".env written (passwords generated; the database users are made from them at mongo's first start)"
    else
        echo ".env kept (passwords unchanged); files and profiles set"
        if [ "$DEV" -eq 1 ] && [ "$(get_env HTTP_PORT)" = "80" ]; then
            set_env HTTP_PORT 8080
        fi
    fi
    set_env COMPOSE_FILE "$files"
    set_env COMPOSE_PROFILES "$profiles"
    #a profile left out: its settings emptied too (HARAKA_HOST set without Haraka would offer the Email (inbound) step)
    if [[ ",$profiles," == *",browser,"* ]]; then
        set_env NFN_BROWSER_URL "http://playwright:8931/mcp"
    else
        set_env NFN_BROWSER_URL ""
    fi
    if [[ ",$profiles," == *",mail,"* ]]; then
        set_env HARAKA_HOST haraka
        #Haraka does not start without the domain: this host's name until one is set (its MX record points to this host)
        if [ -z "$(get_env MAIL_INBOUND_DOMAIN)" ]; then
            domain=$(hostname -f 2>/dev/null || hostname)
            set_env MAIL_INBOUND_DOMAIN "$domain"
            echo "mail: MAIL_INBOUND_DOMAIN=$domain (this host's name; change it in .env to the domain whose MX record points here)"
        fi
        #port 25 on the host, without --dev (2525 on 127.0.0.1 then)
        if [ "$DEV" -eq 0 ] && command -v ss >/dev/null 2>&1 && ss -ltnH 'sport = :25' 2>/dev/null | grep -q .; then
            echo "mail: port 25 is in use here (another mail server?): haraka cannot start; stop it, or run setup with --no-mail"
        fi
    else
        set_env HARAKA_HOST ""
    fi
    if [ "$TLS" -eq 1 ]; then
        local ssl
        ssl=$(get_env SSL_DIR)
        ssl=${ssl:-./ssl}
        [ -f "$ssl/ssl.crt" ] && [ -f "$ssl/ssl.key" ] || echo "tls: put the certificate in $ssl/ssl.crt and $ssl/ssl.key"
    fi
    echo "  files: $files"
    echo "  profiles: ${profiles:-(none)}"
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
        [ -f "$ENV_FILE" ] || setup
        docker compose build
        docker compose up -d
        docker compose ps
        echo "the site: port $(get_env HTTP_PORT), /setup on the first visit (docker compose logs -f app daemon: the first start)"
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
