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

use ddn\sapp\PDFBaseDoc;
use ddn\sapp\PDFBaseObject;
use ddn\sapp\pdfvalue\PDFValueObject;
use ddn\sapp\pdfvalue\PDFValueList;
use ddn\sapp\pdfvalue\PDFValueReference;
use ddn\sapp\pdfvalue\PDFValueType;
use ddn\sapp\pdfvalue\PDFValueHexString;
use ddn\sapp\pdfvalue\PDFValueString;

//use function ddn\sapp\helpers\_parsejpg;
//use function ddn\sapp\helpers\_parsepng;
use function ddn\sapp\helpers\p_error;


class PDFImage {
    public static function tx($x, $y) {
	return sprintf(" 1 0 0 1 %.2F %.2F cm", $x, $y);
    }
    public static function sx($w, $h) {
	return sprintf(" %.2F 0 0 %.2F 0 0 cm", $w, $h);
    }
    public static function deg2rad($angle) {
	return $angle * pi() / 180;
    }
    public static function rx($angle) {
	$angle = self::deg2rad($angle);
	return sprintf(" %.2F %.2F %.2F %.2F 0 0 cm", cos($angle), sin($angle), -sin($angle), cos($angle)); 
    }

    public static function is_base64($string){
	// Check if there are valid base64 characters
	if (!preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $string))
	    return false;

	// Decode the string in strict mode and check the results
	$decoded = base64_decode($string, true);
	if (empty($decoded))
	    return false;

	// Encode the string again
	if(base64_encode($decoded) != $string)
	    return false;

