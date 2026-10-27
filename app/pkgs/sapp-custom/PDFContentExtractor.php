<?php
/*
    CLD: 2026-10-27T22:40:00+02:00: new: the text and the images of a PDF document (PDFDoc), read from the content streams of its pages (and of the Form XObjects they draw):

    text    the strings the pages show (Tj, TJ, ', "), in the order they are drawn, as UTF-8: by the font's ToUnicode CMap, else by its encoding (WinAnsi, MacRoman, Standard,
            Differences: glyph names); 1 byte codes (simple fonts) or the code space of a Type0 font's encoding (Identity-H: 2 bytes; UCS2 / UTF16 CMaps). Spaces and line
            breaks come from the glyphs' positions (the fonts' widths, the text and graphics matrices, TJ adjustments). Glyphs without Unicode are left out (a warning per font)
    images  the images the pages draw (Do of image XObjects; inline images BI ... ID ... EI), each once (`pages`: where it is drawn): DCTDecode as JPEG (unchanged; also CMYK),
            Flate / LZW / ASCIIHex / ASCII85 / RunLength / none as PNG (gray, RGB, CMYK -> RGB, Indexed, Separation, ICCBased, stencil masks; 1 - 16 bits; SMask -> alpha),
            JPXDecode as JPEG 2000 (option jp2); CCITTFax / JBIG2, DeviceN / Lab and other bit depths are skipped (warnings)

    not read: encrypted documents (false), annotations and form fields, Type3 glyph procedures, marked content (ActualText); vertical writing is read as horizontal

    usage
        $x = new PDFContentExtractor($doc);
        $x->get_text([ 'pages' => [ 1, 2 ], 'max_chars' => 200000 ])   the text ("\n\n" between pages: page_separator)
        $x->get_text_pages()                                          [ page (1-based) => text ]
        $x->get_images([ 'min_width' => 32, 'max_images' => 50 ])     [ [ page, pages, name, oid, width, height, bpc, colorspace, filter, mime, ext, data ] ]
        $x->extract([ 'text' => true, 'images' => true ])              both in one pass: [ pages, images, warnings ]
        $x->get_warnings()                                            what was left out by the last call
    false (p_error) when the document cannot be read (encrypted, no pages)
*/

namespace ddn\sapp;

use ddn\sapp\pdfvalue\PDFValueObject;
use ddn\sapp\pdfvalue\PDFValueList;
use ddn\sapp\pdfvalue\PDFValueSimple;
use ddn\sapp\pdfvalue\PDFValueType;
use ddn\sapp\pdfvalue\PDFValueString;
use ddn\sapp\pdfvalue\PDFValueHexString;

use function ddn\sapp\helpers\p_error;

class PDFContentExtractor {

    const DEFAULTS = [
        'text' => true,             //extract the text
        'images' => false,          //extract the images
        'pages' => null,            //the pages (1-based) to read; null: all
        'max_chars' => null,        //the text is cut after this many characters (the pages after it are not read)
        'page_separator' => "\n\n", //get_text: between the pages
        'min_width' => 32,          //smaller images are left out (logos, bullets, lines)
        'min_height' => 32,
        'max_images' => 100,        //the images after these are left out (a warning)
        'max_pixels' => 40000000,   //larger images are left out (a warning)
        'with_data' => true,        //false: the images are listed, not converted (data null)
        'masks' => true,            //SMask as the PNG's alpha channel
        'jp2' => false,             //JPXDecode images as image/jp2 (else left out: the AI models do not read them)
        'inline' => true,           //inline images (BI ... EI)
    ];

    //Form XObjects drawn in Form XObjects, at most
    const MAX_DEPTH = 12;

    //content tokens
    const T_NUM = 1, T_NAME = 2, T_STR = 3, T_ARR = 4, T_ARR_END = 5, T_DICT = 6, T_DICT_END = 7, T_OP = 8, T_VAL = 9;
    const DELIMITERS = " \t\r\n\f\0()<>[]{}/%";
    const WHITESPACE = " \t\r\n\f\0";
    const IDENTITY = [ 1, 0, 0, 1, 0, 0 ];

    //the image filters: the image's data is its stream after the other filters
    const IMAGE_FILTERS = [ 'DCTDecode', 'JPXDecode', 'CCITTFaxDecode', 'JBIG2Decode' ];
    //the abbreviations of the inline images
    const INLINE_KEYS = [ 'BPC' => 'BitsPerComponent', 'CS' => 'ColorSpace', 'D' => 'Decode', 'DP' => 'DecodeParms', 'F' => 'Filter', 'H' => 'Height', 'IM' => 'ImageMask',
        'I' => 'Interpolate', 'W' => 'Width', 'L' => 'Length' ];
    const INLINE_NAMES = [ 'G' => 'DeviceGray', 'RGB' => 'DeviceRGB', 'CMYK' => 'DeviceCMYK', 'I' => 'Indexed', 'AHx' => 'ASCIIHexDecode', 'A85' => 'ASCII85Decode',
        'LZW' => 'LZWDecode', 'Fl' => 'FlateDecode', 'RL' => 'RunLengthDecode', 'CCF' => 'CCITTFaxDecode', 'DCT' => 'DCTDecode' ];

    protected $_doc;
    protected $_opts = [];
    protected $_objects = [];   //oid => PDFObject | false
    protected $_values = [];    //oid => plain value
    protected $_fonts = [];
    protected $_warnings = [];
    protected $_unmapped = [];  //font key => [ label, glyphs without unicode ]
    protected $_runs = [];      //the text runs of the page being read
    protected $_images = [];
    protected $_image_index = []; //oid => index in _images (false: left out)
    protected $_forms = [];     //the Form XObjects being drawn (oid => true: no cycles)
    protected $_page = 0;

    protected static $_glyphs = null;
    protected static $_encodings = null;

    public function __construct(PDFDoc $doc) {
        $this->_doc = $doc;
    }

    // ------------------------------------------------------------------------------------
    //the API

    //the text of the pages, joined by page_separator; false when the document cannot be read
    public function get_text($options = []) {
        $pages = $this->get_text_pages($options);
        if ($pages === false)
            return false;
        $sep = $options['page_separator'] ?? self::DEFAULTS['page_separator'];
        return implode($sep, array_filter($pages, fn($t) => $t !== ''));
    }

    //[ page (1-based) => text ]; false when the document cannot be read
    public function get_text_pages($options = []) {
        $r = $this->extract([ 'text' => true, 'images' => false ] + $options);
        return $r === false ? false : $r['pages'];
    }

    //the images: [ [ page (the first drawing it), pages, name (its resource name, or `inline`), oid (null: inline), width, height, bpc, colorspace, filter, mime, ext, data ] ];
    //false when the document cannot be read
    public function get_images($options = []) {
        $r = $this->extract([ 'text' => false, 'images' => true ] + $options);
        return $r === false ? false : $r['images'];
    }

    public function get_warnings() {
        return $this->_warnings;
    }

    //[ pages: [ page => text ] (when text), images: [ ... ] (when images), warnings ]; false when the document cannot be read
    public function extract($options = []) {
        $this->_opts = $o = $options + self::DEFAULTS;
        $this->_warnings = $this->_unmapped = $this->_images = $this->_image_index = $this->_forms = [];
        if ($this->_doc->is_encrypted())
            return p_error('encrypted documents are not supported');
        $count = $this->_doc->get_page_count();
        if ($count < 1)
            return p_error('the document has no pages');
        $numbers = $o['pages'] === null ? range(1, $count) : array_values(array_unique(array_filter(array_map('intval', (array)$o['pages']), fn($n) => $n >= 1 && $n <= $count)));

        $pages = [];
        $chars = 0;
        foreach ($numbers as $n) {
            $this->_page = $n;
            $this->_runs = [];
            $page = $this->_doc->get_page($n - 1);
            if ($page === false) {
                $this->warn("page {$n}: not found");
                continue;
            }
            $dict = $this->plain($page->get_value());
            $resources = $this->inherited($dict, 'Resources');
            $gs = [ 'ctm' => self::IDENTITY, 'font' => null, 'fs' => 0, 'tc' => 0, 'tw' => 0, 'th' => 1, 'tl' => 0, 'ts' => 0 ];
            $this->run_content($this->page_content($dict), is_array($resources) ? $resources : [], $gs, 0);

            if (!$o['text'])
                continue;
            $text = $this->layout($this->_runs);
            if ($o['max_chars'] !== null && $chars + mb_strlen($text) > $o['max_chars']) {
                $pages[$n] = rtrim(mb_substr($text, 0, max(0, $o['max_chars'] - $chars)));
                $this->warn("the text is cut at {$o['max_chars']} characters (page {$n})");
                break;
            }
            $chars += mb_strlen($text);
            $pages[$n] = $text;
        }
        $this->_runs = [];

        foreach ($this->_unmapped as [ $label, $glyphs, $tounicode ])
            $this->warn("font {$label}: {$glyphs} glyph(s) without Unicode" . ($tounicode ? '' : ' (no ToUnicode)') . ', left out of the text');
        $r = [ 'warnings' => $this->_warnings ];
        if ($o['text'])
            $r['pages'] = $pages;
        if ($o['images'])
            $r['images'] = $this->_images;
        return $r;
    }

    // ------------------------------------------------------------------------------------
    //the objects, as plain PHP values: dictionaries as arrays (keys without /), arrays as lists, names as '/Name', numbers, booleans, null; strings as objects { s: bytes },
    //references as objects { r: oid }

    protected function plain($v) {
        if ($v === false || $v === null)
            return null;
        if ($v instanceof PDFValueObject) {
            $r = [];
            foreach ($v->val() as $k => $x)
                $r[$k] = $this->plain($x);
            return $r;
        }
        if ($v instanceof PDFValueList) {
            $r = [];
            foreach ($v->val() as $x) {
                if ($x instanceof PDFValueSimple)
                    array_push($r, ...self::simple_tokens((string)$x->val()));
                else
                    $r[] = $this->plain($x);
            }
            return $r;
        }
        if ($v instanceof PDFValueHexString)
            return (object)[ 's' => self::hex_bytes((string)$v->val()) ];
        if ($v instanceof PDFValueString)
            return (object)[ 's' => self::unescape((string)$v->val()) ];
        if ($v instanceof PDFValueType)
            return '/' . self::name_decode(trim((string)$v->val()));
        if ($v instanceof PDFValueSimple) {
            $t = self::simple_tokens((string)$v->val());
            return count($t) === 1 ? $t[0] : $t;
        }
        return null;
    }

