<?php

//CLD: 2026-10-22T00:41:28+03:00: new credential variant: Microsoft Teams over Microsoft Graph (OAuth); delegated permissions Team.ReadBasic.All, Channel.ReadBasic.All, ChannelMessage.Send,
//ChatMessage.Send, Chat.ReadBasic (work / school accounts only; some need an admin's consent). Provides `msteams` (the Teams step) and `http`

namespace plugins\MicrosoftIntegration\models;

class CredentialMicrosoftTeams extends \plugins\MicrosoftIntegration\models\CredentialMicrosoftGraph {
    public static $label = 'Microsoft Teams (Microsoft Graph)';
    public static $permissions = [ 'Team.ReadBasic.All', 'Channel.ReadBasic.All', 'ChannelMessage.Send', 'ChatMessage.Send', 'Chat.ReadBasic' ];
    public static $provides = [ 'msteams', 'http' ];
}
