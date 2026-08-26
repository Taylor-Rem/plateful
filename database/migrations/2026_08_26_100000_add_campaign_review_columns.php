<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Automated (Claude) content review of a held campaign: verdict is
            // approved|flagged, notes carry the reviewer's reasoning for the
            // super-admin console. Null = never reviewed by the model.
            $table->string('review_verdict', 16)->nullable()->after('status');
            $table->text('review_notes')->nullable()->after('review_verdict');
            $table->timestamp('reviewed_at')->nullable()->after('review_notes');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['review_verdict', 'review_notes', 'reviewed_at']);
        });
    }
};
