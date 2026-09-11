<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Data\AddressData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\Account\StoreAddressRequest;
use App\Http\Requests\Storefront\Account\UpdateAddressRequest;
use App\Models\Address;
use App\Services\AddressBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AddressesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->addresses()
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get()
                ->map(fn (Address $a) => AddressData::fromModel($a))
                ->all(),
        ]);
    }

    public function store(StoreAddressRequest $request, AddressBook $addresses): JsonResponse
    {
        $address = $addresses->store($request->user(), $request->validated());

        return response()->json(['data' => AddressData::fromModel($address)], 201);
    }

    public function update(UpdateAddressRequest $request, Address $address, AddressBook $addresses): JsonResponse
    {
        abort_if($address->user_id !== $request->user()->id, 404);

        return response()->json(['data' => AddressData::fromModel($addresses->update($address, $request->validated()))]);
    }

    public function destroy(Request $request, Address $address): Response
    {
        abort_if($address->user_id !== $request->user()->id, 404);

        $address->delete();

        return response()->noContent();
    }
}
