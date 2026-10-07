<?php

use App\Models\MachineComponent;
use App\Services\ReplaceMachineComponent;
use Illuminate\Contracts\Console\Kernel;

// Keep the subprocess protocol machine-readable when a newer local PHP emits
// dependency deprecations that are unrelated to the concurrency assertion.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Two processes race to replace the SAME machine component with DIFFERENT
// client_request_ids (argv[4]), each consuming 1 unit from a deliberately
// 1-unit stock (see InventoryComponentCompatibilityEnforcementTest). Exactly
// one must succeed; the other must fail on insufficient stock, never both
// succeeding (double-spend) and never a corrupted half-applied state.
try {
    $mc = MachineComponent::findOrFail($argv[1]);
    $replacement = app(ReplaceMachineComponent::class)->execute($mc, [
        'inventory_source' => 'inventory',
        'inventory_item_id' => $argv[2],
        'inventory_location_id' => $argv[3],
        'quantity' => 1,
        'client_request_id' => $argv[4],
    ]);
    echo json_encode(['ok' => true, 'replacement_id' => (string) $replacement->id]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit(2);
}
