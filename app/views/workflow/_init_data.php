<?php

//the data
return [
    'nodes' => [
      [ 'id' => 'n1', 'fqid' => 'trigger::manual',	'x' => 40,  'y' => 160, 'title' => 'Start', 'isNode' => true ],
      [ 'id' => 'n2', 'fqid' => 'app::set',		'x' => 210, 'y' => 160, 'title' => 'Set', 'isNode' => true ],
      [ 'id' => 'n3', 'fqid' => 'core::if',		'x' => 380, 'y' => 160, 'title' => 'IF', 'isNode' => true ],
      [ 'id' => 'n4', 'fqid' => 'app::action',		'x' => 590, 'y' => 40,  'title' => 'True Path', 'isNode' => true ],
      [ 'id' => 'n5', 'fqid' => 'app::action',		'x' => 590, 'y' => 280, 'title' => 'False Path', 'isNode' => true ],
      [ 'id' => 'n6', 'fqid' => 'core::merge',		'x' => 800, 'y' => 160, 'title' => 'Merge', 'isNode' => true ],

      [ 'id' => 'e1', 'fromNode' => 'n1', 'fromPort' => 0, 'toNode' => 'n2', 'toPort' => 0, 'isEdge' => true ],
      [ 'id' => 'e2', 'fromNode' => 'n2', 'fromPort' => 0, 'toNode' => 'n3', 'toPort' => 0, 'isEdge' => true ],
      [ 'id' => 'e3', 'fromNode' => 'n3', 'fromPort' => 0, 'toNode' => 'n4', 'toPort' => 0, 'branch' => 'true', 'isEdge' => true ],
      [ 'id' => 'e4', 'fromNode' => 'n3', 'fromPort' => 1, 'toNode' => 'n5', 'toPort' => 0, 'branch' => 'false', 'isEdge' => true ],
      [ 'id' => 'e5', 'fromNode' => 'n4', 'fromPort' => 0, 'toNode' => 'n6', 'toPort' => 0, 'isEdge' => true ],
      [ 'id' => 'e6', 'fromNode' => 'n5', 'fromPort' => 0, 'toNode' => 'n6', 'toPort' => 1, 'isEdge' => true ],
    ],
];
