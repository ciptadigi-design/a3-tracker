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

try {
    $movement = app(InventoryLedgerService::class)->outbound(
        InventoryItem::findOrFail($argv[1]),
        InventoryLocation::findOrFail($argv[2]),
        1,
        'replacement_consumption',
        $argv[3],
    );
    echo json_encode(['ok' => true, 'movement_id' => (string) $movement->id]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit(2);
}
