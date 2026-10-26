<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

class RevokeSyncToken extends Command
{
    protected $signature = 'sync:revoke-token {id : ID público del token antiguo, sin el secreto}';

    protected $description = 'Revoca exclusivamente un token sync:write de la cuenta técnica de sincronización';

    public function handle(): int
    {
        $id = filter_var($this->argument('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $token = $id === false ? null : PersonalAccessToken::find($id);
        if (! $token || $token->abilities !== ['sync:write'] || $token->tokenable?->email !== 'sync@get-genesys.invalid') {
            $this->error('Token de sincronización de la cuenta técnica no encontrado.');

            return self::FAILURE;
        }
        $token->delete();
        $this->info('Token antiguo revocado. No se modificaron cuentas ni contraseñas.');

        return self::SUCCESS;
    }
}
