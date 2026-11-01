#!/bin/bash

#the workflow daemon (app/v.php daemon::run: queued jobs, schedules, timers) as a system service, nfn-daemon-<the app's user>.service, as the app's user,
#logging to logs/daemon.log; one per installation (several on one server: e.g. /home/app live, /home/dev development).
#+ the polkit rule of the installation, 49-rail22-services-<user>.rules: the app's user (the web server's PHP: the Services page) may start / stop / restart
#its units, nfn-<name>-<user>.service (the daemon, the web browser's nfn-playwright-<user>, ...), and nfn-haraka.service when the installation has it.
#The other services write their own units (scripts/haraka.sh, scripts/playwright-mcp.sh install) and the rule again (rule).
#CLD: 2026-11-01T22:00:00+02:00: commands, as the other scripts; was scripts/4_services-install.sh (the old start / stop scripts/daemon.sh moved to attic/)
#Run as root:
#   sudo scripts/daemon.sh install                          the unit and the rule, start at boot, start now (a daemon started by hand: stopped first)
#   sudo scripts/daemon.sh start | stop | restart | uninstall
#   sudo scripts/daemon.sh rule                             the polkit rule written again
#   scripts/daemon.sh status                                the installation's services and the rule
#polkit must be installed (minimal systems leave it out: dnf install polkit).

set -euo pipefail

SELF_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SELF="$SELF_DIR/$(basename "${BASH_SOURCE[0]}")"
BASE=$(dirname "$SELF_DIR")
APP_USER=$(stat -c %U "$BASE/app")
UNIT_DIR=/etc/systemd/system
SERVICE=nfn-daemon-$APP_USER
UNIT=$UNIT_DIR/$SERVICE.service
HARAKA_UNIT=nfn-haraka.service
POLKIT_RULE=/etc/polkit-1/rules.d/49-rail22-services-$APP_USER.rules

usage() {
    echo "usage: sudo $0 {install | start | stop | restart | status | rule | uninstall}"
    exit "${1:-1}"
}

command="${1:-}"
[ -n "$command" ] && shift || true
while [ "$#" -gt 0 ]; do
    case $1 in
        -h|--help) usage 0 ;;
        *) echo "unknown option: $1"; usage 1 ;;
    esac
    shift
done

need_root() {
    [ "$(id -u)" -eq 0 ] || { echo "run it as root: sudo $0 $command"; exit 1; }
}

#polkit: the Services page controls the units through it
need_polkit() {
    if ! command -v pkaction >/dev/null 2>&1 || [ ! -d "$(dirname "$POLKIT_RULE")" ]; then
        echo "polkit is not installed ($(dirname "$POLKIT_RULE") missing): install it (dnf install polkit), then run this again"
        exit 1
    fi
}

#unit $1 was written by one of this installation's scripts (or is not there)
own_unit() {
    [ ! -f "$UNIT_DIR/$1" ] || [[ $(head -n1 "$UNIT_DIR/$1") == "#written by $BASE/scripts/"* ]]
}

#the installation's other units: nfn-<name>-<user>.service (e.g. the web browser's), and Haraka's when it is the installation's
other_units() {
    local f
    for f in "$UNIT_DIR"/nfn-*-"$APP_USER".service; do
        [ -f "$f" ] || continue
        f=$(basename "$f")
        if [[ "$f" =~ ^nfn-[a-z0-9]+-"$APP_USER"\.service$ ]] && [ "$f" != "$SERVICE.service" ]; then
            echo "$f"
        fi
    done
    if [ -f "$UNIT_DIR/$HARAKA_UNIT" ] && own_unit "$HARAKA_UNIT"; then
        echo "$HARAKA_UNIT"
    fi
    return 0
}

#a daemon started by hand (its PID file, without the unit): stopped, the unit takes over
stop_pidfile() {
    local file="$BASE/run/daemon.pid" pid
    [ -s "$file" ] || return 0
    pid=$(tr -dc '0-9' < "$file")
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
        echo "+ stopping the daemon started by hand (pid $pid)"
        kill "$pid"
        for _ in $(seq 1 30); do
            kill -0 "$pid" 2>/dev/null || break
            sleep 1
        done
        kill -0 "$pid" 2>/dev/null && kill -9 "$pid"
    fi
    rm -f "$file"
}

