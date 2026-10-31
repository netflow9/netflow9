#!/bin/bash

#the app's background services as systemd units,
#so that the administration (the Admin plugin: Installation) shows them and starts / stops / restarts them:
#  nfn-daemon-<user>.service  the workflow daemon (app/v.php daemon::run), as the app's user (was started as root by scripts/daemon.sh)
#  nfn-haraka.service         Haraka, inbound mail (as root: port 25; it forks, its PID file as before), only when Haraka is installed, one per server
#  nfn-playwright-<user>.service  the AI steps' web browser (scripts/playwright-mcp.sh; was nfn-playwright.service, before playwright-mcp.service: it renames them)
#  + a polkit rule: the app's user (the web server's PHP) may start / stop / restart these units
#CLD: 2026-10-31T18:00:00+02:00: several installations on one server (e.g. /home/app live, /home/dev development): each its own daemon, nfn-daemon-<the app's user> (was nfn-daemon),
#its own web browser, nfn-playwright-<user> (2026-10-31T18:30:00+02:00; its port: PLAYWRIGHT_MCP_PORT of app.env), and its own polkit rule (49-netflow9-services-<user>.rules);
#Haraka (port 25) is the server's: one installation has it, the others are installed with --no-haraka; Haraka's unit written by another installation is left alone

#The processes started by scripts/daemon.sh and scripts/haraka.sh (their PID files) are stopped first; those scripts are no longer needed after this.
#Run as root:
#   sudo scripts/4_services-install.sh [install] [--no-haraka] [--no-playwright]   write the units and the rule, move the running services to them, start at boot
#   sudo scripts/4_services-install.sh uninstall [--no-playwright]                 stop and remove the units and the rule (start the old scripts again by hand if needed)
#   scripts/4_services-install.sh status

set -euo pipefail

SELF=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")
BASE=$(cd "$(dirname "$SELF")/.." && pwd)
APP_USER=$(stat -c %U "$BASE/app")
UNIT_DIR=/etc/systemd/system
POLKIT_RULE=/etc/polkit-1/rules.d/49-netflow9-services-$APP_USER.rules
DAEMON_UNIT=nfn-daemon-$APP_USER.service
HARAKA_UNIT=nfn-haraka.service
#CLD: 2026-10-29T13:00:00+02:00: nfn-playwright (was playwright-mcp), as the other units
#CLD: 2026-10-31T18:30:00+02:00: the installation's (was nfn-playwright.service)
PLAYWRIGHT_UNIT=nfn-playwright-$APP_USER.service
#CLD: 2026-10-31T18:30:00+02:00: the daemon's unit before (nfn-daemon.service): removed by install when this installation wrote it
OLD_DAEMON_UNIT=nfn-daemon.service
#CLD: 2026-10-31T18:00:00+02:00: the first line of the units this script writes: which installation wrote them
WRITTEN_BY="#written by $SELF"

#CLD: 2026-10-28T23:30:00+02:00: install when no command is given (as the other install scripts: sudo scripts/4_services-install.sh [--no-playwright]); was: the usage
command="install"
case "${1:-}" in
    ""|--*) ;;
    *) command="$1"; shift ;;
esac
#CLD: 2026-10-22T23:30:00+03:00: the browser service too (scripts/playwright-mcp.sh), unless --no-playwright
PLAYWRIGHT=1
HARAKA=1
while [ "$#" -gt 0 ]; do
    case $1 in
        --no-playwright) PLAYWRIGHT=0 ;;
        --no-haraka) HARAKA=0 ;;
        *) echo "unknown option: $1"; exit 1 ;;
    esac
    shift
done

need_root() {
    [ "$(id -u)" -eq 0 ] || { echo "run it as root: sudo $0 $command"; exit 1; }
}

