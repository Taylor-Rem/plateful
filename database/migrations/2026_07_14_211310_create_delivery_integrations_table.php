<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_integrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('provider');

            // Uber Direct is per-restaurant: the restaurant holds its own Uber
            // account and pastes these in, because client_credentials has no
            // redirect flow to click through. No refresh_token column — a
            // client_credentials grant has none; you re-run the grant instead.
            $table->text('client_id')->nullable();
            $table->text('client_secret')->nullable();

            // Not encrypted: an account identifier that sits in the URL path of
            // every request, not a secret. Same treatment as
            // pos_integrations.external_merchant_id.
            $table->string('customer_id')->nullable();

            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status')->default('disconnected');
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['restaurant_id', 'provider']);
            $table->index(['restaurant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_integrations');
    }
};
