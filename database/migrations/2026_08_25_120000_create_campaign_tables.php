<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            $table->string('subject');
            $table->string('preheader')->nullable();

            // Structured template fields (locked 2026-08-25): no free-form HTML.
            // A null cta_label/cta_url renders the default storefront CTA.
            $table->string('headline');
            $table->text('body');
            $table->string('offer_callout')->nullable();
            $table->string('cta_label')->nullable();
            $table->string('cta_url')->nullable();

            // {type: all|lapsed|regulars, days?, min_orders?} — resolved to
            // concrete recipients only at send time, never at compose time.
            $table->json('audience_filter');

            $table->string('status', 32)->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('bounced_count')->default(0);
            $table->unsignedInteger('complained_count')->default(0);
            $table->unsignedInteger('unsubscribed_count')->default(0);

            $table->timestamps();

            $table->index(['restaurant_id', 'status']);
        });

        // The audit record of exactly who was mailed: email is a snapshot taken
        // at send time, so a later address change never rewrites history.
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('status', 32)->default('queued');
            $table->string('resend_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'user_id']);
            $table->index(['campaign_id', 'status']);
            // Session 3's webhooks match delivery/bounce events back to rows.
            $table->index('resend_message_id');
        });

        // Platform-wide (not per-restaurant): a hard bounce or complaint at one
        // restaurant stops every restaurant from mailing that address.
        Schema::create('suppressed_emails', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('reason', 32);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressed_emails');
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
    }
};
