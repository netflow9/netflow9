<?php

//CLD: 2026-10-20T13:00:00+03:00: new credential variant: Google Calendar (OAuth); the Google app needs the Google Calendar API enabled and the scope
//https://www.googleapis.com/auth/calendar (the calendars the account can open). Provides `gcalendar` (the Google Calendar step) and `http` (HTTP Request: a bearer token)

//CLD: 2026-10-22T00:35:40+03:00: moved to the GoogleIntegration plugin (was models\credential\CredentialGoogleCalendar); core classes fully qualified
namespace plugins\GoogleIntegration\models;

class CredentialGoogleCalendar extends \plugins\GoogleIntegration\models\CredentialGoogleOAuth2 {
    public static $label = 'Google Calendar (OAuth)';
    public static $icon = 'bi-google';
    public static $provides = [ 'gcalendar', 'http' ];

    public static function serviceScope() {
	return 'https://www.googleapis.com/auth/calendar';
    }

    //the primary calendar, read from the Calendar API
    public function test() {
	$cal = static::getJson('https://www.googleapis.com/calendar/v3/calendars/primary', [ 'Authorization' => 'Bearer ' . $this->accessToken() ]);
	return 'Google Calendar of ' . ($cal['id'] ?? ($this->email ?: '?')) . (!empty($cal['timeZone']) ? " ({$cal['timeZone']})" : '');
    }
}
