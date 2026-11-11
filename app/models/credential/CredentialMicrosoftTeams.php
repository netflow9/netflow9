<?php

//Microsoft Teams over Microsoft Graph (OAuth); delegated permissions Team.ReadBasic.All, Channel.ReadBasic.All, ChannelMessage.Send,
//ChatMessage.Send, Chat.ReadBasic (work / school accounts only; some need an admin's consent). Provides `msteams` (the Teams step) and `http`

namespace models\credential;

class CredentialMicrosoftTeams extends \models\credential\CredentialMicrosoftGraph {
    public static $label = 'Microsoft Teams (Microsoft Graph)';
    //Chat.Read: the Teams message trigger reads the messages of the account's chats (a credential connected without it asks for its consent again)
    public static $permissions = [ 'Team.ReadBasic.All', 'Channel.ReadBasic.All', 'ChannelMessage.Send', 'ChatMessage.Send', 'Chat.ReadBasic', 'Chat.Read' ];
    public static $provides = [ 'msteams', 'http' ];
}
