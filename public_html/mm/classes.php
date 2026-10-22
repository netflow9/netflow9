<?php

class Request {
    public $_config = [];

    public function __construct() {
	$this->_config = [ 'query' => $_GET, 'data' => $_POST, 'cookie' => $_COOKIE, 'files' => $_FILES, ];
    }
    public function getQueryParam($key, $default = null) {
	return $this->_config['query'][$key] ?? $default;
    }
    public function getDataParam($key, $default = null) {
	return $this->_config['data'][$key] ?? $default;
    }
    public function getFile($key) {
	return $this->_config['files'][$key] ?? null;
    }
    public function getCookie($key) {
	return $this->_config['cookies'][$key] ?? null;
    }

    function isPost() {
	return strtoupper($_SERVER['REQUEST_METHOD']) == 'POST';
    }
    function isAjax() {
	return (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');
    }
    function isPjax() {
	return isset($_SERVER['HTTP_X_PJAX']);
    }
    function baseUrl() {
	return $_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'];
    }
}

class Response {
    public $headers = [
	'Content-Type' => 'text/html',
    ];
    public $body = '';
    public function setHeader($key, $val) {
	if (empty($key))
	    return;
	$this->headers[$key] = $val;
    }
    public function redirect($url) {
	switch(true) {
	    //isPjax
	    case R::app()->request->isPjax():
		//redirect via pjax: change status and set X-Pjax-Redirect
		$this->setHeader('X-Pjax-Redirect', $url);
		break;
	    case R::app()->request->isAjax():
		//redirect via ajax: change status and set X-Redirect
		$this->setHeader('X-Redirect', $url);
		break;
	    default:
		//normal redirect, via location and status code=200
		$this->setHeader('Location', $url);
		break;
	}
	$this->body = '';
	\R::app()->end();
    }
    public function write($body = '', $replace = false) {
	if ($replace)
	    $this->body = '';
	$this->body .= $body;
    }
    public function output() {
        //Send headers
        if (headers_sent($f, $l))
    	    throw new \ErrorException("Response::output: headers already sent in file `$f` at line `$l`!!!");
        //Send headers
        foreach ($this->headers as $name => $value) {
            $hValues = explode("\n", $value);
            foreach ($hValues as $hVal)
                header("$name: $hVal", false);
        }
        //Send body
    	echo $this->body;
    }
}

class Server {
    public $name = null;
    public $uri = 'mongodb://127.0.0.1:27017';

    public $auth = false;
    public $username = '';
    public $password = '';
    public $authSource = '';
    public $uriOptions = [];

    public $manager;

    //last authentication error message
    public $auth_error = '';

