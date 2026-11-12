#!/bin/bash

#Rail22 with Docker Compose (docker/: the compose files, the images, the settings in docker/.env): the settings, the images, the containers.
#one script for the compose setup, as the other scripts (a command first); docker compose itself works as well,
#the files to use are in .env (COMPOSE_FILE), so `docker compose ps` etc. (from docker/) see the same setup
#Run as a user of the docker group (or root):
#   docker/docker.sh setup [--dev] [--no-browser] [--mail]           .env written (random passwords, the folder owner's uid / gid), the submodules checked out;
#                                                                   an existing .env: its values kept, the passwords never changed
#the web browser (playwright) by default (--no-browser leaves it out); --mail: the host's Haraka (the operator's, README.md: compose.mail.yaml publishes
#the database and the daemon on 127.0.0.1 for it). HTTPS and mail are the operator's: the site on 127.0.0.1:8080 for the host's reverse proxy (README.md).
#Setup asks (on a terminal) what an option did not say: development, the browser, the host's Haraka, the ports,
#the public address, the containers' user; the defaults are an existing .env's values. Without a terminal: the options, .env's values, the defaults
#   docker/docker.sh build [service...]                           the images (all of the setup's, or the ones named)
#   docker/docker.sh install [setup's options]                    setup (asks, as above), build, start
#   docker/docker.sh start | stop | restart [service...]
#   docker/docker.sh status                                       the containers, the settings in use
#   docker/docker.sh logs [service...]                            followed (ctrl-c ends)
#   docker/docker.sh check                                        the check suite in the app container
#   docker/docker.sh uninstall                                    the containers removed, the data (DATA_DIR: database, config) kept
#   docker/docker.sh purge                                        the containers and the data (DATA_DIR) removed: all data gone (asks first)
#--dev: compose.dev.yaml (the working tree mounted, mailpit on 127.0.0.1:8025, the site on 127.0.0.1 only)

set -euo pipefail

SELF_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
BASE=$(dirname "$SELF_DIR")
ENV_FILE="$SELF_DIR/.env"

usage() {
    echo "usage: $0 {setup | install [--[no-]dev] [--browser | --no-browser] [--mail | --no-mail] | build [service...] | install | start | stop | restart [service...] | status | logs [service...] | check | uninstall | purge}"
    exit "${1:-1}"
}

command="${1:-}"
[ -n "$command" ] && shift || true
# empty: not given, setup asks (or takes .env's value, or the default)
DEV=
BROWSER=
MAIL=
SERVICES=()
while [ "$#" -gt 0 ]; do
    case $1 in
        --dev) DEV=1 ;;
        --no-dev) DEV=0 ;;
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

# the questions: only on a terminal; read's prompt goes to stderr, the answer to stdout
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

# the folders of compose.yaml's mounts in $1: the app's (config, logs, var, tmp, assets) the containers' user's, the database's mongo's own
#(its entrypoint changes their owner); bounces: the host's Haraka writes, the app reads and removes (both)
DATA_DIRS=(config logs var tmp assets bounces mongo-data)
make_data() {
    local d uid gid
    uid=$(get_env NFN_UID)
    gid=$(get_env NFN_GID)
    for d in "${DATA_DIRS[@]}"; do
        mkdir -p "$1/$d"
    done
    if [ "$(id -u)" -eq 0 ]; then
        chown "$uid:$gid" "$1" "$1"/{config,logs,var,tmp,assets,bounces}
    fi
    chmod 700 "$1"
    chmod 777 "$1/bounces"
    echo "  data: $1"
}

