'use strict';

/*
the NetFlow9 plugin: mail for the Email (inbound) triggers of workflows.

    hook_rcpt	a recipient of the inbound domain (nfn.ini [nfn] domain = NetFlow9's MAIL_INBOUND_DOMAIN) is <account mail_prefix>-<8-character code>: accepted when the code
		is a published Email inbound step (collection trigger_tokens, type trigger::email)
		the prefix is in inbound_mail_settings; other domains are left to the next plugins
    hook_queue	for each accepted recipient: the raw message stored as a NetFlow9 project File (GridFS bucket `filestore`, as models\File stores files: metadata aid / project
		/ owner / source / wf / created_at, aid and project also at the top level),
		then a job (collection jobs: \models\node\NodeTriggerEmail::deliver { file, token, rcpt, mail_from }),
		and the daemon woken (NEWJOB on its socket)

		+ aliases to external addresses (e.g. admin@<domain> -> someone@gmail.com),
		with Haraka's `aliases` plugin and its config/aliases, listed BEFORE nfn in config/plugins:
		note_rcpt (priority -100, before `aliases`) notes each recipient as it came;
		hook_rcpt accepts a recipient of the inbound domain that `aliases` rewrote to another domain (any other foreign recipient is still left to the next plugins: no open relay);
		hook_queue sends the message to those addresses through Haraka's outbound queue
		(envelope sender: nfn.ini [nfn] forward_from, else the original sender)

		+ hook_bounce: each bounce (outbound mail that failed for good) saved in nfn.ini [nfn] bounce_dir;
		the bounce is still sent as before

		+ inbound bounces: mail to postmaster@<domain> (and to forward_from, when of the domain) is accepted when bounce_dir is set and saved there
		only null-sender mail (MAIL FROM:<>) to postmaster is accepted (<time>_<uuid>.eml and .json), in hook_queue and hook_queue_outbound

Config: config/nfn.ini. MongoDB driver: node_modules/mongodb (3.x).
*/

const { MongoClient, GridFSBucket } = require('mongodb');
const net = require('net');
const fs = require('fs').promises;
const path = require('path');
const { Readable } = require('stream');

const JOB_SPEC = '\\plugins\\InboundMail\\models\\NodeTriggerEmail::deliver';
const LOCAL_PART = /^([a-z0-9](?:[a-z0-9-]{0,28}[a-z0-9])?)-([a-z0-9]{8})$/;

let cfg = null;
let db = null;

exports.register = function () {
    cfg = this.config.get('nfn.ini', () => this.register());
    cfg.mongodb = cfg.mongodb || {};
    cfg.nfn = cfg.nfn || {};
    this.register_hook('init_master', 'connect');
    this.register_hook('init_child', 'connect');
    this.register_hook('rcpt', 'note_rcpt', -100);
};

exports.note_rcpt = function (next, connection, params) {
    if (connection.transaction)
        connection.transaction.notes.nfn_rcpt = String(params[0].address || '').toLowerCase();
    next();
};

exports.connect = async function (next) {
    await this.db();
    next();
};

// the NetFlow9 database (connected once, again after a failure)
exports.db = async function () {
    if (db)
        return db;
    try {
        const client = new MongoClient(cfg.mongodb.uri || 'mongodb://127.0.0.1:27017/', { useUnifiedTopology: true });
        await client.connect();
        db = client.db(cfg.mongodb.db);
        this.lognotice('nfn: connected to MongoDB');
    } catch (e) {
        this.logerror('nfn: MongoDB connection failed: ' + e.message);
        db = null;
    }
    return db;
};

