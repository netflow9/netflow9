<?php

return [
    //CLD: 2026-10-22T23:00:00+03:00: the installation's administration (super-admins: the accounts, their credits and tool servers, the services); first: the others add their sections
    'Admin',
    'Registration',
    //CLD: 2026-10-23T10:00:00+03:00: the System AI: the installation's model server and the accounts' credits, for the Assistant and the AI steps; before the Assistant (it uses it)
    'SystemAI',
    //CLD: 2026-10-19T20:10:00+03:00: the workflow designer's Assistant (was in the core)
    'Assistant',
    //CLD: 2026-10-19T21:10:00+03:00: inbound mail: the Email (inbound) trigger (was in the core); active only when Haraka is found
    'InboundMail',
    //CLD: 2026-10-20T23:40:00+03:00: the project's MCP server (/mcp), its access tokens and the AI steps' QSM tools (was in the core)
    'SysMCPTools',
    //CLD: 2026-10-21T10:00:00+03:00: LDAP: the accounts' sign-in with their directory (settings: Settings menu, LDAP sign-in), the LDAP server credential and the LDAP step
    'Ldap',
    //CLD: 2026-10-23T12:00:00+03:00: SAML 2.0 single sign-on: the accounts' sign-in at their identity provider (settings: Settings menu, SAML single sign-on; "Sign in with SSO" on the login page)
    'Saml',
    //CLD: 2026-10-22T00:35:40+03:00: Google: the Google credentials (OAuth: Mail, Drive, Sheets, Calendar, Docs) and steps (Google Drive, Google Sheets, Gmail, Google Calendar, Google Docs; were in the core)
    'GoogleIntegration',
    //CLD: 2026-10-22T00:41:28+03:00: Microsoft: Microsoft 365 Mail (IMAP; was in the core) and the Microsoft Graph credentials and steps (Outlook Mail, Outlook Calendar, OneDrive / SharePoint, Excel, Teams)
    'MicrosoftIntegration',
    //CLD: 2026-10-22T22:00:00+03:00: the installation's tool servers for the AI steps (MCP servers run on this machine, e.g. a web browser), for the accounts the administrator enables
    'LocalMCP',
];
