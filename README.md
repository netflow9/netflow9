# Installing NetFlow9

On a new AlmaLinux / Rocky / RHEL 9 server, as root, from any folder:

```sh
curl -fsSLO https://raw.githubusercontent.com/netflow9/netflow9/main/install.sh
sudo bash install.sh
```

It asks first:

- **the server**: the site's domain (its DNS record points to the server), the system user NetFlow9 runs as (its home holds the app), the repository and branch to install from
- **options**: MongoDB on this server (else the connection string of yours), a Let's Encrypt certificate (when the domain points here; else a self-signed one), the web browser for the AI steps, inbound email (and its domain), securing MongoDB (its own users, sign-in required), SELinux permissive (when it is enforcing)

Then it shows what it will do, asks to go on, and:

1. installs the packages that are missing: EPEL, Remi's PHP 8.5 with the app's extensions, nginx, MongoDB 8.3, Redis, certbot, Node.js (the newest stream of the distribution; for the web browser and inbound email)
2. creates the user and its home, and clones the repository there (with its submodules)
3. writes php.ini, the app's PHP-FPM pool and logrotate (`scripts/system/`), the certificate and the nginx site
4. sets up the app: it asks the database, the outgoing email and the first account (company or team, its owner); the app checks the answers itself (`app/v setup::validate`) and asks again when one is wrong
5. installs the options and the background services (the parts below)

The firewall is not changed: open 80 and 443 (and 25 for inbound email). Run it again, e.g. `sudo bash /home/<user>/install.sh`, to add an option later: the app's settings and account are kept, and what is done is left as it is.

The setup in the browser (every address of the site while `app.env` is missing) does step 4 instead; run `install.sh` after it for the options and the services.

## The parts

`install.sh` runs them in this order; each can also be run alone, as root, from anywhere (each finds the app's folder from its own place).

| # | Script | Installs | Needs |
|---|--------|----------|-------|
| 0 | `sudo scripts/0_nginx-install.sh` | The app's nginx site (`scripts/nginx/app.conf`, filled in) over `<app>/etc/nginx.conf` (`/etc/nginx/conf.d/<user>.conf`); checked with `nginx -t`, then nginx reloaded | nginx; `BASE_URL` in `app.env` (or `--server-name=`); the certificate in `<app>/ssl/` |
| | `app/v setup::install` (as the app's user) | The app: the database indexes, the first account and its owner, `app.env` (the answers as JSON on stdin) | no `app.env` yet |
| 1 | `sudo scripts/1_playwright-install.sh` | Playwright and a shared Chromium (`/opt/playwright/browsers`), for the AI steps' web browser | system-wide Node.js and npm |
| 2 | `sudo scripts/2_haraka-install.sh` | Haraka, for inbound mail, into `<app>/haraka` (its files stay the app's user's) | `app.env`; `MAIL_INBOUND_DOMAIN` there (or `--domain=`); system-wide Node.js |
| 3 | `sudo scripts/3_mongo-auth-setup.sh` | MongoDB users `nfn_admin`, `nfn_app`, `nfn_haraka`; their passwords into `app.env` (`MONGO_URI`, `HARAKA_MONGO_URI`) and, with Haraka, `haraka/config/nfn.ini` | `app.env`; MongoDB on this machine with authorization still off |
| 4 | `sudo scripts/4_services-install.sh` | systemd units: `nfn-daemon`, `nfn-haraka` (when Haraka is installed), `playwright-mcp` (when Chromium is), and the polkit rule that lets the administration start / stop them | polkit |

Steps 0, 1, 2 and 4 can be run again (nothing already installed is overwritten). Step 3 too, while MongoDB does not require sign-in: it creates its users again with new passwords; once authorization is on, it stops.
After step 3, `install.sh` turns on authorization in `/etc/mongod.conf`, restarts `mongod` and the services (alone, step 3 prints these steps).
The operators' MongoDB password is left in `<app>/.mongodb-admin` (root's): move it to a password manager and delete the file.

Options: `--dry-run` (steps 0, 1 and 2) prints what would be done; `--help` lists the others.
Check the services with `scripts/4_services-install.sh status`.

## Web server (nginx)

`scripts/nginx/app.conf` is the app's nginx site, a template: step 0 fills in the app's folder (`@BASE@`) and the host of `BASE_URL` (`@SERVER_NAME@`) and writes it where `<app>/etc/nginx.conf` points (`/etc/nginx/conf.d/<user>.conf`; the link is made when missing).
It shows the changes first (`--dry-run`: only that), keeps the old file as `<user>.conf.bak-<time>`, and puts it back when `nginx -t` fails.
`/etc/nginx/nginx.conf` stays as the system installed it (it loads `conf.d/*.conf`). The certificate goes in `<app>/ssl/ssl.crt` and `ssl.key`.

## Other files in `scripts/`

- `playwright-mcp.sh`: the web browser server (Playwright MCP) as a service; step 4 runs it, or run it alone: `sudo scripts/playwright-mcp.sh install | status | restart | uninstall`
- `mongo_users.js`: the users and roles created by step 3
- `haraka/`: NetFlow9's Haraka plugin and configuration, copied by step 2 into `<app>/haraka` (`dkim/dkim_key_gen.sh`: DKIM keys)
- `nginx/`: the app's nginx site, written by step 0 (see above)
- `system/`: `php.ini`, the PHP-FPM pool (`fpm-pool.conf`) and `logrotate.conf`, written by `install.sh`
- `daemon.sh`, `haraka.sh`: the old start / stop scripts, for an installation without the systemd units of step 4 (run them from `scripts/`)
