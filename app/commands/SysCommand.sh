#!/bin/bash
#CLD: 2026-11-01T15:00:00+02:00: the installation's commands from the shell (were AuditCommand.sh, WfCommand.sh); the others: ../v sys::<action> (SysCommand.php)
#  ./SysCommand.sh audit [<account id>]      check the audit log's seal
#  ./SysCommand.sh run <workflow id>         run a workflow's draft
#  ./SysCommand.sh resume <execution id>     run a sleeping execution again
#  ./SysCommand.sh indexes                   create the database indexes

case "$1" in
    audit)   if [ -n "$2" ]; then ../v sys::audit --account="$2"; else ../v sys::audit; fi ;;
    run)     ../v sys::run --id="$2" ;;
    resume)  ../v sys::resume --id="$2" ;;
    indexes) ../v sys::indexes ;;
    *)       sed -n '3,6p' "$0"; exit 1 ;;
esac
