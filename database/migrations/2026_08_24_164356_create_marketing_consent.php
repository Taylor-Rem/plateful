<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_customer', function (Blueprint $table) {
            // Per-restaurant, per-channel consent (campaigns plan). Eligible =
            // opted_in_at set AND opted_out_at null AND user not soft-deleted.
            $table->timestamp('marketing_email_opted_in_at')->nullable()->after('total_spent_cents');
            $table->timestamp('marketing_email_opted_out_at')->nullable()->after('marketing_email_opted_in_at');

            $table->index(['restaurant_id', 'marketing_email_opted_in_at']);
        });

        // Append-only proof of consent — nice for CAN-SPAM, mandatory for TCPA.
        // consent_text_snapshot stores the exact checkbox label the customer saw.
        Schema::create('marketing_consent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('action', 16);
            $table->string('source', 32);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->text('consent_text_snapshot');
            $table->timestamp('created_at');

            $table->index(['user_id', 'restaurant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_consent_events');

        Schema::table('restaurant_customer', function (Blueprint $table) {
            $table->dropIndex(['restaurant_id', 'marketing_email_opted_in_at']);
            $table->dropColumn(['marketing_email_opted_in_at', 'marketing_email_opted_out_at']);
        });
    }
};
