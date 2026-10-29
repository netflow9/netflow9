<?php
//CLD: 2026-10-28T23:55:00+02:00: new view: one page of the documentation (DocsController::index): the contents (sections, their pages; the current section open), the page,
//"On this page" (its h2 / h3; wide screens), previous / next

$section = $current['section'];
$page = $current['page'];
$child = $current['child'];
$shown = $child ?? $page;
$this->pageTitle = ($shown['title'] ?? '') . ' - Documentation';

//a link to a page of the reading order (previous / next)
$pageLink = function($item) {
    return \controllers\DocsController::pageUrl($item['section'], $item['page'], $item['child']);
};
?>
<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $items = [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'Documentation', 'href' => \V::app()->createUrl('/docs/index') ],
	[ 'label' => \V::app()->html->escape($section['title']), 'href' => \controllers\DocsController::pageUrl($section) ],
    ];
    if ($child)
	$items[] = [ 'label' => \V::app()->html->escape($page['title']), 'href' => \controllers\DocsController::pageUrl($section, $page) ];
    $items[] = [ 'label' => \V::app()->html->escape($shown['title']) ];
    $bc = new \sys\html\Breadcrumbs([ 'items' => $items ]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-3 p-lg-4">
    <div class="row g-4">

	<!-- contents: a button opens them on narrow screens -->
	<div class="col-lg-3">
	    <button class="btn btn-sm btn-outline-secondary d-lg-none mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#docs-contents" aria-expanded="false" aria-controls="docs-contents">
		<i class="bi bi-list"></i> Contents
	    </button>
	    <nav id="docs-contents" class="collapse d-lg-block small">
		<?php foreach ($sections as $sec): ?>
		<details class="mb-2" <?= $sec['id'] === $section['id'] ? 'open' : '' ?>>
		    <summary class="fw-semibold py-1"><i class="bi <?= \V::app()->html->escape($sec['icon'] ?? 'bi-book') ?> me-1"></i> <?= \V::app()->html->escape($sec['title']) ?></summary>
		    <ul class="list-unstyled ms-3 mb-1">
			<?php foreach ($sec['pages'] as $pg): ?>
			<?php $isPage = $sec['id'] === $section['id'] && $pg['id'] === $page['id']; ?>
			<li>
			    <a href="<?= \V::app()->html->escape(\controllers\DocsController::pageUrl($sec, $pg)) ?>" class="d-block py-1 text-decoration-none <?= $isPage && !$child ? 'fw-semibold' : 'text-body-secondary' ?>"><?= \V::app()->html->escape($pg['title']) ?></a>
			    <?php if ($isPage && !empty($pg['pages'])): ?>
			    <ul class="list-unstyled ms-3">
				<?php foreach ($pg['pages'] as $ch): ?>
				<li><a href="<?= \V::app()->html->escape(\controllers\DocsController::pageUrl($sec, $pg, $ch)) ?>" class="d-block py-1 text-decoration-none <?= $child && $ch['id'] === $child['id'] ? 'fw-semibold' : 'text-body-secondary' ?>"><?= \V::app()->html->escape($ch['title']) ?></a></li>
				<?php endforeach; ?>
			    </ul>
			    <?php endif; ?>
			</li>
			<?php endforeach; ?>
		    </ul>
		</details>
		<?php endforeach; ?>
	    </nav>
	</div>

	<!-- the page -->
	<div class="col-lg-9 col-xl-7">
	    <article style="max-width: 860px">
		<h1 class="h3 mb-2"><?= \V::app()->html->escape($shown['title']) ?></h1>
		<?php if (!empty($shown['summary'])): ?>
		<p class="lead fs-6 text-body-secondary"><?= \V::app()->html->escape($shown['summary']) ?></p>
		<?php endif; ?>

		<?php if (!$child && !empty($page['pages'])): ?>
		<ul>
		    <?php foreach ($page['pages'] as $ch): ?>
		    <li><a href="<?= \V::app()->html->escape(\controllers\DocsController::pageUrl($section, $page, $ch)) ?>"><?= \V::app()->html->escape($ch['title']) ?></a></li>
		    <?php endforeach; ?>
		</ul>
		<?php endif; ?>

		<!-- the page's own HTML (app/data/docs.json, written by the docs site's editor: its links and headings prepared by the controller) -->
		<?php //CLD: 2026-10-29T10:00:00+02:00: .docs-content (the theme's page typography: headings, code, tables, callouts; _docs.scss); was .docs-page, the docs site's flex row (article + "On this page"): every paragraph / table became a column side by side ?>
		<div class="docs-content">
		    <?= $html ?>
		</div>

		<nav class="d-flex justify-content-between gap-3 border-top mt-5 pt-3">
		    <div>
			<?php if ($prev): ?>
			<a href="<?= \V::app()->html->escape($pageLink($prev)) ?>" class="text-decoration-none">
			    <small class="d-block text-body-secondary">Previous</small><i class="bi bi-arrow-left"></i> <?= \V::app()->html->escape(($prev['child'] ?? $prev['page'])['title']) ?>
			</a>
			<?php endif; ?>
		    </div>
		    <div class="text-end">
			<?php if ($next): ?>
			<a href="<?= \V::app()->html->escape($pageLink($next)) ?>" class="text-decoration-none">
			    <small class="d-block text-body-secondary">Next</small><?= \V::app()->html->escape(($next['child'] ?? $next['page'])['title']) ?> <i class="bi bi-arrow-right"></i>
			</a>
			<?php endif; ?>
		    </div>
		</nav>
		<?php if ($updated !== ''): ?>
		<p class="small text-body-secondary mt-3">Updated <?= \V::app()->html->escape(substr($updated, 0, 10)) ?></p>
		<?php endif; ?>
	    </article>
	</div>

	<!-- on this page: wide screens -->
	<?php if ($toc): ?>
	<div class="col-xl-2 d-none d-xl-block">
	    <nav class="small sticky-top" style="top: 1rem">
		<div class="fw-semibold mb-2">On this page</div>
		<ul class="list-unstyled">
		    <?php foreach ($toc as [ $level, $id, $text ]): ?>
		    <li class="<?= $level === 3 ? 'ms-3' : '' ?>"><a href="#<?= \V::app()->html->escape($id) ?>" class="d-block py-1 text-decoration-none text-body-secondary"><?= \V::app()->html->escape($text) ?></a></li>
		    <?php endforeach; ?>
		</ul>
	    </nav>
	</div>
	<?php endif; ?>
    </div>
</div>