write_unit() {
    local php
    php=$(command -v php || echo /usr/bin/php)
    cat > "$UNIT" <<EOF
#written by $SELF
[Unit]
Description=Rail22 workflow daemon of $BASE (queued jobs, schedules, timers)
After=network-online.target mongod.service
Wants=network-online.target

[Service]
User=$APP_USER
Group=$APP_USER
WorkingDirectory=$BASE/app
ExecStart=$php -d display_errors -d session.save_path=/tmp $BASE/app/v.php daemon::run --debug
StandardOutput=append:$BASE/logs/daemon.log
StandardError=inherit
Restart=on-failure
RestartSec=5
#the master first (it stops its workers), then whatever is left
KillMode=mixed
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
EOF
    chmod 644 "$UNIT"
}

#the rule: this installation's units by their name (nfn-<name>-<user>.service, also those installed later), and Haraka's when it is the installation's
write_rule() {
    local units="" user_re
    if [ -f "$UNIT_DIR/$HARAKA_UNIT" ] && own_unit "$HARAKA_UNIT"; then
        units="\"$HARAKA_UNIT\""
    fi
    #the user's name in a regular expression
    user_re=$(printf '%s' "$APP_USER" | sed 's/[][\.*^$()+?{}|/-]/\\&/g')
    cat > "$POLKIT_RULE" <<EOF
// written by $SELF
// the app's user (the web server's PHP: the Services page) may start / stop / restart the app's services, and nothing else: its units (nfn-<name>-$APP_USER.service)
polkit.addRule(function(action, subject) {
    if (action.id == "org.freedesktop.systemd1.manage-units" && subject.user == "$APP_USER") {
        var unit = action.lookup("unit") || "";
        var units = [ $units ];
        var verbs = [ "start", "stop", "restart" ];
        if ((/^nfn-[a-z0-9]+-$user_re\.service\$/.test(unit) || units.indexOf(unit) >= 0) && verbs.indexOf(action.lookup("verb")) >= 0)
            return polkit.Result.YES;
    }
});
EOF
    chmod 644 "$POLKIT_RULE"
    systemctl try-reload-or-restart polkit.service || true
    echo "+ $POLKIT_RULE"
}

case "$command" in
    install)
        need_root
        need_polkit
        echo "+ app $BASE, user $APP_USER"
        write_unit
        write_rule
        systemctl daemon-reload
        systemctl is-active --quiet "$SERVICE" || stop_pidfile
        chown "$APP_USER:$APP_USER" "$BASE/logs/daemon.log" 2>/dev/null || true
        systemctl enable "$SERVICE"
        systemctl restart "$SERVICE"
        sleep 2
        systemctl --no-pager --lines=3 status "$SERVICE" || true
        echo "Done: the Services page shows it; check that $APP_USER may control it: sudo -u $APP_USER systemctl --no-ask-password restart $SERVICE"
        ;;
    start|stop|restart)
        need_root
        systemctl "$command" "$SERVICE"
        ;;
    rule)
        need_root
        need_polkit
        write_rule
        ;;
    status)
        for u in "$SERVICE.service" $(other_units); do
            if [ "$(systemctl show "$u" -p LoadState --value 2>/dev/null)" = "loaded" ]; then
                printf '%-32s %s\n' "$u" "$(systemctl is-active "$u" 2>/dev/null || true)"
            else
                printf '%-32s %s\n' "$u" "not installed"
            fi
        done
        if [ -r "$(dirname "$POLKIT_RULE")" ]; then
            [ -f "$POLKIT_RULE" ] && echo "polkit rule: $POLKIT_RULE" || echo "polkit rule: not installed"
        else
            echo "polkit rule: unknown (run status as root to check)"
        fi
        ;;
    uninstall)
        need_root
        systemctl disable --now "$SERVICE" 2>/dev/null || true
        rm -f "$UNIT" "$POLKIT_RULE"
        systemctl daemon-reload
        systemctl try-reload-or-restart polkit.service || true
        for u in $(other_units); do
            echo "- $u: left (its own script removes it: scripts/haraka.sh / playwright-mcp.sh uninstall); without the rule the Services page no longer controls it"
        done
        echo "Removed the daemon's unit and the rule"
        ;;
    *)
        usage
        ;;
esac
