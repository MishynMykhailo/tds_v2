<?php

namespace App\Console\Commands;

use App\Models\UserPasswordHash;
use Illuminate\Console\Command;

/**
 * Port of legacy `Component\Users\PruneTask\PruneUserPasswordHash`
 * (application/Component/Users/PruneTask/PruneUserPasswordHash.php) ->
 * `UserPasswordHashService::directDeleteAll("expires_at < NOW()")` — a
 * `PruneTaskRepository::GENERAL_TYPE` task, run daily by legacy's
 * `Cleaner\CronTask\PruneData`. Literal 1-to-1 port: delete
 * `user_password_hashes` rows (password-reset tokens) whose `expires_at`
 * is in the past.
 */
class PruneExpiredPasswordHashes extends Command
{
    protected $signature = 'app:prune-expired-password-hashes';

    protected $description = 'Delete expired user_password_hashes rows (password-reset tokens)';

    public function handle(): int
    {
        $deleted = UserPasswordHash::where('expires_at', '<', now())->delete();

        $this->info("Pruned {$deleted} expired password-reset hash(es).");

        return self::SUCCESS;
    }
}
