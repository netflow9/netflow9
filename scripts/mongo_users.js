// creates the NetFlow9 MongoDB users; passwords come from the environment (NFN_ADMIN_PW, NFN_APP_PW, NFN_HARAKA_PW)
const DB = 'netflow9-db';
const app = db.getSiblingDB(DB);
const admin = db.getSiblingDB('admin');

// operators: everything
admin.createUser({ user: 'nfn_admin', pwd: process.env.NFN_ADMIN_PW, roles: [ { role: 'root', db: 'admin' } ] });

// the PHP app (web, daemon, console, ./v setup::indexes): its own database only
app.createUser({ user: 'nfn_app', pwd: process.env.NFN_APP_PW, roles: [ { role: 'readWrite', db: DB } ] });

// Haraka (haraka/plugins/nfn.js): looks up the address, stores the message (GridFS filestore) and queues a job
const r = (coll, actions) => ({ resource: { db: DB, collection: coll }, actions });
app.createRole({
    role: 'nfnHaraka',
    privileges: [
        r('trigger_tokens', [ 'find' ]),
        r('accounts', [ 'find' ]),
        r('inbound_mail_settings', [ 'find' ]),
        r('filestore.files', [ 'find', 'insert', 'update', 'listIndexes', 'createIndex' ]),
        r('filestore.chunks', [ 'find', 'insert', 'listIndexes', 'createIndex' ]),
        r('jobs', [ 'insert' ]),
    ],
    roles: [],
});
app.createUser({ user: 'nfn_haraka', pwd: process.env.NFN_HARAKA_PW, roles: [ { role: 'nfnHaraka', db: DB } ] });

print('users: ' + admin.getUsers().users.map(u => u.user).concat(app.getUsers().users.map(u => u.user)).join(', '));