	return true;
    }

    public static function parse_jpg($filecontent) {
	// Extract info from a JPEG file
	$a = getimagesizefromstring($filecontent);
	if(!$a)
	    return p_error('Missing or incorrect image');

	if($a[2] != 2)
	    return p_error('Not a JPEG image');

	if(!isset($a['channels']))
	    $a['channels'] = 3;

	switch($a['channels']) {
	    case 3:
		$colspace = 'DeviceRGB';
		break;
	    case 4:
		$colspace = 'DeviceCMYK';
		break;
	    default:
		$colspace = 'DeviceGray';
		break;
	}

	$bpc = isset($a['bits']) ? $a['bits'] : 8;

	$data = $filecontent;

	return [ 'w' => $a[0], 'h' => $a[1], 'cs' => $colspace, 'bpc' => $bpc, 'f' => 'DCTDecode', 'data' => $data ];
    }

    //-----------------------------------------------------------------------------------------------
    public static function parse_png($filecontent) {
	// Extract info from a PNG file
	$f = fopen("php://temp", 'r+');
	fputs($f, $filecontent);
	rewind($f);

	$sig = fread($f, 8);
	if($sig != chr(137) . 'PNG' . chr(13) . chr(10) . chr(26) . chr(10))
	    return p_error('Not a PNG image');

	// Read header chunk
	fread($f,4);

	$hdr_sig = fread($f, 4);
	if ($hdr_sig != 'IHDR')
	    return p_error('Incorrect PNG image');

	// Read a 2 * 4-byte integer from stream
	[ 'w' => $w, 'h' => $h ] = unpack('Nw/Nh', fread($f, 2*4));

	//bits-per-color
	$bpc = ord(fread($f,1));
	if ($bpc > 8)
	    return p_error('16-bit depth not supported');

	$ct = ord(fread($f,1));
	switch($ct) {
	    case 0:
	    case 4:
		$colspace = 'DeviceGray';
		break;
	    case 2:
	    case 6:
		$colspace = 'DeviceRGB';
		break;
	    case 3:
		$colspace = 'Indexed';
		break;
	    default:
		return p_error('Unknown color type');
	}

	$compression = ord(fread($f,1));
	if ($compression != 0)
	    return p_error('Unknown compression method');

	$filter = ord(fread($f,1));
	if ($filter != 0)
	    return p_error('Unknown filter method');

	$interlacing = ord(fread($f,1));
	if ($interlacing != 0)
	    return p_error('Interlacing not supported');

	//skip 4 bytes
	fread($f,4);


	// Scan chunks looking for palette, transparency and image data
	$pal = '';
	$trns = '';
	$data = '';
	do {
	    [ 'n' => $n ] = unpack('Nn', fread($f, 4));
	    $type = fread($f,4);

	    switch($type) {
		case 'PLTE':
		    // Read palette
		    $pal = fread($f, $n);
		    fread($f,4);
		    break;
		case 'tRNS':
		    // Read transparency info
		    $t = fread($f, $n);
		    if($ct==0)
			$trns = array(ord(substr($t,1,1)));
		    elseif($ct==2)
			$trns = array(ord(substr($t,1,1)), ord(substr($t,3,1)), ord(substr($t,5,1)));
		    else {
			$pos = strpos($t,chr(0));
			if($pos!==false)
			    $trns = array($pos);
		    }
		    fread($f,4);
		    break;
		case 'IDAT':
		    // Read image data block
		    $data .= fread($f,$n);
		    fread($f,4);
		    break;
		case 'IEND':
		    break;
		default:
		    fread($f,$n+4);
	    }
	} while($n);

	if($colspace == 'Indexed' && empty($pal))
	    return p_error('Missing palette in image');

	$dp = '/Predictor 15 /Colors ' . ($colspace=='DeviceRGB' ? 3 : 1) . ' /BitsPerComponent ' . $bpc . ' /Columns ' . $w;
	$info = [ 'w'=>$w, 'h'=>$h, 'cs'=>$colspace, 'bpc'=>$bpc, 'f'=>'FlateDecode', 'dp'=>$dp, 'pal'=>$pal, 'trns'=>$trns ];
	if ($ct >= 4) {
	    // Extract alpha channel
	    if(!function_exists('gzuncompress'))
		return p_error('Zlib not available, can\'t handle alpha channel');

	    $data = gzuncompress($data);
	    if ($data === false)
		return p_error('failed to uncompress the image');

	    $color = '';
	    $alpha = '';
	    if($ct==4) {
		// Gray image
		$len = 2*$w;
		for($i=0;$i<$h;$i++) {
		    $pos = (1+$len)*$i;
		    $color .= $data[$pos];
		    $alpha .= $data[$pos];
		    $line = substr($data,$pos+1,$len);
		    $color .= preg_replace('/(.)./s','$1',$line);
		    $alpha .= preg_replace('/.(.)/s','$1',$line);
		}
	    } else {
		// RGB image
		$len = 4*$w;
		for($i=0;$i<$h;$i++) {
		    $pos = (1+$len)*$i;
		    $color .= $data[$pos];
		    $alpha .= $data[$pos];
		    $line = substr($data,$pos+1,$len);
		    $color .= preg_replace('/(.{3})./s','$1',$line);
		    $alpha .= preg_replace('/.{3}(.)/s','$1',$line);
		}
	    }

	    unset($data);
	    $data = gzcompress($color);
	    $info['smask'] = gzcompress($alpha);
	}
	$info['data'] = $data;
	return $info;
    }
    //----------------------------------------------------------------------------


    /**
     * Creates an image object in the document, using the content of "info"
     *   NOTE: the image inclusion is taken from http://www.fpdf.org/; this is a translation
     *         of function _putimage
     */
    public static function create_image_objects($info, $object_factory) { 
	$objects = [];

	$image = call_user_func($object_factory, [
            'Type' => '/XObject',
            'Subtype' => '/Image',
            'Width' => $info['w'],
            'Height' => $info['h'],
            'ColorSpace' => [ ],
            'BitsPerComponent' => $info['bpc'],
            'Length' => strlen($info['data']),
	]);

	switch ($info['cs']) {
	    case 'Indexed':
        	$data = gzcompress($info['pal']);
        	$streamobject = call_user_func($object_factory, [
            	    'Filter' => '/FlateDecode',
            	    'Length' => strlen($data),
        	]);
        	$streamobject->set_stream($data);

        	$image['ColorSpace']->push([
            	    '/Indexed', '/DeviceRGB', (strlen($info['pal']) / 3) - 1, new PDFValueReference($streamobject->get_oid())
        	]);
        	array_push($objects, $streamobject);
        	break;
    	    case 'DeviceCMYK':
        	$image["Decode"] = new PDFValueList([1, 0, 1, 0, 1, 0, 1, 0]);
    	    default:
        	$image['ColorSpace'] = new PDFValueType( $info['cs'] );
        	break;
	}

	if (isset($info['f']))
    	    $image['Filter'] = new PDFValueType($info['f']);

	if(isset($info['dp']))
    	    $image['DecodeParms'] = PDFValueObject::fromstring($info['dp']);

	if (isset($info['trns']) && is_array($info['trns']))
    	    $image['Mask'] = new PDFValueList($info['trns']);

	if (isset($info['smask'])) {
    	    $smaskinfo = [
        	'w' => $info['w'], 
        	'h' => $info['h'], 
        	'cs' => 'DeviceGray', 
        	'bpc' => 8, 
        	'f' => $info['f'], 
        	'dp' => '/Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns '.$info['w'],
        	'data' => $info['smask']
    	    ];

    	    // In principle, it may return multiple objects
    	    $smasks = self::create_image_objects($smaskinfo, $object_factory);
    	    foreach ($smasks as $smask)
        	array_push($objects, $smask);
    	    $image['SMask'] = new PDFValueReference($smask->get_oid());
	}

	$image->set_stream($info['data']);
	array_unshift($objects, $image);

	return $objects;
    }


    /**
     * This function creates the objects needed to add an image to the document, at a specific position and size.
     *   The function is agnostic from the place in which the image is to be created, and just creates the objects
     *   with its contents and prepares the PDF command to place the image
     * @param filename the file name that contains the image, or a string that contains the image (with character '@'
     *                 prepended)
     * @param x points from left in which to appear the image (the units are "content-defined" (i.e. depending on the size of the page))
     * @param y points from bottom in which to appear the image (the units are "content-defined" (i.e. depending on the size of the page))
     * @param w width of the rectangle in which to appear the image (image will be scaled, and the units are "content-defined" (i.e. depending on the size of the page))
     * @param h height of the rectangle in which to appear the image (image will be scaled, and the units are "content-defined" (i.e. depending on the size of the page))
     * @param angle the rotation angle in degrees; the image will be rotated using the center
     * @param keep_proportions if true, the image will keep the proportions when rotated, then the image will not occupy the full 
     * @return result an array with the next fields:
     *                  "images": objects of the corresponding images (i.e. position [0] is the image, the rest elements are masks, if needed)
     *                  "resources": PDFValueObject with keys that needs to be incorporated to the resources of the object in which the images will appear
     *                  "alpha": true if the image has alpha
     *                  "command": pdf command to draw the image
     */
    public static function add_image($object_factory, $filename, $x=0, $y=0, $w=0, $h=0, $angle = 0, $keep_proportions = true) {
	if (empty($filename))
	    return p_error('invalid image name or stream');

	if ($filename[0] === '@') {
	    $filecontent = substr($filename, 1);
	} else if (self::is_base64($filename)) {
	    $filecontent = base64_decode($filename);
	} else {
	    $filecontent = @file_get_contents($filename);
	}

	if ($filecontent === false)
	    return p_error("failed to get the image");

	$finfo = new \finfo();
	$content_type = $finfo->buffer($filecontent, FILEINFO_MIME_TYPE);

	// TODO: support more image types than jpg
	$add_alpha = false;
	switch ($content_type) {
	    case 'image/jpeg':
	    case 'image/pjpeg':
		$info = self::parse_jpg($filecontent);
		break;
	    case 'image/png':
	    case 'image/x-png':
		$add_alpha = true;
		$info = self::parse_png($filecontent);
		break;
	    default:
		return p_error("unsupported mime type");
	}

	// Generate a new identifier for the image
	$info['i'] = "Im" . uniqid();

	if ($w === null)
	    $w = -96;
	if ($h === null)
	    $h = -96;

	if($w < 0)
	    $w = -$info['w'] * 72 / $w;
	if($h < 0)
	    $h = -$info['h'] * 72 / $h;
	if($w == 0)
	    $w = $h * $info['w'] / $info['h'];
	if($h == 0)
	    $h = $w * $info['h'] / $info['w'];

	$images_objects = self::create_image_objects($info, $object_factory);

	// Generate the command to translate and scale the image
	$data = "q ";

	if ($keep_proportions) {
	    $angleRads = self::deg2rad($angle);
	    $W = abs($w * cos($angleRads) + $h * sin($angleRads));
	    $H = abs($w * sin($angleRads) + $h * cos($angleRads));
	    $rW = $W / $w;
	    $rH = $H / $h;
	    $r = min($rW, $rH);
	    $w = $W * $r;
	    $h = $H * $r;
	}

	// Now, how to apply the matrices...
	//   the matrices are not added in the order that we want to apply them; instead, they should be added in the reverse order (as they should be multiplied)
	//   i.e. the nearest matrix to the "/Image Do" will be the one applied.
	//
	// So, if we wanted to rotate using the center of the image and escale the image, we could
	//   A) data = "q " . sx($w, $h) . tx(0.5, 0.5) . rx(90) . tx(-0.5, -0.5) . " /Image Do Q";
	//   or B) data = "q " . tx($w/2, $h/2) . rx(90) . tx(-$w/2, -$h/2) . sx($x, $w)" /Image Do Q";

	/* Option A
	$data .= sx($h, $w);  <-- the order is inverted because now the image is rotated, so that it keeps the original size of the box where it should appear
	$data .= tx(0.5, 0.5);
	$data .= rx(90);
	$data .= tx(-0.5,-0.5);
	*/

	/* Option B
	$data .= tx($w / 2, $h / 2);
	$data .= rx(90);
	$data .= tx(-$w / 2, -$h / 2);
	$data .= sx($w, $h);
	*/

	$data = "q";
	$data .= self::tx($x, $y);
	$data .= self::sx($w, $h);
	if ($angle != 0) {
	    $data .= self::tx(0.5, 0.5);
	    $data .= self::rx($angle);
	    $data .= self::tx(-0.5,-0.5);
	}
	$data .= sprintf(" /%s Do Q", $info['i']);

	$resources = new PDFValueObject([
	    'ProcSet' => [ '/PDF', '/Text', '/ImageB', '/ImageC', '/ImageI' ],
	    'XObject' => new PDFValueObject ([
		$info['i'] => new PDFValueReference($images_objects[0]->get_oid()),
	    ])
	]);

	return [ "image" => $images_objects[0], 'command' => $data, 'resources' => $resources, 'alpha' => $add_alpha ];
    }

}
