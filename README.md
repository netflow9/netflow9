# Rail22

Rail22 is a self-hosted workflow automation platform. You draw a workflow on a canvas: a **trigger** that starts it and **steps** connected one after another, and Rail22 runs it each time the trigger fires: on a schedule, when an email or a web request arrives, when someone fills in a form or uploads a file, or when you press Run.

It is built on PHP and MongoDB, with plain JavaScript and Bootstrap in the browser, and runs on your own server.

## Strong points

- **A visual designer.** Add steps, connect them and set them up on a canvas. It saves as you work, shows errors before you run, and keeps drafts apart from published versions, which you can view and restore.
- **Triggers for every start:** Manual, Schedule, Webhook (signed requests, answers, retries), Form, File upload and Email (inbound, through Haraka).
- **Logic and data steps:** If, Switch, Loop, Merge, Set, Wait, Code, HTTP Request, emails, FTP / SFTP (with PGP and S/MIME), data tables and SQL databases (MySQL, PostgreSQL, SQL Server, Oracle, Firebird, ODBC).
- **People in the loop.** Forms start workflows, and *Human tasks* assign approvals and decisions to people, who answer them under *My tasks*.
- **AI built in.** AI steps work with Claude, OpenAI, Gemini, or models you run yourself (Ollama, Lemonade, LM Studio). They take files (images, PDF, Office), give JSON answers, use tools (MCP servers such as a sandboxed web browser) and answer from your own content. As agents, they use the integrations' operations (read ones by default) and other workflows as tools, and wait for a person's approval (My tasks) before they change something.
- **Tests for AI steps.** Save a run as a test case, or write one: recorded tool results, or real read tools, so nothing is sent or changed. Check the JSON answer, the tools called, the text, or have a model judge it. Compare with the last run, require a pass to publish, and run the tests from Claude Code over MCP.
- **Agents people talk to.** A workflow with a Chat trigger gets a chat page (and an embeddable link); email and Microsoft Teams work too. Conversations carry over, older turns are summarized, and an agent can remember facts in a data table or a knowledge base. Approvals, limits and the audit log apply to everything it does.
- **Knowledge bases (RAG).** Files, web pages and texts are cut into chunks, embedded and searched by meaning and by words, from workflows and AI steps.
- **The Assistant** builds and changes workflows from a description in your own words. An **MCP server** lets AI assistants such as Claude Desktop and Claude Code work with a project.
- **Integrations** for Google (Drive, Sheets, Gmail, Calendar), Microsoft 365 (Outlook, OneDrive / SharePoint, Excel, Teams), LDAP directories and any HTTP API.
- **Safe expressions.** `{{ }}` expressions and the Code step use J, a safe JavaScript subset.
- **Secure by design.** Credentials are kept encrypted (OAuth connections, API keys, passwords) and never appear in a workflow. Roles and permissions are per project. Sign-in supports two-factor codes, LDAP and SAML single sign-on (Entra ID, Okta, Google Workspace, ADFS, Keycloak), and every action goes to an audit log.
- **Visible runs.** Every execution has its state, a log of what each step did, its variables and its error. Each project has a dashboard of what ran, failed, is running or is waiting.
- **Multi-account and extensible.** Accounts, projects and members, plans and limits, and a super-admin area. Tool servers (MCP servers of the installation, such as a web browser, in `mcp.json`) are offered to the AI steps. Plugins add inbound mail, a System AI paid with credits and registration, and new step types can be written in PHP.
- **One-command install** on AlmaLinux / Rocky / RHEL 9 (below), or a setup wizard in the browser on any Linux.

The full documentation is in the app, under Docs (`app/data/docs.json`).

## Installation

### Requirements

- PHP 8.1 or later (PHP-FPM and command line, same version), with the extensions `mongodb` (2.3.0+), `openssl`, `mbstring`, `curl`, `json`, `iconv`, `simplexml`, `zip`, `ctype` and `session`, plus `pcntl` and `posix` on the command line (the daemon)
- MongoDB (8.3 or later for knowledge bases)
- nginx with PHP-FPM, over HTTPS
- an SMTP server for outgoing email
- recommended: Node.js (inbound mail, the web browser tool server), systemd and polkit (services), `ldap`, `zlib`, `bz2` and the `pdo_*` extensions of the databases you connect to

The installer and the browser setup check these and say what is missing.

### With the installer (AlmaLinux / Rocky / RHEL 9)

On a new AlmaLinux / Rocky / RHEL 9 server, as root, from any folder:

```sh
curl -fsSLO https://raw.githubusercontent.com/toolmk/rail22-core/main/install.sh
sudo bash install.sh
```

