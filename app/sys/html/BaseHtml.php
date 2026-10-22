<?php

namespace sys\html;

/**
 * A template helper that assists in generating HTML content. Accessible in templates via
 * `$this->html`, which will auto-load this helper into the rendering context. For examples of how
 * to use this helper, see the documentation for a specific method. For a list of the
 * template strings this helper uses, see the `$_strings` property.
 */

class BaseHtml extends \sys\BaseObject {

    /**
     * String templates used by this helper.
     *
     * @var array
     */
    protected $_strings = array(
		'block'            => '<div{:options}>{:content}</div>',
		'block-start'      => '<div{:options}>',
		'block-end'        => '</div>',
		'charset'          => '<meta charset="{:encoding}" />',
		'image'            => '<img src="{:path}"{:options} />',
		'js-block'         => '<script type="text/javascript"{:options}>{:content}</script>',
		'js-start'         => '<script type="text/javascript"{:options}>',
		'js-end'           => '</script>',
		'link'             => '<a href="{:url}"{:options}>{:title}</a>',
		'list'             => '<ul{:options}>{:content}</ul>',
		'olist'            => '<ol{:options}>{:content}</ol>',
		'list-item'        => '<li{:options}>{:content}</li>',
		'meta'             => '<meta{:options}/>',
		'meta-link'        => '<link href="{:url}"{:options} />',
		'para'             => '<p{:options}>{:content}</p>',
		'para-start'       => '<p{:options}>',
		'para-end'         => '</p>',
		//'script'           => '<script type="text/javascript" src="{:path}"{:options}></script>',
		'script'           => '<script src="{:path}"{:options}></script>',
		'style'            => '<style type="text/css"{:options}>{:content}</style>',
		'style-import'     => '<style type="text/css"{:options}>@import url({:url});</style>',
		'style-link'       => '<link rel="{:type}" type="text/css" href="{:path}"{:options} />',
		'span'		   => '<span{:options}>{:content}</span>',
		'i'		   => '<i{:options}>{:content}</i>',
		'table'		   => '<table{:options}>{:content}</table>',
		'table-start'      => '<table{:options}>',
		'table-end'        => '</table>',
		'table-header'     => '<th{:options}>{:content}</th>',
		'table-header-row' => '<tr{:options}>{:content}</tr>',
		'table-cell'       => '<td{:options}>{:content}</td>',
		'table-row'        => '<tr{:options}>{:content}</tr>',
		'tag'              => '<{:name}{:options}>{:content}</{:name}>',
		'tag-end'          => '</{:name}>',
		'tag-start'        => '<{:name}{:options}>',
		'label'		   => '<label{:options}>{:content}</label>',
    );


    protected $_handlers = array();

    /*
     * List of minimized HTML attributes.
     *
     * @var array
     */
    protected $_minimized = array(
	'compact', 'checked', 'declare', 'readonly', 'disabled', 'selected', 'defer', 'ismap',
	'nohref', 'noshade', 'nowrap', 'multiple', 'noresize', 'async', 'autofocus'
    );

    public function __construct($config = array()) {
	parent::__construct($config);
    }

    protected function _init() {
	parent::_init();
	$this->_handlers += array(
	    'options' => '_attributes',
	    'title'   => 'escape',
	    'value'   => 'escape',
	);
    }

    /**
     * Returns a charset meta-tag for declaring the encoding of the document.
     */
    public function charset($encoding = null) {
	return $this->_render('charset', array('encoding'=>'UTF-8'));
    }

    /**
     * Creates an HTML link (`<a />`) or a document meta-link (`<link />`).
     *
     * If `$url` starts with `'http://'` or `'https://'`, this is treated as an external link.
     * Otherwise, it is treated as a path to controller/action and parsed using
     * the `Router::match()` method (where `Router` is the routing class dependency specified by
     * the rendering context, i.e. `lithium\template\view\Renderer::$_classes`).
     *
     * If `$url` is empty, '#' is used.
     *
     * @param string $title The content to be wrapped by an `<a />` tag,
     *               or the `title` attribute of a meta-link `<link />`.
     * @param mixed $url Can be a string representing a URL relative to the base of your Lithium
     *              application, an external URL (starts with `'http://'` or `'https://'`), an
     *              anchor name starting with `'#'` (i.e. `'#top'`), or an array defining a set
     *              of request parameters that should be matched against a route in `Router`.
     * @param array $options The available options are:
     *              - `'escape'` _boolean_: Whether or not the title content should be escaped.
     *              Defaults to `true`.
     *              - any other options specified are rendered as HTML attributes of the element.
     * @return string Returns an `<a />` or `<link />` element.
     */
    public function link($title, $url = '#', array $options = array()) {
	$defaults = array('escape' => true);
	list($scope, $options) = $this->_options($defaults, $options);

	return $this->_render('link', compact('title', 'url', 'options'), $scope);
    }

