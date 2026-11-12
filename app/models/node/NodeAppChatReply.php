<?php

/*
    Reply to chat - the workflow answers in the conversation that started the run (models\agent\AgentConversation), as text or Markdown, with optional buttons: the button chosen
	comes back as the next message (its value). In a chat page it appears at once; in a conversation by email or Teams it is sent there (util\AgentChannels; buttons: a line saying what to answer).
	A run with no conversation (Run in the designer, a workflow started otherwise) sends nothing: the answer is logged. A Run again sends nothing either: the person has had the answer.
    The variable (default `reply`): { sent, text, message: its number }
*/

namespace models\node;

class NodeAppChatReply extends \models\wf\WfNode {
    public static $type = 'app';
    public static $name = 'chatreply';
    public static $label = 'Reply to chat';
    public static $icon = 'bi-reply';
    public static $category = 'ai';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    const FORMATS = [ 'markdown' => 'Markdown', 'text' => 'Text' ];
    const MAX_TEXT = 50000;
    const MAX_BUTTONS = 10;
    const MAX_LABEL = 80;
    const MAX_VALUE = 500;

    public static function configSchema() {
	return [
	    [
		'key' => 'text',
		'type' => 'textarea',
		'label' => 'Answer',
		'required' => true,
		'help' => 'e.g. {{ ai.text }}',
		'ui' => [ 'rows' => 4, 'expression' => true ],
	    ],
	    [
		'key' => 'format',
		'type' => 'select',
		'label' => 'Answer as',
		'required' => true,
		'default' => 'markdown',
		'choices' => self::FORMATS,
		'ui' => [ 'width' => 3 ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Result variable',
		'required' => true,
		'default' => 'reply',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 5, 'variableName' => 'write' ],
	    ],
	    [
		'key' => 'onError',
		'type' => 'select',
		'label' => 'On error',
		'required' => true,
		'default' => 'fail',
		'choices' => self::ON_ERROR,
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'buttons',
		'type' => 'nested',
		'multiple' => true,
		'label' => 'Buttons',
		'help' => 'Optional: the one chosen comes back as the next message',
		'itemLabel' => 'Button',
		'default' => [],
		'fields' => [
		    [ 'key' => 'label', 'type' => 'text', 'label' => 'Label', 'required' => true, 'ui' => [ 'width' => 6, 'expression' => true ] ],
		    [ 'key' => 'value', 'type' => 'text', 'label' => 'Value', 'help' => 'Empty: the label', 'ui' => [ 'width' => 6, 'expression' => true ] ],
		],
		'ui' => [ 'addLabel' => 'Add button', 'compact' => true, 'maxRows' => self::MAX_BUTTONS ],
	    ],
	    [
		'key' => 'buttonsFrom',
		'type' => 'text',
		'label' => 'More buttons from',
		'default' => '',
		'help' => 'Optional: a list of texts, or of { label, value }, e.g. {{ ai.json.options }}',
		'ui' => [ 'expression' => true ],
	    ],
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'reply') : 'reply';
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object', 'info' => 'the answer: { sent, text, message }; on error { error }', 'fields' => [
	    'sent' => [ 'type' => 'boolean', 'info' => 'false: no conversation (Run in the designer), or a Run again' ], 'text' => [ 'type' => 'string' ], 'message' => [ 'type' => 'number', 'info' => 'its number in the conversation' ],
	    'error' => [ 'type' => 'string', 'info' => 'on error only' ] ] ] ] : [];
    }

    public function execute($edgeId = null) {
	$name = (string)$this->cfg('variable', 'reply');
	$vars = $this->exec->expressionVariables();
	$text = mb_substr(trim(\util\Expression::toText(\util\Expression::render((string)$this->cfg('text', ''), $vars))), 0, self::MAX_TEXT);
	$format = (string)$this->cfg('format', 'markdown') === 'text' ? 'text' : 'markdown';
	$buttons = $this->buttons($vars);
	try {
	    if ($text === '')
		throw new \RuntimeException('the answer is empty');
	    $where = \models\agent\AgentConversation::ofExecution($this->exec);
	    if (!$where || !empty($this->exec->rerun_of)) {
		$this->exec->log($this->wfid, 'Reply to chat: not sent (' . (!$where ? 'no conversation' : 'a Run again') . '): ' . mb_substr($text, 0, 500));
		$result = [ 'sent' => false, 'text' => $text, 'message' => null ];
	    } else {
		[ $conv ] = $where;
		\util\AgentChannels::deliver($conv, $this->exec, $text, $format, $buttons);
		$seq = \models\agent\AgentConversation::reply($conv, $this->exec, $text, $format, $buttons);
		$this->exec->log($this->wfid, 'Reply to chat: message ' . $seq . (count($buttons) ? ', ' . count($buttons) . ' button(s)' : ''));
		$result = [ 'sent' => true, 'text' => $text, 'message' => $seq ];
	    }
	} catch (\RuntimeException | \ErrorException $e) {
	    if (\util\Validator::isIdentifier($name))
		$this->exec->setVariable($name, [ 'error' => $e->getMessage() ]);
	    $this->exec->log($this->wfid, 'Reply to chat: ' . $e->getMessage());
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException('Reply to chat: ' . $e->getMessage());
	}
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $result);
	return [ 0 ];
    }

    //the buttons: [ { label, value } ] of the settings' rows and of More buttons from; empty labels dropped, each value once, at most MAX_BUTTONS
    private function buttons(array $vars) {
	$rows = [];
	foreach((array)$this->cfg('buttons', []) as $row) {
	    if (!is_array($row))
		continue;
	    $rows[] = [ \util\Expression::toText(\util\Expression::render((string)($row['label'] ?? ''), $vars)), \util\Expression::toText(\util\Expression::render((string)($row['value'] ?? ''), $vars)) ];
	}
	$from = trim((string)$this->cfg('buttonsFrom', ''));
	if ($from !== '') {
	    $list = \util\Expression::render($from, $vars);
	    foreach(is_array($list) && array_is_list($list) ? $list : [] as $item) {
		$item = is_object($item) ? (array)$item : $item;
		if (is_array($item))
		    $rows[] = [ \util\Expression::toText($item['label'] ?? ''), \util\Expression::toText($item['value'] ?? '') ];
		elseif (is_scalar($item))
		    $rows[] = [ (string)$item, '' ];
	    }
	}
	$out = [];
	foreach($rows as [ $label, $value ]) {
	    $label = mb_substr(trim($label), 0, self::MAX_LABEL);
	    $value = mb_substr(trim($value), 0, self::MAX_VALUE);
	    if ($label === '')
		continue;
	    $value = $value !== '' ? $value : $label;
	    if (!isset($out[$value]) && count($out) < self::MAX_BUTTONS)
		$out[$value] = [ 'label' => $label, 'value' => $value ];
	}
	return array_values($out);
    }
}
