<?php

namespace sys;

class View extends \sys\BaseObject {

    private $_classes = [];

    public function __construct($config = []) {
	$defaults  = [
	    'views_path' => 'views', //relative to app or app/module directory
	    'module_path' => \V::app()->config('rt.module_path'),
	    'layouts_path' => 'layout', //relative to app directory
	    'default_layout' => 'main',
	    'raw' => false,
	];
	$cfg = \util\Set::merge($defaults, $config);
	parent::__construct($cfg);
    }

    /**
     * Renders output with given template
     *
     * @param string $template Name of the template to be rendererd
     * @param array  $args     Args for view
     */
    //web
    public function renderFile($template, $data = [] ) {
	//resolve template file
	$tpl_file = BASE . '/app/';
	//CLD: 2026-10-08T09:04:38+03:00: the module (plugin) folder only for relative templates: absolute ones (/layouts/...) are relative to the app directory
	//(were looked up in the plugin folder too, so a plugin page and then its error page failed in a loop)
	if (!empty($this->_config['module_path']) && $template[0] != '/')
	    $tpl_file .= $this->_config['module_path'] . '/';
	if ($template[0] != '/')
	    $tpl_file .= $this->_config['views_path'] . '/';
	$tpl_file .= $template . '.php';

	if (!is_readable($tpl_file))
	    \V::app()->error(500, "File not found or not readable: $tpl_file");

        extract($data);
        ob_start();
        require $tpl_file;
        return ob_get_clean();
    }


    public function render($template, $data = [], $layout = null) {
	if ($this->_config['raw'])
	    $layout = false;

	//whether to use a layout
	if ($layout === null)
	    $layout = $this->default_layout;

	$output = (empty($template) ? $data['content'] : $this->renderFile($template, $data));

	//render via layout? => determine layout file
	if ($layout !== false) {
	    $layout = "/{$this->layouts_path}/{$layout}";
	    $output = $this->renderFile($layout, [ 'content' => $output ]);
	} else {
	    $output .= \V::app()->cs->renderPageScripts();
	}

	return \V::app()->response->write($output);
    }
}
