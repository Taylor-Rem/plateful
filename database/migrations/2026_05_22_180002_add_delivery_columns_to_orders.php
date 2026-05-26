<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('tip_recipient')->default('pool')->after('tip_cents');
            $table->foreignId('delivery_assignment_id')
                ->nullable()
                ->after('confirmation_token')
                ->constrained('delivery_assignments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delivery_assignment_id');
            $table->dropColumn('tip_recipient');
        });
    }
};
