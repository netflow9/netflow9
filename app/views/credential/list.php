<?php
$this->pageTitle = 'Credentials & variables';

$createBtn = '';
if (\V::app()->user->can('Credential::create')) {
    $groups = [];
    foreach(\V::app()->om->getVariants('Credential') as $name => $class) {
	//the types of a feature the account's tier does not have are not offered (util\Tiers)
	if (!\util\Tiers::allows(\V::app()->var('rt.account'), $name))
	    continue;
	//nor the types this PHP cannot use (Credential::available(): e.g. a database type without its PDO driver)
	if (!$class::available())
	    continue;
	$search = \V::app()->html->escape(str_replace("'", ' ', mb_strtolower($class::$label . ' ' . $class::$group)));
	$groups[$class::$group][$class::$label] = \util\VString::insert('<li class="cred-type" data-search="{:search}"><a class="dropdown-item" href="/credential/create?type={:type}"><i class="bi {:icon} me-2"></i>{:label}</a></li>', [
	    'search' => $search,
	    'type' => rawurlencode($name),
	    'icon' => $class::$icon,
	    'label' => \V::app()->html->escape($class::$label),
	]);
    }
    ksort($groups);

    $items = '';
    foreach($groups as $group => $links) {
	ksort($links);
	$items .= \util\VString::insert('<li class="cred-type-group"><h6 class="dropdown-header">{:group}</h6></li>{:links}', [
	    'group' => \V::app()->html->escape($group),
	    'links' => implode('', $links),
	]);
    }
    //one line, no single quotes: it goes into a JS string
    //CLD: 2026-10-22T01:18:42+03:00: the menu scrolls (at most 60% of the window), a search box stays on top (its handlers: the page script below); a line when nothing matches
    $searchBox = "<li class=\"sticky-top bg-body px-2 pt-1 pb-2 border-bottom\"><input type=\"search\" class=\"form-control form-control-sm cred-type-search\" placeholder=\"Search types...\" autocomplete=\"off\" aria-label=\"Search credential types\"></li>";
    $noMatch = "<li class=\"cred-type-none px-3 py-2 small text-body-secondary d-none\">No matching type</li>";
    $createBtn = "<div class=\"dropdown d-inline-block\"><button class=\"btn btn-primary btn-sm dropdown-toggle\" data-bs-toggle=\"dropdown\" aria-expanded=\"false\">Create</button><ul class=\"dropdown-menu dropdown-menu-end pt-0 overflow-y-auto\" style=\"z-index:1030; max-height:60vh; min-width:16rem\">{$searchBox}{$items}{$noMatch}</ul></div>";
}

//the grid gets the type's label: what each label can do
$typeInfo = [];
foreach(\V::app()->om->getVariants('Credential') as $name => $class)
    //CLD: 2026-11-03T18:00:00+02:00: + shareable: not the variables
    $typeInfo[$class::$label] = [ 'oauth' => $class::isOAuth(), 'testable' => (bool)$class::$testable, 'shareable' => $name !== 'CredentialVariable' ];
$typeInfo = \util\JSON::encode($typeInfo);

//schema and filtering
$sf = \util\WidgetsUtil::getSchema('Credential');
$schema = $sf['schema'];
unset($schema['created_at'], $schema['updated_at']);
$schema = \util\JSON::encode($schema);
$filter = \util\JSON::encode($sf['filter']);

//CLD: 2026-11-03T18:00:00+02:00: Share, with Credential::share (not in a personal project; not the variables); the second list: those shared with this project (no actions: they stay with their project)
$shareAction = $canShare ? "share: `{% if ((app.credentialTypes[item.__variant] || {}).shareable) { %}<a href='/share/manage?object=Credential&id={{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Share'><i class='bi bi-share'></i></a>{% } %}`,
		    " : '';

//CLD: 2026-11-04T12:00:00+02:00: Move, as Delete: it leaves this project (not the variables, as Share)
$moveAction = \V::app()->user->can('Credential::delete')
    ? "move: `{% if ((app.credentialTypes[item.__variant] || {}).shareable) { %}<a href='/share/move?object=Credential&id={{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Move to another project'><i class='bi bi-folder-symlink'></i></a>{% } %}`,
		    " : '';