    /**
     * Returns a JavaScript include tag (`<script />` element). If the filename is prefixed with
     * `'/'`, the path will be relative to the base path of your application.  Otherwise, the path
     * will be relative to your JavaScript path, usually `webroot/js`.
     *
     * @param mixed $path String The name of a JavaScript file, or an array of names.
     * @param array $options Available options are:
     *              - any other options specified are rendered as HTML attributes of the element.
     * @return string
     * @filter This method can be filtered.
     */
    public function script($path, array $options = array()) {
	//SC: 2025-04-28: added default
	$defaults = [ 'type' => 'text/javascript' ];
	$options += $defaults;

	if (is_array($path)) {
	    foreach ($path as $i => $item) {
		$path[$i] = $this->script($item, $options);
	    }

	    return join("\n", $path) . "\n";
	}

	return $this->_render('script', compact('path', 'options'));
    }

    /**
     * Creates a `<link />` element for CSS stylesheets or a `<style />` tag. If the filename is
     * prefixed with `'/'`, the path will be relative to the base path of your application.
     * Otherwise, the path will be relative to your stylesheets path, usually `webroot/css`.
     *
     * @param mixed $path The name of a CSS stylesheet in `/app/webroot/css`, or an array
     *              containing names of CSS stylesheets in that directory.
     * @param array $options Available options are:
     *              - `'inline'` _boolean_: Whether or not the `<style />` element should be output
     *              inline. When set to `false`, the `styles()` handler prints out the styles,
     *              and other specified styles to be included in the layout. Defaults to `true`.
     *              This is useful when page-specific styles are created inline in the page, and
     *              you'd like to place them in
     *              the `<head />` along with your other styles.
     *              - `'type'` _string_: By default, accepts `stylesheet` or `import`, which
     *              respectively correspond to `style-link` and `style-import` strings templates
     *              defined in `Html::$_strings`.
     *              - any other options specified are rendered as HTML attributes of the element.
     * @return string CSS <link /> or <style /> tag, depending on the type of link.
     * @filter This method can be filtered.
     */
    public function style($path, array $options = array()) {
	$defaults = array('type' => 'stylesheet');
	list($scope, $options) = $this->_options($defaults, $options);

	if (is_array($path)) {
	    foreach ($path as $i => $item) {
		$path[$i] = $this->style($item, $scope);
	    }
	    return join("\n", $path) . "\n";
	}

	$type = $scope['type'];
	$params = compact('type', 'path', 'options');
	$template = ($type == 'import') ? 'style-import' : 'style-link';

	return $this->_render($template, $params);
    }

    /**
     * Creates a formatted `<img />` element.
     *
     * @param string $path Path to the image file. If the filename is prefixed with
     *               `'/'`, the path will be relative to the base path of your application.
     *               Otherwise the path will be relative to the images directory, usually `app/webroot/img/`.
     *               If the name starts with `'http://'`, this is treated as an external url used as the `src`
     *               attribute.
     * @param array $options Array of HTML attributes.
     * @return string Returns a formatted `<img />` tag.
     * @filter This method can be filtered.
     */
    public function image($path, array $options = array()) {
	$defaults = array('alt' => '');
	$options += $defaults;

	return $this->_render('image', compact('path', 'options'), $options);
    }

    public function tag($tag, array $params = array(), array $options = array()) {

	$defaults = array('escape' => true);
	list($scope, $options) = $this->_options($defaults, $options);

	return $this->_render($tag, $params + compact('options'), $scope);
    }



