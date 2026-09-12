<?php

use App\Models\Account;
use App\Models\User;
use App\Services\MemberLifecycle;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || ! str_contains(config('database.connections.mysql.database'), 'test')) {
    exit(2);
}

try {
    $actor = User::findOrFail($argv[1]);
    $account = Account::findOrFail($argv[2]);
    echo "READY\n";
    flush();
    app(MemberLifecycle::class)->update($actor, $account, $argv[3], json_decode($argv[4], true));
    echo json_encode(['status' => 200]);
} catch (HttpExceptionInterface $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(1);
}
