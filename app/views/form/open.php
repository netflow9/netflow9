<?php
//CLD: 2026-10-13T18:28:46+03:00: the page of a Form trigger (FormController::open): its title and the form
$this->pageTitle = $title;
$tpl = '<div class="card shadow-sm"><div class="card-body"><h4 class="mb-3">{:title}</h4>{:form}</div></div>';
echo \util\VString::insert($tpl, [
    'title' => \V::app()->html->escape($title),
    //CLD: 2026-10-14T07:50:50+03:00: the partial is in views/form (the Forms plugin moved into the app)
    'form' => $this->renderFile('/views/form/_fill', compact('form', 'url', 'submitLabel')),
]);
