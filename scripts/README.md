# Installing

```sh
sudo scripts/install.sh
```

Run it as root, in a terminal. It asks:

- **the app**: the public address of the site, the MongoDB connection string and database name (a new or empty one)
- **outgoing email**: SMTP server, port, encryption, username and password, the sender address
- **the first account**: company or team, and its owner (username, email, names, password)
- **options**: nginx for the site (a self-signed certificate when `ssl/ssl.crt` is missing), the web browser for the AI steps, inbound email (and its domain), securing MongoDB (its own users, sign-in required; offered when MongoDB is on this machine without sign-in)

The answers are checked by the app itself (`app/v setup::validate`) and asked again when one is wrong. Then everything needed is checked, and what is missing is listed without changing anything.
It shows what it will do, asks to go on, and runs the parts below in order. Run it again to add an option later: the app's settings and account are kept.

Needed before: PHP (with the `mongodb` extension; web PHP-FPM and command line), MongoDB, nginx with a PHP-FPM pool running as the app's user (listening on `<app>/run/php-fpm.sock`, owned by nginx), polkit; Node.js and npm (system-wide) for the web browser and inbound email.

The setup in the browser (every address of the site while `app.env` is missing) does the same for the app; run `install.sh` after it for the options and the services.

## The parts

`install.sh` runs them in this order; each can also be run alone, as root, from anywhere (each finds the app's folder from its own place).

| # | Script | Installs | Needs |
|---|--------|----------|-------|
| 0 | `sudo scripts/0_nginx-install.sh` | The app's nginx site (`nginx/app.conf`, filled in) over `<app>/etc/nginx.conf` (`/etc/nginx/conf.d/app.conf`); checked with `nginx -t`, then nginx reloaded | nginx; `BASE_URL` in `app.env` (or `--server-name=`); the certificate in `<app>/ssl/` |
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

`nginx/app.conf` is the app's nginx site, a template: step 0 fills in the app's folder (`@BASE@`) and the host of `BASE_URL` (`@SERVER_NAME@`) and writes it where `<app>/etc/nginx.conf` points (`/etc/nginx/conf.d/app.conf`; the link is made when missing).
It shows the changes first (`--dry-run`: only that), keeps the old file as `app.conf.bak-<time>`, and puts it back when `nginx -t` fails.
`/etc/nginx/nginx.conf` stays as the system installed it (it loads `conf.d/*.conf`). The certificate goes in `<app>/ssl/ssl.crt` and `ssl.key`.

## Other files

- `playwright-mcp.sh`: the web browser server (Playwright MCP) as a service; step 4 runs it, or run it alone: `sudo scripts/playwright-mcp.sh install | status | restart | uninstall`
- `mongo_users.js`: the users and roles created by step 3
- `haraka/`: NetFlow9's Haraka plugin and configuration, copied by step 2 (`dkim/dkim_key_gen.sh`: DKIM keys)
- `nginx/`: the app's nginx site, written by step 0 (see above)
- `daemon.sh`, `haraka.sh`: the old start / stop scripts, for an installation without the systemd units of step 4 (run them from this folder)
