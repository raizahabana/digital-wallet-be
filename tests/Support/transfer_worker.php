<?php

/**
 * Child process for ConcurrentTransferTest: boots the app and runs ONE transfer.
 *
 * Usage: php transfer_worker.php <senderUserId> <recipientEmail> <amount> <startAtUnixMicrotime>
 * Exit code: 0 = transferred, 2 = insufficient funds, 1 = anything else (message on STDERR).
 */

use App\Actions\Wallet\TransferFunds;
use App\DataTransferObjects\TransferData;
use App\Exceptions\InsufficientFundsException;
use App\Models\User;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[, $senderId, $recipientEmail, $amount, $startAt] = $argv;

$sender = User::findOrFail((int) $senderId);

// Every worker waits for the same instant, so they all hit the wallet lock together.
while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    app(TransferFunds::class)->execute(new TransferData($sender, $recipientEmail, $amount));
    exit(0);
} catch (InsufficientFundsException) {
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(1);
}
