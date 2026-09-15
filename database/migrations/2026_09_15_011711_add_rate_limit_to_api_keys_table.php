<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A per-key request ceiling for the operator API and the MCP server.
     * Null means the platform default (ApiKey::DEFAULT_RATE_LIMIT_PER_MINUTE);
     * keys minted from the "Connect an AI assistant" page get a lower one
     * so a runaway assistant cannot hammer the menu.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->unsignedSmallInteger('rate_limit_per_minute')->nullable()->after('scopes');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('rate_limit_per_minute');
        });
    }
};
