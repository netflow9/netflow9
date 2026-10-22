#!/bin/bash
#CLD: 2026-10-22T00:00:00+03:00: check the audit log's seal: ./AuditCommand.sh [<account id>]

if [ -n "$1" ]; then
    ../v audit::verify --account="$1"
else
    ../v audit::verify
fi
