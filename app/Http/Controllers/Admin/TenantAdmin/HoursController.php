<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateHoursRequest;
use App\Models\Restaurant;
use App\Models\RestaurantHour;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class HoursController extends Controller
{
    public function edit(Restaurant $restaurant): Response
    {
        return Inertia::render('Admin/TenantAdmin/Hours', [
            'restaurant' => RestaurantData::fromModel($restaurant->load('hours')),
        ]);
    }

    public function update(UpdateHoursRequest $request, Restaurant $restaurant): RedirectResponse
    {
        $windows = (array) $request->input('windows', []);

        DB::transaction(function () use ($restaurant, $windows): void {
            RestaurantHour::where('restaurant_id', $restaurant->id)->delete();

            $rows = [];
            $now = now();
            foreach ($windows as $day => $list) {
                if (! is_array($list)) {
                    continue;
                }
                $day = (int) $day;
                if ($day < 0 || $day > 6) {
                    continue;
                }

                $position = 0;
                foreach ($list as $w) {
                    $opens = $w['opens_at'] ?? null;
                    $closes = $w['closes_at'] ?? null;
                    if (! is_string($opens) || ! is_string($closes)) {
                        continue;
                    }

                    $rows[] = [
                        'restaurant_id' => $restaurant->id,
                        'day_of_week' => $day,
                        'opens_at' => $opens.':00',
                        'closes_at' => $closes.':00',
                        'position' => $position++,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($rows !== []) {
                RestaurantHour::insert($rows);
            }
        });

        return back()->with('success', 'Hours updated.');
    }
}