It asks first:

- **the server**: the site's domain (its DNS record points to the server), the system user Rail22 runs as (its home holds the app), the repository and branch to install from
- **options**: MongoDB on this server (else the connection string of yours), a Let's Encrypt certificate (when the domain points here; else a self-signed one), the web browser for the AI steps, inbound email (and its domain), securing MongoDB (its own users, sign-in required), SELinux permissive (when it is enforcing)

Then it shows what it will do, asks to go on, and:

1. installs the packages that are missing: EPEL, Remi's PHP 8.5 with the app's extensions, nginx, MongoDB 8.3, Redis, certbot, Node.js (the newest stream of the distribution; for the web browser and inbound email)
2. creates the user and its home, and clones the repository there (with its submodules)
3. writes php.ini, the app's PHP-FPM pool and logrotate (`scripts/system/`), the certificate and the nginx site
4. sets up the app: it asks the database, the outgoing email and the first account (company or team, its owner); the app checks the answers itself (`app/v sys::validate`) and asks again when one is wrong
5. installs the options and the background services (the parts below)

The firewall is not changed: open 80 and 443 (and 25 for inbound email). Run it again, e.g. `sudo bash /home/<user>/install.sh`, to add an option later or to update: the app's settings and account are kept, and what is done is left as it is. It first offers to update the app's code from git (`<n> new commits`), when its working tree has no local changes and its branch can fast-forward, then starts again from the new code; the parts below then update what they install.

The setup in the browser (every address of the site while `app.env` is missing) does step 4 instead; run `install.sh` after it for the options and the services.

### The parts

