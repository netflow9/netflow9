<?php

declare(strict_types=1);

//loads the sys\J classes (the app's autoloader does it inside the app)
//CLD: 2026-10-20T22:45:00+03:00: and util\JSON (J's JSON text and JSON.parse)
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'sys\\J\\')) {
        $file = __DIR__ . '/../' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file))
            require $file;
    } elseif ($class === 'util\\JSON') {
        require __DIR__ . '/../../../util/JSON.php';
    }
});

use sys\J\Interpreter;
use sys\J\LimitExceeded;
use sys\J\Parser;
use sys\J\ScriptError;

$code = <<<'JS'
// Script written by a user in your IDE
const total = order.items.reduce((sum, it) => sum + it.price * it.qty, 0);
const discount = total > 100 ? 0.1 : 0;

notify(`Order ${order.id}: total ${total.toFixed(2)}`);

return {
  total,
  discount,
  final: Math.round(total * (1 - discount) * 100) / 100,
  skus: order.items.map(it => it.sku.toUpperCase()),
};
JS;

// 1. Parse (never throws). Send $parsed->diagnostics to the editor as markers.
$parsed = Parser::parse($code);
foreach ($parsed->diagnostics as $d) {
    printf("%s at %d:%d - %s\n", $d['severity'], $d['line'], $d['column'], $d['message']);
}

// 2. Execute with a host-provided context.
$vm = new Interpreter(['maxSteps' => 100_000, 'timeLimit' => 1.0]);
try {
    $result = $vm->run($parsed, [
        'order' => [
            'id' => 'A-17',
            'items' => [
                ['sku' => 'ab-1', 'price' => 40, 'qty' => 2],
                ['sku' => 'cd-2', 'price' => 25.5, 'qty' => 1],
            ],
        ],
        // Only \Closure values become callable from scripts.
        'notify' => function (string $message): void {
            echo "[notify] $message\n";
        },
    ]);
    var_dump($result);
    print_r($vm->getOutput()); // console.log output
} catch (ScriptError $e) {
    echo $e->getMessage(), "\n"; // e.g. "Uncaught TypeError: ... (line 3, column 1)"
} catch (LimitExceeded $e) {
    echo 'Aborted: ', $e->getMessage(), "\n";
}

// 3. IDE: the AST is plain arrays, so it can be cached or sent to the browser.
$broken = Parser::parse("const x = {a: 1, b: }\nclass Foo {}\nx.");
echo json_encode($broken, JSON_PRETTY_PRINT), "\n";
