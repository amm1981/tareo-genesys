<?php

namespace App\Http\Controllers;

use App\Exceptions\SyncException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApiAuthController extends Controller
{
    public function login(Request $request)
    {
        // Exclusivamente JSON del body; no aceptar credenciales en la URL.
        $input = Validator::make($request->json()->all(), [
            'usuario' => ['required', 'string', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,63}$/D'],
            'password' => ['required', 'string', 'max:72'],
        ])->validate();
        if (strlen($input['password']) > 72) {
            throw ValidationException::withMessages(['password' => 'Contraseña fuera del límite admitido.']);
        }

        return DB::transaction(function () use ($input) {
            $user = User::where('api_username', Str::lower($input['usuario']))->lockForUpdate()->first();
            $valid = Hash::check($input['password'], $user?->password ?? config('api_access.dummy_password_hash'));
            if (! $valid || ! $user || ! $user->api_enabled) {
                throw new SyncException('invalid_credentials', 'Usuario o contraseña incorrectos.', 401);
            }
            if (Hash::needsRehash($user->password)) {
                $user->password = Hash::make($input['password']);
                $user->save();
            }
            $user->queryTokens()->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();
            $ids = $user->queryTokens()->orderByDesc('id')->pluck('id');
            $remove = $ids->slice(max(0, config('api_access.max_tokens_per_user') - 1));
            if ($remove->isNotEmpty()) {
                $user->queryTokens()->whereIn('id', $remove)->delete();
            }
            $minutes = config('api_access.token_ttl_minutes');
            $expires = now()->addMinutes($minutes);
            $token = $user->createToken('api-query', ['costs:read'], $expires);

            return response()->json(['data' => [
                'access_token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_in' => $minutes * 60,
                'expires_at' => $expires->toIso8601String(),
            ]]);
        }, 3);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => ['revocado' => true]]);
    }
}
