<?php

namespace sys\console;

class Response extends \sys\BaseObject {

    /**
     * Writes string to output stream
     *
     * @param string $output
     * @return mixed
     */
    public function write($body = '', $replace = false) {
	echo (string)strip_tags($body);
    }


    public function output() {
	//output has been sent by write; nothing to do
    }


    public function getMessageForCode($status) {
	return $status;
    }

}