setup() {
    local files profiles=() owner_uid owner_gid
    local d_dev=0 d_browser=1 d_mail=0

    if [ -d "$BASE/.git" ] && command -v git >/dev/null 2>&1; then
        git -C "$BASE" submodule update --init
    fi
    [ -f "$BASE/app/pkgs/mongo-php-library/composer.json" ] || { echo "app/pkgs is empty: the submodules are needed (git submodule update --init)"; exit 1; }

    # an existing .env gives the defaults: its compose files, its profiles, its values
    if [ -f "$ENV_FILE" ]; then
        EXISTING=1
        files=$(get_env COMPOSE_FILE)
        echo ".env found: its values are the defaults (the passwords are kept)"
        in_list compose.dev.yaml "$files" && d_dev=1
        in_list browser "$(get_env COMPOSE_PROFILES)" || d_browser=0
        in_list compose.mail.yaml "$files" && d_mail=1
    fi

    pick_yn DEV "$d_dev" "Development (the working tree mounted, mailpit, local ports only)?"
    pick_yn BROWSER "$d_browser" "Web browser for the AI steps (playwright)?"
    pick_yn MAIL "$d_mail" "Haraka on this host (yours, README.md: the database and the daemon published on 127.0.0.1 for it)?"

    files="compose.yaml"
    [ "$DEV" -eq 0 ] || files="$files:compose.dev.yaml"
    [ "$MAIL" -eq 0 ] || files="$files:compose.mail.yaml"
    [ "$BROWSER" -eq 0 ] || profiles+=(browser)
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

    # the project's name (containers, volumes, images): the folder's, so two installations on one host (/home/dev, /home/app) stay apart
    pick_env COMPOSE_PROJECT_NAME "rail22-$(basename "$BASE" | tr 'A-Z' 'a-z' | tr -c 'a-z0-9_\n-' '-')" "The compose project's name (its containers, volumes, images)"

    # the containers' user: the folder's owner (root setting up another user's home: that user, not root)
    owner_uid=$(stat -c %u "$BASE")
    owner_gid=$(stat -c %g "$BASE")
    pick_env NFN_UID "$owner_uid" "The containers' user id (the files' owner)"
    pick_env NFN_GID "$owner_gid" "The containers' group id"

    # the data (database, config, logs, mail) in folders of the user's home, made here with their owners (docker would make them root's)
    local home data
    home=$(getent passwd "$(get_env NFN_UID)" | cut -d: -f6 || true)
    pick_env DATA_DIR "${home:-$BASE}/docker-volumes" "The data's folder (the database, the config, the logs)"
    data=$(get_env DATA_DIR)
    case "$data" in
        /*) ;;
        *) data="$BASE/$data"; set_env DATA_DIR "$data" ;;
    esac
    make_data "$data"
    # development mounts public_html read-only: the assets mount's point must be there already (public_html/assets is not in git)
    if [ "$DEV" -eq 1 ] && [ ! -d "$BASE/public_html/assets" ]; then
        mkdir -p "$BASE/public_html/assets"
        [ "$(id -u)" -ne 0 ] || chown "$owner_uid:$owner_gid" "$BASE/public_html/assets"
    fi

    #the site for the operator's reverse proxy (HTTPS there); development: 127.0.0.1 always (compose.dev.yaml)
    [ "$DEV" -eq 1 ] || pick_env HTTP_BIND 127.0.0.1 "The site's address (127.0.0.1: the reverse proxy on this host)"
    pick_env HTTP_PORT 8080 "The site's port (the reverse proxy forwards to it)"
    port_warn "$(get_env HTTP_PORT)" "the site"
    pick_env BASE_URL "" "The public address, the reverse proxy's (e.g. https://flows.example.com; empty: asked by /setup)"

    if in_list browser "$profiles"; then
        set_env NFN_BROWSER_URL "http://playwright:8931/mcp"
    else
        set_env NFN_BROWSER_URL ""
    fi
    #the host's Haraka: the ports it reaches (nfn.ini's uri and job_port), the app's SMTP server (development: mailpit); without it HARAKA_HOST empty
    #(set, the Email (inbound) step would be offered)
    if [ "$MAIL" -eq 1 ]; then
        set_env HARAKA_HOST host.docker.internal
        pick_env MONGO_HOST_PORT 27017 "The database's port for Haraka (on 127.0.0.1)"
        port_warn "$(get_env MONGO_HOST_PORT)" "the database"
        pick_env DAEMON_HOST_PORT 9000 "The daemon's port for Haraka (on 127.0.0.1)"
        port_warn "$(get_env DAEMON_HOST_PORT)" "the daemon"
        [ "$DEV" -eq 1 ] || [ -n "$(get_env SMTP_HOST)" ] || { set_env SMTP_HOST host.docker.internal; set_env SMTP_PORT 25; }
    else
        set_env HARAKA_HOST ""
    fi

    # root setting up another user's folder: .env stays that user's (mode 600)
    if [ "$(id -u)" -eq 0 ] && [ "$owner_uid" -ne 0 ]; then
        chown "$owner_uid:$owner_gid" "$ENV_FILE"
    fi
    chmod 600 "$ENV_FILE"

    echo "  project: $(get_env COMPOSE_PROJECT_NAME)"
    echo "  files: $files"
    echo "  profiles: ${profiles:-(none)}"
    echo "  site: $(get_env HTTP_BIND):$(get_env HTTP_PORT) (HTTPS: your reverse proxy in front, README.md); containers' user $(get_env NFN_UID):$(get_env NFN_GID)"
    [ "$MAIL" -eq 0 ] || echo "  mail: your Haraka on this host (README.md, Inbound mail): database 127.0.0.1:$(get_env MONGO_HOST_PORT), daemon 127.0.0.1:$(get_env DAEMON_HOST_PORT), bounces $(get_env DATA_DIR)/bounces"
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
        # setup each time (an existing .env: its values offered, kept on enter)
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
        echo "containers removed; the data (database, config, logs in $(get_env DATA_DIR)) kept: $0 start brings it back"
        ;;
    purge)
        need_docker
        need_env
        read -r -p "remove the containers and every volume, the data's folders too ($(get_env DATA_DIR): the database, app.env with APP_SECRET, logs)? type yes: " answer
        [ "$answer" = "yes" ] || { echo "nothing removed"; exit 1; }
        docker compose down --remove-orphans -v
        # the data's folders too (the database's files are mongo's user's: removed from a container, as root there)
        data=$(get_env DATA_DIR)
        if [ -n "$data" ] && [ -d "$data" ]; then
            docker run --rm -u 0 --entrypoint rm -v "$data:/data" mongo:8.3 -rf "${DATA_DIRS[@]/#//data/}"
            rmdir "$data" 2>/dev/null || true
            echo "removed: $data"
        fi
        ;;
    *)
        usage
        ;;
esac
