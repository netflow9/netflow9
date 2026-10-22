<?php

//CLD: 2026-10-17T13:24:18+03:00: moved from the BaseNodes plugin into the core (was plugins\BaseNodes\app)
namespace models\node;

class NodeAppAction extends \models\WfNode {
    //static data/structure
    public static $type = 'app';
    public static $name = 'action';
    public static $label = 'Action';
    public static $icon = 'bi-arrow-right-circle-fill';
    public static $color = 'var(--wf-color-app)';
    public static $inputs = 1;
    public static $outputs = 1;

}