    public function __construct($config = []) {
	foreach ($config as $param => $value)
	    $this->$param = $value;

	if (empty($this->name))
	    $this->name = $this->uri;
    }
    public function getUri() {
	return $this->uri;
    }
    public function connect($username, $password, $db = 'admin') {
	if (empty($db))
	    $db = 'admin';

	try {
	    $uriOptions = $this->uriOptions;
	    $uriOptions['readPreference'] = 'secondary';

	    //whether to authenticate
	    if (!empty($this->auth)) {
		//if username/password are provided, use them
		if(!empty($this->username) && !empty($this->password)) {
		    $uriOptions['username'] = $this->username;
		    $uriOptions['password'] = $this->password;
		} else {
		    $uriOptions['username'] = $username;
		    $uriOptions['password'] = $password;
		}
		if (!empty($this->authSource)) {
		    $uriOptions['authSource'] = $this->authSource;
		} else {
		    $uriOptions['authSource'] = $db;
		}
	    }

	    //SC: new
	    $driverOptions = [
		'typeMap' => [
		    'array' => 'array',
		    'document' => 'array',
		    'root' => 'array',
		]
	    ];

	    $this->manager = new \MongoDB\Driver\Manager($this->uri, $uriOptions);

	    return true;
	} catch(Exception $e) {
	    $this->auth_error = $e->getMessage();
	    return false;
	}
	//should never reach here
    }
    //get all (applicable) databases names
    public function listDatabases() {
	$dbs = [];
	//get all databases from the server; if fail, return user databases
	try {
	    $command = new \MongoDB\Driver\Command([ 'listDatabases' => 1 ]);
	    $cursor = $this->manager->executeReadCommand('admin', $command);
	    //$cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
	    $res = current($cursor->toArray());
	    
	    foreach($res->databases as $db) {
		if ($this->canViewDatabase($db->name))
		    $dbs[] = $db->name;
	    }
	} catch(Exception $e) {
	    $dbs = R::app()->user->dbs();
	}
	ksort($dbs);
	return $dbs;
    }
    // List all (applicable) collections in a DB
    public function listCollections($db) {
	$collections = [];

	try {
	    $command = new \MongoDB\Driver\Command([ 'listCollections' => 1 ]);
	    $cursor = $this->manager->executeCommand($db, $command);

	    foreach($cursor as $cInfo) {
		if ($this->canViewCollection($cInfo->name)) {
		    $_command = new \MongoDB\Driver\Command([ 'count' => $cInfo->name ]);
		    $_cursor = $this->manager->executeCommand($db, $_command);
		    $res = current($_cursor->toArray());
        	    $collections[$cInfo->name] = $res->n;
        	}
	    }
	} catch(Exception $e) {
	}

	ksort($collections);
	return $collections;
    }
    public function canViewCollection($name) {
	if (!empty(R::app()->config['ui_hide_system_collections']) && strpos($name, 'system.') === 0)
	    return false;
	if (!empty(R::app()->config['ui_hide_collections']) && in_array($name, R::app()->config['ui_hide_collections']))
	    return false;
	return true;
    }
    public function canViewDatabase($name) {
	if (!empty(R::app()->config['ui_hide_dbs']) && in_array($name, R::app()->config['ui_hide_dbs']))
	    return false;
	if (!empty(R::app()->config['ui_only_dbs']) && !in_array($name, R::app()->config['ui_only_dbs']))
	    return false;
	return true;
    }
}

class User {
    public function isGuest() {
	return empty($_SESSION['user']);
    }
    // Logs out the current user. Remove authentication-related session data.
    public function logout() {
	setcookie(session_name(), '', time() - 8 * 3600);
	session_destroy();
    }
    // The user identity information will be saved in session storage
    public function login($username, $password, $db) {
	$_SESSION['user']['username'] = $username;
	$_SESSION['user']['password'] = $password;
	$_SESSION['user']['db'] = $db;

	setcookie(session_name(), session_id(), time() + 8 * 3600);
	return true;
    }
    public function username() {
	return $_SESSION['user']['username'] ?? '';
    }
    public function password() {
	return $_SESSION['user']['password'] ?? '';
    }
    public function db() {
	return $this->dbs()[0];
    }
    public function dbs() {
	$db = $_SESSION['user']['db'] ?? [];
	
	//if no db specified, get it from server
	if (empty($db)) {
	    $db = 'admin';
	    if (!R::app()->server->auth && !empty(R::app()->server->mongo_db))
		$db = R::app()->server->mongo_db;
	    return [ $db ];
	}
	if (!is_array($db))
	    $db = explode(',', $db);
	
	return array_values($db);
    }
    //message popups
    public function addMessage($message) {
	$data = $_SESSION['flash'] ?? [];
	$data[] = $message;
	$_SESSION['flash'] = $data;
    }
    public function getMessages() {
	$data = $_SESSION['flash'];
	$_SESSION['flash'] = [];
	return $data;
    }
}

class View {
    private function to_file($template) {
	return __ROOT__ . '/views/' . $template . '.php';
    }

