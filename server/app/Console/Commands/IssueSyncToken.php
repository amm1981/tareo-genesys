<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class IssueSyncToken extends Command
{
    protected $signature = 'sync:token {--days=30 : Vigencia entre 1 y 365 días} {--save-client-env : Guardar directamente en client/.env, solo en local}';

    protected $description = 'Crea un usuario técnico y un token Sanctum limitado a sync:write';

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 365) {
            $this->error('Vigencia inválida.');

            return self::FAILURE;
        }
        $file = base_path('../client/.env');
        if ($this->option('save-client-env')) {
            if (! app()->environment('local') || ! is_file($file)) {
                $this->error('Se requiere APP_ENV=local y client/.env existente.');

                return self::FAILURE;
            }
            $contents = file_get_contents($file);
            if (! preg_match('/^API_TOKEN=\s*$/m', $contents)) {
                $this->error('API_TOKEN ya está definido o falta la variable. Consérvelo o revóquelo manualmente.');

                return self::FAILURE;
            }
        }
        $user = User::firstOrCreate(['email' => 'sync@get-genesys.invalid'], ['name' => 'GET_Genesys Sync', 'password' => Str::random(64)]);
        $token = $user->createToken('manual-sync-'.now()->format('YmdHis'), ['sync:write'], now()->addDays($days));
        if ($this->option('save-client-env')) {
            file_put_contents($file, preg_replace('/^API_TOKEN=\s*$/m', 'API_TOKEN='.$token->plainTextToken, $contents), LOCK_EX);
            $this->info('Token limitado guardado en client/.env; no se muestra ni registra.');
        } else {
            $this->info('Copie el siguiente valor a API_TOKEN en client/.env. Solo se muestra ahora:');
            $this->line($token->plainTextToken);
        }

        return self::SUCCESS;
    }
}
