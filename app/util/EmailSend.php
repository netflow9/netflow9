<?php

namespace util;

class EmailSend {

    //data keys:
    // - message: the message
    // - sender:
    // - to:
    public static function send($data, $options = []) {
	$defaults = [
	    // The SMTP server timeout in seconds. 5 minutes (300sec) is from RFC2821 section 4.5.3.2.
	    'timeout' => 300,
	    // * SMTP class debug output mode.
	    // * Options:
	    // * * SMTP::DEBUG_OFF: No output
	    // * * SMTP::DEBUG_CLIENT: Client messages
	    // * * SMTP::DEBUG_SERVER: Client and server messages
	    // * * SMTP::DEBUG_CONNECTION: As SERVER plus connection status
	    // * * SMTP::DEBUG_LOWLEVEL: Noisy, low-level data output, rarely needed
	    // * @see SMTP::$do_debug
	    'debug' => \util\SMTP::DEBUG_OFF, //\components\SMTP::DEBUG_CONNECTION,
	    'logger' => function($message, $level) {
		\V::app()->log->debug("SMTP: $message");
	    },
	    'doVerp' => false,
	];
	$options = \util\Set::merge($defaults, $options);

	//process data: if string => it's a filename and contents needs to be read and deserialized
	$fname = false;
	if (is_string($data)) {
	    $fname = $data;
	    if (!is_readable($fname)) {
		\V::app()->log->error("file:$fname is not readable. Return.");
		return;
	    }
	    $data = file_get_contents($fname);
	    $data = unserialize($data);
	}

	//SC: 2026-03-30: check params early
	if (!defined('SMTP_HOST')) {
	    \V::app()->log->error("SMTP_HOST is not defined. Cannot send emails. Stop");
	    return -1;
	}
	if (!defined('SMTP_PORT'))
	    define('SMTP_PORT', 25);

	//try to connect to SMTP server indicated in options
        $smtp = new SMTP();

        $smtp->setTimeout($options['timeout']);
        $smtp->setDebugLevel($options['debug']);
        $smtp->setDebugOutput($options['logger']);
        $smtp->setVerp($options['doVerp']);

	//let's get the SMTPOptions from global config
	$params = \V::app()->config('params');


	if (!defined('SMTP_SECURE'))
	    define('SMTP_SECURE', false);

	if (!defined('SMTP_AUTOTLS'))
	    define('SMTP_AUTOTLS', false);

	//connect
	switch(SMTP_SECURE) {
	    case 'ssl':
		$tls = false;
		$prefix = 'ssl://';
		break;
	    case 'tls':
	    default:
		$tls = true;
		$prefix = '';
		break;
	}

	//override TLS if options says so
	if (SMTP_AUTOTLS === false)
	    $tls = false;
	
        //SC: 2026-03-30: use SMTP_USERNAME and SMTP_PASSWORD
        $smtp_auth = false;
        if (defined('SMTP_AUTH') && !empty(SMTP_AUTH)) {
	    if (defined('SMTP_USERNAME') && defined('SMTP_PASSWORD')) {
		$smtp_auth = (!empty(SMTP_USERNAME) && !empty(SMTP_PASSWORD));
	    }
	}

	//try connection
	//$connected = $smtp->connect($prefix . $SMTPOptions['hostname'], $SMTPOptions['port'], $options['timeout'], []);
	$connected = $smtp->connect($prefix . SMTP_HOST, SMTP_PORT, $options['timeout'], []);
	if (!$connected) {
	    \V::app()->log->error("Could not connect to SMTP host:" . SMTP_HOST);
	    return -1;
	}

	if (!defined('SMTP_FROM_DOMAIN'))
	    define('SMTP_FROM_DOMAIN', gethostname());
	//CLD: 2026-10-31T12:12:05+02:00: an empty SMTP_FROM_DOMAIN (app.env.example's placeholder) is as none: the host name
	$helo = SMTP_FROM_DOMAIN !== '' ? SMTP_FROM_DOMAIN : gethostname();

	//try sending hello
        try {
    	    $smtp->hello($helo);
            //Disable TLS if server does not offer STARTTLS
            if (!$smtp->getServerExt('STARTTLS'))
        	$tls = false;

            if ($tls) {
        	//try TLS
                if (!$smtp->startTLS()) {
            	    \V::app()->log->debug('SMTP: cannot STARTLS');
            	    return -1;
                }
                // We must resend EHLO after TLS negotiation
                $smtp->hello($helo);
            }

            if ($smtp_auth) {
        	if (!$smtp->authenticate(SMTP_USERNAME, SMTP_PASSWORD, $auth_type = null, $oauth = null)) {
        	    \V::app()->log->debug('SMTP: cannot authenticate');
        	    return -1;
        	}
            }
        } catch (Exception $e) {
            \V::app()->log->debug('SMTP: error: ' . $e->getMessage());
            // We must have connected, but then failed TLS or Auth, so close connection nicely
            $smtp->quit();
            return -1;
        }

        if (empty($smtp->mail($data['sender']))) {
    	    \V::app()->log->debug('SMTP: cannot send: ' . implode(',', $smtp->getError()) );
	    return -1;
        }

	//SC: 2026-02-05: let's log the sender in our worker.log
    	\V::app()->log->debug('SMTP: sending mail from:' . $data['sender']);
	
        // Attempt to send to all recipients
        foreach ([ 'to', 'cc', 'bcc' ] as $grp) {
    	    if (empty($data[$grp]))
    		continue;
    	    if (is_string($data[$grp]))
    		$data[$grp] = unserialize($data[$grp]);

	    if (!is_array($data[$grp]))
		continue;

            foreach ($data[$grp] as $to_email => $to_name) {
        	$sent = $smtp->recipient($to_email, 'NEVER');
        	if (!$sent)
                    \V::app()->log->debug('SMTP: error sending mail to: ' . $to_email . ', error:' . implode(',', $smtp->getError()));

    		\V::app()->log->debug('SMTP: sending mail to:' . $to_email);
            }
        }

        // Only send the DATA command if we have viable recipients
        //CLD: 2026-10-29T12:00:00+03:00: the headers come without a trailing CRLF (compose(), Job::reportError): CRLF CRLF ends the last header and adds the blank line
        //before the body (was one CRLF: the body's first line, e.g. the MIME boundary, read as a header - Haraka: "Header did not look right")
        if( !$smtp->data(rtrim($data['headers'], "\r\n") . "\r\n\r\n" . $data['body'])) {
    	    \V::app()->log->debug('SMTP: error sending mail');
    	    return -1;
        }

        $smtp_transaction_id = $smtp->getLastTransactionID();

        $smtp->quit();
        $smtp->close();

	if ($fname)
	    unlink($fname);

        return true;
    }