    /**
     * Escapes values. In non-HTML/XML contexts should override this method accordingly.
     *
     * @param string $value
     * @param array $options
     * @return mixed
     */
    public function escape($value, array $options = array()) {
	$defaults = array('escape' => true);
	$options += $defaults;

	if ($options['escape'] === false)
	    return $value;

	if (is_array($value))
	    return array_map(array($this, __FUNCTION__), $value);

	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Convert a set of options to HTML attributes
     *
     * @param array $params
     * @param array $options
     * @return string
     */
    public function _attributes($params, array $options = array()) {
	$defaults = array('escape' => true, 'prepend' => ' ', 'append' => '');
	$options += $defaults;
	$result = array();

	if (!is_array($params)) {
	    return !$params ? '' : $options['prepend'] . $params;
	}

	foreach ($params as $key => $value) {
	    if ($next = $this->_attribute($key, $value, $options)) {
		$result[] = $next;
	    }
	}
	return $result ? $options['prepend'] . implode(' ', $result) . $options['append'] : '';
    }

    /**
     * Convert a key/value pair to a valid HTML attribute.
     *
     * @param string $key The key name of the HTML attribute.
     * @param mixed $value The HTML attribute value.
     * @param array $options The options used when converting the key/value pair to attributes:
     *              - `'escape'` _boolean_: Indicates whether `$key` and `$value` should be
     *                HTML-escaped. Defaults to `true`.
     *              - `'format'` _string_: The format string. Defaults to `'%s="%s"'`.
     * @return string Returns an HTML attribute/value pair, in the form of `'$key="$value"'`.
     */
    protected function _attribute($key, $value, array $options = array()) {
	$defaults = array('escape' => true, 'format' => '%s="%s"');
	$options += $defaults;

	if (in_array($key, $this->_minimized)) {
	    $isMini = ($value == 1 || $value === true || $value == $key);
	    if (!($value = $isMini ? $key : $value)) {
		return null;
	    }
	}

	//\V::app()->log->debug('value=' . print_r($value, true));
	$value = (string) $value;

	if ($options['escape']) {
	    return sprintf($options['format'], $this->escape($key), $this->escape($value));
	}
	return sprintf($options['format'], $key, $value);
    }

    /**
     * Render a string template after applying filters
     * Use examples in the Html::link() method:
     * `return $this->_render('link', compact('title', 'url', 'options'), $scope);`
     *
     * @param string $string template key (in Helper::_strings) to render
     * @param array $params associated array of template inserts {:key} will be replaced by value
     * @param array $options Available options:
     *              - `'handlers'` _array_: Before inserting `$params` inside the string template,
     *              `$this->_handlers are applied to each value of `$params` according
     *              to the key (e.g `$params['url']`, which is processed by the `'url'` handler
     *              via `$this->applyHandler()`).
     *              The `'handlers'` option allow to set custom mapping beetween `$params`'s key and
     *              `$this->_handlers. e.g. the following handler:
     *              `'handlers' => array('url' => 'path')` will make `$params['url']` to be
     *              processed by the `'path'` handler instead of the `'url'` one.
     * @return string Rendered HTML
     */
    protected function _render($string, $params, array $options = []) {
	$strings = $this->_strings;

	if (!is_array($params))
	    \V::app()->log->debug('params not array:' . print_r($params));
	foreach ($params as $key => $value) {
	    $handler = isset($options['handlers'][$key]) ? $options['handlers'][$key] : $key;
	    $params[$key] = $this->applyHandler($this, $handler, $value, $options);
	}
	//$strings = $this->_context->strings();
	return \util\VString::insert(isset($strings[$string]) ? $strings[$string] : $string, $params);
    }

    /**
     * Filters a piece of content through a content handler.  A handler can be:
     * - a string containing the name of a method defined in `$helper`. The method is called with 3
     *   parameters: the value to be handled, the helper method called (`$method`) and the
     *   `$options` that were passed into `applyHandler`.
     * - an array where the first element is an object reference, and the second element is a method
     *   name.  The method name given will be called on the object with the same parameters as
     *   above.
     * - a closure, which takes the value as the first parameter, an array containing an instance of
     *   the calling helper and the calling method name as the second, and `$options` as the third.
     * In all cases, handlers should return the transformed version of `$value`.
     *
     * @see lithium\template\view\Renderer::handlers()
     * @see lithium\template\view\Renderer::$_handlers
     * @param object $helper The instance of the object (usually a helper) that is invoking
     * @param string $name The name of the value to which the handler is applied, i.e. `'url'`,
     *               `'path'` or `'title'`.
     * @param mixed $value The value to be transformed by the handler, which is ultimately returned.
     * @param array $options Any options which should be passed to the handler used in this call.
     * @return mixed The transformed value of `$value`, after it has been processed by a handler.
     */
    public function applyHandler($helper, $name, $value, array $options = array()) {
	if (!(isset($this->_handlers[$name]) && $handler = $this->_handlers[$name])) {
	    return $value;
	}

	//if (is_string($handler) && !$helper)
	//    $helper = $this->helper('html');

	if (is_string($handler) && is_object($helper)) {
	    return $helper->invokeMethod($handler, array($value, $options));
	}
	if (is_array($handler) && is_object($handler[0])) {
	    list($object, $func) = $handler;
	    return $object->invokeMethod($func, array($value, $options));
	}

	if (is_callable($handler)) {
	    return $handler($value, $options);
	}
	return $value;
    }

    /**
     * Takes the defaults and current options, merges them and returns options which have
     * the default keys removed and full set of options as the scope.
     *
     * @param array $defaults
     * @param array $scope the complete set of options
     * @return array $scope, $options
     */
    protected function _options(array $defaults, array $scope) {
	$scope += $defaults;
	$options = array_diff_key($scope, $defaults);
	return array($scope, $options);
    }


}
