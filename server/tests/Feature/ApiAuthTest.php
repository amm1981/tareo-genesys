<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Fixture-password-123!';

    protected function setUp(): void
    {
        parent::setUp();
        config(['api_access.dummy_password_hash' => Hash::make('dummy-test-password')]);
    }

    private function consumer(): User
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $user->forceFill(['api_username' => 'sistema.erp', 'api_enabled' => true])->save();

        return $user;
    }

    private function login(string $password = self::PASSWORD, string $username = 'sistema.erp')
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', ['usuario' => $username, 'password' => $password]);
    }

    public function test_login_issues_a_hashed_expiring_read_only_token(): void
    {
        $this->freezeTime();
        $user = $this->consumer();
        $response = $this->login(username: 'SISTEMA.ERP')->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.expires_in', 3600);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $token = PersonalAccessToken::firstOrFail();
        $this->assertSame(['costs:read'], $token->abilities);
        $this->assertSame(now()->addHour()->timestamp, $token->expires_at->timestamp);
        $this->assertSame($user->id, $token->tokenable_id);
        $secret = explode('|', $response->json('data.access_token'), 2)[1];
        $this->assertSame(hash('sha256', $secret), $token->token);
        $this->withHeader('Authorization', 'Bearer '.$response->json('data.access_token'))->getJson('/api/v1/costos/periodos?anio=2026')->assertOk();
    }

    public function test_credentials_must_be_in_json_body_and_passwords_are_not_trimmed(): void
    {
        $this->consumer();
        $this->postJson('/api/v1/auth/login?usuario=sistema.erp&password=fixture', [])->assertUnprocessable();
        $this->post('/api/v1/auth/login', ['usuario' => 'sistema.erp', 'password' => self::PASSWORD], ['Accept' => 'application/json'])->assertStatus(415);
        $this->login(' '.self::PASSWORD)->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unknown_disabled_and_wrong_password_have_the_same_response(): void
    {
        $user = $this->consumer();
        $expected = ['error' => ['code' => 'invalid_credentials', 'message' => 'Usuario o contraseña incorrectos.']];
        $response = $this->login('incorrecta')->assertUnauthorized()->assertExactJson($expected);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->login(username: 'desconocido')->assertUnauthorized()->assertExactJson($expected);
        $user->api_enabled = false;
        $user->save();
        $this->login()->assertUnauthorized()->assertExactJson($expected);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_existing_sync_users_are_not_enabled_for_password_login(): void
    {
        User::factory()->create(['name' => 'sistema.erp', 'email' => 'sistema.erp@example.test', 'password' => self::PASSWORD]);
        $this->login()->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_is_throttled_and_preserves_retry_after(): void
    {
        $this->consumer();
        for ($i = 0; $i < 5; $i++) {
            $this->login('incorrecta')->assertUnauthorized();
        }
        $this->login()->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_password_byte_limit_prevents_bcrypt_truncation(): void
    {
        $this->consumer();
        $this->login(str_repeat('á', 37))->assertUnprocessable();
        $this->login(str_repeat('x', 73))->assertUnprocessable();
    }

    public function test_public_http_login_is_rejected(): void
    {
        $this->consumer();
        $this->postJson('http://api.example.test/api/v1/auth/login', ['usuario' => 'sistema.erp', 'password' => self::PASSWORD])->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = $this->consumer();
        $first = $this->login()->json('data.access_token');
        $second = $this->login()->json('data.access_token');
        $sync = $user->createToken('sync-fixture', ['sync:write']);
        $this->withHeader('Authorization', 'Bearer '.$first)->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('data.revocado', true);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/costos/periodos?anio=2026')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$second)->getJson('/api/v1/costos/periodos?anio=2026')->assertOk();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $sync->accessToken->id]);
    }

    public function test_oldest_tokens_are_pruned_at_the_account_limit(): void
    {
        config(['api_access.max_tokens_per_user' => 2]);
        $user = $this->consumer();
        $first = $this->login()->json('data.access_token');
        $this->login()->assertOk();
        $last = $this->login()->assertOk()->json('data.access_token');
        $this->assertSame(2, $user->queryTokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$first)->getJson('/api/v1/costos/periodos?anio=2026')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$last)->getJson('/api/v1/costos/periodos?anio=2026')->assertOk();
    }

    public function test_expired_or_disabled_consumer_tokens_are_rejected(): void
    {
        $user = $this->consumer();
        $expired = $user->createToken('expired', ['costs:read'], now()->subMinute())->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$expired)->getJson('/api/v1/costos/periodos?anio=2026')->assertUnauthorized();
        $valid = $user->createToken('valid', ['costs:read'], now()->addHour())->plainTextToken;
        $user->api_enabled = false;
        $user->save();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$valid)->getJson('/api/v1/costos/periodos?anio=2026')->assertForbidden();
    }

    public function test_cli_creates_rotates_and_disables_a_consumer_without_touching_sync_tokens(): void
    {
        $this->artisan('api:usuario', ['usuario' => 'erp', '--nombre' => 'Sistema ERP'])
            ->expectsQuestion('Contraseña (16 a 72 bytes)', self::PASSWORD)
            ->expectsQuestion('Repita la contraseña', self::PASSWORD)->assertSuccessful();
        $user = User::where('api_username', 'erp')->firstOrFail();
        $this->assertTrue($user->api_enabled);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $old = $this->login(username: 'erp')->assertOk()->json('data.access_token');
        $sync = $user->createToken('sync-fixture', ['sync:write']);
        $replacement = 'Replacement-password-123!';
        $this->artisan('api:usuario', ['usuario' => 'erp'])
            ->expectsQuestion('Contraseña (16 a 72 bytes)', $replacement)
            ->expectsQuestion('Repita la contraseña', $replacement)->assertSuccessful();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$old)->getJson('/api/v1/costos/periodos?anio=2026')->assertUnauthorized();
        $this->login(username: 'erp')->assertUnauthorized();
        $this->login($replacement, 'erp')->assertOk();
        $this->artisan('api:usuario', ['usuario' => 'erp', '--desactivar' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->api_enabled);
        $this->assertSame(0, $user->queryTokens()->count());
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $sync->accessToken->id]);
        $this->login($replacement, 'erp')->assertUnauthorized();
    }

    public function test_cli_rejects_invalid_identifiers_and_unconfirmed_passwords(): void
    {
        $this->artisan('api:usuario', ['usuario' => 'x'])->assertFailed();
        $this->artisan('api:usuario', ['usuario' => 'erp'])
            ->expectsQuestion('Contraseña (16 a 72 bytes)', self::PASSWORD)
            ->expectsQuestion('Repita la contraseña', 'diferente')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
}
