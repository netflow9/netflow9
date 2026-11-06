<?php

//CLD: 2026-10-22T00:41:28+03:00: new credential variant: Outlook Calendar over Microsoft Graph (OAuth); delegated permission Calendars.ReadWrite (+ Calendars.Read.Shared: the free / busy times of
//others). Provides `mscalendar` (the Outlook Calendar step) and `http`

namespace models\credential;

class CredentialMicrosoftCalendar extends \models\credential\CredentialMicrosoftGraph {
    public static $label = 'Outlook Calendar (Microsoft Graph)';
    public static $permissions = [ 'Calendars.ReadWrite', 'Calendars.Read.Shared' ];
    public static $provides = [ 'mscalendar', 'http' ];
}
