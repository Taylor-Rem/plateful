<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Data\AddressData;
use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\Account\StoreAddressRequest;
use App\Http\Requests\Storefront\Account\UpdateAddressRequest;
use App\Models\Address;
use App\Services\AddressBook;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function store(StoreAddressRequest $request, AddressBook $addresses): RedirectResponse
    {
        $addresses->store($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Address saved.']);

        return to_route('storefront.account.addresses.index');
    }

    public function update(UpdateAddressRequest $request, Address $address, AddressBook $addresses): RedirectResponse
    {
        abort_if($address->user_id !== $request->user()->id, 404);

        $addresses->update($address, $request->validated());

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
