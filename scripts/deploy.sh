#!/bin/bash

#the installation updated to what is pushed (run it in the installation that is deployed to, e.g. /home/app): the repository with its submodules, the MultiTenant
#plugin's own repository when it has it, the new indexes, then PHP-FPM and the daemon restarted.
#The repositories are fetched first and only fast-forwarded, so a remote that cannot be reached or a local commit stops it before anything changes.
#Every step can be run again: after a failure, fix it and run it again.
#CLD: 2026-11-02T21:00:00+02:00: new
#As root (PHP-FPM can only be restarted by root; the git and the indexes steps run as the app's user):
#   sudo scripts/deploy.sh run
#   scripts/deploy.sh status       the commits here and on the remote; changes nothing here

set -euo pipefail

SELF_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
BASE=$(dirname "$SELF_DIR")
APP_USER=$(stat -c %U "$BASE/app")
PLUGIN="$BASE/app/plugins/MultiTenant"
DAEMON=nfn-daemon-$APP_USER

usage() {
    echo "usage: sudo $0 {run | status}"
    exit "${1:-1}"
}

#as the app's user, whoever runs this
as_user() {
    if [ "$(id -un)" = "$APP_USER" ]; then
        "$@"
    elif [ "$(id -u)" -eq 0 ]; then
        sudo -H -u "$APP_USER" "$@"
    else
        echo "run it as root or as $APP_USER"
        exit 1
    fi
}

#the repositories of this installation
repos() {
    echo "$BASE"
    if [ -e "$PLUGIN/.git" ]; then
        echo "$PLUGIN"
    fi
}

#the PHP-FPM service that is running (its name depends on how PHP was installed)
fpm_unit() {
    systemctl list-units --type=service --state=running --no-legend --plain '*php*fpm*' | awk 'NR==1 { print $1 }'
}

#the app's version in this installation (app/data/Version.php)
version() {
    sed -n "s/.*const NUMBER = '\(.*\)';.*/\1/p" "$BASE/app/data/Version.php" 2>/dev/null || true
}

show_commit() {
    as_user git -C "$1" log -1 --format='%h %s' "$2" | cut -c1-100
}

#the commits of repository $1 that are here and not on the remote, newest first; the first 20, then how many more.
#A rewritten history on the remote gives every commit another hash: those found on the remote by author date and subject are counted, not listed.
#The output of git is captured first: piped straight from sudo the list came out empty, and the count did not
local_commits() {
    local here remote real total own
    here=$(as_user git -C "$1" log --format=$'%at %s\t%h %ad %s' --date=short '@{u}..HEAD')
    remote=$(as_user git -C "$1" log --format='%at %s' 'HEAD..@{u}')
    if [ -z "$here" ]; then
        echo "  only here: 0 commit(s)"
        return 0
    fi
    real=$(awk -F'\t' 'NR==FNR { seen[$0]; next } !($1 in seen) { print $2 }' <(printf '%s\n' "$remote") <(printf '%s\n' "$here"))
    total=$(printf '%s\n' "$here" | wc -l)
    own=0
    if [ -n "$real" ]; then
        own=$(printf '%s\n' "$real" | wc -l)
    fi
    echo "  only here: $total commit(s) by hash, $own of them not on the remote by date and subject"
    if [ "$own" -gt 0 ]; then
        printf '%s\n' "$real" | awk 'NR <= 20 { print "    " $0 }' | cut -c1-110
        if [ "$own" -gt 20 ]; then
            echo "    ... and $((own - 20)) more"
        fi
    fi
    if [ "$own" -lt "$total" ]; then
        echo "  the other $((total - own)) are on the remote under other hashes: its history was rewritten"
    fi
    if [ "$own" -eq 0 ]; then
        echo "  no work of this clone would be lost. To take the remote's history, as $APP_USER, after checking git status (uncommitted changes are discarded too):"
        echo "    git -C $1 reset --hard '@{u}'"
    fi
}

status() {
    local repo
    while read -r repo; do
        as_user git -C "$repo" fetch -q
        echo "$repo"
        echo "  here:   $(show_commit "$repo" HEAD)"
        echo "  remote: $(show_commit "$repo" '@{u}')"
        local_commits "$repo"
    done < <(repos)
    echo "version: $(version)"
    echo "$DAEMON: $(systemctl is-active "$DAEMON" || true)"
}

run() {
    local repo fpm
    [ "$(id -u)" -eq 0 ] || { echo "run it as root: sudo $0 run"; exit 1; }

    #fetched first: both or nothing when a remote cannot be reached
    while read -r repo; do
        as_user git -C "$repo" fetch -q
        if ! as_user git -C "$repo" merge-base --is-ancestor HEAD '@{u}'; then
            echo "$repo has commits the remote does not have: nothing changed, solve it first"
            local_commits "$repo"
            exit 1
        fi
    done < <(repos)

    while read -r repo; do
        echo "== $repo: $(show_commit "$repo" HEAD)"
        as_user git -C "$repo" merge --ff-only -q '@{u}'
        echo "   now: $(show_commit "$repo" HEAD)"
    done < <(repos)
    as_user git -C "$BASE" submodule update -q --init --recursive

    echo "== indexes"
    (cd "$BASE/app/commands" && as_user ../v sys::indexes)

    fpm=$(fpm_unit)
    if [ -z "$fpm" ]; then
        echo "no PHP-FPM service is running: start it"
        exit 1
    fi
    echo "== restart: $fpm $DAEMON"
    systemctl restart "$fpm" "$DAEMON"
    systemctl is-active --quiet "$fpm" "$DAEMON" || { echo "not running after the restart: systemctl status $fpm $DAEMON"; exit 1; }
    echo "Done: version $(version)"
}

main() {
    case "${1:-}" in
        run) run ;;
        status) status ;;
        -h|--help) usage 0 ;;
        *) usage 1 ;;
    esac
}

#last line: git may replace this file while it runs
main "$@"
