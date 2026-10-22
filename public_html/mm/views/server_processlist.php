<?php
$cols = [
    'opid' => 'ID', 
    'desc' => 'Description', 
    'client' => 'Client', 
    'active' => 'Active',
    'lockType' => 'LockType',
    'waitingForLock' => 'Waiting',
    'secs_running' => 'SecsRunning',
    'op' => 'Operation',
    'ns' => 'NameSpace'
];

$lineRender = function($item) use($cols) {
    $output = '';
    $output .= '<tr bgcolor="#fffeee">';

    //opid column is special
    $rowspan = (!empty($item['command']) ? 'rowspan="2"' : '');

    $killBtn = '';
    if (!empty($item['client'])) {
	$url = R::app()->url('app.server_killop', [ 'opid' => $item['opid'] ]);
	$title = '<i class="bi bi-x"></i>';
	$killBtn = R::app()->html->link($title, $url, [
	    'data-confirm' => 'Please confirm',
	    'data-method' => 'post',
	    'title' => 'Kill',
	    'class' => 'mx-1',
	]);
    }

    $output .= "<td valign='top' $rowspan>{$item['opid']} $killBtn</td>";
    
    foreach ($cols as $key => $desc) {
	if ($key == 'opid')
	    continue;
	$output .= '<td valign="top">' . ($item[$key] ?? '') . '</td>';
    }
    $output .= '</tr>';

    if (!empty($item['command'])) {
	$output .= '<tr bgcolor="#fffeee"><td colspan="9">';
	if ($item['op'] == 'query')
	    $output .= '<strong>Query Plan:</strong><br>' . $item['planSummary'] ?? '' . '<br>';
	$output .= '<p class="mb-0"><b>Command:</b></p>' . $item['command'];
	$output .= '</td></tr>';
    }
    return $output;
};

?>

<h5>Process list</h5>

<table class="table table-bordered">
    <tr bgcolor="#cfffff">
	<?php
	foreach ($cols as $param => $desc)
	    echo "<th>$desc</th>";
	?>
    </tr>
    <?php
    foreach ($progs as $prog)
	echo $lineRender($prog);
    ?>
</table>

