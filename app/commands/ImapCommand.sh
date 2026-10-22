#!/bin/bash
#CLD: 2026-10-08T09:19:59+03:00: try the IMAP client against a mailbox: ./ImapCommand.sh [--preset=gmail|outlook] [--user=..] [--password=..|--token=..] [--folder=INBOX] [--count=5] [--debug]
#with no options it uses the placeholders at the top of ImapCommand.php

../v imap::test --preset=gmail --user=qsmdev2026@gmail.com --password='fjrorbumjdqnvamv'
