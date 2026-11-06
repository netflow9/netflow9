<?php

//CLD: 2026-10-22T00:41:28+03:00: new credential variant: Microsoft Teams over Microsoft Graph (OAuth); delegated permissions Team.ReadBasic.All, Channel.ReadBasic.All, ChannelMessage.Send,
//ChatMessage.Send, Chat.ReadBasic (work / school accounts only; some need an admin's consent). Provides `msteams` (the Teams step) and `http`

namespace models\credential;

class CredentialMicrosoftTeams extends \models\credential\CredentialMicrosoftGraph {
    public static $label = 'Microsoft Teams (Microsoft Graph)';
    //CLD: 2026-11-02T18:00:00+02:00: + Chat.Read: the Teams message trigger reads the messages of the account's chats; a credential connected before asks for its consent again
    public static $permissions = [ 'Team.ReadBasic.All', 'Channel.ReadBasic.All', 'ChannelMessage.Send', 'ChatMessage.Send', 'Chat.ReadBasic', 'Chat.Read' ];
    public static $provides = [ 'msteams', 'http' ];
}