    /*
	a MIME message for send():
	    $m = {
		from (address),
		from_name,
		to / cc / bcc (addresses),
		reply_to,
		subject,
		html,
		text,
		images: [ cid => File ]
	    } -> [ headers, body ];
	    with images: multipart/related (the alternative + the images, inline),
	    else multipart/alternative (text, html); quoted-printable bodies, encoded headers (CR / LF removed: no header injection)
    */
    public static function compose(array $m) {
	$clean = fn($v) => trim(str_replace([ "\r", "\n" ], ' ', (string)$v));
	//a display name: quoted when plain ASCII (it may hold , : ; ...), else encoded
	$name = fn($n) => $clean($n) === '' ? '' : (mb_check_encoding($clean($n), 'ASCII') ? '"' . addcslashes($clean($n), '"\\') . '"' : mb_encode_mimeheader($clean($n), 'UTF-8', 'Q')) . ' ';
	$list = fn($l) => implode(', ', array_map(fn($a) => '<' . $clean($a) . '>', (array)$l));
	$id = bin2hex(random_bytes(8));
	$domain = substr(strrchr((string)$m['from'], '@'), 1) ?: 'localhost';
	$headers = [
	    'From: ' . $name($m['from_name'] ?? '') . '<' . $clean($m['from']) . '>',
	    'To: ' . $list($m['to'] ?? []),
	];
	if (!empty($m['cc']))
	    $headers[] = 'Cc: ' . $list($m['cc']);
	if (!empty($m['reply_to']))
	    $headers[] = 'Reply-To: <' . $clean($m['reply_to']) . '>';
	$headers = array_merge($headers, [
	    'Subject: ' . mb_encode_mimeheader($clean($m['subject'] ?? ''), 'UTF-8', 'Q'),
	    'Date: ' . date(DATE_RFC2822),
	    'Message-ID: <' . $id . '@' . $domain . '>',
	    'MIME-Version: 1.0',
	]);
	$qp = fn($s) => str_replace("\n", "\r\n", str_replace("\r\n", "\n", quoted_printable_encode(str_replace([ "\r\n", "\r" ], "\n", (string)$s))));
	$alt = 'alt-' . $id;
	$alternative = "--{$alt}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . $qp($m['text'] ?? '') . "\r\n"
	    . "--{$alt}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . $qp($m['html'] ?? '') . "\r\n--{$alt}--\r\n";
	if (empty($m['images'])) {
	    $headers[] = "Content-Type: multipart/alternative; boundary=\"{$alt}\"";
	    return [ implode("\r\n", $headers), $alternative ];
	}
	$rel = 'rel-' . $id;
	$headers[] = "Content-Type: multipart/related; boundary=\"{$rel}\"; type=\"multipart/alternative\"";
	$body = "--{$rel}\r\nContent-Type: multipart/alternative; boundary=\"{$alt}\"\r\n\r\n" . $alternative;
	foreach($m['images'] as $cid => $file) {
	    $type = $file->imageMimeType() ?: 'application/octet-stream';
	    $fname = $clean(preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$file->filename));
	    $body .= "--{$rel}\r\nContent-Type: {$type}; name=\"{$fname}\"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <{$cid}>\r\n"
		. "Content-Disposition: inline; filename=\"{$fname}\"\r\n\r\n" . chunk_split(base64_encode($file->getContents()), 76, "\r\n");
	}
	return [ implode("\r\n", $headers), $body . "--{$rel}--\r\n" ];
    }

    //the job of a queued email (the Send Email step): { aid, from_name, to, cc, bcc, reply_to, subject, html, text, images: [ cid => file id ] }; sent from
    //the app's address (config mail.from);
    //an image file gone is left out;
    //throws when the SMTP server does not take it (the daemon tries again)
    public static function deliver($data) {
	$data = (array)$data;
/*
	if (!empty($data->images)) {
	    \V::app()->log->debug('EmailSend::deliver: data=' . print_r($data, true));
	}

	$data = json_decode(\util\JSON::encode($data), true);
*/
	$images = [];


	foreach((array)($data['images'] ?? []) as $cid => $fid) {
	    $file = \V::app()->om->findOne('File', [ '_id' => (string)$fid, 'aid' => $data['aid'] ?? null ]);
	    if ($file)
		$images[$cid] = $file;
	}
	$from = (string)\V::app()->config('mail.from');
	[ $headers, $body ] = self::compose([ 'from' => $from, 'images' => $images ] + $data);
	$rcpt = fn($l) => array_fill_keys(array_values(array_filter((array)$l)), '');
	$result = self::send([ 'sender' => $from, 'to' => $rcpt($data['to'] ?? []), 'cc' => $rcpt($data['cc'] ?? []), 'bcc' => $rcpt($data['bcc'] ?? []), 'headers' => $headers, 'body' => $body ]);
	if ($result !== true)
	    throw new \ErrorException('the email "' . ($data['subject'] ?? '') . '" was not accepted by the SMTP server');
    }

    //an email of the app (data\SysEmails: sign-up, later invitations) to $to,
    //filled with $vars (+ app: the app's name),
    //queued as the Send Email step's are (deliver(), run by the daemon); the job's id
    public static function system($name, $to, array $vars = []) {
	$template = \data\SysEmails::$data[$name] ?? null;
	if (!$template)
	    throw new \ErrorException("EmailSend::system: no email `{$name}` (data\\SysEmails)");
	$vars += [ 'app' => (string)\V::app()->config('app.name') ];
	$subject = \util\EmailHtml::fillText($template['subject'], $vars);
	$r = \util\EmailHtml::render($template['email'], $vars, $subject);
	return \models\Job::submit('\util\EmailSend::deliver', [ 'from_name' => $vars['app'], 'to' => (array)$to, 'subject' => $subject, 'html' => $r['html'], 'text' => $r['text'],
	    'images' => $r['images'] ], [ 'delete_on_done' => true ]);
    }
}
