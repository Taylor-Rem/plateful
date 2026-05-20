<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0=Sun..6=Sat (Carbon dayOfWeek)
            $table->time('opens_at');
            $table->time('closes_at');
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index(['restaurant_id', 'day_of_week', 'position']);
        });

        // Backfill: every existing restaurant gets 9am-9pm every day.
        $restaurantIds = DB::table('restaurants')->pluck('id');
        if ($restaurantIds->isEmpty()) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($restaurantIds as $rid) {
            for ($day = 0; $day < 7; $day++) {
                $rows[] = [
                    'restaurant_id' => $rid,
                    'day_of_week' => $day,
                    'opens_at' => '09:00:00',
                    'closes_at' => '21:00:00',
                    'position' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('restaurant_hours')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_hours');
    }
};
