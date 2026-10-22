<h5>System Overview</h5>
<table width="100%" class="table-bordered">
    <tr bgcolor="#cccccc"><th colspan="2">Mongo Server</th></tr>
    <tr>
	<td width="40%">Command line (db.serverCmdLineOpts())</td>
	<td ><?= $commandLine ?></td>
    </tr>
    <tr>
	<td>Connection</td>
	<td>
	    <?php
		foreach ($connections as $param => $value)
		echo $param . ':' . $value . '<br>';
	    ?>
	</td>
    </tr>

    <tr>
	<td>Build infos</td>
	<td>
	    <?php
		foreach ($buildInfos as $param => $value)
		echo $param . ':' . $value . '<br>';
	    ?>
	</td>
    </tr>

    <tr bgcolor="#cccccc"><th colspan="2">Web Server</th></tr>
    <tr>
	<td>Info</td>
	<td>
	    <?php
		foreach ($webServers as $param => $value)
		echo $param . ':' . $value . '<br>';
	    ?>
	</td>
    </tr>

</table>
