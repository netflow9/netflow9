#!/bin/bash

#CLD: 2026-10-22T23:00:00+03:00: new: the app's background services as systemd units, so that the administration (the Admin plugin: Installation) shows them and starts / stops /
#restarts them:
#  nfn-daemon.service       the workflow daemon (app/v.php daemon::run), as the app's user (was started as root by scripts/daemon.sh)
#  nfn-haraka.service       Haraka, inbound mail (as root: port 25; it forks, its PID file as before), only when Haraka is installed
#  + a polkit rule: the app's user (the web server's PHP) may start / stop / restart these units and playwright-mcp.service (scripts/playwright-mcp.sh), nothing else
#The processes started by scripts/daemon.sh and scripts/haraka.sh (their PID files) are stopped first; those scripts are no longer needed after this.
#Run as root:
#   sudo scripts/services-install.sh install       write the units and the rule, move the running services to them, start at boot
#   sudo scripts/services-install.sh uninstall     stop and remove the units and the rule (start the old scripts again by hand if needed)
#   scripts/services-install.sh status

set -euo pipefail

SELF=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")
BASE=$(cd "$(dirname "$SELF")/.." && pwd)
APP_USER=$(stat -c %U "$BASE/app")
UNIT_DIR=/etc/systemd/system
POLKIT_RULE=/etc/polkit-1/rules.d/49-netflow9-services.rules
DAEMON_UNIT=nfn-daemon.service
HARAKA_UNIT=nfn-haraka.service
MANAGED="$DAEMON_UNIT $HARAKA_UNIT playwright-mcp.service"

command="${1:-}"

need_root() {
    [ "$(id -u)" -eq 0 ] || { echo "run it as root: sudo $0 $command"; exit 1; }
}

#stops the process of PID file $1 (started by the old scripts), waiting for it to end
stop_pidfile() {
    local file="$1" name="$2" pid
    [ -s "$file" ] || return 0
    pid=$(tr -dc '0-9' < "$file")
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
        echo "+ stopping $name started by its script (pid $pid)"
        kill "$pid"
        for _ in $(seq 1 30); do
            kill -0 "$pid" 2>/dev/null || break
            sleep 1
        done
        kill -0 "$pid" 2>/dev/null && { echo "  still running: kill -9 $pid"; kill -9 "$pid"; }
    fi
    rm -f "$file"
}

write_daemon_unit() {
    local php
    php=$(command -v php || echo /usr/bin/php)
    cat > "$UNIT_DIR/$DAEMON_UNIT" <<EOF
#written by $SELF
[Unit]
Description=NetFlow9 workflow daemon (queued jobs, schedules, timers)
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
}

write_haraka_unit() {
    local node
    node=$(PATH=/usr/local/bin:/usr/bin:/bin command -v node || true)
    [ -n "$node" ] || { echo "no system-wide node for Haraka (/usr/bin/node): Haraka not installed as a unit"; return 1; }
    cat > "$UNIT_DIR/$HARAKA_UNIT" <<EOF
#written by $SELF
[Unit]
Description=NetFlow9 inbound mail (Haraka)
After=network-online.target mongod.service
Wants=network-online.target

[Service]
#as root: port 25; Haraka forks (config/smtp.ini daemonize=true) and writes its PID file
Type=forking
PIDFile=$BASE/run/haraka.pid
ExecStart=$node $BASE/haraka/node_modules/haraka/bin/haraka -c $BASE/haraka
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
}

write_polkit_rule() {
    local units=""
    for u in $MANAGED; do units="$units${units:+, }\"$u\""; done
    cat > "$POLKIT_RULE" <<EOF
// written by $SELF
// the app's user (the web server's PHP: the administration) may start / stop / restart the app's services, and nothing else
polkit.addRule(function(action, subject) {
    if (action.id == "org.freedesktop.systemd1.manage-units" && subject.user == "$APP_USER") {
        var units = [ $units ];
        var verbs = [ "start", "stop", "restart" ];
        if (units.indexOf(action.lookup("unit")) >= 0 && verbs.indexOf(action.lookup("verb")) >= 0)
            return polkit.Result.YES;
    }
});
EOF
    chmod 644 "$POLKIT_RULE"
}

case "$command" in
    install)
        need_root
        echo "+ app $BASE, user $APP_USER"
        write_daemon_unit
        haraka=0
        if [ -f "$BASE/haraka/node_modules/haraka/bin/haraka" ] && write_haraka_unit; then
            haraka=1
        fi
        write_polkit_rule
        systemctl daemon-reload
        systemctl try-reload-or-restart polkit.service || true

        #the workflow daemon: from the script (as root) to the unit (as the app's user); the files root left
        stop_pidfile "$BASE/run/daemon.pid" "the workflow daemon"
        rm -f "$BASE/run/job-notify.sock"
        chown "$APP_USER:$APP_USER" "$BASE/logs/daemon.log" 2>/dev/null || true
        systemctl enable --now "$DAEMON_UNIT"

        if [ "$haraka" -eq 1 ]; then
            stop_pidfile "$BASE/run/haraka.pid" "Haraka"
            systemctl enable --now "$HARAKA_UNIT"
        fi
        sleep 2
        systemctl --no-pager --lines=3 status $DAEMON_UNIT $([ "$haraka" -eq 1 ] && echo $HARAKA_UNIT) || true
        echo "Done: Administration > Installation shows them; check that $APP_USER may control them: sudo -u $APP_USER systemctl --no-ask-password restart $DAEMON_UNIT"
        ;;
    uninstall)
        need_root
        for u in $DAEMON_UNIT $HARAKA_UNIT; do
            systemctl disable --now "$u" 2>/dev/null || true
            rm -f "$UNIT_DIR/$u"
        done
        rm -f "$POLKIT_RULE"
        systemctl daemon-reload
        echo "Removed; start the services with scripts/daemon.sh and scripts/haraka.sh if needed"
        ;;
    status)
        for u in $DAEMON_UNIT $HARAKA_UNIT playwright-mcp.service; do
            printf '%-26s %s\n' "$u" "$(systemctl is-active "$u" 2>/dev/null || true)"
        done
        [ -f "$POLKIT_RULE" ] && echo "polkit rule: $POLKIT_RULE" || echo "polkit rule: not installed"
        ;;
    *)
        echo "usage: sudo $0 {install | uninstall | status}"
        exit 1
        ;;
esac
