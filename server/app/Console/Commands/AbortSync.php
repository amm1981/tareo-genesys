<?php

namespace App\Console\Commands;

use App\Services\SyncService;
use Illuminate\Console\Command;

class AbortSync extends Command
{
    protected $signature = 'sync:abort {uuid}';

    protected $description = 'Marca una transferencia pendiente como fallida y libera el período; conserva el snapshot activo';

    public function handle(SyncService $sync): int
    {
        $sync->abort($this->argument('uuid'));
        $this->info('Transferencia cancelada; snapshot previo conservado.');

        return self::SUCCESS;
    }
}
