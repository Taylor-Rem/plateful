<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_quotes', function (Blueprint $table): void {
            $table->id();

            // Referenced from the browser between the address lookup and the
            // checkout POST, so the id must not be guessable — an incrementing
            // one would let anyone quote-shop another restaurant's fees.
            $table->uuid('token')->unique();

            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_quote_id')->nullable();

            // The address this quote was issued for, and the provider-encoded
            // payload that went over the wire. Uber rejects a create whose
            // address differs from its quote's, so the payload is replayed
            // verbatim rather than re-derived.
            $table->json('dropoff_address');
            $table->text('dropoff_address_payload')->nullable();
            $table->text('pickup_address_payload')->nullable();

            // The fee is money, so it is read from here at checkout and never
            // taken from the client.
            $table->integer('fee_cents');
            $table->integer('eta_minutes')->nullable();
            $table->integer('pickup_duration_minutes')->nullable();
            $table->timestamp('dropoff_eta_at')->nullable();
            $table->timestamp('dropoff_deadline_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            // Which quote priced this order. Lets dispatch replay the exact
            // quote the customer was charged from instead of re-deriving one,
            // and keeps the fee traceable to its origin afterwards.
            $table->uuid('delivery_quote_token')->nullable()->after('delivery_fee_cents');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('delivery_quote_token');
        });

        Schema::dropIfExists('delivery_quotes');
    }
};
