<?php

/*
    Send email - an email made of blocks (models\EmailBlocks,
	edited with the block designer app.emaildesigner.js) sent to the recipients of the step.
	The step fills the {{ }} with the run's variables and renders the email
	    (util\EmailHtml: responsive, inline styles, Outlook-safe, a text version, the images embedded),
	    then queues it: the daemon sends it (util\EmailSend::deliver); the run goes on.

    From: the app's address (config mail.from), the step's sender name and Reply-To
*/
namespace models\node;

class NodeAppSendEmail extends \models\WfNode {
    public static $type = 'app';
    public static $name = 'send-email';
    public static $label = 'Send email';
    public static $icon = 'bi-envelope-paper';
    public static $color = 'var(--wf-color-app)';
    public static $inputs = 1;
    public static $outputs = 1;

    const MAX_RECIPIENTS = 50;
    //the image types every email client shows
    const IMAGE_TYPES = [ 'image/png', 'image/jpeg', 'image/gif' ];

    public static function configSchema() {
	return [
	    [
		'key' => 'to',
		'type' => 'text',
		'label' => 'To',
		'required' => true,
		'help' => 'Addresses separated by commas, or an expression giving one or a list, e.g. {{ form.email }}',
		'ui' => [ 'expression' => true ],
	    ],
	    [
		'key' => 'cc',
		'type' => 'text',
		'label' => 'Cc',
		'ui' => [ 'expression' => true, 'width' => 6 ],
	    ],
	    [
		'key' => 'bcc',
		'type' => 'text',
		'label' => 'Bcc',
		'ui' => [ 'expression' => true, 'width' => 6 ],
	    ],
	    [
		'key' => 'subject',
		'type' => 'text',
		'label' => 'Subject',
		'required' => true,
		'ui' => [ 'expression' => true ],
	    ],
	    [
		'key' => 'fromName',
		'type' => 'text',
		'label' => 'Sender name',
		'default' => (string)\V::app()->config('app.name'),
		'help' => 'Sent from ' . \V::app()->config('mail.from'),
		'ui' => [ 'expression' => true, 'width' => 6 ],
	    ],
	    [
		'key' => 'replyTo',
		'type' => 'text',
		'label' => 'Reply to',
		'help' => 'Where replies go (empty: nowhere useful)',
		'ui' => [ 'expression' => true, 'width' => 6 ],
	    ],
	    \models\EmailBlocks::schema(),
	];
    }

    //the email stored normalized; its blocks complete; the expressions in its blocks well formed; the images files of the workflow's project
    public function validates($options = []) {
	$valid = parent::validates($options);
	$config = is_array($this->config) ? $this->config : [];
	$config['email'] = \models\EmailBlocks::normalize($config['email'] ?? []);
	$this->config = $config;
	foreach(\models\EmailBlocks::errors($config['email']) as $m) {
	    $this->errors('config', "Message: {$m}");
	    $valid = false;
	}
	$wf = $this->workflow;
	foreach($config['email']['blocks'] as $i => $b) {
	    $texts = $b['type'] === 'html' ? [ $b['html'] ] : [ $b['href'] ];
	    if ($b['type'] === 'html')
		$texts = array_map(fn($p) => \sys\J\Template::OPEN . html_entity_decode(substr($b['html'], $p['start'], $p['end'] - $p['start']), ENT_QUOTES | ENT_HTML5, 'UTF-8') . \sys\J\Template::CLOSE,
		    \sys\J\Template::parts($b['html']));
	    foreach($texts as $t) {
		foreach(\util\Expression::check($t) as $p) {
		    if ($p['severity'] === 'error') {
			$this->errors('config', 'Message: block ' . ($i + 1) . ': ' . $p['message']);
			$valid = false;
		    }
		}
	    }
	    $file = $b['type'] === 'image' && $b['file'] !== '' && $wf ? \V::app()->om->findOne('File', [ '_id' => $b['file'], 'aid' => $wf->aid, 'metadata.project' => $wf->project ]) : null;
	    if ($b['type'] === 'image' && $b['file'] !== '' && !$file) {
		$this->errors('config', 'Message: block ' . ($i + 1) . ': the image is not a file of this project');
		$valid = false;
	    } elseif ($file && !in_array($file->imageMimeType(), self::IMAGE_TYPES, true)) {
		//CLD: 2026-10-16T12:02:12+03:00: only the image types every email client shows (Outlook shows no WebP)
		$this->errors('config', 'Message: block ' . ($i + 1) . ': use a PNG, JPEG or GIF image');
		$valid = false;
	    }
	}
	return $valid;
    }

    //the addresses of an expression's value: a list, or text separated by commas / semicolons / spaces; each must be an e-mail address
    private function addresses($key, array $vars) {
	$value = \util\Expression::render((string)$this->cfg($key, ''), $vars);
	$list = is_array($value) ? array_map([ \util\Expression::class, 'toText' ], array_values($value)) : preg_split('/[\s,;]+/', \util\Expression::toText($value));
	$list = array_values(array_unique(array_filter(array_map('trim', $list), fn($a) => $a !== '')));
	foreach($list as $a) {
	    if (!\util\Validator::rule('email', $a, 'any'))
		throw new \ErrorException("Send email: `{$a}` ({$key}) is not an e-mail address");
	}
	return $list;
    }

    public function execute($edgeId = null) {
	$vars = $this->exec->expressionVariables();
	$to = $this->addresses('to', $vars);
	$cc = $this->addresses('cc', $vars);
	$bcc = $this->addresses('bcc', $vars);
	if (!$to)
	    throw new \ErrorException('Send email: no recipient (To is empty)');
	if (count($to) + count($cc) + count($bcc) > self::MAX_RECIPIENTS)
	    throw new \ErrorException('Send email: more than ' . self::MAX_RECIPIENTS . ' recipients');
	$replyTo = \util\EmailHtml::fillText($this->cfg('replyTo', ''), $vars);
	if ($replyTo !== '' && !\util\Validator::rule('email', $replyTo, 'any'))
	    throw new \ErrorException("Send email: `{$replyTo}` (Reply to) is not an e-mail address");
	$subject = mb_substr(\util\EmailHtml::fillText($this->cfg('subject', ''), $vars), 0, 300);

	$r = \util\EmailHtml::render(is_array($this->cfg('email')) ? $this->cfg('email') : [], $vars, $subject);
	$job = \models\Job::submit('\util\EmailSend::deliver', [
	    'aid' => (string)$this->exec->aid,
	    'from_name' => mb_substr(\util\EmailHtml::fillText($this->cfg('fromName', ''), $vars), 0, 100),
	    'to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'reply_to' => $replyTo,
	    'subject' => $subject, 'html' => $r['html'], 'text' => $r['text'], 'images' => $r['images'],
	], [ 'aid' => $this->exec->aid, 'project' => $this->exec->project, 'delete_on_done' => true ]);
	$this->exec->log($this->wfid, 'email "' . mb_substr($subject, 0, 80) . '" queued for ' . implode(', ', array_slice($to, 0, 5)) . (count($to) > 5 ? ', ...' : '') . " (job {$job})");
	return [ 0 ];
    }
}
