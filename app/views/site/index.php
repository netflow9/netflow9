<?php
$this->pageTitle = 'Overview';

//CLD: 2026-10-14T21:44:49+03:00: the placeholder figures ($dashboardCfg, all 0) are replaced by the dashboard (app.Overview, SiteController::overview)
?>

<div class="main-header py-2 px-3">
    <a href="#" class="btn-expand-sidebar d-none" title="Expand sidebar">
	<i class="bi bi-layout-sidebar-inset"></i>
    </a>

    <!-- breadcrumbs -->
    <nav aria-label="breadcrumb">
	<ol class="breadcrumb mb-0">
	    <li class="breadcrumb-item active" aria-current="page">Project Home</li>
	</ol>
    </nav>
    <div class="spacer"></div>
    <!-- right menu here -->
</div>

<div class="main-body p-4">
    <!-- page header -->
    <div class="d-flex align-items-center w-100 align-items-top mb-3">
	<div>
	    <h5>Overview</h5>
	    <?php //CLD: 2026-10-14T21:44:49+03:00: how the project's workflows run (was: All the workflows, variables and tables you can access) ?>
	    <span class="text-muted">How this project's workflows run</span>
	</div>
	<div class="flex-fill"></div>
    </div>

    <?php //CLD: 2026-10-14T21:44:49+03:00: the dashboard (public_html/js/app.overview.js); chart colors as tokens: blue = completed, red = error (validated for color vision
    //deficiency on the light / dark card surface), recessive grid, text in the text tokens ?>
    <style>
    .netflow9-overview {
	--ov-completed: #2a78d6;
	--ov-error: #e34948;
	--ov-surface: var(--bs-body-bg);
	--ov-grid: #e7e6e2;
	--ov-text: var(--bs-body-color);
	--ov-muted: var(--bs-secondary-color);
    }
    [data-bs-theme=dark] .netflow9-overview {
	--ov-completed: #3987e5;
	--ov-error: #e66767;
	--ov-grid: #343a40;
    }
    .netflow9-overview .ov-num { font-variant-numeric: tabular-nums; }
    .netflow9-overview .ov-spark { vertical-align: middle; }
    .netflow9-overview .ov-periods .btn:not(.active), .netflow9-overview .ov-refresh { color: var(--bs-body-color); }
    </style>
    <div class="netflow9-overview" id="netflow9-overview"></div>
    <?php \V::app()->cs->addPageScript("new app.Overview(document.getElementById('netflow9-overview'), { url: '/site/overview' });"); ?>
</div>
