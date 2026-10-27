<?php
/*
    This file is part of SAPP

    Simple and Agnostic PDF Parser (SAPP) - Parse PDF documents in PHP (and update them)
    Copyright (C) 2020 - Carlos de Alfonso (caralla76@gmail.com)

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Lesser General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU Lesser General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace ddn\sapp;

use ddn\sapp\PDFObject;
use ddn\sapp\pdfvalue\PDFValue;
use ddn\sapp\pdfvalue\PDFValueHexString;
use ddn\sapp\pdfvalue\PDFValueList;
use ddn\sapp\pdfvalue\PDFValueObject;
use ddn\sapp\pdfvalue\PDFValueReference;
use ddn\sapp\pdfvalue\PDFValueSimple;
use ddn\sapp\pdfvalue\PDFValueString;
use ddn\sapp\pdfvalue\PDFValueType;

// This is an special object that has a set of fields
class PDFSignatureObject extends PDFObject {
    // The maximum signature length, needed to create a placeholder to calculate the range of bytes
    // that will cover the signature.
    public static $__SIGNATURE_MAX_LENGTH = 27742;

    // The maximum expected length of the byte range, used to create a placeholder while the size
    // is not known. 68 digits enable 20 digits for the size of the document
    public static $__BYTERANGE_SIZE = 68;

    protected $_prev_content_size = 0;
    protected $_post_content_size = null;

    /**
     * Constructs the object and sets the default values needed to sign
     * @param oid the oid for the object
     */
    public function __construct($oid, $details = null) {
        $this->_prev_content_size = 0;
        $this->_post_content_size = null;
        parent::__construct($oid, [
            'Filter' => "/Adobe.PPKLite",
            'Type' => "/Sig",
            //'SubFilter' => "/adbe.pkcs7.detached",
	    'SubFilter' => "/ETSI.CAdES.detached",
            'ByteRange' => new PDFValueSimple(str_repeat(" ", self::$__BYTERANGE_SIZE)),
            'Contents' => "<" . str_repeat("0", self::$__SIGNATURE_MAX_LENGTH) . ">",
            'M' => new PDFValueString(PDFUtilFnc::date_to_pdfstring()),
        ]);

	$this->set_metadata($details);
    }

    /**
     * Function used to add some metadata fields to the signature: name, reason of signature, etc.
     * @param name the name of the signer
     * @param reason the reason for the signature
     * @param location the location of signature
     * @param contact the contact info
     */
    public function set_metadata($meta = []) {
	if (empty($meta))
	    return;
	$fields = [ 'Name', 'Reason', 'Location', 'ContactInfo' ];
	foreach($fields as $f) {
	    //CLD: 2026-10-27T23:55:00+02:00: $meta (was $details, not defined here: the metadata was never set)
	    $fval = $meta[$f] ?? null;
	    //CLD: 2026-10-28T00:05:00+02:00: the text as UTF-16BE hex with its BOM (as upstream's PDFDoc::toUTF16Hex; was the text itself in <...>: not a valid hex string)
	    if (!is_null($fval))
		$this->_value[$f] = new PDFValueHexString(self::utf16_hex((string)$fval));
	}
    }

    //CLD: 2026-10-28T00:05:00+02:00: text (UTF-8) -> FEFF + its UTF-16BE bytes in hex: a PDF text string
    protected static function utf16_hex($text) {
        return 'FEFF' . strtoupper(bin2hex(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')));
    }

    /**
     * Function that sets the size of the content that will appear in the file, previous to this object,
     *   and the content that will be included after. This is needed to get the range of bytes of the
     *   signature.
     */
    public function set_sizes($prev_content_size, $post_content_size = null) {
        $this->_prev_content_size = $prev_content_size;
        $this->_post_content_size = $post_content_size;
    }
    /**
     * This function gets the offset of the marker, relative to this object. To make correct, the offset of the object
     *   shall have properly been set. It makes use of the parent "to_pdf_entry" function to avoid recursivity.
     * @return position the position of the <0000 marker
     */
    public function get_signature_marker_offset() {
        $tmp_output = parent::to_pdf_entry();
        $marker = "/Contents";
        $position = strpos($tmp_output, $marker);
        return $position + strlen($marker);
    }
    /**
     * Overrides the parent function to calculate the proper range of bytes, according to the sizes provided and the
     *   string representation of this object
     * @return str the string representation of this object
     */
    public function to_pdf_entry() {
        $signature_size = strlen(parent::to_pdf_entry());
        $offset = $this->get_signature_marker_offset();
        $starting_second_part = $this->_prev_content_size + $offset + self::$__SIGNATURE_MAX_LENGTH + 2;

        $contents_size = strlen("" . $this->_value['Contents']);

        $byterange_str =  "[ 0 " .
            ($this->_prev_content_size + $offset) . " " .
            ($starting_second_part) . " " .
            ($this->_post_content_size!==null?$this->_post_content_size + ($signature_size - $contents_size - $offset):0) . " ]";

        $this->_value['ByteRange'] =
            new PDFValueSimple($byterange_str . str_repeat(" ", self::$__BYTERANGE_SIZE - strlen($byterange_str) + 1)
        );

        return parent::to_pdf_entry();
    }
}
