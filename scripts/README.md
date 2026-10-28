# Install scripts

Run them **as root**, in the order of their numbers, from anywhere (each finds the app's folder from its own place).
Skip the ones you don't need. Steps 1, 2 and 4 can be run again (nothing already installed is overwritten); step 3 runs once (it stops when MongoDB already has users).

| # | Script | Installs | Needs |
|---|--------|----------|-------|
| 1 | `sudo scripts/1_playwright-install.sh` | Playwright and a shared Chromium (`/opt/playwright/browsers`), for the AI steps' web browser | system-wide Node.js and npm |
| 2 | `sudo scripts/2_haraka-install.sh` | Haraka, for inbound mail, into `<app>/haraka` (its files stay the app's user's) | `MAIL_INBOUND_DOMAIN` in `app.env` (or `--domain=`); system-wide Node.js |
| 3 | `sudo scripts/3_mongo-auth-setup.sh` | MongoDB users `nfn_admin`, `nfn_app`, `nfn_haraka`; their passwords into `app.env` and `haraka/config/nfn.ini` | MongoDB with authorization still off; step 2 done (it writes Haraka's `nfn.ini`) |
| 4 | `sudo scripts/4_services-install.sh install` | systemd units: `nfn-daemon`, `nfn-haraka` (when step 2 is done), `playwright-mcp` (when step 1 is done), and the polkit rule that lets the administration start / stop them | — |

After step 3, turn on authorization in `/etc/mongod.conf`, restart `mongod`, then the services; the script prints the steps.
The operators' MongoDB password is left in `<app>/.mongodb-admin` (root's): move it to a password manager and delete the file.

Options: `--dry-run` (steps 1 and 2) prints what would be done; `--help` lists the others.
Check the services with `scripts/4_services-install.sh status`.

## Web server (nginx)

`nginx/app.conf` is the app's nginx site (`/etc/nginx/conf.d/app.conf`; `/etc/nginx/nginx.conf` stays as the system installed it, it loads `conf.d/*.conf`).
Fill it in as root, from the app's folder, with the host of `BASE_URL` (`app.env`); the certificate goes in `<app>/ssl/ssl.crt` and `ssl.key`:

```sh
sed -e "s|@BASE@|$PWD|g" -e "s|@SERVER_NAME@|app.example.com|g" scripts/nginx/app.conf > /etc/nginx/conf.d/app.conf
nginx -t && systemctl reload nginx
```

PHP is served by a PHP-FPM pool running as the app's user, listening on `<app>/run/php-fpm.sock` (owned by nginx).

## Other files

- `playwright-mcp.sh`: the web browser server (Playwright MCP) as a service; step 4 runs it, or run it alone: `sudo scripts/playwright-mcp.sh install | status | restart | uninstall`
- `mongo_users.js`: the users and roles created by step 3
- `haraka/`: NetFlow9's Haraka plugin and configuration, copied by step 2
- `nginx/`: the app's nginx site (see above)
- `daemon.sh`, `haraka.sh`: the old start / stop scripts, for an installation without the systemd units of step 4 (run them from this folder)
