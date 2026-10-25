<?php

namespace data;

class SysEmails {
    public static $data = [
	'password_reset' => [
	    'subject' => 'Your {{ app }} sign-in',
	    'email' => [
		'preheader' => 'Your username, and a link to set a new password',
		'blocks' => [
		    [
			'type' => 'html',
			'html' => '<h1>Signing in to {{ app }}</h1>'
			. '<p>Account: <strong>{{ account }}</strong><br>Username: <strong>{{ username }}</strong> (you can also sign in with {{ email }})</p>'
			. '<p>Forgot the password? Set a new one: the link works once, for 24 hours.</p>'
			. '<p><a href="{{ link }}"><strong>Set a new password</strong></a></p>'
			. '<p style="color: #555555">If the link does not open, copy this address into your browser:<br>{{ link }}</p>'
			. '<p style="color: #555555">You did not ask for this? Ignore this email: your password stays as it is.</p>'
		    ],
		],
	    ],
	],
	'password_changed' => [
	    'subject' => 'Your {{ app }} password was changed',
	    'email' => [
		'preheader' => 'If it was you, there is nothing to do',
		'blocks' => [
		    [
			'type' => 'html',
			'html' => '<h1>Your password was changed</h1>'
			. '<p>The password of the {{ app }} account of {{ email }} was just changed, and the other sign-ins were ended.</p>'
			. '<p>It was you? There is nothing to do.</p>'
			. '<p>It was not you? <a href="{{ login }}">Ask for a new password now</a> ("Forgot username or password?" on the login page) and tell your administrator.</p>'
		    ],
		],
	    ],
	],
	//CLD: 2026-10-25T11:20:00+02:00: a user created by an administrator (Users: Create; or Send the sign-in link): a link to choose the password (models\User::sendPasswordLink)
	'user_welcome' => [
	    'subject' => 'Your {{ app }} user for {{ account }}',
	    'email' => [
		'preheader' => 'Choose your password to sign in',
		'blocks' => [
		    [
			'type' => 'html',
			'html' => '<h1>Welcome to {{ app }}</h1>'
			. '<p>{{ inviter }} created a user for you in <strong>{{ account }}</strong>. You sign in with <strong>{{ email }}</strong>.</p>'
			. '<p>Choose your password to sign in. The link works once, for {{ days }} days.</p>'
			. '<p><a href="{{ link }}"><strong>Choose your password</strong></a></p>'
			. '<p style="color: #555555">If the link does not open, copy this address into your browser:<br>{{ link }}</p>'
			. '<p style="color: #555555">Not expecting this? Ignore this email: nothing happens until a password is chosen.</p>'
		    ],
		],
	    ],
	],
	'project_invite' => [
	    'subject' => '{{ inviter }} invited you to {{ project }} on {{ app }}',
	    'email' => [
		'preheader' => 'Join the project {{ project }} of {{ account }}',
		'blocks' => [
		    [
			'type' => 'html',
			'html' => '<h1>You are invited to {{ project }}</h1>'
			. '<p>{{ inviter }} invited you to work on the project <strong>{{ project }}</strong> of <strong>{{ account }}</strong> in {{ app }}.</p>'
			. '<p>Choose your name and a password to join. The link works once, for {{ days }} days.</p>'
			. '<p><a href="{{ link }}"><strong>Join {{ project }}</strong></a></p>'
			. '<p style="color: #555555">If the link does not open, copy this address into your browser:<br>{{ link }}</p>'
			. '<p style="color: #555555">You do not know {{ inviter }}? Ignore this email: nothing is created.</p>'
		    ],
		],
	    ],
	],
	'project_added' => [
	    'subject' => '{{ inviter }} added you to {{ project }} on {{ app }}',
	    'email' => [
		'preheader' => 'You are now a member of {{ project }}',
		'blocks' => [
		    [
			'type' => 'html',
			'html' => '<h1>You were added to {{ project }}</h1>'
			. '<p>{{ inviter }} added you to the project <strong>{{ project }}</strong> of <strong>{{ account }}</strong> in {{ app }}.</p>'
			. '<p><a href="{{ link }}"><strong>Open {{ app }}</strong></a> and choose the project in the sidebar.</p>'
		    ],
		],
	    ],
	],
    ];
}