#CLD: 2026-10-31T18:00:00+02:00: unit $1 was written by this installation (or is not there)
own_unit() {
    [ ! -f "$UNIT_DIR/$1" ] || [ "$(head -n1 "$UNIT_DIR/$1")" = "$WRITTEN_BY" ]
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
$WRITTEN_BY
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
    own_unit "$HARAKA_UNIT" || { echo "! $HARAKA_UNIT is another installation's ($(head -n1 "$UNIT_DIR/$HARAKA_UNIT")): left as it is"; return 1; }
    cat > "$UNIT_DIR/$HARAKA_UNIT" <<EOF
$WRITTEN_BY
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

#CLD: 2026-10-29T00:30:00+02:00: polkit must be installed (its rules' folder there): the administration controls the units through it; checked before anything is written
#(a minimal system has no polkit: the rule could not be written, after the daemon's unit)
need_polkit() {
    if ! command -v pkaction >/dev/null 2>&1 || [ ! -d "$(dirname "$POLKIT_RULE")" ]; then
        echo "polkit is not installed ($(dirname "$POLKIT_RULE") missing): install it (dnf install polkit), then run this again"
        exit 1
    fi
}

#CLD: 2026-10-31T18:00:00+02:00: the units of this installation: its daemon, Haraka when it has it, the web browser unless --no-playwright (was all three)
write_polkit_rule() {
    local units="" managed="$DAEMON_UNIT"
    [ "$1" -eq 1 ] && managed="$managed $HARAKA_UNIT"
    [ "$PLAYWRIGHT" -eq 1 ] && managed="$managed $PLAYWRIGHT_UNIT"
    for u in $managed; do units="$units${units:+, }\"$u\""; done
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
        need_polkit
        echo "+ app $BASE, user $APP_USER"
        write_daemon_unit
        haraka=0
        if [ "$HARAKA" -eq 1 ] && [ -f "$BASE/haraka/node_modules/haraka/bin/haraka" ] && write_haraka_unit; then
            haraka=1
        fi
        write_polkit_rule "$haraka"
        systemctl daemon-reload
        systemctl try-reload-or-restart polkit.service || true

        #the workflow daemon: from the script (as root) to the unit (as the app's user); the files root left
        #CLD: 2026-10-22T23:30:00+03:00: only when the unit does not run yet (run again: the units' own processes are left alone)
        #CLD: 2026-10-31T18:30:00+02:00: the unit of the former name (this installation's), stopped first: the same daemon
        if [ -f "$UNIT_DIR/$OLD_DAEMON_UNIT" ] && [ "$(head -n1 "$UNIT_DIR/$OLD_DAEMON_UNIT")" = "$WRITTEN_BY" ]; then
            echo "- the daemon's former unit: $OLD_DAEMON_UNIT removed"
            systemctl disable --now "$OLD_DAEMON_UNIT" 2>/dev/null || true
            rm -f "$UNIT_DIR/$OLD_DAEMON_UNIT"
            rm -f /etc/polkit-1/rules.d/49-netflow9-services.rules
            systemctl daemon-reload
        fi
        if ! systemctl is-active --quiet "$DAEMON_UNIT"; then
            stop_pidfile "$BASE/run/daemon.pid" "the workflow daemon"
            rm -f "$BASE/run/job-notify.sock"
        fi
        chown "$APP_USER:$APP_USER" "$BASE/logs/daemon.log" 2>/dev/null || true
        systemctl enable --now "$DAEMON_UNIT"

        if [ "$haraka" -eq 1 ]; then
            #the unit's Haraka writes the same PID file: stopped only when it was started by the script
            systemctl is-active --quiet "$HARAKA_UNIT" || stop_pidfile "$BASE/run/haraka.pid" "Haraka"
            systemctl enable --now "$HARAKA_UNIT"
        fi

        #CLD: 2026-10-22T23:30:00+03:00: the AI steps' web browser (its own script: user, firewall, unit), when the shared Chromium is there
        if [ "$PLAYWRIGHT" -eq 1 ]; then
            if ls -d /opt/playwright/browsers/chromium-[0-9]*/chrome-linux64/chrome >/dev/null 2>&1; then
                "$BASE/scripts/playwright-mcp.sh" install
            else
                echo "! the web browser for the AI steps is not installed: no shared Chromium; run scripts/1_playwright-install.sh first, then this again (or scripts/playwright-mcp.sh install)"
            fi
        fi
        sleep 2
        systemctl --no-pager --lines=3 status $DAEMON_UNIT $([ "$haraka" -eq 1 ] && echo $HARAKA_UNIT) || true
        echo "Done: Administration > Installation shows them; check that $APP_USER may control them: sudo -u $APP_USER systemctl --no-ask-password restart $DAEMON_UNIT"
        ;;
    uninstall)
        need_root
        for u in $DAEMON_UNIT $HARAKA_UNIT; do
            #CLD: 2026-10-31T18:00:00+02:00: not another installation's Haraka
            [ -f "$UNIT_DIR/$u" ] || continue
            own_unit "$u" || { echo "$u is another installation's: left as it is"; continue; }
            systemctl disable --now "$u" 2>/dev/null || true
            rm -f "$UNIT_DIR/$u"
        done
        rm -f "$POLKIT_RULE"
        systemctl daemon-reload
        #CLD: 2026-10-29T13:00:00+02:00: the unit of either name (playwright-mcp.service before the rename)
        if [ "$PLAYWRIGHT" -eq 1 ] && { [ -f "$UNIT_DIR/$PLAYWRIGHT_UNIT" ] || [ -f "$UNIT_DIR/playwright-mcp.service" ] || [ -f "$UNIT_DIR/nfn-playwright.service" ]; }; then
            "$BASE/scripts/playwright-mcp.sh" uninstall
        fi
        echo "Removed; start the services with scripts/daemon.sh and scripts/haraka.sh if needed"
        ;;
    status)
        #CLD: 2026-10-22T23:30:00+03:00: a unit not installed says so (was: inactive); the polkit rule: unknown when its folder cannot be read (only root may)
        for u in $DAEMON_UNIT $HARAKA_UNIT $PLAYWRIGHT_UNIT; do
            if [ "$(systemctl show "$u" -p LoadState --value 2>/dev/null)" = "loaded" ]; then
                printf '%-28s %s\n' "$u" "$(systemctl is-active "$u" 2>/dev/null || true)"
            else
                printf '%-28s %s\n' "$u" "not installed"
            fi
        done
        if [ -r "$(dirname "$POLKIT_RULE")" ]; then
            [ -f "$POLKIT_RULE" ] && echo "polkit rule: $POLKIT_RULE" || echo "polkit rule: not installed"
        else
            echo "polkit rule: unknown (run status as root to check)"
        fi
        ;;
    *)
        echo "usage: sudo $0 [install | uninstall | status] [--no-haraka] [--no-playwright]   (install when none is given)"
        exit 1
        ;;
esac