`install.sh` runs them in this order; each can also be run alone, as root, from anywhere (each finds the app's folder from its own place). Each takes a command, and `status` shows its state:

| Script | Does | Needs |
|--------|------|-------|
| `sudo scripts/nginx.sh install` | nginx (when missing) and the app's site (the template in the script, filled in) over `<app>/etc/nginx.conf` (`/etc/nginx/conf.d/<user>.conf`); checked with `nginx -t`, then nginx reloaded. Also `setup` (the site only, `--dry-run`), `start`, `stop`, `restart`, `reload`, `status`, `test`, `uninstall` (the site out) | nginx; `BASE_URL` in `app.env` (or `--server-name=`); the certificate in `<app>/ssl/` |
| `app/v sys::install` (as the app's user) | The app: the database indexes, the first account and its owner, `app.env` (the answers as JSON on stdin) | no `app.env` yet |
| `sudo scripts/haraka.sh install` | Haraka, for inbound mail, into `<app>/haraka` (its files stay the app's user's), and its unit `nfn-haraka` (one per server: port 25), started. Also `start`, `stop`, `restart`, `status`, `uninstall` (the unit; the files stay) | `app.env`; `MAIL_INBOUND_DOMAIN` there (or `--domain=`); system-wide Node.js |
| `sudo scripts/mongo.sh secure` | MongoDB users `nfn_admin`, `nfn_app`, `nfn_haraka`; their passwords into `app.env` (`MONGO_URI`, `HARAKA_MONGO_URI`) and, with Haraka, `haraka/config/nfn.ini`; then sign-in required (`/etc/mongod.conf`, mongod restarted) and the app's services restarted. Also `users`, `auth` (the two halves), `start`, `stop`, `restart`, `status` | `app.env`; MongoDB on this machine with authorization still off |
| `sudo scripts/daemon.sh install` | The workflow daemon's unit `nfn-daemon-<user>`, started, and the polkit rule that lets the Services page start / stop / restart the installation's units (`nfn-<name>-<user>`, and `nfn-haraka` when the installation has it). Also `start`, `stop`, `restart`, `status` (all the installation's services), `rule`, `uninstall` | polkit |
| `sudo scripts/playwright-mcp.sh install` | The AI steps' web browser (Playwright MCP, its Chromium) as the unit `nfn-playwright-<user>`, and its entry in `mcp.json`. Also `start`, `stop`, `restart`, `status`, `uninstall` | system-wide Node.js |

They can be run again: nothing already installed is overwritten (`haraka.sh` updates Rail22's plugin). `mongo.sh secure` too, while MongoDB does not require sign-in: it creates its users again with new passwords; once authorization is on, it stops.
The operators' MongoDB password is left in `<app>/.mongodb-admin` (root's): move it to a password manager and delete the file.

Options: `--dry-run` (`nginx.sh setup`, `haraka.sh install`) prints what would be done; `--help` lists the others.
Check the services with `scripts/daemon.sh status`.

### Web server (nginx)

The app's nginx site is a template inside `scripts/nginx.sh`: it fills in the app's folder (`@BASE@`) and the host of `BASE_URL` (`@SERVER_NAME@`) and writes it where `<app>/etc/nginx.conf` points (`/etc/nginx/conf.d/<user>.conf`; the link is made when missing).
It shows the changes first (`--dry-run`: only that), keeps the old file as `<user>.conf.bak-<time>`, and puts it back when `nginx -t` fails.
`/etc/nginx/nginx.conf` stays as the system installed it (it loads `conf.d/*.conf`). The certificate goes in `<app>/ssl/ssl.crt` and `ssl.key`.

### Other files in `scripts/`

- `playwright/`: the web browser's entry in `mcp.json` (`mcp-server.json`; the tool servers, next to `app.env`: `./v mcpserver::list`)

- `mongo_users.js`: the users and roles created by `mongo.sh users`, and by the Docker `mongo` container at its first start
- `haraka/`: Rail22's Haraka plugin and configuration, copied by `haraka.sh install` into `<app>/haraka` (`dkim/dkim_key_gen.sh`: DKIM keys)
- `system/`: `php.ini`, the PHP-FPM pool (`fpm-pool.conf`) and `logrotate.conf`, written by `install.sh`

### With Docker Compose

On any Linux host with Docker Engine and its Compose plugin (2.24 or later); the images are built locally from the repository:

```bash
git clone --recurse-submodules https://github.com/toolmk/rail22-core.git && cd rail22
scripts/docker.sh install                         # .env (random passwords, your uid / gid), build, start; then open the site: /setup
```

Or step by step: `scripts/docker.sh setup [--dev] [--tls] [--no-browser] [--no-mail]` writes `.env` (the web browser and inbound mail are on unless left out; `MAIL_INBOUND_DOMAIN` defaults to this host's name) (an existing one keeps its passwords; the compose files go in `COMPOSE_FILE`, so plain `docker compose` uses the same ones), then `build`, `start`; also `stop`, `restart`, `status`, `logs`, `check`, `uninstall` (volumes kept), `purge` (all data). By hand: `cp docker/env.example .env`, fill it in, `docker compose build && docker compose up -d`.

- Containers: `mongo` (8.3, sign-in required; its users from `scripts/mongo_users.js`), `app` (PHP-FPM, AlmaLinux 9 + Remi, the installer's packages), `daemon`, `web` (nginx, port 80); profiles `browser` (`playwright`: the AI steps' web browser, with its own firewall table) and `mail` (`haraka`, port 25; `127.0.0.1:2525` with `compose.dev.yaml`), in `COMPOSE_PROFILES`: both on by default
- `app.env`, `plugins.json` and `mcp.json` live in the `config` volume (`NFN_CONFIG_DIR`); keep a copy of `app.env` (`APP_SECRET`)
- The Services page is read-only there: `docker compose restart daemon`; the console: `docker compose exec app app/v <command>`
- Upgrade: `git pull && git submodule update --init && docker compose build && docker compose up -d` (the app adds the new indexes at its start)
- HTTPS in the container: `-f compose.tls.yaml` (`ssl/ssl.crt`, `ssl/ssl.key`); development: `-f compose.dev.yaml` (the working tree mounted, mailpit, the site on `127.0.0.1:8080`)
- Files: `compose*.yaml`, `docker/` (the images, `env.example`), `.dockerignore`, `scripts/docker.sh`. More: the docs, Self-hosting > Docker Compose

### By hand (other systems)

Install PHP, its extensions, MongoDB, nginx with a PHP-FPM pool running as the app's user, and polkit. Then clone the app into that user's home, with its submodules (the libraries in `app/pkgs`):

```sh
git clone --recurse-submodules https://github.com/toolmk/rail22-core.git /home/app
sudo /home/app/scripts/nginx.sh install --server-name=netflow.example.com
```

Open the site. While there is no `app.env`, every address shows the setup wizard. It checks the prerequisites, then asks for the server settings (public address, MongoDB, outgoing email) and the first account with its owner. After that, install the options and the services with the scripts in `scripts/` (see *The parts* above).

### After installing

- Keep a copy of `app.env` in a safe place. Its `APP_SECRET` encrypts the stored credentials, and they cannot be read without it.
- Check the installation with the check suite: `cd app/commands && ./CheckCommand.sh`.
- Upgrade: back up, run `git pull` and `git submodule update --init`, then create any new indexes with `cd app/commands && ../v sys::indexes`, and restart PHP-FPM and the daemon with `sudo systemctl restart php-fpm nfn-daemon`.
