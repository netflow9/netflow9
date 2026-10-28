# Install scripts

Run them **as root**, in the order of their numbers, from anywhere (each finds the app's folder from its own place).
Skip the ones you don't need. Steps 0, 1, 2 and 4 can be run again (nothing already installed is overwritten). Step 3 too, while MongoDB does not require sign-in: it creates its users again with new passwords; once authorization is on, it stops.

| # | Script | Installs | Needs |
|---|--------|----------|-------|
| 0 | `sudo scripts/0_nginx-install.sh` | The app's nginx site (`nginx/app.conf`, filled in) over `<app>/etc/nginx.conf` (`/etc/nginx/conf.d/app.conf`); checked with `nginx -t`, then nginx reloaded | nginx; `BASE_URL` in `app.env` (or `--server-name=`); the certificate in `<app>/ssl/` |
| 1 | `sudo scripts/1_playwright-install.sh` | Playwright and a shared Chromium (`/opt/playwright/browsers`), for the AI steps' web browser | system-wide Node.js and npm |
| 2 | `sudo scripts/2_haraka-install.sh` | Haraka, for inbound mail, into `<app>/haraka` (its files stay the app's user's) | `MAIL_INBOUND_DOMAIN` in `app.env` (or `--domain=`); system-wide Node.js |
| 3 | `sudo scripts/3_mongo-auth-setup.sh` | MongoDB users `nfn_admin`, `nfn_app`, `nfn_haraka`; their passwords into `app.env` and `haraka/config/nfn.ini` | MongoDB with authorization still off; step 2 done (it writes Haraka's `nfn.ini`) |
| 4 | `sudo scripts/4_services-install.sh` | systemd units: `nfn-daemon`, `nfn-haraka` (when step 2 is done), `playwright-mcp` (when step 1 is done), and the polkit rule that lets the administration start / stop them | polkit installed |

After step 3, turn on authorization in `/etc/mongod.conf`, restart `mongod`, then the services; the script prints the steps.
The operators' MongoDB password is left in `<app>/.mongodb-admin` (root's): move it to a password manager and delete the file.

Options: `--dry-run` (steps 0, 1 and 2) prints what would be done; `--help` lists the others.
Check the services with `scripts/4_services-install.sh status`.

## Web server (nginx)

`nginx/app.conf` is the app's nginx site, a template: step 0 fills in the app's folder (`@BASE@`) and the host of `BASE_URL` (`@SERVER_NAME@`) and writes it where `<app>/etc/nginx.conf` points (`/etc/nginx/conf.d/app.conf`; the link is made when missing).
It shows the changes first (`--dry-run`: only that), keeps the old file as `app.conf.bak-<time>`, and puts it back when `nginx -t` fails.
`/etc/nginx/nginx.conf` stays as the system installed it (it loads `conf.d/*.conf`). The certificate goes in `<app>/ssl/ssl.crt` and `ssl.key`.

PHP is served by a PHP-FPM pool running as the app's user, listening on `<app>/run/php-fpm.sock` (owned by nginx).

## Other files

- `playwright-mcp.sh`: the web browser server (Playwright MCP) as a service; step 4 runs it, or run it alone: `sudo scripts/playwright-mcp.sh install | status | restart | uninstall`
- `mongo_users.js`: the users and roles created by step 3
- `haraka/`: NetFlow9's Haraka plugin and configuration, copied by step 2
- `nginx/`: the app's nginx site, written by step 0 (see above)
- `daemon.sh`, `haraka.sh`: the old start / stop scripts, for an installation without the systemd units of step 4 (run them from this folder)
