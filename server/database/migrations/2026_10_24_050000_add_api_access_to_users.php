<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('api_username', 64)->nullable()->unique();
            $table->boolean('api_enabled')->default(false);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Reversión manual: conservar las cuentas y revocar su acceso explícitamente.');
    }
};