    /**
     * Renders output with given template
     *
     * @param string $template Name of the template to be rendererd
     * @param array  $args     Args for view
     */
    public function renderFile($template, $data = [] ) {
        extract($data);
        ob_start();

	if ($template[0] != '/')
	    $template = $this->to_file($template);

	if (!is_readable($template))
	    R::app()->error("File not found or not readable: $template");

        require $template;
        return ob_get_clean();
    }
    public function render($template, $data = [], $layout = null, $return = false) {
	// if template starts with '/' then it is treated as absolute path and  no further processing done
	if ($template[0] != '/')
	    $template = $this->to_file($template);

        $content = $output = $this->renderFile($template, $data);
	//whether to use a layout
	if ($layout !== false) {
	    //render via layout => determine layout file
	    if ($layout === null)
		$layout = 'sys_layout';

	    $layout = $this->to_file($layout);
	    $output = $this->renderFile($layout, compact('content'));
	}
	if ($return)
	    return $output;
	R::app()->response->write($output);
    }
}

class Html {
    // String templates used by this helper.
    protected $_strings = [
	'button'         => '<button{:options}>{:title}</button>',
	'block'		=> '<div{:options}>{:content}</div>',
	'block-start'	=> '<div{:options}>',
	'block-end'	=> '</div>',
	'link'		=> '<a href="{:url}"{:options}>{:title}</a>',
	'list'		=> '<ul{:options}>{:content}</ul>',
	'list-item'	=> '<li{:options}>{:content}</li>',
	'para'		=> '<p{:options}>{:content}</p>',
	'para-start'	=> '<p{:options}>',
	'para-end'	=> '</p>',
	'span'		=> '<span{:options}>{:content}</span>',
	'i'		=> '<i{:options}>{:content}</i>',
	'tag'		=> '<{:name}{:options}>{:content}</{:name}>',
	'tag-end'	=> '</{:name}>',
	'tag-start'	=> '<{:name}{:options}>',
	'label'		=> '<label{:options}>{:label}</label>',
	'select'	=> '<select name="{:name}"{:options}>{:raw}</select>',
	'select-multi'	=> '<select name="{:name}[]"{:options}>{:raw}</select>',
	'select-option'	=> '<option value="{:value}"{:options}>{:title}</option>',
	'text'           => '<input type="text" name="{:name}"{:options} />',
	'textarea'       => '<textarea name="{:name}"{:options}>{:value}</textarea>',
	'checkbox'       => '<input type="checkbox" name="{:name}"{:options} />',
	'hidden'         => '<input type="hidden" name="{:name}"{:options} />',
	'submit'         => '<input type="submit" value="{:title}"{:options} />',
    ];
    protected $_handlers = [
	    'options' => '_attributes',
	    //'title'   => 'escape',
	    'value'   => 'escape',
    ];
    // * List of minimized HTML attributes.
    protected $_minimized = [
	'compact', 'checked', 'declare', 'readonly', 'disabled', 'selected', 'defer', 'ismap',
	'nohref', 'noshade', 'nowrap', 'multiple', 'noresize', 'async', 'autofocus',
    ];

    /**
     * Generates a form field with a label, input, and error message (if applicable), all contained
     * within a wrapping element.
     *
     * {{{
     *  echo $this->form->field('name');
     *  echo $this->form->field('present', array('type' => 'checkbox'));
     *  echo $this->form->field(array('email' => 'Enter a valid email'));
     *  echo $this->form->field(array('name','email','phone'), array('div' => false));
     * }}}
     * @param mixed $name The name of the field to render. If the form was bound to an object
     *                   passed in `create()`, `$name` should be the name of a field in that object.
     *                   Otherwise, can be any arbitrary field name, as it will appear in POST data.
     *                   Alternatively supply an array of fields that will use the same options
     *                   array($field1 => $label1, $field2, $field3 => $label3)
     * @param array $options Rendering options for the form field. The available options are as
     *              follows:
     *              - `'label'` _mixed_: A string or array defining the label text and / or
     *                parameters. By default, the label text is a human-friendly version of `$name`.
     *                However, you can specify the label manually as a string, or both the label
     *                text and options as an array, i.e.:
     *                `array('Your Label Title' => array('class' => 'foo', 'other' => 'options'))`.
     *              - `'type'` _string_: The type of form field to render. Available default options
     *                are: `'text'`, `'textarea'`, `'select'`, `'checkbox'`, `'password'` or
     *                `'hidden'`, as well as any arbitrary type (i.e. HTML5 form fields).
     *              - `'template'` _string_: Defaults to `'template'`, but can be set to any named
     *                template string, or an arbitrary HTML fragment. For example, to change the
     *                default wrapper tag from `<div />` to `<li />`, you can pass the following:
     *                `'<li{:wrap}>{:label}{:input}{:error}</li>'`.
     *              - `'wrap'` _array_: An array of HTML attributes which will be embedded in the
     *                wrapper tag.
     *              - `list` _array_: If `'type'` is set to `'select'`, `'list'` is an array of
     *                key/value pairs representing the `$list` parameter of the `select()` method.
     * @return string Returns a form input (the input type is based on the `'type'` option), with
     *         label and error message, wrapped in a `<div />` element.
     */
    public function field($name, $options = []) {
	$defaults = [
	    'label' => null,
	    'labelOptions' => [],
	    'type' => isset($options['list']) ? 'select' : 'text',
	    'list' => null,
	    'value' => null,
	    'id' => 'f-' . uniqid(),
	    'wrap' => [ 'class' => 'mb-2' ],
	    'input' => [ 'class' => 'form-control' ],
	];

	list($scope, $options) = $this->_options($defaults, $options);

	if ($scope['type'] == 'hidden')
	    $scope['label'] = null;

	//render label
	$label = null;
	if ($scope['label']) {
	    switch($scope['type']) {
		case 'checkbox':
		    $label = $this->label($scope['label'], [ 'for' => $scope['id'], 'class' => 'form-check-label' ] /*+ $options*/);
		    break;
		default:
		    $label = $this->label($scope['label'], [ 'for' => $scope['id'] ] /*+ $options*/);
		    break;
	    }
	}

	//render input
	$__input_options = $scope['input'] + [ 'id' => $scope['id'], 'value' => $scope['value'] ] + $options;
	switch($scope['type']) {
	    case 'select':
		$__input_options['class'] = 'form-select';
		$input = $this->select($name, $scope['list'], $__input_options);
		break;
	    case 'checkbox':
		$__input_options['class'] = 'form-check-input';
		$input = $this->checkbox($name, $__input_options);
		break;
	    default:
		$call_args = [ $name, $__input_options ];
    		$input = call_user_func_array([ $this, $scope['type'] ], $call_args);
    		break;
	}

	switch($scope['type']) {
	    case 'checkbox':
		$__wrap_options = [ 'options' => [ 'class' => 'form-check mb-2' ], 'content' => $input . $label ];
		break;
	    default:
		$__wrap_options = [ 'options' => $scope['wrap'], 'content' => $label . $input ];
		break;
	}
	return $this->tag('block', $__wrap_options, [ 'escape' => true ]);
    }

