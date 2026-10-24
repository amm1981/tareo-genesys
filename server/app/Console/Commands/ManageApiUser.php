<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManageApiUser extends Command
{
    protected $signature = 'api:usuario {usuario : Identificador del sistema consumidor} {--nombre= : Nombre descriptivo} {--desactivar : Deshabilitar la cuenta y revocar tokens de consulta}';

    protected $description = 'Crea o cambia la contraseña de una cuenta de consulta; solicita la contraseña de forma oculta';

    public function handle(): int
    {
        $username = Str::lower($this->argument('usuario'));
        $name = $this->option('nombre');
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/D', $username) || ($name !== null && (trim($name) === '' || mb_strlen($name) > 255))) {
            $this->error('Usuario inválido: 3 a 64 caracteres, letras, números, punto, guion o guion bajo; nombre de hasta 255 caracteres.');

            return self::FAILURE;
        }
        if ($this->option('desactivar')) {
            $found = DB::transaction(function () use ($username) {
                $user = User::where('api_username', $username)->lockForUpdate()->first();
                if (! $user) {
                    return false;
                }
                $user->api_enabled = false;
                $user->save();
                $user->queryTokens()->delete();

                return true;
            });
            if (! $found) {
                $this->error('Cuenta de consulta inexistente.');

                return self::FAILURE;
            }
            $this->info('Cuenta deshabilitada; tokens de consulta revocados.');

            return self::SUCCESS;
        }
        $this->info('Se creará o actualizará la cuenta y se revocarán sus tokens de consulta anteriores.');
        $password = $this->secret('Contraseña (16 a 72 bytes)', false);
        $confirm = $this->secret('Repita la contraseña', false);
        if (! is_string($password) || strlen($password) < 16 || strlen($password) > 72 || $password !== $confirm) {
            $this->error('La contraseña debe tener entre 16 y 72 bytes y coincidir con la confirmación.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($username, $name, $password) {
            $user = User::where('api_username', $username)->lockForUpdate()->first();
            if (! $user) {
                $user = new User;
                $user->email = 'api-'.Str::uuid().'@get-genesys.invalid';
                $user->name = $name ?? 'API '.$username;
            } elseif ($name !== null) {
                $user->name = $name;
            }
            $user->api_username = $username;
            $user->api_enabled = true;
            $user->password = $password;
            $user->save();
            $user->queryTokens()->delete();
        });
        $this->info('Cuenta de consulta habilitada. La contraseña no se muestra ni se registra.');

        return self::SUCCESS;
    }
}
