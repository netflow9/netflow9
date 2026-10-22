#!/bin/bash
#CLD: 2026-10-06T23:21:04+03:00: run a workflow: ./WfCommand.sh <workflow id>  (or resume an execution: ../v wf::resume --id=<execution id>)

../v wf::run --id="$1"
