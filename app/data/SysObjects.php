<?php

namespace data;

class SysObjects {
    //-------------------------------------------------------------------------------
    //system objects: declaration needed here to use management infrastructure only; not user selectable or else
    public static function get() {
	return [
	    //fields: base (Field) and sub-classes
	    'Field' => [
		'class' => 'sys\fields\Field',
	    ],
	    'Field_text' => [
		'class' => 'sys\fields\Field_text',
		'field' => true
	    ],
	    'Field_textarea' => [
		'class' => 'sys\fields\Field_textarea',
		'field' => true
	    ],
	    'Field_number' => [
		'class' => 'sys\fields\Field_number',
		'field' => true
	    ],
	    'Field_email' => [
		'class' => 'sys\fields\Field_email',
		'field' => true
	    ],
	    'Field_url' => [
		'class' => 'sys\fields\Field_url',
		'field' => true
	    ],
	    'Field_checkbox' => [
		'class' => 'sys\fields\Field_checkbox',
		'field' => true
	    ],
	    'Field_switch' => [
		'class' => 'sys\fields\Field_switch',
		'field' => true
	    ],
	    'Field_datetime' => [
		'class' => 'sys\fields\Field_datetime',
		'field' => true
	    ],
	    'Field_date' => [
		'class' => 'sys\fields\Field_date',
		'field' => true
	    ],
	    'Field_select' => [
		'class' => 'sys\fields\Field_select',
		'field' => true
	    ],
	    'Field_object' => [
		'class' => 'sys\fields\Field_object',
		'field' => true
	    ],


	    'Account' => [
		'class' => 'models\Account',
	    ],
	    'File' => [
		'class' => 'models\File',
		'userSelectable' => true,
	    ],
	    'User' => [
		'class' => 'models\User',
		'userSelectable' => true,
	    ],
	    //CLD: 2026-10-24T08:20:00+03:00: removed PasswordReset: the "forgot password" link is the user's (User reset_*)
	    'ProjectInvite' => [
		'class' => 'models\user\ProjectInvite',
		'auditLog' => false,
	    ],
	    //CLD: 2026-10-21T18:00:00+03:00: the sign-in throttle (failed attempts and locks; SiteController login / tfa)
	    'LoginThrottle' => [
		'class' => 'models\user\LoginThrottle',
		'auditLog' => false,
	    ],


	    'ObjectDef' => [
		'class' => 'sys\ObjectDef',
	    ],
	    'Role' => [
		'class' => 'models\Role',
	    ],
	    'Project' => [
		'class' => 'models\Project',
	    ],
	    //CLD: 2026-10-22T00:28:13+03:00: Variable removed: the project variables are credentials of type Variable (CredentialVariable, below)
	    'Workflow' => [
		'class' => 'models\Workflow',
	    ],
	    'WfNode' => [
		'class' => 'models\wf\WfNode',
	    ],
	    'WfEdge' => [
		'class' => 'models\wf\WfEdge',
	    ],
	    'WfExecution' => [
		'class' => 'models\wf\WfExecution',
		'auditLog' => false,
	    ],
	    //CLD: 2026-10-11T13:51:42+03:00: published versions of workflows
	    'WfVersion' => [
		'class' => 'models\wf\WfVersion',
		'auditLog' => false,
	    ],
	    'AuditLog' => [
		'class' => 'models\AuditLog',
		'auditLog' => false,
	    ],
	    //CLD: 2026-10-11T17:45:25+03:00: the execution log (never recorded in either log)
	    'ExecutionLog' => [
		'class' => 'models\ExecutionLog',
		'auditLog' => false,
	    ],
	    'Job' => [
		'class' => 'models\Job',
		'auditLog' => false,
	    ],
	    //CLD: 2026-10-16T11:26:09+03:00: the token index of the token triggers (Webhook, Form, Email inbound; was registered by the BaseNodes plugin); rebuilt from the workflows: not in
	    //the audit log
	    //CLD: 2026-10-19T11:21:19+03:00: Signup is registered by the Registration plugin
	    'TriggerToken' => [
		'class' => 'models\TriggerToken',
		'auditLog' => false,
	    ],
	    //CLD: 2026-10-30T15:00:00+02:00: ExecutionSlot removed (sync executions: every execution runs in the daemon)
	    'DataTableDef' => [
		'class' => 'models\datatable\DataTableDef',
	    ],
	    //CLD: 2026-10-30T17:18:20+02:00: the saved views of a data table (named filters)
	    'DataTableView' => [
		'class' => 'models\datatable\DataTableView',
	    ],
	    //CLD: 2026-10-30T23:30:00+02:00: the knowledge bases (documents cut into chunks, searched by meaning and by words: the Knowledge base step, the AI steps' tool); their documents and
	    //chunks are written in bulk, not in the audit log
	    'KnowledgeBase' => [
		'class' => 'models\kb\KnowledgeBase',
	    ],
	    'KbDocument' => [
		'class' => 'models\kb\KbDocument',
		'auditLog' => false,
	    ],
	    'KbChunk' => [
		'class' => 'models\kb\KbChunk',
		'auditLog' => false,
	    ],
	    //CLD: 2026-10-31T01:00:00+02:00: the account's embedding model (the knowledge bases': Settings, Knowledge bases)
	    'EmbeddingSettings' => [
		'class' => 'models\kb\EmbeddingSettings',
	    ],
	    //CLD: 2026-10-20T23:40:00+03:00: McpToken (access tokens of the project's MCP server) is registered by the SysMCPTools plugin
	    //CLD: 2026-10-26T23:56:07+02:00: core again (the project's MCP server, controllers\McpController; same object name: stored tokens, audit entries and roles keep working)
	    'McpToken' => [
		'class' => 'models\McpToken',
	    ],

	    'Credential' => [
		'class' => 'models\Credential',
		'has-variants' => true,
	    ],
	    'CredentialApiKey' => [
		'class' => 'models\credential\CredentialApiKey',
		'variant-of' => 'Credential',
	    ],
	    'CredentialBasicAuth' => [
		'class' => 'models\credential\CredentialBasicAuth',
		'variant-of' => 'Credential',
	    ],
	    'CredentialBearer' => [
		'class' => 'models\credential\CredentialBearer',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-10-18T12:00:00+03:00: was CredentialLemonade (any OpenAI-compatible API)
	    'CredentialOpenAI' => [
		'class' => 'models\credential\CredentialOpenAI',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-10-22T01:36:46+03:00: a Lemonade server (OpenAI-compatible; the Lemonade AI step) and an Ollama server (the Ollama step)
	    'CredentialLemonade' => [
		'class' => 'models\credential\CredentialLemonade',
		'variant-of' => 'Credential',
	    ],
	    'CredentialLmStudio' => [
		'class' => 'models\credential\CredentialLmStudio',
		'variant-of' => 'Credential',
	    ],
	    'CredentialOllama' => [
		'class' => 'models\credential\CredentialOllama',
		'variant-of' => 'Credential',
	    ],
	    'CredentialAnthropic' => [
		'class' => 'models\credential\CredentialAnthropic',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-10-22T12:00:00+03:00: the Google Gemini API (the Gemini step)
	    'CredentialGemini' => [
		'class' => 'models\credential\CredentialGemini',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-10-12T21:51:51+03:00: an MCP server whose tools the AI steps can use (util\McpClient)
	    'CredentialMcp' => [
		'class' => 'models\credential\CredentialMcp',
		'variant-of' => 'Credential',
	    ],
	    'CredentialImap' => [
		'class' => 'models\credential\CredentialImap',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-10-26T23:39:49+02:00: the Google credentials (OAuth: Mail, Drive, Sheets, Calendar, Docs), core for every account again (were registered by the GoogleIntegration plugin, plan feature google)
	    'CredentialGoogleMail' => [
		'class' => 'models\credential\CredentialGoogleMail',
		'variant-of' => 'Credential',
	    ],
	    'CredentialGoogleDrive' => [
		'class' => 'models\credential\CredentialGoogleDrive',
		'variant-of' => 'Credential',
	    ],
	    'CredentialGoogleSheets' => [
		'class' => 'models\credential\CredentialGoogleSheets',
		'variant-of' => 'Credential',
	    ],
	    'CredentialGoogleCalendar' => [
		'class' => 'models\credential\CredentialGoogleCalendar',
		'variant-of' => 'Credential',
	    ],
	    'CredentialGoogleDocs' => [
		'class' => 'models\credential\CredentialGoogleDocs',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-10-26T23:39:49+02:00: the Microsoft credentials (Microsoft 365 Mail over IMAP, and the Microsoft Graph ones: Outlook Mail, Outlook Calendar, OneDrive & SharePoint, Teams), core for every
	    //account again (were registered by the MicrosoftIntegration plugin, plan feature microsoft)
	    'CredentialMicrosoftMail' => [
		'class' => 'models\credential\CredentialMicrosoftMail',
		'variant-of' => 'Credential',
	    ],
	    'CredentialMicrosoftOutlook' => [
		'class' => 'models\credential\CredentialMicrosoftOutlook',
		'variant-of' => 'Credential',
	    ],
	    'CredentialMicrosoftCalendar' => [
		'class' => 'models\credential\CredentialMicrosoftCalendar',
		'variant-of' => 'Credential',
	    ],
	    'CredentialMicrosoftFiles' => [
		'class' => 'models\credential\CredentialMicrosoftFiles',
		'variant-of' => 'Credential',
	    ],
	    'CredentialMicrosoftTeams' => [
		'class' => 'models\credential\CredentialMicrosoftTeams',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-10-20T16:00:00+03:00: an FTP / FTPS / SFTP server and a key to decrypt files with (the FTP / SFTP step)
	    'CredentialFtp' => [
		'class' => 'models\credential\CredentialFtp',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-10-25T20:00:00+02:00: the databases (the Database step), core for every account (were registered by the Database plugin, plan feature database); each offered when PHP
	    //has its PDO driver (Credential::available())
	    'CredentialMysql' => [
		'class' => 'models\credential\CredentialMysql',
		'variant-of' => 'Credential',
	    ],
	    'CredentialPostgres' => [
		'class' => 'models\credential\CredentialPostgres',
		'variant-of' => 'Credential',
	    ],
	    'CredentialMssql' => [
		'class' => 'models\credential\CredentialMssql',
		'variant-of' => 'Credential',
	    ],
	    'CredentialOracle' => [
		'class' => 'models\credential\CredentialOracle',
		'variant-of' => 'Credential',
	    ],
	    'CredentialFirebird' => [
		'class' => 'models\credential\CredentialFirebird',
		'variant-of' => 'Credential',
	    ],
	    'CredentialOdbc' => [
		'class' => 'models\credential\CredentialOdbc',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-11-01T08:00:00+02:00: a Checkmk site (the Checkmk steps and problem trigger), core for every account
	    'CredentialCheckmk' => [
		'class' => 'models\credential\CredentialCheckmk',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-11-01T14:00:00+02:00: a Zabbix server (the Zabbix steps and problem trigger), core for every account
	    'CredentialZabbix' => [
		'class' => 'models\credential\CredentialZabbix',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-11-03T10:00:00+02:00: a Wazuh installation (the Wazuh steps and alert trigger), core for every account
	    'CredentialWazuh' => [
		'class' => 'models\credential\CredentialWazuh',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-11-01T06:00:00+02:00: a FortiGate firewall (the FortiGate steps), core for every account
	    'CredentialFortiGate' => [
		'class' => 'models\credential\CredentialFortiGate',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-11-01T10:00:00+02:00: a Sophos Firewall (the Sophos Firewall steps: its XML API), core for every account
	    'CredentialSophos' => [
		'class' => 'models\credential\CredentialSophos',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-11-01T16:00:00+02:00: a Hyper-V host (the Hyper-V steps and VM state trigger; PowerShell over WinRM), core for every account
	    'CredentialHyperV' => [
		'class' => 'models\credential\CredentialHyperV',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-11-01T14:00:00+02:00: a Proxmox VE cluster or server (the Proxmox steps and task trigger), core for every account
	    'CredentialProxmox' => [
		'class' => 'models\credential\CredentialProxmox',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-11-01T12:00:00+02:00: a VMware vCenter Server (the VMware steps and alarm trigger), core for every account
	    'CredentialVmware' => [
		'class' => 'models\credential\CredentialVmware',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-11-01T04:00:00+02:00: a Veeam Backup & Replication server (the Veeam steps and event trigger), core for every account
	    'CredentialVeeam' => [
		'class' => 'models\credential\CredentialVeeam',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-11-01T00:30:00+02:00: a Bitdefender GravityZone console (the GravityZone steps and event trigger), core for every account
	    'CredentialGravityZone' => [
		'class' => 'models\\credential\\CredentialGravityZone',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-10-25T19:30:00+02:00: an LDAP server (the LDAP step), core for every account (was registered by the Ldap plugin, plan feature ldap_step)
	    'CredentialLdap' => [
		'class' => 'models\credential\CredentialLdap',
		'variant-of' => 'Credential',
	    ],
	    'CredentialDecryption' => [
		'class' => 'models\credential\CredentialDecryption',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-10-20T18:00:00+03:00: a key to encrypt files with before sending them (the FTP / SFTP Upload step)
	    'CredentialEncryption' => [
		'class' => 'models\credential\CredentialEncryption',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-10-20T19:00:00+03:00: your private key to sign files with before sending them (the FTP / SFTP Upload step)
	    'CredentialSigning' => [
		'class' => 'models\credential\CredentialSigning',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-10-20T20:00:00+03:00: what the senders sign with, to check the signatures of files downloaded (the FTP / SFTP step)
	    'CredentialVerification' => [
		'class' => 'models\credential\CredentialVerification',
		'variant-of' => 'Credential',
	    ],
	    //CLD: 2026-10-22T00:28:13+03:00: a project variable, {{ vars.<name> }} (was the Variable object)
	    'CredentialVariable' => [
		'class' => 'models\credential\CredentialVariable',
		'variant-of' => 'Credential',
	    ],




	    //CLD: 2026-10-17T13:24:18+03:00: the workflow steps (models\node; were registered by the BaseNodes plugin, removed); the order is the step picker's
	    'NodeCoreIf' => [
		'class' => 'models\node\NodeCoreIf',
		'wfNode' => true,
	    ],
	    'NodeCoreSwitch' => [
		'class' => 'models\node\NodeCoreSwitch',
		'wfNode' => true,
	    ],
	    'NodeCoreMerge' => [
		'class' => 'models\node\NodeCoreMerge',
		'wfNode' => true,
	    ],
	    'NodeCoreNote' => [
		'class' => 'models\node\NodeCoreNote',
		'wfNode' => true,
	    ],
	    'NodeCoreLoop' => [
		'class' => 'models\node\NodeCoreLoop',
		'wfNode' => true,
	    ],
	    'NodeTriggerManual' => [
		'class' => 'models\node\NodeTriggerManual',
		'wfNode' => true,
	    ],
	    'NodeTriggerWebhook' => [
		'class' => 'models\node\NodeTriggerWebhook',
		'wfNode' => true,
	    ],
	    //CLD: 2026-11-01T16:11:37+02:00: Email (inbound) in the core (was registered by the InboundMail plugin, only when Haraka was found); available() when Haraka is found
	    'NodeTriggerEmail' => [
		'class' => 'models\node\NodeTriggerEmail',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-17T20:40:00+03:00: File upload trigger
	    'NodeTriggerFile' => [
		'class' => 'models\node\NodeTriggerFile',
		'wfNode' => true,
	    ],
	    'NodeTriggerSchedule' => [
		'class' => 'models\node\NodeTriggerSchedule',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-30T19:00:00+02:00: Called by a workflow (the workflow is started by the Run a workflow step of another one: its inputs and outputs)
	    'NodeTriggerCall' => [
		'class' => 'models\node\NodeTriggerCall',
		'wfNode' => true,
	    ],
	    'NodeAppSet' => [
		'class' => 'models\node\NodeAppSet',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-30T00:00:00Z: the Action step (NodeAppAction: an empty step, it did nothing) removed
	    'NodeAppCode' => [
		'class' => 'models\node\NodeAppCode',
		'wfNode' => true,
	    ],
	    'NodeAppHttp' => [
		'class' => 'models\node\NodeAppHttp',
		'wfNode' => true,
	    ],
	    'NodeAppDownload' => [
		'class' => 'models\node\NodeAppDownload',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-30T15:00:00+02:00: Read a web page (the basic HTTP Request: a GET, the page's text or HTML)
	    'NodeAppWebPage' => [
		'class' => 'models\node\NodeAppWebPage',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-20T16:00:00+03:00: FTP / SFTP (list or download files: filters, decryption, decompression, afterwards delete / move)
	    'NodeAppFtp' => [
		'class' => 'models\node\NodeAppFtp',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-20T18:00:00+03:00: FTP / SFTP Upload (project files or a text; compression, encryption, safe .part upload, if the file exists)
	    'NodeAppFtpUpload' => [
		'class' => 'models\node\NodeAppFtpUpload',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-25T20:00:00+02:00: Database (query and change SQL databases), core for every account (was registered by the Database plugin, plan feature database); not in the
	    //palette when PHP has no PDO driver of its types (WfNode::available())
	    //CLD: 2026-10-31T23:30:00+02:00: one step per database type (was NodeAppDatabase, now their base); each not in the palette when PHP has no PDO driver of its type
	    'NodeAppMysql' => [
		'class' => 'models\node\NodeAppMysql',
		'wfNode' => true,
	    ],
	    'NodeAppPostgres' => [
		'class' => 'models\node\NodeAppPostgres',
		'wfNode' => true,
	    ],
	    'NodeAppMssql' => [
		'class' => 'models\node\NodeAppMssql',
		'wfNode' => true,
	    ],
	    'NodeAppOracle' => [
		'class' => 'models\node\NodeAppOracle',
		'wfNode' => true,
	    ],
	    'NodeAppFirebird' => [
		'class' => 'models\node\NodeAppFirebird',
		'wfNode' => true,
	    ],
	    'NodeAppOdbc' => [
		'class' => 'models\node\NodeAppOdbc',
		'wfNode' => true,
	    ],
	    //CLD: 2026-11-01T08:00:00+02:00: Checkmk (one step per area, NodeAppCheckmk their base; the problem trigger, polling), core for every account
	    'NodeAppCmkStatus' => [
		'class' => 'models\node\NodeAppCmkStatus',
		'wfNode' => true,
	    ],
	    'NodeAppCmkAck' => [
		'class' => 'models\node\NodeAppCmkAck',
		'wfNode' => true,
	    ],
	    'NodeAppCmkHosts' => [
		'class' => 'models\node\NodeAppCmkHosts',
		'wfNode' => true,
	    ],
	    'NodeAppCmkApi' => [
		'class' => 'models\node\NodeAppCmkApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerCheckmk' => [
		'class' => 'models\node\NodeTriggerCheckmk',
		'wfNode' => true,
	    ],
	    //CLD: 2026-11-01T14:00:00+02:00: Zabbix (one step per area, NodeAppZabbix their base; the problem trigger, polling), core for every account
	    'NodeAppZbxProblems' => [
		'class' => 'models\node\NodeAppZbxProblems',
		'wfNode' => true,
	    ],
	    'NodeAppZbxAck' => [
		'class' => 'models\node\NodeAppZbxAck',
		'wfNode' => true,
	    ],
	    'NodeAppZbxHosts' => [
		'class' => 'models\node\NodeAppZbxHosts',
		'wfNode' => true,
	    ],
	    'NodeAppZbxApi' => [
		'class' => 'models\node\NodeAppZbxApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerZabbix' => [
		'class' => 'models\node\NodeTriggerZabbix',
		'wfNode' => true,
	    ],
	    //CLD: 2026-11-03T10:00:00+02:00: Wazuh (one step per area, NodeAppWazuh their base; the alert trigger, polling the indexer), core for every account
	    'NodeAppWzAlerts' => [
		'class' => 'models\node\NodeAppWzAlerts',
		'wfNode' => true,
	    ],
	    'NodeAppWzVulnerabilities' => [
		'class' => 'models\node\NodeAppWzVulnerabilities',
		'wfNode' => true,
	    ],
	    'NodeAppWzAgents' => [
		'class' => 'models\node\NodeAppWzAgents',
		'wfNode' => true,
	    ],
	    'NodeAppWzResponse' => [
		'class' => 'models\node\NodeAppWzResponse',
		'wfNode' => true,
	    ],
	    'NodeAppWzScans' => [
		'class' => 'models\node\NodeAppWzScans',
		'wfNode' => true,
	    ],
	    'NodeAppWzInventory' => [
		'class' => 'models\node\NodeAppWzInventory',
		'wfNode' => true,
	    ],
	    'NodeAppWzManager' => [
		'class' => 'models\node\NodeAppWzManager',
		'wfNode' => true,
	    ],
	    'NodeAppWzApi' => [
		'class' => 'models\node\NodeAppWzApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerWazuh' => [
		'class' => 'models\node\NodeTriggerWazuh',
		'wfNode' => true,
	    ],
	    //CLD: 2026-11-01T06:00:00+02:00: FortiGate (one step per area, NodeAppFortiGate their base; the event trigger: an automation stitch's webhook), core for every account
	    'NodeAppFgtAddresses' => [
		'class' => 'models\node\NodeAppFgtAddresses',
		'wfNode' => true,
	    ],
	    'NodeAppFgtBanned' => [
		'class' => 'models\node\NodeAppFgtBanned',
		'wfNode' => true,
	    ],
	    'NodeAppFgtPolicies' => [
		'class' => 'models\node\NodeAppFgtPolicies',
		'wfNode' => true,
	    ],
	    'NodeAppFgtUsers' => [
		'class' => 'models\node\NodeAppFgtUsers',
		'wfNode' => true,
	    ],
	    'NodeAppFgtSystem' => [
		'class' => 'models\node\NodeAppFgtSystem',
		'wfNode' => true,
	    ],
	    'NodeAppFgtApi' => [
		'class' => 'models\node\NodeAppFgtApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerFortiGate' => [
		'class' => 'models\node\NodeTriggerFortiGate',
		'wfNode' => true,
	    ],
	    //CLD: 2026-11-01T10:00:00+02:00: Sophos Firewall (one step per area, NodeAppSophos their base; no trigger: SFOS sends no webhooks), core for every account
	    'NodeAppSfosHosts' => [
		'class' => 'models\node\NodeAppSfosHosts',
		'wfNode' => true,
	    ],
	    'NodeAppSfosRules' => [
		'class' => 'models\node\NodeAppSfosRules',
		'wfNode' => true,
	    ],
	    'NodeAppSfosUsers' => [
		'class' => 'models\node\NodeAppSfosUsers',
		'wfNode' => true,
	    ],
	    'NodeAppSfosWeb' => [
		'class' => 'models\node\NodeAppSfosWeb',
		'wfNode' => true,
	    ],
	    'NodeAppSfosSystem' => [
		'class' => 'models\node\NodeAppSfosSystem',
		'wfNode' => true,
	    ],
	    'NodeAppSfosApi' => [
		'class' => 'models\node\NodeAppSfosApi',
		'wfNode' => true,
	    ],
	    //CLD: 2026-11-01T16:00:00+02:00: Hyper-V (one step per area, NodeAppHyperV their base; the VM state trigger, polling), core for every account
	    'NodeAppHyperVVms' => [
		'class' => 'models\node\NodeAppHyperVVms',
		'wfNode' => true,
	    ],
	    'NodeAppHyperVCheckpoints' => [
		'class' => 'models\node\NodeAppHyperVCheckpoints',
		'wfNode' => true,
	    ],
	    'NodeAppHyperVHost' => [
		'class' => 'models\node\NodeAppHyperVHost',
		'wfNode' => true,
	    ],
	    'NodeAppHyperVPowerShell' => [
		'class' => 'models\node\NodeAppHyperVPowerShell',
		'wfNode' => true,
	    ],
	    'NodeTriggerHyperV' => [
		'class' => 'models\node\NodeTriggerHyperV',
		'wfNode' => true,
	    ],
	    //CLD: 2026-11-01T14:00:00+02:00: Proxmox VE (one step per area, NodeAppProxmox their base; the task trigger, polling), core for every account
	    'NodeAppProxmoxGuests' => [
		'class' => 'models\node\NodeAppProxmoxGuests',
		'wfNode' => true,
	    ],
	    'NodeAppProxmoxSnapshots' => [
		'class' => 'models\node\NodeAppProxmoxSnapshots',
		'wfNode' => true,
	    ],
	    'NodeAppProxmoxBackups' => [
		'class' => 'models\node\NodeAppProxmoxBackups',
		'wfNode' => true,
	    ],
	    'NodeAppProxmoxCluster' => [
		'class' => 'models\node\NodeAppProxmoxCluster',
		'wfNode' => true,
	    ],
	    'NodeAppProxmoxApi' => [
		'class' => 'models\node\NodeAppProxmoxApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerProxmox' => [
		'class' => 'models\node\NodeTriggerProxmox',
		'wfNode' => true,
	    ],
	    //CLD: 2026-11-01T12:00:00+02:00: VMware vCenter (one step per area, NodeAppVmware their base; the alarm trigger, polling), core for every account
	    'NodeAppVmwareVms' => [
		'class' => 'models\node\NodeAppVmwareVms',
		'wfNode' => true,
	    ],
	    'NodeAppVmwareSnapshots' => [
		'class' => 'models\node\NodeAppVmwareSnapshots',
		'wfNode' => true,
	    ],
	    'NodeAppVmwareInventory' => [
		'class' => 'models\node\NodeAppVmwareInventory',
		'wfNode' => true,
	    ],
	    'NodeAppVmwareAlarms' => [
		'class' => 'models\node\NodeAppVmwareAlarms',
		'wfNode' => true,
	    ],
	    'NodeAppVmwareApi' => [
		'class' => 'models\node\NodeAppVmwareApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerVmware' => [
		'class' => 'models\node\NodeTriggerVmware',
		'wfNode' => true,
	    ],
	    //CLD: 2026-11-01T04:00:00+02:00: Veeam Backup & Replication (one step per area, NodeAppVeeam their base; the event trigger, polling), core for every account
	    'NodeAppVeeamJobs' => [
		'class' => 'models\node\NodeAppVeeamJobs',
		'wfNode' => true,
	    ],
	    'NodeAppVeeamSessions' => [
		'class' => 'models\node\NodeAppVeeamSessions',
		'wfNode' => true,
	    ],
	    'NodeAppVeeamBackups' => [
		'class' => 'models\node\NodeAppVeeamBackups',
		'wfNode' => true,
	    ],
	    'NodeAppVeeamInfrastructure' => [
		'class' => 'models\node\NodeAppVeeamInfrastructure',
		'wfNode' => true,
	    ],
	    'NodeAppVeeamRestore' => [
		'class' => 'models\node\NodeAppVeeamRestore',
		'wfNode' => true,
	    ],
	    'NodeAppVeeamApi' => [
		'class' => 'models\node\NodeAppVeeamApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerVeeam' => [
		'class' => 'models\node\NodeTriggerVeeam',
		'wfNode' => true,
	    ],
	    //CLD: 2026-11-01T00:30:00+02:00: Bitdefender GravityZone (one step per API area, NodeAppGravityZone their base; the event trigger), core for every account
	    'NodeAppGzEndpoints' => [
		'class' => 'models\\node\\NodeAppGzEndpoints',
		'wfNode' => true,
	    ],
	    'NodeAppGzIncidents' => [
		'class' => 'models\\node\\NodeAppGzIncidents',
		'wfNode' => true,
	    ],
	    'NodeAppGzBlocklist' => [
		'class' => 'models\\node\\NodeAppGzBlocklist',
		'wfNode' => true,
	    ],
	    'NodeAppGzQuarantine' => [
		'class' => 'models\\node\\NodeAppGzQuarantine',
		'wfNode' => true,
	    ],
	    'NodeAppGzInvestigation' => [
		'class' => 'models\\node\\NodeAppGzInvestigation',
		'wfNode' => true,
	    ],
	    'NodeAppGzReports' => [
		'class' => 'models\\node\\NodeAppGzReports',
		'wfNode' => true,
	    ],
	    'NodeAppGzApi' => [
		'class' => 'models\\node\\NodeAppGzApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerGravityZone' => [
		'class' => 'models\\node\\NodeTriggerGravityZone',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-25T19:30:00+02:00: LDAP (search and change a directory), core for every account (was registered by the Ldap plugin, plan feature ldap_step)
	    'NodeAppLdap' => [
		'class' => 'models\node\NodeAppLdap',
		'wfNode' => true,
	    ],
	    'NodeAppClaude' => [
		'class' => 'models\node\NodeAppClaude',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-18T12:30:00+03:00: was NodeAppLemonade
	    'NodeAppOpenAI' => [
		'class' => 'models\node\NodeAppOpenAI',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-22T01:36:46+03:00: Lemonade AI (the OpenAI step's requests, on a Lemonade server) and Ollama (Ollama's own API)
	    'NodeAppLemonade' => [
		'class' => 'models\node\NodeAppLemonade',
		'wfNode' => true,
	    ],
	    'NodeAppLmStudio' => [
		'class' => 'models\node\NodeAppLmStudio',
		'wfNode' => true,
	    ],
	    'NodeAppOllama' => [
		'class' => 'models\node\NodeAppOllama',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-22T12:00:00+03:00: Gemini (Google; the Gemini API)
	    'NodeAppGemini' => [
		'class' => 'models\node\NodeAppGemini',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-30T23:30:00+02:00: Knowledge base (add documents, search them by meaning and by words)
	    'NodeAppKnowledge' => [
		'class' => 'models\node\NodeAppKnowledge',
		'wfNode' => true,
	    ],
	    'NodeAppTable' => [
		'class' => 'models\node\NodeAppTable',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-17T14:00:00+03:00: renamed NodeAppEmail -> NodeAppSendEmail
	    'NodeAppSendEmail' => [
		'class' => 'models\node\NodeAppSendEmail',
		'wfNode' => true,
	    ],
	    'NodeAppWait' => [
		'class' => 'models\node\NodeAppWait',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-17T14:20:00+03:00: the IMAP trigger is a regular step, Read emails (was NodeTriggerImap)
	    //CLD: 2026-10-17T14:45:00+03:00: renamed NodeAppImap -> NodeAppReadEmail
	    'NodeAppReadEmail' => [
		'class' => 'models\node\NodeAppReadEmail',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-26T23:39:49+02:00: the Google steps (Drive, Sheets, Gmail, Calendar, Docs), core for every account again (were registered by the GoogleIntegration plugin, plan feature google)
	    'NodeAppGoogleDrive' => [
		'class' => 'models\node\NodeAppGoogleDrive',
		'wfNode' => true,
	    ],
	    'NodeAppGoogleSheets' => [
		'class' => 'models\node\NodeAppGoogleSheets',
		'wfNode' => true,
	    ],
	    'NodeAppGmail' => [
		'class' => 'models\node\NodeAppGmail',
		'wfNode' => true,
	    ],
	    'NodeAppGoogleCalendar' => [
		'class' => 'models\node\NodeAppGoogleCalendar',
		'wfNode' => true,
	    ],
	    'NodeAppGoogleDocs' => [
		'class' => 'models\node\NodeAppGoogleDocs',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-26T23:39:49+02:00: the Microsoft steps (Outlook Mail, Outlook Calendar, OneDrive / SharePoint, Excel, Teams), core for every account again (were registered by the MicrosoftIntegration
	    //plugin, plan feature microsoft)
	    'NodeAppOutlookMail' => [
		'class' => 'models\node\NodeAppOutlookMail',
		'wfNode' => true,
	    ],
	    'NodeAppOutlookCalendar' => [
		'class' => 'models\node\NodeAppOutlookCalendar',
		'wfNode' => true,
	    ],
	    'NodeAppOneDrive' => [
		'class' => 'models\node\NodeAppOneDrive',
		'wfNode' => true,
	    ],
	    'NodeAppExcel' => [
		'class' => 'models\node\NodeAppExcel',
		'wfNode' => true,
	    ],
	    'NodeAppTeams' => [
		'class' => 'models\node\NodeAppTeams',
		'wfNode' => true,
	    ],
    'NodeTriggerForm' => [
		'class' => 'models\node\NodeTriggerForm',
		'wfNode' => true,
	    ],
	    'NodeAppTask' => [
		'class' => 'models\node\NodeAppTask',
		'wfNode' => true,
	    ],
	    //CLD: 2026-11-02T18:00:00+02:00: talking to an agent: the Chat and Teams message triggers, Reply to chat
	    'NodeTriggerChat' => [
		'class' => 'models\node\NodeTriggerChat',
		'wfNode' => true,
	    ],
	    'NodeTriggerTeams' => [
		'class' => 'models\node\NodeTriggerTeams',
		'wfNode' => true,
	    ],
	    'NodeAppChatReply' => [
		'class' => 'models\node\NodeAppChatReply',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-30T19:00:00+02:00: Run a workflow (starts a workflow that starts with Called by a workflow; waits for its outputs, or continues)
	    'NodeAppWorkflow' => [
		'class' => 'models\node\NodeAppWorkflow',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-26T23:49:50+02:00: the designer's Assistant, core (were registered by the Assistant plugin): the account's settings, its spending per month, the conversation of each workflow
	    'AssistantSettings' => [
		'class' => 'models\assistant\AssistantSettings',
	    ],
	    'AssistantUsage' => [
		'class' => 'models\assistant\AssistantUsage',
	    ],
	    //CLD: 2026-11-02T10:00:00+02:00: what an account's agents may do (Account settings > AI), what its AI steps spent this month (own keys)
	    'AgentPolicy' => [
		'class' => 'models\agent\AgentPolicy',
		'name' => 'AI policy',
	    ],
	    'AgentUsage' => [
		'class' => 'models\agent\AgentUsage',
	    ],
	    //CLD: 2026-11-02T14:00:00+02:00: the traces of the AI steps' visits (the Executions panel)
	    'AgentTrace' => [
		'class' => 'models\agent\AgentTrace',
		'auditLog' => false,
	    ],
	    //CLD: 2026-11-02T15:46:06+02:00: a workflow's test cases and test runs (util\AgentEval); the audit log has named actions for them (EvalController): a case's inputs are large
	    'WfTestCase' => [
		'class' => 'models\wf\WfTestCase',
		'auditLog' => false,
	    ],
	    'WfTestRun' => [
		'class' => 'models\wf\WfTestRun',
		'auditLog' => false,
	    ],
	    //CLD: 2026-11-02T18:00:00+02:00: the conversations with agents, their messages
	    'AgentConversation' => [
		'class' => 'models\agent\AgentConversation',
		'auditLog' => false,
	    ],
	    'AgentMessage' => [
		'class' => 'models\agent\AgentMessage',
		'auditLog' => false,
	    ],
	    //CLD: 2026-11-01T11:55:18+02:00: the tool servers (util\ToolServers): the servers an account may use, the sessions open (were registered by the LocalMCP plugin)
	    'ToolServerAccess' => [
		'class' => 'models\ToolServerAccess',
	    ],
	    'ToolServerSlot' => [
		'class' => 'models\ToolServerSlot',
	    ],
	    //CLD: 2026-11-01T04:00:00+02:00: what a polling trigger remembers between its checks (Veeam event)
	    //CLD: 2026-11-01T16:11:37+02:00: an account's inbound mail prefix (the Email inbound addresses; was the InboundMail plugin's)
	    'InboundMailSettings' => [
		'class' => 'models\InboundMailSettings',
	    ],
	    'TriggerState' => [
		'class' => 'models\TriggerState',
	    ],
	    //not in the audit log: deleting a workflow would copy its conversation there (SOC2.md gap 11); the workflow's deletion is recorded
	    'AssistantChat' => [
		'class' => 'models\assistant\AssistantChat',
		'auditLog' => false,
	    ],
	    //CLD: 2026-10-17T13:24:18+03:00: the IMAP trigger's check state and the Human task's tasks (were registered by the BaseNodes plugin); the check state is not in the audit log
	    'MailPoll' => [
		'class' => 'models\MailPoll',
		'auditLog' => false,
	    ],
	    //CLD: 2026-10-20T16:00:00+03:00: the files the FTP / SFTP step has taken ("Only new files"); not in the audit log
	    'RemoteFileState' => [
		'class' => 'models\RemoteFileState',
		'auditLog' => false,
	    ],
	    'WfTask' => [
		'class' => 'models\wf\WfTask',
	    ],
	    //the LDAP sign-in: an account's settings, the users who are LDAP users
	    'LdapSettings' => [
		'class' => 'models\LdapSettings',
	    ],
	    'LdapLink' => [
		'class' => 'models\LdapLink',
	    ],
	    //the SAML single sign-on: an account's settings, the users who are SSO users, the requests waiting for their response, the assertions used (not in the audit log)
	    'SamlSettings' => [
		'class' => 'models\saml\SamlSettings',
	    ],
	    'SamlLink' => [
		'class' => 'models\saml\SamlLink',
	    ],
	    'SamlRequest' => [
		'class' => 'models\saml\SamlRequest',
		'auditLog' => false,
	    ],
	    'SamlReplay' => [
		'class' => 'models\saml\SamlReplay',
		'auditLog' => false,
	    ],

	];
    }
}