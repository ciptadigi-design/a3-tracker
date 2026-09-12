<?php

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Services\InventoryLedgerService;
use Illuminate\Contracts\Console\Kernel;

// Keep the subprocess protocol machine-readable when a newer local PHP emits
// dependency deprecations that are unrelated to the concurrency assertion.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Unlike consume_inventory.php (two DIFFERENT client_request_ids racing for
// the same limited stock), this harness gives both processes the SAME
// client_request_id, argv[3] - simulating two concurrent retries of one
// logical decrease. Correct idempotent behavior means both must resolve to
// the exact same movement row, and stock must be decremented only once.
try {
    $movement = app(InventoryLedgerService::class)->outbound(
        InventoryItem::findOrFail($argv[1]),
        InventoryLocation::findOrFail($argv[2]),
        3,
        'adjustment_out',
        $argv[3],
    );
    echo json_encode(['ok' => true, 'movement_id' => (string) $movement->id]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit(2);
}