    //"0 0 612 792" -> [ 0, 0, 612, 792 ]; "4 0 R" -> { r: 4 }
    protected static function simple_tokens($s) {
        $parts = preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY);
        $r = [];
        $n = count($parts);
        for ($i = 0; $i < $n; $i++) {
            $p = $parts[$i];
            if ($i + 2 < $n && $parts[$i + 2] === 'R' && ctype_digit($p) && ctype_digit($parts[$i + 1])) {
                $r[] = (object)[ 'r' => (int)$p ];
                $i += 2;
            } elseif (is_numeric($p))
                $r[] = $p + 0;
            elseif ($p === 'true' || $p === 'false')
                $r[] = $p === 'true';
            elseif ($p === 'null')
                $r[] = null;
            else
                $r[] = $p;
        }
        return $r;
    }

    protected static function is_ref($v) {
        return is_object($v) && isset($v->r);
    }

    protected static function is_str($v) {
        return is_object($v) && isset($v->s);
    }

    //the PDFObject of reference $v (cached); null when there is none
    protected function obj($v) {
        if (!self::is_ref($v))
            return null;
        $oid = $v->r;
        if (!array_key_exists($oid, $this->_objects))
            $this->_objects[$oid] = $this->_doc->get_object($oid);
        return $this->_objects[$oid] ?: null;
    }

    //the value of $v, following references
    protected function resolve($v, $depth = 0) {
        while (self::is_ref($v) && $depth++ < 32) {
            $oid = $v->r;
            if (!array_key_exists($oid, $this->_values)) {
                $o = $this->obj($v);
                $this->_values[$oid] = $o ? $this->plain($o->get_value()) : null;
            }
            $v = $this->_values[$oid];
        }
        return self::is_ref($v) ? null : $v;
    }

    //the decoded stream of reference $v (all its filters); null when it cannot be decoded
    protected function stream($v, $what = 'stream') {
        $o = $this->obj($v);
        if (!$o)
            return null;
        $dict = $this->resolve($v);
        [ $filters, $parms ] = $this->filters_of(is_array($dict) ? $dict : []);
        [ $data, $final, $err ] = $this->decode_filters((string)$o->get_stream(true), $filters, $parms);
        if ($err !== null || $final !== null) {
            $this->warn("{$what} {$v->r}: " . ($err ?? "{$final} is not a content stream"));
            return null;
        }
        return $data;
    }

    //key $key of a page, or of its parents (Resources, MediaBox, ...: inherited)
    protected function inherited($dict, $key) {
        for ($i = 0; is_array($dict) && $i < 32; $i++) {
            if (isset($dict[$key]))
                return $this->resolve($dict[$key]);
            $dict = $this->resolve($dict['Parent'] ?? null);
        }
        return null;
    }

    //the content of a page: its Contents stream, or its streams joined
    protected function page_content($dict) {
        $c = $dict['Contents'] ?? null;
        if (self::is_ref($c)) {
            $v = $this->resolve($c);
            //a reference to an array of streams
            if (is_array($v) && array_is_list($v) && $v && self::is_ref($v[0]))
                $c = $v;
        }
        $refs = is_array($c) ? $c : [ $c ];
        $parts = [];
        foreach ($refs as $ref)
            if (self::is_ref($ref) && ($s = $this->stream($ref, 'content')) !== null)
                $parts[] = $s;
        return implode("\n", $parts);
    }

    protected function warn($message) {
        if (!in_array($message, $this->_warnings, true))
            $this->_warnings[] = $message;
    }

    // ------------------------------------------------------------------------------------
    //strings and names

    //the bytes of a literal string, its escapes decoded (\n \r \t \b \f \( \) \\ \ddd, a backslash at the end of a line)
    protected static function unescape($s) {
        if (strpos($s, '\\') === false)
            return $s;
        return preg_replace_callback('/\\\\(?:([0-7]{1,3})|(\r\n|\r|\n)|(.))/s', function($m) {
            if ($m[1] !== '')
                return chr(octdec($m[1]) & 0xFF);
            if (($m[2] ?? '') !== '')
                return '';
            return [ 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f" ][$m[3]] ?? $m[3];
        }, $s);
    }

    protected static function hex_bytes($s) {
        $s = preg_replace('/[^0-9a-fA-F]/', '', $s);
        if (strlen($s) % 2)
            $s .= '0';
        return (string)hex2bin($s);
    }

    //#xx in names
    protected static function name_decode($s) {
        return strpos($s, '#') === false ? $s : preg_replace_callback('/#([0-9a-fA-F]{2})/', fn($m) => chr(hexdec($m[1])), $s);
    }

    //UTF-16BE -> UTF-8 (a single byte: Latin-1)
    protected static function utf16($bytes) {
        if ($bytes === '')
            return '';
        if (strlen($bytes) === 1)
            return mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
        return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
    }

    // ------------------------------------------------------------------------------------
    //content streams

    //the next token of $s at $pos: [ type, value ] (null at the end)
    protected static function next_token($s, &$pos, $len) {
        while ($pos < $len) {
            $pos += strspn($s, self::WHITESPACE, $pos);
            if ($pos >= $len)
                return null;
            $c = $s[$pos];
            if ($c !== '%')
                break;
            $pos += strcspn($s, "\r\n", $pos);
        }
        if ($pos >= $len)
            return null;

        switch ($c) {
            case '/':
                $n = strcspn($s, self::DELIMITERS, $pos + 1);
                $name = substr($s, $pos + 1, $n);
                $pos += 1 + $n;
                return [ self::T_NAME, '/' . self::name_decode($name) ];
            case '(':
                return [ self::T_STR, (object)[ 's' => self::literal($s, $pos, $len) ] ];
            case '<':
                if (($s[$pos + 1] ?? '') === '<') {
                    $pos += 2;
                    return [ self::T_DICT, null ];
                }
                $end = strpos($s, '>', $pos);
                if ($end === false)
                    $end = $len;
                $hex = substr($s, $pos + 1, $end - $pos - 1);
                $pos = $end + 1;
                return [ self::T_STR, (object)[ 's' => self::hex_bytes($hex) ] ];
            case '>':
                $pos += (($s[$pos + 1] ?? '') === '>') ? 2 : 1;
                return [ self::T_DICT_END, null ];
            case '[':
                $pos++;
                return [ self::T_ARR, null ];
            case ']':
                $pos++;
                return [ self::T_ARR_END, null ];
            case '{':
            case '}':
            case ')':
                $pos++;
                return [ self::T_VAL, null ];
        }
        $n = strcspn($s, self::DELIMITERS, $pos);
        $t = substr($s, $pos, $n);
        $pos += $n;
        if (is_numeric($t))
            return [ self::T_NUM, $t + 0 ];
        //numbers such as 1.2.3 or --5 (bad writers): what PHP can read of them
        if (preg_match('/^[+-]*(\d*\.?\d+|\d+\.)/', $t, $m))
            return [ self::T_NUM, (float)ltrim($m[0], '+-') * (substr_count($m[0], '-') % 2 ? -1 : 1) ];
        if ($t === 'true' || $t === 'false')
            return [ self::T_VAL, $t === 'true' ];
        if ($t === 'null')
            return [ self::T_VAL, null ];
        return [ self::T_OP, $t ];
    }

    //the literal string at $pos (its "("), its escapes decoded; $pos after its ")"
    protected static function literal($s, &$pos, $len) {
        $pos++;
        $depth = 1;
        $out = '';
        while ($pos < $len) {
            $n = strcspn($s, '()\\', $pos);
            $out .= substr($s, $pos, $n);
            $pos += $n;
            if ($pos >= $len)
                break;
            $c = $s[$pos++];
            if ($c === '(') {
                $depth++;
                $out .= $c;
            } elseif ($c === ')') {
                if (--$depth === 0)
                    break;
                $out .= $c;
            } else {
                $e = $s[$pos] ?? '';
                if ($e >= '0' && $e <= '7') {
                    $oct = '';
                    while (strlen($oct) < 3 && $pos < $len && $s[$pos] >= '0' && $s[$pos] <= '7')
                        $oct .= $s[$pos++];
                    $out .= chr(octdec($oct) & 0xFF);
                    continue;
                }
                $pos++;
                switch ($e) {
                    case 'n': $out .= "\n"; break;
                    case 'r': $out .= "\r"; break;
                    case 't': $out .= "\t"; break;
                    case 'b': $out .= "\x08"; break;
                    case 'f': $out .= "\f"; break;
                    case "\r":
                        if (($s[$pos] ?? '') === "\n")
                            $pos++;
                        break;
                    case "\n":
                        break;
                    default:
                        $out .= $e;
                }
            }
        }
        return $out;
    }

    protected static function mul($m, $n) {
        return [
            $m[0] * $n[0] + $m[1] * $n[2], $m[0] * $n[1] + $m[1] * $n[3],
            $m[2] * $n[0] + $m[3] * $n[2], $m[2] * $n[1] + $m[3] * $n[3],
            $m[4] * $n[0] + $m[5] * $n[2] + $n[4], $m[4] * $n[1] + $m[5] * $n[3] + $n[5],
        ];
    }

    protected static function matrix($v) {
        if (!is_array($v) || count($v) < 6)
            return self::IDENTITY;
        $m = array_map(fn($x) => is_numeric($x) ? (float)$x : 0.0, array_slice(array_values($v), 0, 6));
        return $m;
    }

    //runs content $s with resources $res and graphics state $gs (the text runs and images it draws)
    protected function run_content($s, array $res, array $gs, $depth) {
        $len = strlen($s);
        $pos = 0;
        $ops = [];
        $marks = [];
        $stack = [];
        $tm = $tlm = self::IDENTITY;
        $text = $this->_opts['text'];
        $images = $this->_opts['images'];

        while (($tok = self::next_token($s, $pos, $len)) !== null) {
            [ $type, $v ] = $tok;
            switch ($type) {
                case self::T_ARR:
                case self::T_DICT:
                    $marks[] = [ count($ops), $type ];
                    continue 2;
                case self::T_ARR_END:
                case self::T_DICT_END:
                    $m = array_pop($marks);
                    if ($m === null)
                        continue 2;
                    $items = array_splice($ops, $m[0]);
                    if ($m[1] === self::T_DICT) {
                        $d = [];
                        for ($i = 0; $i + 1 < count($items); $i += 2)
                            if (is_string($items[$i]) && $items[$i] !== '' && $items[$i][0] === '/')
                                $d[substr($items[$i], 1)] = $items[$i + 1];
                        $ops[] = $d;
                    } else
                        $ops[] = $items;
                    continue 2;
                case self::T_OP:
                    break;
                default:
                    $ops[] = $v;
                    continue 2;
            }

            //an operator: $v, its operands $ops
            $marks = [];
            switch ($v) {
                case 'q':
                    $stack[] = $gs;
                    break;
                case 'Q':
                    if ($stack)
                        $gs = array_pop($stack);
                    break;
                case 'cm':
                    if (count($ops) >= 6)
                        $gs['ctm'] = self::mul(self::matrix(array_slice($ops, -6)), $gs['ctm']);
                    break;
                case 'BI':
                    $this->inline_image($s, $pos, $len, $res, $images && $this->_opts['inline']);
                    break;
                case 'Do':
                    $name = end($ops);
                    if (is_string($name) && ($text || $images))
                        $this->draw_xobject(substr($name, 1), $res, $gs, $depth);
                    break;
            }
            if ($text) {
                switch ($v) {
                    case 'BT':
                        $tm = $tlm = self::IDENTITY;
                        break;
                    case 'Tf':
                        if (count($ops) >= 2 && is_string($ops[0])) {
                            $gs['font'] = $this->font($res, substr($ops[0], 1));
                            $gs['fs'] = (float)$ops[1];
                        }
                        break;
                    case 'Tc': $gs['tc'] = (float)($ops[0] ?? 0); break;
                    case 'Tw': $gs['tw'] = (float)($ops[0] ?? 0); break;
                    case 'Tz': $gs['th'] = (float)($ops[0] ?? 100) / 100; break;
                    case 'TL': $gs['tl'] = (float)($ops[0] ?? 0); break;
                    case 'Ts': $gs['ts'] = (float)($ops[0] ?? 0); break;
                    case 'Td':
                    case 'TD':
                        if (count($ops) >= 2) {
                            [ $tx, $ty ] = [ (float)$ops[0], (float)$ops[1] ];
                            if ($v === 'TD')
                                $gs['tl'] = -$ty;
                            $tlm = [ $tlm[0], $tlm[1], $tlm[2], $tlm[3], $tx * $tlm[0] + $ty * $tlm[2] + $tlm[4], $tx * $tlm[1] + $ty * $tlm[3] + $tlm[5] ];
                            $tm = $tlm;
                        }
                        break;
                    case 'Tm':
                        if (count($ops) >= 6)
                            $tm = $tlm = self::matrix($ops);
                        break;
                    case 'T*':
                        $tlm = [ $tlm[0], $tlm[1], $tlm[2], $tlm[3], -$gs['tl'] * $tlm[2] + $tlm[4], -$gs['tl'] * $tlm[3] + $tlm[5] ];
                        $tm = $tlm;
                        break;
                    case 'Tj':
                        if ($ops)
                            $this->show([ end($ops) ], $gs, $tm);
                        break;
                    case 'TJ':
                        if ($ops && is_array(end($ops)))
                            $this->show(end($ops), $gs, $tm);
                        break;
                    case "'":
                    case '"':
                        if ($v === '"' && count($ops) >= 3) {
                            $gs['tw'] = (float)$ops[0];
                            $gs['tc'] = (float)$ops[1];
                        }
                        $tlm = [ $tlm[0], $tlm[1], $tlm[2], $tlm[3], -$gs['tl'] * $tlm[2] + $tlm[4], -$gs['tl'] * $tlm[3] + $tlm[5] ];
                        $tm = $tlm;
                        if ($ops)
                            $this->show([ end($ops) ], $gs, $tm);
                        break;
                }
            }
            $ops = [];
        }
    }

    //Do: a Form XObject (its content, in the current state) or an image
    protected function draw_xobject($name, array $res, array $gs, $depth) {
        $xobjects = $this->resolve($res['XObject'] ?? null);
        $ref = is_array($xobjects) ? ($xobjects[$name] ?? null) : null;
        $dict = $this->resolve($ref);
        if (!is_array($dict))
            return;
        $subtype = $dict['Subtype'] ?? null;
        if ($subtype === '/Image') {
            if ($this->_opts['images'] && self::is_ref($ref))
                $this->xobject_image($name, $ref, $dict, $res);
            return;
        }
        if ($subtype !== '/Form' || !self::is_ref($ref))
            return;
        if ($depth >= self::MAX_DEPTH || isset($this->_forms[$ref->r]))
            return;
        $content = $this->stream($ref, 'form');
        if ($content === null)
            return;
        $fres = $this->resolve($dict['Resources'] ?? null);
        $gs['ctm'] = self::mul(self::matrix($this->resolve($dict['Matrix'] ?? null)), $gs['ctm']);
        $this->_forms[$ref->r] = true;
        $this->run_content($content, is_array($fres) ? $fres : $res, $gs, $depth + 1);
        unset($this->_forms[$ref->r]);
    }

    // ------------------------------------------------------------------------------------
    //text

    //shows the strings of $items (strings and TJ adjustments): a text run; $tm is advanced
    protected function show(array $items, array $gs, array &$tm) {
        $font = $gs['font'];
        if ($font === null) {
            $this->warn('text without a font (Tf) left out');
            return;
        }
        $fs = $gs['fs'];
        $th = $gs['th'];
        $ctm = $gs['ctm'];
        $start = null;
        $out = '';
        $unmapped = 0;
        //the space between the last glyph and the next one (text space, unscaled): character / word spacing and TJ adjustments; wider than 0.12 em: a space
        $gap = 0;
        $ink = null;
        foreach ($items as $it) {
            if (is_int($it) || is_float($it)) {
                $gap += -$it / 1000 * $fs;
                $tx = -$it / 1000 * $fs * $th;
                $tm[4] += $tx * $tm[0];
                $tm[5] += $tx * $tm[1];
                continue;
            }
            if (!self::is_str($it))
                continue;
            if ($start === null)
                $start = $this->text_point($tm, $gs, $ctm);
            foreach ($this->codes($font, $it->s) as [ $code, $n ]) {
                $u = $this->unicode($font, $code, $n);
                $w = $font['w'][$code] ?? $font['dw'];
                //a space that does not move (negative word spacing: letters spaced out by the writer) is not a space
                if ($u === ' ' && ($w * $fs + $gs['tc'] + ($n === 1 && $code === 32 ? $gs['tw'] : 0)) < 0.1 * abs($fs))
                    $u = '';
                if ($u === null)
                    $unmapped++;
                elseif ($u !== '') {
                    if ($gap > 0.12 * abs($fs) && $out !== '' && !self::space_at($out, $u))
                        $out .= ' ';
                    $out .= $u;
                }
                $tx = $w * $fs * $th;
                $tm[4] += $tx * $tm[0];
                $tm[5] += $tx * $tm[1];
                //the end of the run: after the glyph, not after its spacing
                $ink = [ $tm[4], $tm[5] ];
                $gap = $gs['tc'] + ($n === 1 && $code === 32 ? $gs['tw'] : 0);
                $tm[4] += $gap * $th * $tm[0];
                $tm[5] += $gap * $th * $tm[1];
            }
        }
        if ($unmapped) {
            $this->_unmapped[$font['key']] ??= [ $font['label'], 0, $font['tu'] !== null ];
            $this->_unmapped[$font['key']][1] += $unmapped;
        }
        if ($start === null || $out === '')
            return;
        $end = $this->text_point([ $tm[0], $tm[1], $tm[2], $tm[3], $ink[0], $ink[1] ], $gs, $ctm);
        $this->_runs[] = [ 'x' => $start[0], 'y' => $start[1], 'ex' => $end[0], 'ey' => $end[1], 'dx' => $start[2], 'dy' => $start[3], 'h' => $start[4], 't' => $out ];
    }

    //the device position of the text point, the text's direction and its height: [ x, y, dx, dy, h ]
    protected function text_point(array $tm, array $gs, array $ctm) {
        $m = self::mul($tm, $ctm);
        $x = $gs['ts'] * $m[2] + $m[4];
        $y = $gs['ts'] * $m[3] + $m[5];
        $l = hypot($m[0], $m[1]) ?: 1;
        return [ $x, $y, $m[0] / $l, $m[1] / $l, abs($gs['fs']) * hypot($m[2], $m[3]) ];
    }

    //the text of a page from its runs: a space between the runs of a line that are apart, a line break when the line changes (an empty line when it is far)
    protected function layout(array $runs) {
        $out = '';
        $prev = null;
        foreach ($runs as $r) {
            if ($prev !== null) {
                $vx = $r['x'] - $prev['ex'];
                $vy = $r['y'] - $prev['ey'];
                $along = $vx * $prev['dx'] + $vy * $prev['dy'];
                $across = $vy * $prev['dx'] - $vx * $prev['dy'];
                $h = min($r['h'], $prev['h']) ?: max($r['h'], $prev['h'], 1);
                $same_dir = $r['dx'] * $prev['dx'] + $r['dy'] * $prev['dy'] > 0.9;
                if ($same_dir && abs($across) < 0.5 * $h) {
                    //the same text drawn again over itself (bold by overprinting): once
                    if ($r['t'] === $prev['t'] && abs($r['x'] - $prev['x']) < 0.3 * $h && abs($r['y'] - $prev['y']) < 0.3 * $h)
                        continue;
                    if (($along > 0.15 * $h || $along < -0.5 * $h) && !self::space_at($out, $r['t']))
                        $out .= ' ';
                } else
                    $out = rtrim($out, ' ') . ($same_dir && -$across > 2 * $h ? "\n\n" : "\n");
            }
            $out .= $r['t'];
            $prev = $r;
        }
        $out = strtr($out, [ "\u{FB00}" => 'ff', "\u{FB01}" => 'fi', "\u{FB02}" => 'fl', "\u{FB03}" => 'ffi', "\u{FB04}" => 'ffl', "\u{FB05}" => 'st', "\u{FB06}" => 'st',
            "\u{00A0}" => ' ', "\u{00AD}" => '-', "\r\n" => "\n", "\r" => "\n" ]);
        $out = mb_scrub($out, 'UTF-8');
        $out = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $out);
        $out = preg_replace('/[ \t]+$/m', '', $out);
        $out = preg_replace('/\n{3,}/', "\n\n", $out);
        return trim($out);
    }

    protected static function space_at($out, $next) {
        return $out === '' || ctype_space(substr($out, -1)) || ($next !== '' && ctype_space($next[0]));
    }

    // ------------------------------------------------------------------------------------
    //fonts

    //font $name of resources $res: [ key, label, two (codes of more than a byte), cs (code space), tu (ToUnicode: [ length => [ code => text ] ]), enc ([ code => text ]:
    //simple fonts), ucs2 (the codes are UTF-16), w ([ code => width, in text space ]), dw ]
    protected function font(array $res, $name) {
        $fonts = $this->resolve($res['Font'] ?? null);
        $ref = is_array($fonts) ? ($fonts[$name] ?? null) : null;
        $key = self::is_ref($ref) ? 'o' . $ref->r : 'n' . $name . '-' . md5(serialize($ref));
        if (isset($this->_fonts[$key]))
            return $this->_fonts[$key];
        $f = $this->resolve($ref);
        if (!is_array($f))
            $f = [];
        $subtype = $f['Subtype'] ?? '/Type1';
        $base = is_string($f['BaseFont'] ?? null) ? substr($f['BaseFont'], 1) : $name;
        $font = [ 'key' => $key, 'label' => $base, 'two' => false, 'cs' => null, 'tu' => null, 'enc' => [], 'ucs2' => false, 'w' => [], 'dw' => 0.5 ];

        if (isset($f['ToUnicode']) && self::is_ref($f['ToUnicode']) && ($cmap = $this->stream($f['ToUnicode'], 'ToUnicode')) !== null)
            $font['tu'] = self::parse_cmap($cmap);

        if ($subtype === '/Type0') {
            $font['two'] = true;
            $font['cs'] = [ [ 2, "\x00\x00", "\xFF\xFF" ] ];
            $identity = false;
            $enc = $f['Encoding'] ?? null;
            if (is_string($enc)) {
                $identity = in_array($enc, [ '/Identity-H', '/Identity-V' ], true);
                $font['ucs2'] = (bool)preg_match('/UCS2|UTF16/', $enc);
            } elseif (self::is_ref($enc) && ($cmap = $this->stream($enc, 'CMap')) !== null) {
                $cs = self::parse_cmap($cmap)['cs'];
                if ($cs)
                    $font['cs'] = $cs;
            }
            $desc = $this->resolve($f['DescendantFonts'] ?? null);
            $desc = is_array($desc) ? $this->resolve($desc[0] ?? null) : null;
            if (is_array($desc)) {
                $font['dw'] = (float)($this->resolve($desc['DW'] ?? null) ?? 1000) / 1000;
                //the widths are by CID: CID = the code with Identity, else unknown (DW)
                if ($identity)
                    $font['w'] = $this->cid_widths($this->resolve($desc['W'] ?? null));
            } else
                $font['dw'] = 1;
        } else {
            $fm = 0.001;
            if ($subtype === '/Type3') {
                $m = $this->resolve($f['FontMatrix'] ?? null);
                $fm = is_array($m) && is_numeric($m[0] ?? null) ? (float)$m[0] : 0.001;
            }
            $first = (int)($this->resolve($f['FirstChar'] ?? null) ?? 0);
            $widths = $this->resolve($f['Widths'] ?? null);
            if (is_array($widths)) {
                foreach (array_values($widths) as $i => $w)
                    $font['w'][$first + $i] = (float)$this->resolve($w) * $fm;
                $fd = $this->resolve($f['FontDescriptor'] ?? null);
                $font['dw'] = (float)(is_array($fd) ? ($this->resolve($fd['MissingWidth'] ?? null) ?? 0) : 0) * $fm;
            } else
                //the standard 14 fonts have no widths
                $font['dw'] = stripos($base, 'Courier') !== false ? 0.6 : 0.5;
            $font['enc'] = $this->simple_encoding($f['Encoding'] ?? null, $subtype);
        }
        return $this->_fonts[$key] = $font;
    }

    //CID widths from W: [ c [ w1 w2 ... ] c1 c2 w ... ]
    protected function cid_widths($w) {
        $r = [];
        if (!is_array($w))
            return $r;
        $w = array_values($w);
        $n = count($w);
        for ($i = 0; $i < $n; ) {
            $c = $this->resolve($w[$i]);
            $next = $this->resolve($w[$i + 1] ?? null);
            if (is_array($next)) {
                foreach (array_values($next) as $k => $v)
                    $r[(int)$c + $k] = (float)$this->resolve($v) / 1000;
                $i += 2;
            } elseif ($i + 2 < $n) {
                $last = (int)$next;
                $v = (float)$this->resolve($w[$i + 2]) / 1000;
                for ($k = (int)$c; $k <= $last && $k - (int)$c < 65536; $k++)
                    $r[$k] = $v;
                $i += 3;
            } else
                break;
        }
        return $r;
    }

    //[ code => text ] of a simple font: its base encoding (by name, BaseEncoding, or the default of its type), with its Differences
    protected function simple_encoding($enc, $subtype) {
        $tables = self::encodings();
        $enc = $this->resolve($enc);
        $base = $subtype === '/TrueType' ? 'WinAnsiEncoding' : 'StandardEncoding';
        if (is_string($enc))
            $base = substr($enc, 1);
        elseif (is_array($enc) && is_string($enc['BaseEncoding'] ?? null))
            $base = substr($enc['BaseEncoding'], 1);
        $map = $tables[$base] ?? $tables[$subtype === '/TrueType' ? 'WinAnsiEncoding' : 'StandardEncoding'];
        if (is_array($enc) && is_array($diff = $this->resolve($enc['Differences'] ?? null))) {
            $code = 0;
            foreach ($diff as $d) {
                $d = $this->resolve($d);
                if (is_int($d) || is_float($d))
                    $code = (int)$d;
                elseif (is_string($d) && $d !== '' && $d[0] === '/') {
                    $map[$code & 0xFF] = self::glyph_unicode(substr($d, 1));
                    $code++;
                }
            }
        }
        return $map;
    }

    //the codes of string $s in font $font: [ [ code, bytes ] ]
    protected function codes(array $font, $s) {
        $n = strlen($s);
        $r = [];
        if (!$font['two']) {
            for ($i = 0; $i < $n; $i++)
                $r[] = [ ord($s[$i]), 1 ];
            return $r;
        }
        $cs = $font['cs'];
        $lens = array_unique(array_column($cs, 0));
        if ($lens === [ 2 ]) {
            for ($i = 0; $i + 1 < $n; $i += 2)
                $r[] = [ (ord($s[$i]) << 8) | ord($s[$i + 1]), 2 ];
            return $r;
        }
        sort($lens);
        for ($i = 0; $i < $n; ) {
            $taken = false;
            foreach ($lens as $l) {
                if ($i + $l > $n)
                    break;
                $b = substr($s, $i, $l);
                foreach ($cs as [ $cl, $lo, $hi ]) {
                    if ($cl !== $l)
                        continue;
                    $in = true;
                    for ($k = 0; $k < $l && $in; $k++)
                        $in = $b[$k] >= $lo[$k] && $b[$k] <= $hi[$k];
                    if ($in) {
                        $r[] = [ $l === 1 ? ord($b) : hexdec(bin2hex($b)), $l ];
                        $i += $l;
                        $taken = true;
                        break 2;
                    }
                }
            }
            if (!$taken) {
                $l = min($lens[0], $n - $i);
                $r[] = [ hexdec(bin2hex(substr($s, $i, $l))), $l ];
                $i += max(1, $l);
            }
        }
        return $r;
    }

    //the text of a code; null when unknown
    protected function unicode(array $font, $code, $n) {
        if ($font['tu'] !== null && isset($font['tu']['map'][$n][$code]))
            return $font['tu']['map'][$n][$code];
        if ($font['ucs2'])
            return self::utf16(pack($n === 1 ? 'C' : 'n', $code));
        if ($font['two'])
            return null;
        $u = $font['enc'][$code] ?? '';
        return $u === '' ? null : $u;
    }

    //a CMap: [ cs: [ [ bytes, low, high ] ] (its code space), map: [ bytes => [ code => text ] ] (bfchar, bfrange) ]
    protected static function parse_cmap($s) {
        $r = [ 'cs' => [], 'map' => [] ];
        if (preg_match_all('/begincodespacerange(.*?)endcodespacerange/s', $s, $blocks))
            foreach ($blocks[1] as $b)
                if (preg_match_all('/<([0-9a-fA-F]*)>\s*<([0-9a-fA-F]*)>/', $b, $m, PREG_SET_ORDER))
                    foreach ($m as [ , $lo, $hi ]) {
                        $lo = self::hex_bytes($lo);
                        $hi = self::hex_bytes($hi);
                        if (strlen($lo) > 0 && strlen($lo) === strlen($hi))
                            $r['cs'][] = [ strlen($lo), $lo, $hi ];
                    }
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $s, $blocks))
            foreach ($blocks[1] as $b)
                if (preg_match_all('/<([0-9a-fA-F]+)>\s*(?:<([0-9a-fA-F]*)>|\/([^\s\[\]<>\/]+))/', $b, $m, PREG_SET_ORDER))
                    foreach ($m as $x) {
                        $src = self::hex_bytes($x[1]);
                        $r['map'][strlen($src)][hexdec(bin2hex($src))] = ($x[3] ?? '') !== '' ? self::glyph_unicode($x[3]) : self::utf16(self::hex_bytes($x[2]));
                    }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $s, $blocks))
            foreach ($blocks[1] as $b)
                if (preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*(?:<([0-9a-fA-F]*)>|\[(.*?)\])/s', $b, $m, PREG_SET_ORDER))
                    foreach ($m as $x) {
                        $lo_b = self::hex_bytes($x[1]);
                        $n = strlen($lo_b);
                        $lo = hexdec(bin2hex($lo_b));
                        $hi = min(hexdec(bin2hex(self::hex_bytes($x[2]))), $lo + 65535);
                        if (isset($x[4]) && $x[4] !== '') {
                            preg_match_all('/<([0-9a-fA-F]*)>/', $x[4], $dst);
                            foreach ($dst[1] as $i => $d)
                                if ($lo + $i <= $hi)
                                    $r['map'][$n][$lo + $i] = self::utf16(self::hex_bytes($d));
                            continue;
                        }
                        $dst = self::hex_bytes($x[3]);
                        if ($dst === '')
                            continue;
                        //the last byte (code unit) of the destination is incremented
                        $unit = strlen($dst) >= 2 ? 2 : 1;
                        $head = substr($dst, 0, -$unit);
                        $last = hexdec(bin2hex(substr($dst, -$unit)));
                        for ($c = $lo; $c <= $hi; $c++)
                            $r['map'][$n][$c] = self::utf16($head . pack($unit === 2 ? 'n' : 'C', ($last + $c - $lo) & ($unit === 2 ? 0xFFFF : 0xFF)));
                    }
        return $r;
    }

    //the text of a glyph name ('' when unknown): the glyph list, uniXXXX, uXXXX[XX], a.sc (the part before the dot), f_f_i (its parts)
    protected static function glyph_unicode($name) {
        $g = self::glyphs();
        if (isset($g[$name]))
            return $g[$name];
        if (preg_match('/^uni((?:[0-9A-Fa-f]{4})+)$/', $name, $m))
            return self::utf16(hex2bin($m[1]));
        if (preg_match('/^u([0-9A-Fa-f]{4,6})$/', $name, $m))
            return (string)mb_chr(hexdec($m[1]), 'UTF-8');
        $dot = strpos($name, '.');
        if ($dot > 0)
            return self::glyph_unicode(substr($name, 0, $dot));
        if (strpos($name, '_') !== false)
            return implode('', array_map([ self::class, 'glyph_unicode' ], explode('_', $name)));
        return '';
    }

    //glyph names (the Adobe Glyph List, its Latin, Greek and punctuation part) => text
    protected static function glyphs() {
        if (self::$_glyphs !== null)
            return self::$_glyphs;
        $g = [];
        $add = function($start, $names) use (&$g) {
            foreach (preg_split('/\s+/', trim($names)) as $i => $n)
                if ($n !== '-')
                    $g[$n] = mb_chr($start + $i, 'UTF-8');
        };
        $add(0x20, 'space exclam quotedbl numbersign dollar percent ampersand quotesingle parenleft parenright asterisk plus comma hyphen period slash
            zero one two three four five six seven eight nine colon semicolon less equal greater question at
            A B C D E F G H I J K L M N O P Q R S T U V W X Y Z bracketleft backslash bracketright asciicircum underscore grave
            a b c d e f g h i j k l m n o p q r s t u v w x y z braceleft bar braceright asciitilde');
        $add(0x391, 'Alpha Beta Gamma Delta Epsilon Zeta Eta Theta Iota Kappa Lambda Mu Nu Xi Omicron Pi Rho - Sigma Tau Upsilon Phi Chi Psi Omega');
        $add(0x3B1, 'alpha beta gamma delta epsilon zeta eta theta iota kappa lambda mu nu xi omicron pi rho sigma1 sigma tau upsilon phi chi psi omega');
        $add(0xA0, 'nbspace exclamdown cent sterling currency yen brokenbar section dieresis copyright ordfeminine guillemotleft logicalnot sfthyphen registered macron
            degree plusminus twosuperior threesuperior acute mu paragraph periodcentered cedilla onesuperior ordmasculine guillemotright onequarter onehalf threequarters questiondown
            Agrave Aacute Acircumflex Atilde Adieresis Aring AE Ccedilla Egrave Eacute Ecircumflex Edieresis Igrave Iacute Icircumflex Idieresis
            Eth Ntilde Ograve Oacute Ocircumflex Otilde Odieresis multiply Oslash Ugrave Uacute Ucircumflex Udieresis Yacute Thorn germandbls
            agrave aacute acircumflex atilde adieresis aring ae ccedilla egrave eacute ecircumflex edieresis igrave iacute icircumflex idieresis
            eth ntilde ograve oacute ocircumflex otilde odieresis divide oslash ugrave uacute ucircumflex udieresis yacute thorn ydieresis');
        $add(0x100, 'Amacron amacron Abreve abreve Aogonek aogonek Cacute cacute Ccircumflex ccircumflex Cdotaccent cdotaccent Ccaron ccaron Dcaron dcaron
            Dcroat dcroat Emacron emacron Ebreve ebreve Edotaccent edotaccent Eogonek eogonek Ecaron ecaron Gcircumflex gcircumflex Gbreve gbreve
            Gdotaccent gdotaccent Gcommaaccent gcommaaccent Hcircumflex hcircumflex Hbar hbar Itilde itilde Imacron imacron Ibreve ibreve Iogonek iogonek
            Idotaccent dotlessi IJ ij Jcircumflex jcircumflex Kcommaaccent kcommaaccent kgreenlandic Lacute lacute Lcommaaccent lcommaaccent Lcaron lcaron Ldot
            ldot Lslash lslash Nacute nacute Ncommaaccent ncommaaccent Ncaron ncaron napostrophe Eng eng Omacron omacron Obreve obreve
            Ohungarumlaut ohungarumlaut OE oe Racute racute Rcommaaccent rcommaaccent Rcaron rcaron Sacute sacute Scircumflex scircumflex Scedilla scedilla
            Scaron scaron Tcommaaccent tcommaaccent Tcaron tcaron Tbar tbar Utilde utilde Umacron umacron Ubreve ubreve Uring uring
            Uhungarumlaut uhungarumlaut Uogonek uogonek Wcircumflex wcircumflex Ycircumflex ycircumflex Ydieresis Zacute zacute Zdotaccent zdotaccent Zcaron zcaron longs');
        $g += [
            'Scommaaccent' => 'Ș', 'scommaaccent' => 'ș', 'Tcedilla' => 'Ţ', 'tcedilla' => 'ţ', 'florin' => 'ƒ',
            'quoteleft' => '‘', 'quoteright' => '’', 'quotedblleft' => '“', 'quotedblright' => '”', 'quotesinglbase' => '‚', 'quotedblbase' => '„',
            'guilsinglleft' => '‹', 'guilsinglright' => '›', 'bullet' => '•', 'endash' => '–', 'emdash' => '—', 'ellipsis' => '…', 'dagger' => '†', 'daggerdbl' => '‡',
            'perthousand' => '‰', 'trademark' => '™', 'Euro' => '€', 'circumflex' => 'ˆ', 'tilde' => '˜', 'caron' => 'ˇ', 'breve' => '˘', 'dotaccent' => '˙', 'ring' => '˚',
            'ogonek' => '˛', 'hungarumlaut' => '˝', 'fi' => 'fi', 'fl' => 'fl', 'ff' => 'ff', 'ffi' => 'ffi', 'ffl' => 'ffl', 'minus' => '−', 'fraction' => '⁄',
            'infinity' => '∞', 'lessequal' => '≤', 'greaterequal' => '≥', 'notequal' => '≠', 'approxequal' => '≈', 'partialdiff' => '∂', 'summation' => '∑',
            'product' => '∏', 'radical' => '√', 'integral' => '∫', 'lozenge' => '◊', 'arrowleft' => '←', 'arrowright' => '→', 'arrowup' => '↑', 'arrowdown' => '↓',
            'arrowboth' => '↔', 'checkmark' => '✓', 'section' => '§', 'estimated' => '℮', 'Ohm' => 'Ω', 'Omega' => 'Ω', 'mu' => 'µ', 'Delta' => 'Δ',
            'nbspace' => ' ', 'sfthyphen' => '-', 'space' => ' ', 'periodcentered' => '·', 'middot' => '·', 'quotereversed' => '‛', 'minute' => '′', 'second' => '″',
            'numero' => '№', 'afii61352' => '№', 'commaaccent' => '̦', 'dotlessj' => 'ȷ',
        ];
        return self::$_glyphs = $g;
    }

    //the base encodings: [ name => [ code => text ] ]
    protected static function encodings() {
        if (self::$_encodings !== null)
            return self::$_encodings;
        $win = $mac = $std = [];
        for ($c = 0x20; $c < 0x7F; $c++)
            $win[$c] = $mac[$c] = $std[$c] = chr($c);
        for ($c = 0x80; $c <= 0xFF; $c++) {
            $u = mb_convert_encoding(chr($c), 'UTF-8', 'Windows-1252');
            $win[$c] = $u === '?' ? '' : $u;
            $u = @iconv('MACINTOSH', 'UTF-8', chr($c));
            $mac[$c] = $u === false ? '' : $u;
        }
        $win[0xA0] = ' ';
        $win[0xAD] = '-';
        $std[0x27] = '’';
        $std[0x60] = '‘';
        $g = self::glyphs();
        $high = [ 0xA1 => 'exclamdown', 0xA2 => 'cent', 0xA3 => 'sterling', 0xA4 => 'fraction', 0xA5 => 'yen', 0xA6 => 'florin', 0xA7 => 'section', 0xA8 => 'currency',
            0xA9 => 'quotesingle', 0xAA => 'quotedblleft', 0xAB => 'guillemotleft', 0xAC => 'guilsinglleft', 0xAD => 'guilsinglright', 0xAE => 'fi', 0xAF => 'fl',
            0xB1 => 'endash', 0xB2 => 'dagger', 0xB3 => 'daggerdbl', 0xB4 => 'periodcentered', 0xB6 => 'paragraph', 0xB7 => 'bullet', 0xB8 => 'quotesinglbase',
            0xB9 => 'quotedblbase', 0xBA => 'quotedblright', 0xBB => 'guillemotright', 0xBC => 'ellipsis', 0xBD => 'perthousand', 0xBF => 'questiondown',
            0xC1 => 'grave', 0xC2 => 'acute', 0xC3 => 'circumflex', 0xC4 => 'tilde', 0xC5 => 'macron', 0xC6 => 'breve', 0xC7 => 'dotaccent', 0xC8 => 'dieresis',
            0xCA => 'ring', 0xCB => 'cedilla', 0xCD => 'hungarumlaut', 0xCE => 'ogonek', 0xCF => 'caron', 0xD0 => 'emdash', 0xE1 => 'AE', 0xE3 => 'ordfeminine',
            0xE8 => 'Lslash', 0xE9 => 'Oslash', 0xEA => 'OE', 0xEB => 'ordmasculine', 0xF1 => 'ae', 0xF5 => 'dotlessi', 0xF8 => 'lslash', 0xF9 => 'oslash', 0xFA => 'oe',
            0xFB => 'germandbls' ];
        foreach ($high as $c => $n)
            $std[$c] = $g[$n] ?? '';
        return self::$_encodings = [ 'WinAnsiEncoding' => $win, 'MacRomanEncoding' => $mac, 'StandardEncoding' => $std, 'MacExpertEncoding' => $std, 'PDFDocEncoding' => $win ];
    }

    // ------------------------------------------------------------------------------------
    //filters

    //[ filter names (without /, abbreviations expanded), their parameters ] of a stream or image dictionary
    protected function filters_of(array $dict) {
        $f = $this->resolve($dict['Filter'] ?? $dict['F'] ?? null);
        $p = $this->resolve($dict['DecodeParms'] ?? $dict['DP'] ?? null);
        $filters = [];
        foreach (is_array($f) ? $f : ($f === null ? [] : [ $f ]) as $x) {
            $x = $this->resolve($x);
            if (is_string($x) && $x !== '' && $x[0] === '/') {
                $n = substr($x, 1);
                $filters[] = self::INLINE_NAMES[$n] ?? $n;
            }
        }
        $parms = [];
        $list = is_array($p) && array_is_list($p) && $p !== [] ? $p : [ $p ];
        foreach ($filters as $i => $x) {
            $v = $this->resolve($list[$i] ?? null);
            $parms[$i] = is_array($v) ? array_map(fn($y) => $this->resolve($y), $v) : [];
        }
        return [ $filters, $parms ];
    }

    //decodes $data by $filters: [ data, the image filter it ends with (its data is not decoded) or null, error or null ]
    protected function decode_filters($data, array $filters, array $parms) {
        foreach ($filters as $i => $f) {
            $p = $parms[$i] ?? [];
            switch ($f) {
                case 'FlateDecode':
                    $data = self::inflate($data);
                    if ($data === false)
                        return [ null, null, 'FlateDecode: bad data' ];
                    $data = self::predictor($data, $p);
                    break;
                case 'LZWDecode':
                    $data = self::predictor(self::lzw($data, (int)($p['EarlyChange'] ?? 1)), $p);
                    break;
                case 'ASCIIHexDecode':
                    $end = strpos($data, '>');
                    $data = self::hex_bytes($end === false ? $data : substr($data, 0, $end));
                    break;
                case 'ASCII85Decode':
                    $data = self::ascii85($data);
                    break;
                case 'RunLengthDecode':
                    $data = self::run_length($data);
                    break;
                case 'Crypt':
                    if (($p['Name'] ?? '/Identity') !== '/Identity')
                        return [ null, null, 'Crypt: encrypted' ];
                    break;
                default:
                    if (in_array($f, self::IMAGE_FILTERS, true)) {
                        if ($i !== count($filters) - 1)
                            return [ null, null, "{$f} is not the last filter" ];
                        return [ $data, $f, null ];
                    }
                    return [ null, null, "unknown filter {$f}" ];
            }
        }
        return [ $data, null, null ];
    }

    //zlib data; what can be read of a damaged stream; false when nothing
    protected static function inflate($d) {
        $r = @gzuncompress($d);
        if ($r !== false)
            return $r;
        $r = @gzinflate($d);
        if ($r !== false)
            return $r;
        $ctx = @inflate_init(ZLIB_ENCODING_DEFLATE);
        $out = '';
        if ($ctx !== false)
            foreach (str_split($d, 8192) as $chunk) {
                $p = @inflate_add($ctx, $chunk, ZLIB_SYNC_FLUSH);
                if ($p === false)
                    break;
                $out .= $p;
            }
        return $out !== '' ? $out : false;
    }

    //the predictor of DecodeParms $p undone: TIFF 2 (8 bits), PNG 10 - 15
    protected static function predictor($data, array $p) {
        $pred = (int)($p['Predictor'] ?? 1);
        if ($pred < 2)
            return $data;
        $colors = max(1, (int)($p['Colors'] ?? 1));
        $bpc = max(1, (int)($p['BitsPerComponent'] ?? 8));
        $cols = max(1, (int)($p['Columns'] ?? 1));
        $bpp = max(1, intdiv($colors * $bpc + 7, 8));
        $rowlen = intdiv($colors * $bpc * $cols + 7, 8);

        if ($pred === 2) {
            if ($bpc !== 8)
                return $data;
            $out = '';
            foreach (str_split($data, $rowlen) as $row) {
                $b = array_values(unpack('C*', $row));
                for ($i = $colors, $n = count($b); $i < $n; $i++)
                    $b[$i] = ($b[$i] + $b[$i - $colors]) & 0xFF;
                $out .= pack('C*', ...$b);
            }
            return $out;
        }

        $out = '';
        $prev = array_fill(0, $rowlen, 0);
        $n = strlen($data);
        for ($pos = 0; $pos < $n; $pos += $rowlen + 1) {
            $type = ord($data[$pos]);
            $row = substr($data, $pos + 1, $rowlen);
            if (strlen($row) < $rowlen)
                $row = str_pad($row, $rowlen, "\0");
            $b = array_values(unpack('C*', $row));
            switch ($type) {
                case 1:
                    for ($i = $bpp; $i < $rowlen; $i++)
                        $b[$i] = ($b[$i] + $b[$i - $bpp]) & 0xFF;
                    break;
                case 2:
                    for ($i = 0; $i < $rowlen; $i++)
                        $b[$i] = ($b[$i] + $prev[$i]) & 0xFF;
                    break;
                case 3:
                    for ($i = 0; $i < $rowlen; $i++)
                        $b[$i] = ($b[$i] + (($i >= $bpp ? $b[$i - $bpp] : 0) + $prev[$i] >> 1)) & 0xFF;
                    break;
                case 4:
                    for ($i = 0; $i < $rowlen; $i++) {
                        $a = $i >= $bpp ? $b[$i - $bpp] : 0;
                        $up = $prev[$i];
                        $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                        $pp = $a + $up - $c;
                        $pa = abs($pp - $a);
                        $pb = abs($pp - $up);
                        $pc = abs($pp - $c);
                        $b[$i] = ($b[$i] + ($pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $up : $c))) & 0xFF;
                    }
                    break;
            }
            $out .= pack('C*', ...$b);
            $prev = $b;
        }
        return $out;
    }

    protected static function lzw($data, $early = 1) {
        $dict = [];
        $next = 258;
        $bits = 9;
        $out = '';
        $prev = null;
        $buf = 0;
        $nb = 0;
        $n = strlen($data);
        for ($i = 0; $i < $n; $i++) {
            $buf = ($buf << 8) | ord($data[$i]);
            $nb += 8;
            while ($nb >= $bits) {
                $code = ($buf >> ($nb - $bits)) & ((1 << $bits) - 1);
                $nb -= $bits;
                $buf &= (1 << $nb) - 1;
                if ($code === 256) {
                    $dict = [];
                    $next = 258;
                    $bits = 9;
                    $prev = null;
                    continue;
                }
                if ($code === 257)
                    return $out;
                if ($code < 256)
                    $entry = chr($code);
                elseif (isset($dict[$code]))
                    $entry = $dict[$code];
                elseif ($code === $next && $prev !== null)
                    $entry = $prev . $prev[0];
                else
                    return $out;
                $out .= $entry;
                if ($prev !== null && $next < 4096)
                    $dict[$next++] = $prev . $entry[0];
                $prev = $entry;
                if ($next + $early >= (1 << $bits) && $bits < 12)
                    $bits++;
            }
        }
        return $out;
    }

    protected static function ascii85($s) {
        $s = preg_replace('/\s+/', '', $s);
        if (strncmp($s, '<~', 2) === 0)
            $s = substr($s, 2);
        $end = strpos($s, '~>');
        if ($end !== false)
            $s = substr($s, 0, $end);
        $out = '';
        $group = [];
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($c === 'z' && !$group) {
                $out .= "\0\0\0\0";
                continue;
            }
            $v = ord($c) - 33;
            if ($v < 0 || $v > 84)
                continue;
            $group[] = $v;
            if (count($group) === 5) {
                $out .= pack('N', (((($group[0] * 85 + $group[1]) * 85 + $group[2]) * 85 + $group[3]) * 85 + $group[4]) & 0xFFFFFFFF);
                $group = [];
            }
        }
        if (count($group) > 1) {
            $k = count($group);
            $group = array_pad($group, 5, 84);
            $out .= substr(pack('N', (((($group[0] * 85 + $group[1]) * 85 + $group[2]) * 85 + $group[3]) * 85 + $group[4]) & 0xFFFFFFFF), 0, $k - 1);
        }
        return $out;
    }

    protected static function run_length($s) {
        $out = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; ) {
            $l = ord($s[$i++]);
            if ($l === 128)
                break;
            if ($l < 128) {
                $out .= substr($s, $i, $l + 1);
                $i += $l + 1;
            } else {
                $out .= str_repeat($s[$i] ?? '', 257 - $l);
                $i++;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------
    //images

    //an image XObject, once (the pages drawing it are added to it)
    protected function xobject_image($name, $ref, array $dict, array $res) {
        $oid = $ref->r;
        if (array_key_exists($oid, $this->_image_index)) {
            $i = $this->_image_index[$oid];
            if ($i !== false && !in_array($this->_page, $this->_images[$i]['pages'], true))
                $this->_images[$i]['pages'][] = $this->_page;
            return;
        }
        $this->_image_index[$oid] = false;
        $o = $this->obj($ref);
        if (!$o)
            return;
        $i = $this->image($dict, (string)$o->get_stream(true), $res, $name, $oid);
        if ($i !== null)
            $this->_image_index[$oid] = $i;
    }

    //an inline image (after BI: its dictionary, ID, its data, EI); $pos after EI
    protected function inline_image($s, &$pos, $len, array $res, $keep) {
        $dict = [];
        $key = null;
        $marks = [];
        $ops = [];
        while (($tok = self::next_token($s, $pos, $len)) !== null) {
            [ $type, $v ] = $tok;
            if ($type === self::T_OP && $v === 'ID')
                break;
            if ($type === self::T_ARR) {
                $marks[] = count($ops);
                continue;
            }
            if ($type === self::T_ARR_END) {
                $m = array_pop($marks);
                $ops[] = array_splice($ops, $m ?? 0);
                continue;
            }
            if ($type === self::T_DICT || $type === self::T_DICT_END)
                continue;
            $ops[] = $v;
        }
        for ($i = 0; $i + 1 < count($ops); $i += 2)
            if (is_string($ops[$i]) && $ops[$i] !== '' && $ops[$i][0] === '/') {
                $k = substr($ops[$i], 1);
                $dict[self::INLINE_KEYS[$k] ?? $k] = self::inline_value($ops[$i + 1]);
            }
        //the data: after one white space; its length when known (no filter), else up to EI
        $pos++;
        $w = (int)($dict['Width'] ?? 0);
        $h = (int)($dict['Height'] ?? 0);
        $data = null;
        if (empty($dict['Filter'])) {
            $cs = $dict['ColorSpace'] ?? '/DeviceGray';
            $n = !empty($dict['ImageMask']) ? 1 : (is_string($cs) ? ([ '/DeviceRGB' => 3, '/DeviceCMYK' => 4 ][$cs] ?? 1) : 1);
            $bpc = !empty($dict['ImageMask']) ? 1 : (int)($dict['BitsPerComponent'] ?? 8);
            $l = intdiv($w * $n * $bpc + 7, 8) * $h;
            $after = $pos + $l;
            $rest = substr($s, $after, 16);
            if ($l > 0 && preg_match('/^[\s\0]*EI(?=[\s\0\/\[<(%]|$)/', $rest, $m)) {
                $data = substr($s, $pos, $l);
                $pos = $after + strlen($m[0]);
            }
        }
        if ($data === null) {
            if (preg_match('/[\s\0]EI(?=[\s\0\/\[<(%]|$)/', $s, $m, PREG_OFFSET_CAPTURE, $pos)) {
                $data = substr($s, $pos, $m[0][1] - $pos);
                $pos = $m[0][1] + 3;
            } else {
                $data = substr($s, $pos);
                $pos = $len;
            }
        }
        if ($keep)
            $this->image($dict, $data, $res, 'inline', null);
    }

    protected static function inline_value($v) {
        if (is_string($v) && $v !== '' && $v[0] === '/')
            return '/' . (self::INLINE_NAMES[substr($v, 1)] ?? substr($v, 1));
        if (is_array($v) && array_is_list($v))
            return array_map([ self::class, 'inline_value' ], $v);
        return $v;
    }

    //an image (dictionary $dict, stream $raw), added to the images: its index; null when left out
    protected function image(array $dict, $raw, array $res, $name, $oid) {
        $o = $this->_opts;
        $w = (int)$this->resolve($dict['Width'] ?? null);
        $h = (int)$this->resolve($dict['Height'] ?? null);
        if ($w < 1 || $h < 1 || $w < $o['min_width'] || $h < $o['min_height'])
            return null;
        $label = "page {$this->_page} {$name} ({$w}x{$h})";
        if (count($this->_images) >= $o['max_images']) {
            $this->warn("only the first {$o['max_images']} images are extracted");
            return null;
        }
        if ($w * $h > $o['max_pixels']) {
            $this->warn("{$label}: too large (more than {$o['max_pixels']} pixels), left out");
            return null;
        }
        $mask = !empty($this->resolve($dict['ImageMask'] ?? null));
        $cs = $mask ? [ 'type' => 'mask', 'n' => 1, 'name' => 'ImageMask' ] : $this->colorspace($dict['ColorSpace'] ?? null, $res);
        $bpc = $mask ? 1 : (int)($this->resolve($dict['BitsPerComponent'] ?? null) ?? 8);
        [ $filters, $parms ] = $this->filters_of($dict);
        $final = $filters && in_array(end($filters), self::IMAGE_FILTERS, true) ? end($filters) : null;

        $rec = [ 'page' => $this->_page, 'pages' => [ $this->_page ], 'name' => $name, 'oid' => $oid, 'width' => $w, 'height' => $h, 'bpc' => $bpc,
            'colorspace' => $cs['name'], 'filter' => $filters ? end($filters) : null, 'mime' => null, 'ext' => null, 'data' => null ];
        if ($final === 'CCITTFaxDecode' || $final === 'JBIG2Decode') {
            $this->warn("{$label}: {$final} images are not extracted");
            return null;
        }
        if ($final === 'JPXDecode' && !$o['jp2']) {
            $this->warn("{$label}: JPEG 2000 (JPXDecode), left out (option jp2)");
            return null;
        }
        if ($final === null && $cs['type'] === 'unknown') {
            $this->warn("{$label}: color space {$cs['name']} is not supported, left out");
            return null;
        }
        if ($final === null && !in_array($bpc, [ 1, 2, 4, 8, 16 ], true)) {
            $this->warn("{$label}: {$bpc} bits per component are not supported, left out");
            return null;
        }
        //CLD: 2026-10-27T23:40:00+02:00: $final ?? '' (null as an array offset is deprecated since PHP 8.5)
        [ $rec['mime'], $rec['ext'] ] = [ 'DCTDecode' => [ 'image/jpeg', 'jpg' ], 'JPXDecode' => [ 'image/jp2', 'jp2' ] ][$final ?? ''] ?? [ 'image/png', 'png' ];

        if ($o['with_data']) {
            $alpha = ($final === null && $o['masks'] && !$mask && isset($dict['SMask'])) ? $this->alpha($dict['SMask'], $w, $h, $label) : null;
            //the PNG straight from the stream: one FlateDecode filter with the PNG predictor, the samples as they are
            $png = null;
            if ($alpha === null && $final === null && count($filters) === 1 && $filters[0] === 'FlateDecode' && (int)($parms[0]['Predictor'] ?? 1) >= 10
                && (int)($parms[0]['Colors'] ?? 1) === $cs['n'] && (int)($parms[0]['BitsPerComponent'] ?? 8) === $bpc && (int)($parms[0]['Columns'] ?? 1) === $w
                && !$this->inverted($dict, $cs) && ($direct = self::png_direct($cs, $bpc)) !== null)
                $png = self::png($w, $h, $bpc, $direct, '', 0, $cs['type'] === 'indexed' ? $this->palette($cs) : null, $raw);
            if ($png === null) {
                [ $data, $f, $err ] = $this->decode_filters($raw, $filters, $parms);
                if ($err !== null) {
                    $this->warn("{$label}: {$err}, left out");
                    return null;
                }
                $png = $f !== null ? $data : $this->to_png($dict, $data, $cs, $w, $h, $bpc, $alpha);
                if ($png === null) {
                    $this->warn("{$label}: cannot be converted, left out");
                    return null;
                }
            }
            $rec['data'] = $png;
        }
        $this->_images[] = $rec;
        return count($this->_images) - 1;
    }

    //a color space: [ type (gray, rgb, cmyk, indexed, sep, unknown), n (components), name, base, hival, lookup ]
    protected function colorspace($cs, array $res, $depth = 0) {
        $cs = $this->resolve($cs);
        $unknown = fn($name) => [ 'type' => 'unknown', 'n' => 1, 'name' => $name ];
        if ($depth > 8)
            return $unknown('?');
        if (is_string($cs)) {
            $n = substr($cs, 1);
            switch ($n) {
                case 'DeviceGray': case 'CalGray': case 'G':
                    return [ 'type' => 'gray', 'n' => 1, 'name' => $n ];
                case 'DeviceRGB': case 'CalRGB': case 'RGB':
                    return [ 'type' => 'rgb', 'n' => 3, 'name' => $n ];
                case 'DeviceCMYK': case 'CMYK':
                    return [ 'type' => 'cmyk', 'n' => 4, 'name' => $n ];
            }
            //a color space of the resources
            $named = $this->resolve($res['ColorSpace'] ?? null);
            if (is_array($named) && isset($named[$n]))
                return $this->colorspace($named[$n], $res, $depth + 1);
            return $unknown($n);
        }
        if (!is_array($cs) || !$cs || !is_string($cs[0] ?? null))
            return [ 'type' => 'gray', 'n' => 1, 'name' => 'DeviceGray' ];
        $kind = substr($cs[0], 1);
        switch ($kind) {
            case 'ICCBased':
                $icc = $this->resolve($cs[1] ?? null);
                $n = is_array($icc) ? (int)($this->resolve($icc['N'] ?? null) ?? 3) : 3;
                $type = [ 1 => 'gray', 3 => 'rgb', 4 => 'cmyk' ][$n] ?? null;
                if ($type === null)
                    return is_array($icc) && isset($icc['Alternate']) ? $this->colorspace($icc['Alternate'], $res, $depth + 1) : $unknown("ICCBased N={$n}");
                return [ 'type' => $type, 'n' => $n, 'name' => "ICCBased ({$type})" ];
            case 'CalGray':
                return [ 'type' => 'gray', 'n' => 1, 'name' => 'CalGray' ];
            case 'CalRGB':
                return [ 'type' => 'rgb', 'n' => 3, 'name' => 'CalRGB' ];
            case 'Indexed':
            case 'I':
                $base = $this->colorspace($cs[1] ?? null, $res, $depth + 1);
                $hival = (int)$this->resolve($cs[2] ?? 0);
                $lookup = $cs[3] ?? null;
                $bytes = null;
                if (self::is_str($lookup))
                    $bytes = $lookup->s;
                elseif ($this->is_stream($lookup))
                    $bytes = $this->stream($lookup, 'lookup');
                elseif (self::is_str($str = $this->resolve($lookup)))
                    $bytes = $str->s;
                if ($bytes === null || !in_array($base['type'], [ 'gray', 'rgb', 'cmyk' ], true))
                    return $unknown('Indexed (' . $base['name'] . ')');
                return [ 'type' => 'indexed', 'n' => 1, 'name' => 'Indexed (' . $base['name'] . ')', 'base' => $base, 'hival' => max(0, min(255, $hival)), 'lookup' => $bytes ];
            case 'Separation':
                return [ 'type' => 'sep', 'n' => 1, 'name' => 'Separation' ];
        }
        return $unknown($kind);
    }

    protected function is_stream($ref) {
        if (!self::is_ref($ref))
            return false;
        $o = $this->obj($ref);
        return $o && (string)$o->get_stream(true) !== '';
    }

    //the palette of an Indexed color space: RGB triples
    protected function palette(array $cs) {
        $base = $cs['base'];
        $count = $cs['hival'] + 1;
        $lookup = str_pad(substr($cs['lookup'], 0, $count * $base['n']), $count * $base['n'], "\0");
        if ($base['type'] === 'rgb')
            return $lookup;
        $out = '';
        $b = array_values(unpack('C*', $lookup));
        for ($i = 0; $i < $count; $i++) {
            if ($base['type'] === 'gray')
                $out .= str_repeat(chr($b[$i]), 3);
            else
                $out .= pack('C3', ...self::cmyk_rgb($b[$i * 4], $b[$i * 4 + 1], $b[$i * 4 + 2], $b[$i * 4 + 3]));
        }
        return $out;
    }

    protected static function cmyk_rgb($c, $m, $y, $k) {
        return [ intdiv((255 - $c) * (255 - $k), 255), intdiv((255 - $m) * (255 - $k), 255), intdiv((255 - $y) * (255 - $k), 255) ];
    }

    //Decode [ 1 0 ... ]: the samples inverted
    protected function inverted(array $dict, array $cs) {
        $d = $this->resolve($dict['Decode'] ?? null);
        if (!is_array($d) || count($d) < 2 || $cs['type'] === 'indexed')
            return false;
        for ($i = 0; $i + 1 < count($d); $i += 2)
            if ((float)$d[$i] !== 1.0 || (float)$d[$i + 1] !== 0.0)
                return false;
        return true;
    }

    //the PNG color type the samples are as they are (no conversion); null when they are not
    protected static function png_direct(array $cs, $bpc) {
        switch ($cs['type']) {
            case 'gray':
            case 'mask':
                return 0;
            case 'rgb':
                return $bpc >= 8 ? 2 : null;
            case 'indexed':
                return $bpc <= 8 ? 3 : null;
        }
        return null;
    }

    //the alpha channel of SMask $ref: w * h bytes; null when it cannot be used
    protected function alpha($ref, $w, $h, $label) {
        $dict = $this->resolve($ref);
        $o = $this->obj($ref);
        if (!is_array($dict) || !$o)
            return null;
        if ((int)$this->resolve($dict['Width'] ?? null) !== $w || (int)$this->resolve($dict['Height'] ?? null) !== $h) {
            $this->warn("{$label}: its alpha mask (SMask) has another size, left out");
            return null;
        }
        [ $filters, $parms ] = $this->filters_of($dict);
        [ $data, $final, $err ] = $this->decode_filters((string)$o->get_stream(true), $filters, $parms);
        if ($err !== null || $final !== null) {
            $this->warn("{$label}: its alpha mask (SMask) cannot be read, left out");
            return null;
        }
        $bpc = (int)($this->resolve($dict['BitsPerComponent'] ?? null) ?? 8);
        if ($bpc === 8)
            return str_pad(substr($data, 0, $w * $h), $w * $h, "\xFF");
        if (!in_array($bpc, [ 1, 2, 4, 16 ], true))
            return null;
        $rowlen = intdiv($w * $bpc + 7, 8);
        $out = '';
        for ($y = 0; $y < $h; $y++)
            $out .= pack('C*', ...self::samples(str_pad(substr($data, $y * $rowlen, $rowlen), $rowlen, "\xFF"), $w, $bpc, true));
        return $out;
    }

    //the samples of a row: 8 bit values (scaled), or as they are (indexes)
    protected static function samples($row, $count, $bpc, $scale) {
        if ($count < 1)
            return [];
        if ($bpc === 8)
            return array_slice(array_values(unpack('C*', $row)), 0, $count);
        if ($bpc === 16) {
            $v = array_slice(array_values(unpack('n*', $row)), 0, $count);
            return $scale ? array_map(fn($x) => $x >> 8, $v) : $v;
        }
        $out = [];
        $max = (1 << $bpc) - 1;
        $per = intdiv(8, $bpc);
        $n = strlen($row);
        for ($i = 0; $i < $n && count($out) < $count; $i++) {
            $b = ord($row[$i]);
            for ($k = $per - 1; $k >= 0 && count($out) < $count; $k--) {
                $v = ($b >> ($k * $bpc)) & $max;
                $out[] = $scale ? intdiv($v * 255, $max) : $v;
            }
        }
        return $out;
    }

    //decoded samples $data as a PNG; null when they cannot be converted
    protected function to_png(array $dict, $data, array $cs, $w, $h, $bpc, $alpha) {
        $n = $cs['n'];
        $rowlen = intdiv($w * $n * $bpc + 7, 8);
        $size = $rowlen * $h;
        $data = strlen($data) >= $size ? substr($data, 0, $size) : str_pad($data, $size, "\0");
        $invert = $this->inverted($dict, $cs);
        $type = $cs['type'];
        //Separation: the tint is ink (1: full)
        if ($type === 'sep') {
            $type = 'gray';
            $invert = !$invert;
        }

        if ($alpha === null && ($direct = self::png_direct([ 'type' => $type ], $bpc)) !== null) {
            if ($invert)
                $data ^= str_repeat("\xFF", $size);
            return self::png($w, $h, $bpc, $direct, $data, $rowlen, $type === 'indexed' ? $this->palette($cs) : null);
        }

        //8 bits: gray / RGB (CMYK, Indexed, other bit depths), with alpha
        $gray = $type === 'gray' || $type === 'mask';
        $ctype = $gray ? ($alpha === null ? 0 : 4) : ($alpha === null ? 2 : 6);
        $palette = $type === 'indexed' ? array_values(unpack('C*', $this->palette($cs))) : null;
        $out = '';
        for ($y = 0; $y < $h; $y++) {
            $s = self::samples(substr($data, $y * $rowlen, $rowlen), $w * $n, $bpc, $type !== 'indexed');
            if ($invert)
                foreach ($s as $i => $v)
                    $s[$i] = 255 - $v;
            $px = [];
            for ($x = 0; $x < $w; $x++) {
                switch ($type) {
                    case 'gray':
                    case 'mask':
                        $px[] = $s[$x] ?? 0;
                        break;
                    case 'rgb':
                        array_push($px, $s[$x * 3] ?? 0, $s[$x * 3 + 1] ?? 0, $s[$x * 3 + 2] ?? 0);
                        break;
                    case 'cmyk':
                        array_push($px, ...self::cmyk_rgb($s[$x * 4] ?? 0, $s[$x * 4 + 1] ?? 0, $s[$x * 4 + 2] ?? 0, $s[$x * 4 + 3] ?? 0));
                        break;
                    case 'indexed':
                        $i = min($s[$x] ?? 0, $cs['hival']) * 3;
                        array_push($px, $palette[$i], $palette[$i + 1], $palette[$i + 2]);
                        break;
                }
                if ($alpha !== null)
                    $px[] = ord($alpha[$y * $w + $x]);
            }
            $out .= pack('C*', ...$px);
        }
        $channels = [ 0 => 1, 2 => 3, 4 => 2, 6 => 4 ][$ctype];
        return self::png($w, $h, 8, $ctype, $out, $w * $channels);
    }

    //a PNG: rows $data of $rowlen bytes (filter 0 added), or $idat (the zlib stream of rows with their filter bytes); $palette: RGB triples (color type 3)
    protected static function png($w, $h, $depth, $ctype, $data, $rowlen, $palette = null, $idat = null) {
        $chunk = fn($type, $d) => pack('N', strlen($d)) . $type . $d . pack('N', crc32($type . $d));
        if ($idat === null)
            $idat = gzcompress("\0" . implode("\0", str_split($data, max(1, $rowlen))), 6);
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $w, $h, $depth, $ctype, 0, 0, 0))
            . ($palette !== null ? $chunk('PLTE', $palette) : '')
            . $chunk('IDAT', $idat)
            . $chunk('IEND', '');
    }
}
