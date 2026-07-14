<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `split` is being dropped from DeliveryFeeStrategy: it implied a
        // splitting rule nobody ever defined, and no reader ever existed. Fold
        // any row that somehow holds it back to the default rather than leave a
        // value the enum can no longer cast.
        DB::table('restaurants')
            ->where('delivery_fee_strategy', 'split')
            ->update(['delivery_fee_strategy' => 'pass_through']);

        Schema::table('restaurants', function (Blueprint $table): void {
            // Never read by anything, and its job — capping what a restaurant
            // absorbs — is a decision deferred until the measured drift says it
            // is needed. Re-add it then, with a rule behind it.
            $table->dropColumn('customer_delivery_fee_cents_max');

            // Uber's dropoff_eta assumes the food is ready NOW. Without prep
            // time the customer promise is wrong by the length of the ticket and
            // the courier idles in the lobby. Feeds both Uber's pickup_ready_dt
            // and the ETA the customer is shown.
            $table->unsignedSmallInteger('prep_time_minutes')->default(5)->after('delivery_fee_strategy');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->integer('customer_delivery_fee_cents_max')->nullable();
            $table->dropColumn('prep_time_minutes');
        });
    }
};
