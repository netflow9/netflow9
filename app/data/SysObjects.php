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
		'class' => 'models\WfNode',
	    ],
	    'WfEdge' => [
		'class' => 'models\WfEdge',
	    ],
	    'WfExecution' => [
		'class' => 'models\WfExecution',
		'auditLog' => false,
	    ],
	    //CLD: 2026-10-11T13:51:42+03:00: published versions of workflows
	    'WfVersion' => [
		'class' => 'models\WfVersion',
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
	    //CLD: 2026-10-18T16:05:00+03:00: sync execution slots (were used by WfExecution directly; leases, not in the audit log)
	    'ExecutionSlot' => [
		'class' => 'models\ExecutionSlot',
		'auditLog' => false,
	    ],
	    'DataTableDef' => [
		'class' => 'models\DataTableDef',
	    ],
	    //CLD: 2026-10-20T23:40:00+03:00: McpToken (access tokens of the project's MCP server) is registered by the SysMCPTools plugin

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
	    //CLD: 2026-10-22T00:35:40+03:00: the Google credentials (Mail, Drive, Sheets, Calendar, Docs) are registered by the GoogleIntegration plugin
	    //CLD: 2026-10-22T00:41:28+03:00: the Microsoft credentials (Microsoft 365 Mail over IMAP, and the Microsoft Graph ones) are registered by the MicrosoftIntegration plugin
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
	    //CLD: 2026-10-19T21:10:00+03:00: NodeTriggerEmail is registered by the InboundMail plugin (when Haraka is found)
	    //CLD: 2026-10-17T20:40:00+03:00: File upload trigger
	    'NodeTriggerFile' => [
		'class' => 'models\node\NodeTriggerFile',
		'wfNode' => true,
	    ],
	    'NodeTriggerSchedule' => [
		'class' => 'models\node\NodeTriggerSchedule',
		'wfNode' => true,
	    ],
	    'NodeAppSet' => [
		'class' => 'models\node\NodeAppSet',
		'wfNode' => true,
	    ],
	    'NodeAppAction' => [
		'class' => 'models\node\NodeAppAction',
		'wfNode' => true,
	    ],
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
	    'NodeAppDatabase' => [
		'class' => 'models\node\NodeAppDatabase',
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
	    'NodeAppOllama' => [
		'class' => 'models\node\NodeAppOllama',
		'wfNode' => true,
	    ],
	    //CLD: 2026-10-22T12:00:00+03:00: Gemini (Google; the Gemini API)
	    'NodeAppGemini' => [
		'class' => 'models\node\NodeAppGemini',
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
	    //CLD: 2026-10-22T00:35:40+03:00: the Google steps (Drive, Sheets, Gmail, Calendar, Docs) are registered by the GoogleIntegration plugin
    'NodeTriggerForm' => [
		'class' => 'models\node\NodeTriggerForm',
		'wfNode' => true,
	    ],
	    'NodeAppTask' => [
		'class' => 'models\node\NodeAppTask',
		'wfNode' => true,
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
		'class' => 'models\WfTask',
	    ],

	];
    }
}