#!/bin/bash

#load config file
. $(dirname "$SELF")/../app.env

command='start'

#parse command line parameters
while [ "$1" != "" ]; do
    case $1 in
        start|stop|restart)
    	    command=$1
    	;;
        *)
    	    echo "usage: $0 {start|stop}"
    	    exit
    	;;
    esac
    shift
done

PID_FILE="$BASE/run/daemon.pid"
LOG_FILE="$BASE/logs/daemon.log"
DEBUG="--debug"
#DEBUG=""

OPTIONS=""


case $command in
    start)
        #check if a process is already running
        if test -s $PID_FILE ; then
    	    #pidfile exists, read contents
            read pid < $PID_FILE
            if kill -0 $pid 2>/dev/null ; then
        	echo "Daemon ALREADY RUNNING with pid=$pid"
                exit 0
            else
                echo "Daemon NOT RUNNING, but pid file exists. cleanup"
                rm -f $PID_FILE
            fi
        fi

        echo "launching daemon"
        echo $$ > $PID_FILE
        $BASE/app/v.php daemon::run $DEBUG $OPTIONS 1>>$LOG_FILE 2>&1 &

        echo $! > $PID_FILE
        echo "launched daemon with pid=$!"
    ;;
    stop)
        pid=`cat $PID_FILE`
        kill $pid
        rm -f $PID_FILE
        echo "stopped daemon with pid=$pid"
    ;;
    restart)
        $0 stop
        sleep 3
        $0 start
    ;;
esac
exit 0
