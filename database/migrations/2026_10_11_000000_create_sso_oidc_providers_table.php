<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_oidc_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 27)->unique();
            $table->string('label', 64);
            $table->string('issuer', 255);
            $table->string('client_id', 255);
            $table->text('client_secret');
            $table->string('scopes', 255)->default('openid email profile');
            $table->string('groups_claim', 64)->default('groups');
            $table->json('admin_groups');
            $table->json('member_groups');
            $table->boolean('create_users')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_oidc_providers');
    }
};
