<?php

namespace sys;

class Assets extends BaseObject {

    // published assets
    private $_published = [];

    public function __construct($config = []) {
	$defaults = [
	    // the root directory storing the published asset files
	    'basePath' => BASE . '/public_html/assets',
	    // the base URL through which the published asset files can be accessed.
	    'baseUrl' => '/assets',
	    //permission to be set on directories
	    'dirMode' => 0775,
	    //permission to be set on files
	    'fileMode' => 0644,
	    //append timestamp ( will look like /path/to/asset?v=timestamp )
	    'appendTimestamp' => false
	];
	$config += $defaults;
	parent::__construct($config);

    }

    // Initializes the component, checkind permissions
    public function _init() {
        parent::_init();

        if (!is_dir($this->basePath)) {
            throw new \ErrorException("The directory does not exist: {$this->basePath}");
        }
        if (!is_writable($this->basePath)) {
            throw new \ErrorException("The directory is not writable by the Web process: {$this->basePath}");
        }
    }

    public function createCSSBundle($files = []) {
	return $this->__createBundle($files, 'css');
    }

    public function createJsBundle($files = []) {
	return $this->__createBundle($files, 'js');
    }

    //creates a bundle of files
    public function __createBundle($files = [], $suffix) {
	//generate a filename from md5(concatenated files)
	//will save the bundle in __bundles/fsfasfafsd.[js|css]
	$hashsource = implode('', $files) . count($files);
	$fileName = md5($hashsource) . '.' . $suffix;
	$dir = $suffix;

        $dstDir = $this->basePath . '/' . $dir;
        $dstFile = $dstDir . '/' . $fileName;

        if (!is_dir($dstDir))
            mkdir($dstDir, $this->dirMode, true);

	//if file does not exists => create
	$modified = false;
	if (!is_readable($dstFile)) {
	    touch($dstFile);
            @chmod($dstFile, $this->fileMode);
            $modified = true;
	}

	foreach($files as $file) {
    	    if (@filemtime($dstFile) < @filemtime($file)) {
    		$modified = true;
    		break;
    	    }
	}
	if ($modified) {
	    file_put_contents($dstFile, '');
	    foreach($files as $file) {
		$c = file_get_contents($file);
		file_put_contents($dstFile, "/* $file */\n", FILE_APPEND);
		file_put_contents($dstFile, $c, FILE_APPEND);
		file_put_contents($dstFile, "\n", FILE_APPEND);
	    }
	}
	
	$url = $this->baseUrl . "/$dir/$fileName";
	if ($this->appendTimestamp && ($ts = @filemtime($src)) > 0) {
	    $url .= "?v=$ts";
	}
	return $url;
    }

    //returns the Base URL of a published file
    public function getBaseUrl($src) {
        $dir = sprintf('%x', crc32(dirname($src)));
	return "{$this->baseUrl}/{$dir}";
    }


    // Publishes a file: $src the asset file to be published
    public function publishFile($src) {
	//compute a hash
        $dir = sprintf('%x', crc32(dirname($src)));

        $fileName = basename($src);
        $dstDir = $this->basePath . '/' . $dir;
        $dstFile = $dstDir . '/' . $fileName;

        if (!is_dir($dstDir)) {
            mkdir($dstDir, $this->dirMode, true);
        }

        if (@filemtime($dstFile) < @filemtime($src)) {
            copy($src, $dstFile);
            @chmod($dstFile, $this->fileMode);
        }

	$base_url = "{$this->baseUrl}/{$dir}";
	$url = "{$base_url}/{$fileName}";

	if ($this->appendTimestamp && ($ts = @filemtime($src)) > 0) {
	    $url .= "?v=$ts";
	}
	$this->_published[$src] = [ $dstFile, $url, $base_url ];
	return $url;
    }

    // Returns the published path of a file path.
    public function getPublishedPath($path) {
        if (isset($this->_published[$path])) {
            return $this->_published[$path][0];
        }
        return false;
    }

    // Returns the URL of a published file path.
    public function getPublishedUrl($path) {
        if (isset($this->_published[$path])) {
            return $this->_published[$path][1];
        }
        return false;
    }

    //returns the Base URL of a published file path
    public function getPublishedBaseUrl($path) {
        if (isset($this->_published[$path])) {
            return $this->_published[$path][2];
        }
        return false;
    }
}
