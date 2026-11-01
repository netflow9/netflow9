// creates the Rail22 MongoDB users; passwords come from the environment (NFN_ADMIN_PW, NFN_APP_PW, NFN_HARAKA_PW), the database from NFN_DB (MONGO_DB of app.env)
// used by scripts/mongo.sh users, and by the Docker mongo container at its first start (compose.yaml)
const DB = process.env.NFN_DB || 'rail22-db';
const app = db.getSiblingDB(DB);
const admin = db.getSiblingDB('admin');

// run again (authorization still off): Rail22's users and role are dropped first, then created with the new passwords; other users are left alone
if (admin.getUser('nfn_admin')) admin.dropUser('nfn_admin');
if (app.getUser('nfn_app')) app.dropUser('nfn_app');
if (app.getUser('nfn_haraka')) app.dropUser('nfn_haraka');
if (app.getRole('nfnHaraka')) app.dropRole('nfnHaraka');

// operators: everything
admin.createUser({ user: 'nfn_admin', pwd: process.env.NFN_ADMIN_PW, roles: [ { role: 'root', db: 'admin' } ] });

// the PHP app (web, daemon, console, ./v sys::indexes): its own database only
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