exports.hook_rcpt = async function (next, connection, params) {
    const txn = connection.transaction;
    const rcpt = params[0];
    const domain = String(cfg.nfn.domain || '').toLowerCase();

    // CLD: 2026-10-15T14:04:51+03:00: an address of the domain that `aliases` rewrote to another domain: accepted, forwarded at queue time
    const came = txn ? String(txn.notes.nfn_rcpt || '') : '';
    if (txn && domain && came.endsWith('@' + domain) && String(rcpt.host || '').toLowerCase() !== domain) {
        this.loginfo('nfn: accepted ' + came + ' (alias of ' + rcpt.address + ')');
        return next(OK);
    }
    if (!txn || !domain || String(rcpt.host || '').toLowerCase() !== domain)
        return next();

    const address = String(rcpt.user || '').toLowerCase() + '@' + domain;
    // CLD: 2026-10-18T09:40:00+03:00: postmaster (bounces from other servers): accepted, saved in bounce_dir at queue time
    // CLD: 2026-10-18T09:55:00+03:00: only null-sender mail (bounces); other mail to postmaster is refused as before (unknown address)
    const nullSender = !txn.mail_from || !txn.mail_from.address;
    if (cfg.nfn.bounce_dir && nullSender && this.postmasters(domain).has(address)) {
        txn.notes.nfn_postmaster = (txn.notes.nfn_postmaster || []).concat(address);
        this.loginfo('nfn: accepted ' + address + ' (bounces)');
        return next(OK);
    }
    const m = String(rcpt.user || '').toLowerCase().match(LOCAL_PART);
    if (!m)
        return next(DENY, 'unknown address: ' + address);

    const d = await this.db();
    if (!d)
        return next(DENYSOFT, 'temporarily unavailable');
    try {
        // CLD: 2026-10-17T15:35:00+03:00: the step type is trigger::email (was trigger::inbound)
        const entry = await d.collection('trigger_tokens').findOne({ type: 'trigger::email', token: m[2] });
        const account = entry ? await d.collection('accounts').findOne({ _id: entry.aid }) : null;
        const settings = entry ? await d.collection('inbound_mail_settings').findOne({ aid: entry.aid }) : null;
        const prefix = settings ? String(settings.prefix || '') : String((account && account.mail_prefix) || '');
        if (!account || prefix !== m[1] || account.state === 'disabled')
            return next(DENY, 'unknown address: ' + address);
        txn.notes.nfn = txn.notes.nfn || [];
        txn.notes.nfn.push({ rcpt: address, token: m[2], aid: entry.aid, project: entry.project, wf: entry.wf });
        this.loginfo('nfn: accepted ' + address);
        return next(OK);
    } catch (e) {
        this.logerror('nfn: recipient check failed: ' + e.message);
        return next(DENYSOFT, 'temporarily unavailable');
    }
};

exports.hook_queue = function (next, connection) {
    const txn = connection.transaction;
    const targets = txn.notes.nfn || [];
    // CLD: 2026-10-15T14:04:51+03:00: the recipients of another domain are alias targets (hook_rcpt accepted no other foreign recipient)
    const domain = String(cfg.nfn.domain || '').toLowerCase();
    const forward = txn.rcpt_to.filter((a) => String(a.host || '').toLowerCase() !== domain);
    // CLD: 2026-10-18T09:40:00+03:00: the postmaster recipients (hook_rcpt)
    const postmaster = txn.notes.nfn_postmaster || [];
    if (!targets.length && !forward.length && !postmaster.length)
        return next();

    txn.message_stream.get_data(async (raw) => {
        if (forward.length) {
            const sent = await new Promise((resolve) => {
                const outbound = this.haraka_require('./outbound');
                const from = cfg.nfn.forward_from || (txn.mail_from ? String(txn.mail_from.address || '') : '') || ('postmaster@' + domain);
                outbound.send_email(from, forward.map((a) => a.address), Readable.from([raw]), (code, msg) => {
                    this.loginfo('nfn: forwarded to ' + forward.map((a) => a.address).join(', ') + ': ' + msg);
                    resolve(code === OK);
                });
            });
            if (!sent)
                return next(DENYSOFT, 'temporarily unavailable');
            // CLD: 2026-10-18T09:40:00+03:00: not done yet when there are postmaster recipients
            if (!targets.length && !postmaster.length)
                return next(OK, 'forwarded');
        }
        // CLD: 2026-10-18T09:40:00+03:00: mail to postmaster saved in bounce_dir
        if (postmaster.length) {
            if (!(await this.save_inbound_bounce(txn, raw, postmaster)))
                return next(DENYSOFT, 'temporarily unavailable');
            if (!targets.length)
                return next(OK, 'saved');
        }
        // CLD: 2026-10-16T12:04:51+03:00: storing is store() (shared with hook_queue_outbound)
        if (!(await this.store(txn, raw, targets)))
            return next(DENYSOFT, 'temporarily unavailable');
        return next(OK, 'queued for NetFlow9');
    });
};

// CLD: 2026-10-16T12:04:51+03:00: mail relayed by Haraka (e.g. sent by itself through 127.0.0.1: the Send Email step) skips the `queue` hook and goes to outbound, which
// finds netflow9.io's MX is this server and gives up ("MX is local"): its recipients are stored here and taken off the transaction; outbound delivers the others
exports.hook_queue_outbound = function (next, connection) {
    const txn = connection.transaction;
    const targets = txn.notes.nfn || [];
    // CLD: 2026-10-18T09:40:00+03:00: + the postmaster recipients (hook_rcpt): saved in bounce_dir, taken off the transaction too
    const postmaster = txn.notes.nfn_postmaster || [];
    if (!targets.length && !postmaster.length)
        return next();
    txn.message_stream.get_data(async (raw) => {
        if (targets.length && !(await this.store(txn, raw, targets)))
            return next(DENYSOFT, 'temporarily unavailable');
        if (postmaster.length && !(await this.save_inbound_bounce(txn, raw, postmaster)))
            return next(DENYSOFT, 'temporarily unavailable');
        const ours = new Set(targets.map((t) => t.rcpt).concat(postmaster));
        txn.rcpt_to = txn.rcpt_to.filter((a) => !ours.has(String(a.address || '').toLowerCase()));
        return txn.rcpt_to.length ? next() : next(OK, 'queued for NetFlow9');
    });
};

