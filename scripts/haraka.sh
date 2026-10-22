#!/bin/bash

#load config files
. $(dirname "$SELF")/../app.env

CFG=$BASE/haraka/config

command='start'

#parse command line parameters
while [ "$1" != "" ]; do
    case $1 in
        start|stop|restart)
    	    command=$1
    	;;
        *)
    	    echo "usage: $0 {start|stop|restart}"
    	    exit
    	;;
    esac
    shift
done

PID_FILE="$BASE/run/haraka.pid"

case $command in
    start)
	#check existence of EMAIL_INBOUND_DIR
	#[ ! -d "$EMAIL_INBOUND_DIR" ] &&  mkdir -p "$EMAIL_INBOUND_DIR"

        #check if a process is already running
        if test -s $PID_FILE ; then
    	    #pidfile exists, read contents
            read pid < $PID_FILE
            if kill -0 $pid 2>/dev/null ; then
        	echo "haraka ALREADY RUNNING with pid=$pid"
                exit 0
            else
                echo "haraka NOT RUNNING, but pid file exists. cleanup"
                rm -f $PID_FILE
            fi
        fi

        echo "launching haraka"
        $BASE/haraka/node_modules/haraka/bin/haraka -c $BASE/haraka
    ;;
    stop)
        pid=`cat $PID_FILE`
        kill $pid
        rm -f $PID_FILE
        echo "stopped haraka with pid=$pid"
    ;;
    restart)
        $0 stop
        $0 start
    
esac
exit 0
