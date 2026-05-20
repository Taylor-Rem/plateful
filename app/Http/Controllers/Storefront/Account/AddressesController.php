<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Data\AddressData;
use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\Account\StoreAddressRequest;
use App\Http\Requests\Storefront\Account\UpdateAddressRequest;
use App\Models\Address;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AddressesController extends Controller
{
    public function index(Request $request, CurrentTenant $tenant): Response
    {
        $user = $request->user();

        $addresses = $user->addresses()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(fn (Address $a) => AddressData::fromModel($a))
            ->all();

        return Inertia::render('Storefront/Account/Addresses', [
            'restaurant' => RestaurantData::fromModel($tenant->get()),
            'addresses' => $addresses,
        ]);
    }

    public function store(StoreAddressRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        DB::transaction(function () use ($user, $data): void {
            $isDefault = (bool) ($data['is_default'] ?? false);
            if ($isDefault) {
                $user->addresses()->update(['is_default' => false]);
            }

            $user->addresses()->create([
                'label' => $data['label'] ?? null,
                'street' => $data['street'],
                'street2' => $data['street2'] ?? null,
                'city' => $data['city'],
                'state' => $data['state'],
                'postal_code' => $data['postal_code'],
                'country' => $data['country'] ?: 'US',
                'instructions' => $data['instructions'] ?? null,
                'is_default' => $isDefault,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Address saved.']);

        return to_route('storefront.account.addresses.index');
    }

    public function update(UpdateAddressRequest $request, Address $address): RedirectResponse
    {
        abort_if($address->user_id !== $request->user()->id, 404);

        $data = $request->validated();

        DB::transaction(function () use ($request, $address, $data): void {
            $isDefault = (bool) ($data['is_default'] ?? false);
            if ($isDefault) {
                $request->user()->addresses()
                    ->where('id', '!=', $address->id)
                    ->update(['is_default' => false]);
            }

            $address->update([
                'label' => $data['label'] ?? null,
                'street' => $data['street'],
                'street2' => $data['street2'] ?? null,
                'city' => $data['city'],
                'state' => $data['state'],
                'postal_code' => $data['postal_code'],
                'country' => $data['country'] ?: 'US',
                'instructions' => $data['instructions'] ?? null,
                'is_default' => $isDefault,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Address updated.']);

        return to_route('storefront.account.addresses.index');
    }

    public function destroy(Request $request, Address $address): RedirectResponse
    {
        abort_if($address->user_id !== $request->user()->id, 404);

        $address->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Address deleted.']);

        return to_route('storefront.account.addresses.index');
    }
}