// CLD: 2026-10-16T12:04:51+03:00: the message for each recipient: a project File, a job, the daemon woken; false when it could not be stored
exports.store = async function (txn, raw, targets) {
    const d = await this.db();
    if (!d)
        return false;
    const mailFrom = txn.mail_from ? String(txn.mail_from.address || '') : '';
    try {
        const bucket = new GridFSBucket(d, { bucketName: 'filestore' });
        for (const t of targets) {
            const metadata = { aid: t.aid, project: t.project, owner: null, source: 'inbound', wf: t.wf, created_at: new Date() };
            const id = await new Promise((resolve, reject) => {
                const up = bucket.openUploadStream('message-' + txn.uuid + '.eml', { contentType: 'message/rfc822', metadata });
                up.on('error', reject);
                up.on('finish', () => resolve(up.id));
                up.end(raw);
            });
            await d.collection('filestore.files').updateOne({ _id: id }, { $set: { aid: t.aid, project: t.project } });
            await d.collection('jobs').insertOne({
                job_spec: JOB_SPEC,
                job_data: { file: String(id), token: t.token, rcpt: t.rcpt, mail_from: mailFrom },
                aid: t.aid,
                project: t.project,
                state: 'new',
                run_count: 0,
                delete_on_done: true,
                created_at: new Date(),
            });
            this.loginfo('nfn: queued ' + t.rcpt + ' as file ' + id);
        }
    } catch (e) {
        this.logerror('nfn: storing the message failed: ' + e.message);
        return false;
    }
    // wake the daemon; when it does not run, it finds the jobs when it starts
    if (cfg.nfn.job_socket) {
        const s = net.createConnection(cfg.nfn.job_socket, () => s.end('NEWJOB\n'));
        s.on('error', () => {});
    }
    return true;
};

// a bounce saved in nfn.ini [nfn] bounce_dir: <time>_<uuid>.eml, the original message (from the queue file, after its todo header), and
// <time>_<uuid>.json, the details; Haraka then sends the bounce as usual (next() without a code)
exports.hook_bounce = async function (next, hmail, error) {
    const dir = String(cfg.nfn.bounce_dir || '');
    if (!dir)
        return next();
    try {
        const todo = hmail.todo || {};
        const queued = await fs.readFile(hmail.path);
        await this.save_bounce(todo.uuid || hmail.filename, queued.subarray(hmail.data_start || (queued.readUInt32BE(0) + 4)), {
            direction: 'outbound',
            error: String(error && error.message ? error.message : error),
            mail_from: todo.mail_from ? String(todo.mail_from.original || todo.mail_from) : '',
            rcpt_to: (todo.rcpt_to || []).map((r) => ({ address: String(r.original || r), reason: r.reason || null })),
            domain: todo.domain || null,
            queue_file: hmail.filename,
            bounced_at: new Date(),
        });
    } catch (e) {
        this.logerror('nfn: saving the bounce failed: ' + e.message);
    }
    next();
};

// the postmaster addresses of the domain: postmaster@<domain> and nfn.ini [nfn] forward_from when it is of the domain
exports.postmasters = function (domain) {
    const set = new Set(['postmaster@' + domain]);
    const from = String(cfg.nfn.forward_from || '').toLowerCase();
    if (from.endsWith('@' + domain))
        set.add(from);
    return set;
};

// a message received for postmaster saved in bounce_dir; false when it could not be saved
exports.save_inbound_bounce = async function (txn, raw, rcpts) {
    try {
        await this.save_bounce(txn.uuid, raw, {
            direction: 'inbound',
            mail_from: txn.mail_from ? String(txn.mail_from) : '',
            rcpt_to: rcpts.map((a) => ({ address: a })),
            received_at: new Date(),
        });
        return true;
    } catch (e) {
        this.logerror('nfn: saving the inbound bounce failed: ' + e.message);
        return false;
    }
};

// writes <time>_<id>.eml (the message) and <time>_<id>.json (the details) in bounce_dir
exports.save_bounce = async function (id, message, details) {
    const dir = String(cfg.nfn.bounce_dir || '');
    const name = new Date().toISOString().replace(/[:.]/g, '-') + '_' + id;
    await fs.mkdir(dir, { recursive: true });
    await fs.writeFile(path.join(dir, name + '.eml'), message);
    await fs.writeFile(path.join(dir, name + '.json'), JSON.stringify(details, null, 2));
    this.loginfo('nfn: bounce saved as ' + path.join(dir, name));
};
