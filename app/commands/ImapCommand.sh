#!/bin/bash
#try the IMAP client against a mailbox: ./ImapCommand.sh [--preset=gmail|outlook] [--user=..] [--password=..|--token=..] [--folder=INBOX] [--count=5] [--debug]
#the mailbox from IMAP_USER, the password from IMAP_PASSWORD or asked (not shown; none in this file); the options given are passed on
#(e.g. --preset=outlook, --folder=, --count=, --debug; the default preset is gmail)
#  IMAP_USER=me@gmail.com ./ImapCommand.sh --count=3

set -euo pipefail

if [ -z "${IMAP_USER:-}" ]; then
    read -r -p 'Mailbox (IMAP_USER): ' IMAP_USER
fi
if [ -z "${IMAP_PASSWORD:-}" ]; then
    read -r -s -p "Password of ${IMAP_USER} (IMAP_PASSWORD): " IMAP_PASSWORD
    echo
fi

case " $* " in
    *" --preset="*) PRESET=() ;;
    *) PRESET=(--preset=gmail) ;;
esac

../v imap::test "${PRESET[@]}" --user="${IMAP_USER}" --password="${IMAP_PASSWORD}" "$@"