    /**
     * Generates an HTML button `<button></button>`.
     *
     * @param string $title The title of the button.
     * @param array $options Any options passed are converted to HTML attributes within the
     *              `<button></button>` tag.
     * @return string Returns a `<button></button>` tag with the given title and HTML attributes.
     */
    public function button($title = null, $options = []) {
	//$defaults = [ 'escape' => true ];
	//list($scope, $options) = $this->_options($defaults, $options);

	return $this->_render('button', compact('title', 'options'), [ 'escape' => true ]);
    }

    /**
     * Generates an HTML `<input type="submit" />` object.
     *
     * @param string $title The title of the submit button.
     * @param array $options Any options passed are converted to HTML attributes within the
     *              `<input />` tag.
     * @return string Returns a submit `<input />` tag with the given title and HTML attributes.
     */
    public function submit($title = null, $options = []) {
	$defaults = [ 'class' => 'btn btn-sm btn-success' ];
	list($scope, $options) = $this->_options($defaults, $options);

	return $this->_render('submit', [ 'title' => $title, 'options' => $scope ], [ 'escape' => true ]);
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
    public function link($title, $url = '#', $options = []) {
	$defaults = [ 'escape' => true ];
	list($scope, $options) = $this->_options($defaults, $options);

	return $this->_render('link', compact('title', 'url', 'options'), $scope);
    }

    /**
     * Generates an HTML `<input type="text" />` object.
     *
     * @param string $name The name of the field.
     * @param array $options All options passed are rendered as HTML attributes.
     * @return string Returns a `<input />` tag with the given name and HTML attributes.
     */
    public function text($name, $options = []) {
	$defaults = [ 'escape' => true ];
	list($scope, $options) = $this->_options($defaults, $options);

	return $this->_render('text', compact('name', 'options'));
    }

    /**
     * Generates an HTML `<textarea>...</textarea>` object.
     *
     * @param string $name The name of the field.
     * @param array $options The options to be used when generating the `<textarea />` tag pair,
     *              which are as follows:
     *              - `'value'` _string_: The content value of the field.
     *              - Any other options specified are rendered as HTML attributes of the element.
     * @return string Returns a `<textarea>` tag with the given name and HTML attributes.
     */
    public function textarea($name, $options = []) {
	$defaults = [ 'escape' => true, 'value' => null ];
	list($scope, $options) = $this->_options($defaults, $options);
	$value = $scope['value'] ?? '';
	return $this->_render('textarea', compact('name', 'options', 'value'));
    }

    /**
     * Generates an HTML `<input type="checkbox" />` object.
     *
     * @param string $name The name of the field.
     * @param array $options Options to be used when generating the checkbox `<input />` element:
     *              - `'checked'` _boolean_: Whether or not the field should be checked by default.
     *              - `'value'` _mixed_: if specified, it will be used as the 'value' html
     *                attribute and no hidden input field will be added.
     *              - Any other options specified are rendered as HTML attributes of the element.
     * @return string Returns a `<input />` tag with the given name and HTML attributes.
     */
    public function checkbox($name, $options = []) {
	$defaults = [ 'ckb_value' => 1, 'hidden' => true ];

	list($scope, $options) = $this->_options($defaults, $options);

	if (!isset($options['checked']) && !empty($scope['value']))
	    $options['checked'] = ($scope['ckb_value'] == $scope['value']);

	$out = '';
	if ($scope['hidden'])
	    $out .= $this->hidden($name, [ 'value' => (empty($options['checked']) ? '' : $scope['ckb_value']) ]);

	$options['value'] = $scope['ckb_value'];
	return $out . $this->_render('checkbox', compact('name', 'options'));
    }

    /**
     * Generates an HTML `<input type="hidden" />` object.
     *
     * @param string $name The name of the field.
     * @param array $options An array of HTML attributes with which the field should be rendered.
     * @return string Returns a `<input />` tag with the given name and HTML attributes.
     */
    public function hidden($name, $options = []) {
	return $this->_render('hidden', compact('name', 'options'));
    }

    public function label($label, $options = []) {
	$defaults = [ 'escape' => true ];
	list($scope, $options) = $this->_options($defaults, $options);

	return $this->_render('label', compact('label', 'options'));
    }

    public function tag($tag, $params = [], $options = []) {
	$defaults = [ 'escape' => true ];
	list($scope, $options) = $this->_options($defaults, $options);

	return $this->_render($tag, $params + compact('options'), $scope);
    }

    /**
     * Generates a `<select />` list using the `$list` parameter for the `<option />` tags. The
     * default selection will be set to the value of `$options['value']`, if specified.
     *
     * For example: {{{
     * $this->form->select('colors', array(1 => 'red', 2 => 'green', 3 => 'blue'), array(
     * 	'id' => 'Colors', 'value' => 2
     * ));
     * // Renders a '<select />' list with options 'red', 'green' and 'blue', with the 'green'
     * // option as the selection
     * }}}
     *
     * @param string $name The `name` attribute of the `<select />` element.
     * @param array $list An associative array of key/value pairs, which will be used to render the
     *              list of options.
     * @param array $options Any HTML attributes that should be associated with the `<select />`
     *             element. If the `'value'` key is set, this will be the value of the option
     *             that is selected by default.
     * @return string Returns an HTML `<select />` element.
     */
    public function select($name, $list = [], $options = []) {
	$defaults = [ 'empty' => false, 'value' => null ];
	list($scope, $options) = $this->_options($defaults, $options);

	$template = 'select';
	if ($scope['multiple'] ?? null) {
	    $name = str_replace('[]', '', $name);
	    $template = 'select-multi';
	}

	
	$raw = $this->_selectOptions($list, $scope);
	return $this->_render($template, compact('name', 'options', 'raw'));
    }

    protected function _selectOptions(array $list, array $scope) {
	$result = '';

	foreach ($list as $value => $title) {
	    if (is_array($scope['value'])) {
		$selected = in_array($value, $scope['value']);
	    } else {
		$selected = ($value == $scope['value']);
	    }
	    $options = $selected ? [ 'selected' => true ] : [];
	    $result .= $this->_render('select-option', compact('value', 'title', 'options'));
	}
	return $result;
    }

    /**
     * Escapes values. In non-HTML/XML contexts should override this method accordingly.
     *
     * @param string $value
     * @param array $options
     * @return mixed
     */
    public function escape($value, $options = []) {
	$defaults = [ 'escape' => true ];
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
    protected function _attributes($params, array $options = []) {
	$defaults = [ 'escape' => true, ];
	$options += $defaults;
	$result = [];

	if (!is_array($params)) {
	    if (empty($params))
		return '';
	    return ' ' . $params;
	}

	foreach ($params as $key => $value) {
	    $__attr = $this->_attribute($key, $value, $options);
	    if ($__attr)
		$result[] = $__attr;
	}
	if (!empty($result))
	    return ' ' . implode(' ', $result);

	return '';
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
	$defaults = [ 'escape' => true, ];
	$options += $defaults;

	if (in_array($key, $this->_minimized)) {
	    if (!empty($value) || $value == $key)
		return $key;

	    return '';
	}

	$value = (string) $value;

	if ($options['escape']) {
	    return $this->escape($key) . '="' . $this->escape($value) . '"';
	}
	return $key . '="' . $value . '"';
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
	foreach ($params as $key => $value) {
	    $handler = $this->_handlers[$key] ?? null;
	    if (!$handler)
		continue;
	    $params[$key] = $this->$handler($value, $options);
	}
	return Utils::pluck(isset($this->_strings[$string]) ? $this->_strings[$string] : $string, $params);
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

class Pager {
    public $size;
    public $total;
    public $page;

    function __construct($config = []) {
	$this->size = $config['size'] ?? 20;
	$this->total = $config['total'];
	$this->page = $config['page'] ?? 1;
    }
    function total() {
	return $this->total;
    }
    function size() {
	return $this->size;
    }
    function page() {
	return $this->page;
    }
    function next() {
	$n = ceil($this->total / $this->size);
	return $this->page < $n ? ($this->page + 1) : $n;
    }
    function prev() {
	$n = ceil($this->total / $this->size);
	return $this->page > 1 ? ($this->page - 1) : 1;
    }
    function offset() {
	$n = ceil($this->total / $this->size);
	$offset = $this->size * ($this->page - 1);
	if ($offset < 0)
	    $offset = 0;

	if ($offset >= $this->total)
            $offset = max($this->size * ($n - 1), 0);

	return $offset;
    }
    function last() {
	return ceil($this->total / $this->size);
    }
}

/**
 * Application class
 *
 */
class R {
    public $user = null;
    public $request = null;
    public $response = null;
    public $server = null;
    public $view = null;

    public $config = [];

    public $__controller = null;
    public $__action= null;

    protected static $app;

    public function __construct($config) {
	$this->user = new User();
	$this->request = new Request();
	$this->response = new Response();
	$this->view = new View();
	$this->server = new Server($config['server']);
	$this->html = new Html();

	//preprocess ui options
	if (!empty($config['ui_only_dbs']) && !is_array($config['ui_only_dbs']))
	    $config['ui_only_dbs'] = explode(',', $config['ui_only_dbs']);
	if (!empty($config['ui_hide_dbs']) && !is_array($config['ui_hide_dbs']))
	    $config['ui_hide_dbs'] = explode(',', $config['ui_hide_dbs']);
	if (!empty($config['ui_hide_collections']) && !is_array($config['ui_hide_collections']))
	    $config['ui_hide_collections'] = explode(',', $config['ui_hide_collections']);

	$this->config = $config;
    }

    // Start application
    public function start() {
	ob_start();

	session_start();
	static::$app = $this;

	//exception handler
	set_exception_handler([ $this, 'exceptionHandler' ]);

    	set_error_handler(function($errno, $errstr = '', $errfile = '', $errline = '') {
    	    if (error_reporting() & $errno)
    		throw new \ErrorException($errstr, $errno, 0, $errfile, $errline);
    	});

	$route = $this->request->getQueryParam('action', 'app.home');

	if (strpos($route, '.') === false)
	    $route .= '.home';

	if (!preg_match('/(^.*(?:^|\\.))(\\w+)\\.(\\w+)$/', $route, $match))
	    trigger_error("you called an invalid action: $action");

	$name = $match[1] . $match[2];
	$this->__controller = $match[2];
	$this->__action = $match[3];

	//we are operating in user authenticated context all the time - except when login
	if ($this->__action != 'login') {
	    if ($this->user->isGuest()) {
		//if auth is disabled
		if (empty($this->config['auth'])) {
		    $this->user->login('rockmongo_memo', 'rockmongo_memo', 'admin');
		} else {
		    $this->response->redirect($this->url('app.login'));
		}
	    }

	    //validate connection
	    if (!$this->server->connect($this->user->username(), $this->user->password(), $this->user->db()))
		$this->redirect($this->url('app.login', [ 'error' => $this->server->error ]));
	}

	//controller class
	if ($this->__controller != 'app')
	    trigger_error("controller should be `app` in route: $route", E_USER_ERROR);

	$obj = new App;

	$method = "do_" . $this->__action;
	if (!method_exists($obj, $method))
	    trigger_error("class 'App' does not have action '{$this->__action}'");

	$ret = $obj->$method();
	R::app()->end();
    }

    // get the application singleton
    public static function & app() {
	return static::$app;
    }

    public function exceptionHandler($exception) {
	$message = $exception->getMessage();
	$message .= '<pre>' . print_r(debug_backtrace()[0]['args'], true) . '</pre>';
	$this->error($message);
    }

    //this will stop the application
    public function end() {
	$this->response->write(ob_get_clean());
	$this->response->output();

	session_write_close();
	exit(0);
    }

    // Stop the application and immediately send the response with a specific status and body to the HTTP client.
    public function error($message = '') {
    	if (ob_get_level() !== 0)
    	    ob_clean();

	ob_start();
	$this->view->render('sys_exception', [ 'message' => $message ], $layout = false, $return = false);

	$this->end();
    }

    // Construct a url from action and it's parameters
    public function url($action, $params = []) {
	if (strpos($action, '.') === false)
	    $action = $this->__controller . '.' . $action;

	$url = $this->request->baseUrl() . '?action=' . $action;
	unset($params['action']);
	if (!empty($params))
	    $url .= '&' . http_build_query($params);

	return $url;
    }

    public function render($template, $data = [], $layout = null, $return = false) {
    	//if(strpos($template, '/') === false)
    	//    $template = $this->__controller . '/' . $template;

    	if (($this->request->isPjax() || $this->request->isAjax()) && $layout === null)
	    $layout = false;

	return $this->view->render($template, $data, $layout, $return);
    }
}

class Utils {
    static function getSizeHuman($size, $decimals = 2) {
	$suffix = [ 'B', 'K', 'M', 'G', 'T', 'P' ];
	$factor = floor((strlen($size) - 1) / 3);
	return number_format($size / pow(1024, $factor), $decimals) . $suffix[$factor];
    }

    /**
     * Replaces variable placeholders inside a string with any given data. Each key
     * in the `$data` array corresponds to a variable placeholder name in `$str`.
     *
     * Usage:
     * {{{
     * pluck(
     *     'My name is {:name} and I am {:age} years old.',
     *     array('name' => 'Bob', 'age' => '65')
     * ); // returns 'My name is Bob and I am 65 years old.'
     * }}}
     *
     * @param string $str A string containing variable place-holders.
     * @param array $data A key, value array where each key stands for a place-holder variable
     *                     name to be replaced with value.
     * @param array $options Available options are:
     *        - `'after'`: The character or string after the name of the variable place-holder
     *          (defaults to `}`).
     *        - `'before'`: The character or string in front of the name of the variable
     *          place-holder (defaults to `'{:'`).
     *        - `'clean'`: A boolean or array with instructions for `String::clean()`.
     *        - `'escape'`: The character or string used to escape the before character or string
     *          (defaults to `'\'`).
     *        - `'format'`: A regular expression to use for matching variable place-holders
     *          (defaults to `'/(?<!\\)\:%s/'`. Please note that this option takes precedence over
     *          all other options except `'clean'`.
     * @return string
     * @todo Optimize this
     */
    static function pluck($str, array $data, array $options = array()) {
	$defaults = [
	    'before' => '{:',
	    'after' => '}',
	    'escape' => null,
	    'format' => null,
	    //'clean' => false
	];
	$options += $defaults;
	$format = $options['format'];
	reset($data);

	if ($format == 'regex' || (!$format && $options['escape'])) {
	    $format = sprintf(
		'/(?<!%s)%s%%s%s/',
		preg_quote($options['escape'], '/'),
		str_replace('%', '%%', preg_quote($options['before'], '/')),
		str_replace('%', '%%', preg_quote($options['after'], '/'))
	    );
	}

	if (!$format && key($data) !== 0) {
	    $replace = array();

	    foreach ($data as $key => $value) {
		$value = (is_array($value) || $value instanceof Closure) ? '' : $value;

		try {
		    if (is_object($value) && method_exists($value, '__toString')) {
			$value = (string) $value;
		    }
		} catch (Exception $e) {
		    $value = '';
		}
		$replace["{$options['before']}{$key}{$options['after']}"] = $value;
	    }
	    $str = strtr($str, $replace);
	    //return $options['clean'] ? static::clean($str, $options) : $str;
	    return $str;
	}

	if (strpos($str, '?') !== false && isset($data[0])) {
	    $offset = 0;
	    while (($pos = strpos($str, '?', $offset)) !== false) {
		$val = array_shift($data);
		$offset = $pos + strlen($val);
		$str = substr_replace($str, $val, $pos, 1);
	    }
	    //return $options['clean'] ? static::clean($str, $options) : $str;
	    return $str;
	}

	foreach ($data as $key => $value) {
	    $hashVal = crc32($key);
	    $key = sprintf($format, preg_quote($key, '/'));

	    if (!$key) {
		continue;
	    }
	    $str = preg_replace($key, $hashVal, $str);
	    $str = str_replace($hashVal, $value, $str);
	}

	if (!isset($options['format']) && isset($options['before'])) {
	    $str = str_replace($options['escape'] . $options['before'], $options['before'], $str);
	}
	//return $options['clean'] ? static::clean($str, $options) : $str;
	return $str;
    }

    static function formatVar($var, $map = []) {
	if (is_scalar($var) || is_null($var))
	    return [ $var, $map ];

	if (is_array($var)) {
	    foreach ($var as $index => $value) {
		list($__var, $map) = self::formatVar($value, $map);
		$var[$index] = $__var;
	    }

	    return [ $var, $map ];
	}

	if (is_object($var)) {
	    switch (get_class($var)) {
		case 'MongoDB\\BSON\\ObjectId':
		    $__var = 'ObjectId("' . $var->__toString() . '")';
		    break;
		case 'MongoDB\\BSON\\UTCDateTime':
		    $__var = 'MongoDate("' . $var->toDateTime()->format(DATE_RFC3339_EXTENDED) . '")';
		    break;
		case 'MongoDB\BSON\Binary':
		    $__var = 'MongoBinaryData("' . base64_encode($var) . '")';
		    break;
		default:
		    if (method_exists($var, "__toString"))
			$__var = get_class($var) . '::' . $var->__toString();
		    else
			$__var = $var;
		    break;
	    }
	    $uniq = uniqid();
	    $map_key = "mongo-param-$uniq";
	    $map[$map_key] = $__var;
	    return [ $map_key, $map ];
	}
	return [ $var, $map ];
    }

    /**
     * Export the variable to a string
     * @return string
     */
    static function exportPHP($var) {
	$map = [];
	list($var, $map) = self::formatVar($var, $map);

	foreach ($map as $index => $value)
	    $map["'$index'"] = $value;

	$string = var_export($var, true);
	$string = preg_replace("/=> \n\s+array/", "=> array", $string);
	return strtr($string, $map);
    }

    static function highlight($var) {
	$varString = self::exportPHP($var);

	$string = highlight_string("<?php " . $varString, true);
	$string = preg_replace("/" . preg_quote('<span style="color: #0000BB">&lt;?php&nbsp;</span>', "/") . "/", '', $string, 1);

	return $string;
    }

    // Checks if an IPv4 address is contained in the list of given IPs or subnets.
    static function checkIp($ip, $list = []) {
	if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
	    return false;

	foreach ($list as $__ip) {
	    $address = $__ip;
	    $netmask = 32;

	    if (str_contains($__ip, '/')) {
		[ $address, $netmask ] = explode('/', $__ip, 2);
		if ($netmask == '0' && !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
		    continue;

		if ($netmask < 0 || $netmask > 32)
		    continue;
	    }

	    if (ip2long($address) === false)
		continue;

	    if (substr_compare(sprintf('%032b', ip2long($ip)), sprintf('%032b', ip2long($address)), 0, $netmask) === 0)
		return true;
	}
	return false;
    }

    static function varEvalPHP($source) {
	$allowed_token_types = [
	    T_OPEN_TAG, T_RETURN, T_WHITESPACE, T_ARRAY,
	    T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING,
	    T_DOUBLE_ARROW, T_CLOSE_TAG, T_NEW, T_DOUBLE_COLON,
	    T_COMMENT,
	];
	$allowed_keywords = [
	    'objectid', 'mongocode', 'mongodate', 'mongoregex',
	    'mongobinarydata',
	    'mongodbref',
	    'true', 'false', 'null',
	];

	$tokens = token_get_all("<?php return $source;");
	foreach ($tokens as $token) {
	    if (is_long($token[0])) {
		if (in_array($token[0], $allowed_token_types))
		    continue;

		if ($token[0] == T_STRING) {
		    $func = strtolower($token[1]);
		    if (in_array($func, $allowed_keywords))
			continue;
		}

		$token_name = token_name($token[0]);
		throw new ErrorException("Error at `({$token_name}) {$token[1]}`");
	    }
        }
	return eval("return $source;");
    }

}