$script = "
+function() {
    const container = document.getElementById('obj-list');
    //read by the row action templates (compiled with new Function: only globals are visible)
    app.credentialTypes = {$typeInfo};
    new app.GridView(container, {
	create: '{$createBtn}',
	title: `<h5 class='h5 mb-0'>Credentials & variables</h5>`,
	dataUrl: '/credential/list?data=1',

	schema: {
	    readonly: true,
	    data: {$schema},
	},

	filter: {
		data: {$filter},
	},

	grid: {
		createRowUrl: false,
		rowActions: {
		    test: `{% if ((app.credentialTypes[item.__variant] || {}).testable) { %}<a href='/credential/test/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' data-method='post' title='Test'><i class='bi bi-plug'></i></a>{% } %}`,
		    connect: `{% if ((app.credentialTypes[item.__variant] || {}).oauth) { %}<a href='/credential/connect/{{item._id}}' no-pjax class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Connect (sign in at the provider)'><i class='bi bi-box-arrow-in-right'></i></a>{% } %}`,
		    {$shareAction}{$moveAction}update: `<a href='/credential/update/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn'><i class='bi bi-pencil'></i></a>`,
		    delete: `<a href='/credential/delete/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' data-confirm='Are you sure?' data-method='post'><i class='bi bi-trash'></i></a>`,
		}
	},
	onInit: () => {
	    //CLD: 2026-10-22T01:18:42+03:00: the Create menu's search box: filters the types (name or group) as you type, hides the groups left empty; cleared and focused when the menu opens;
	    //Enter opens the only / first match. On the container (made again on each page load: no handlers left behind)
	    const filterTypes = (menu, text) => {
		const words = text.toLowerCase().split(/\\s+/).filter(Boolean);
		let group = null, groupShown = false, shown = 0;
		const closeGroup = () => { if (group) group.classList.toggle('d-none', !groupShown); };
		menu.querySelectorAll('li.cred-type-group, li.cred-type').forEach((li) => {
		    if (li.classList.contains('cred-type-group')) {
			closeGroup();
			group = li;
			groupShown = false;
			return;
		    }
		    const hit = words.every((w) => li.dataset.search.includes(w));
		    li.classList.toggle('d-none', !hit);
		    if (hit) {
			groupShown = true;
			shown++;
		    }
		});
		closeGroup();
		menu.querySelector('li.cred-type-none')?.classList.toggle('d-none', shown > 0);
	    };
	    container.addEventListener('input', (e) => {
		if (e.target.classList.contains('cred-type-search'))
		    filterTypes(e.target.closest('.dropdown-menu'), e.target.value);
	    });
	    container.addEventListener('keydown', (e) => {
		if (e.key !== 'Enter' || !e.target.classList.contains('cred-type-search'))
		    return;
		e.preventDefault();
		e.target.closest('.dropdown-menu').querySelector('li.cred-type:not(.d-none) a')?.click();
	    });
	    container.addEventListener('shown.bs.dropdown', (e) => {
		const input = e.target.closest('.dropdown')?.querySelector('.cred-type-search');
		if (!input)
		    return;
		input.value = '';
		const menu = input.closest('.dropdown-menu');
		filterTypes(menu, '');
		//CLD: 2026-10-22T01:24:00+03:00: the width of the full list kept while searching (the menu narrowed and moved as the matches got shorter)
		menu.style.width = '';
		menu.style.width = menu.offsetWidth + 'px';
		input.focus();
	    });

	    //refresh gv on events
	    app.events.on('object::deleted object::created object::updated', (e) => {
		container._dataView.reload();
	    });
	},

    });
}();
";

\V::app()->cs->addPageScript($script);

//CLD: 2026-11-03T18:00:00+02:00: the credentials shared with this project
if ($sharedCount > 0) {
    \V::app()->cs->addPageScript("
+function() {
    new app.GridView(document.getElementById('obj-shared'), {
	filter: false,
	create: '',
	title: `<h5 class='h5 mb-0'>Shared with this project</h5>`,
	dataUrl: '/credential/list?data=1&shared=1',
	schema: {
	    readonly: true,
	    updateColumnUrl: '',
	    createColumnUrl: '',
	    data: {
		name: { label: 'Name', type: 'text', filter: false },
		type: { label: 'Type', type: 'text', filter: false },
		project: { label: 'From project', type: 'text', filter: false },
	    },
	},
	grid: { createRowUrl: false, rowActions: {} },
    });
}();
");
}

?>
<div class="main-header py-2 px-3">
    <?= (new \sys\html\BtnExpandSidebar())->render() ?>
    <?= (new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'Credentials & variables' ],
    ]]))->render() ?>
</div>

<div class="main-body p-4">
    <div id="obj-list"></div>
    <?php if ($sharedCount > 0): ?>
    <div id="obj-shared" class="mt-4"></div>
    <?php endif; ?>
</div>
