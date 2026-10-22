<?php

/*
    the new account registration (sign-up)
    Enabled in app/plugins.php; without it there is no sign-up page and no link on the login page.
*/

namespace plugins\Registration;

class Plugin {

    public function __construct() {
	\V::app()->om->registerObjects([
	    //a sign-up waiting for its email to be confirmed (no account yet: not in the audit log)
	    'Signup' => [
		'class' => __NAMESPACE__ . '\\models\\Signup',
		'auditLog' => false,
	    ],
	]);

	//the system emails of the sign-up (util\EmailSend::system)
	//  signup_confirm   the link that confirms a sign-up (vars: link)
	//  signup_exists    a sign-up asked for an address that already has an account (vars: email, login)
	\data\SysEmails::$data += [
	    'signup_confirm' => [
		'subject' => 'Confirm your email for {{ app }}',
		'email' => [
		    'preheader' => 'One click and your {{ app }} account is ready',
		    'blocks' => [
			[
			    'type' => 'html',
			    'html' => '<h1>Welcome to {{ app }}</h1>'
			    . '<p>Confirm your email address to create your account. The link works once, for 24 hours.</p>'
			    . '<p><a href="{{ link }}"><strong>Confirm my email address</strong></a></p>'
			    . '<p style="color: #555555">If the link does not open, copy this address into your browser:<br>{{ link }}</p>'
			    . '<p style="color: #555555">You did not ask for an account? Ignore this email: nothing is created.</p>'
			],
		    ],
		],
	    ],
	    'signup_exists' => [
		'subject' => 'You already have a {{ app }} account',
		'email' => [
		    'preheader' => 'Log in with this address',
		    'blocks' => [
			[
			    'type' => 'html',
			    'html' => '<h1>You already have an account</h1>'
			    . '<p>Someone, maybe you, asked to create a {{ app }} account with {{ email }}. This address already has one.</p>'
			    . '<p><a href="{{ login }}"><strong>Log in</strong></a>. Forgot the password? Use "Forgot username or password?" on the login page.</p>'
			    . '<p style="color: #555555">It was not you? Ignore this email: nothing changed.</p>'
			],
		    ],
		],
	    ],
	];

	if (\V::app()->config('app.mode') != 'web')
	    return;

	\V::app()->hooks->addHook('site:login-links', function() {
	    echo \V::app()->html->link(\V::t('New here? Create an account'), '/registration/register', [ 'class' => 'pt-1' ]);
	});
    }
}
