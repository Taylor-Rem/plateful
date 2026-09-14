<?php

namespace App\Http\Controllers\Api\V1\Operator;

use App\Data\CustomerData;
use App\Data\PaginationMetaData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operator\CustomerListRequest;
use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use App\Support\Customers\CustomersQuery;
use Illuminate\Http\JsonResponse;

class CustomersController extends Controller
{
    public function __construct(protected CustomersQuery $customers) {}

    public function index(CustomerListRequest $request, Restaurant $restaurant): JsonResponse
    {
        [$sort, $dir] = $this->customers->sort($request->input('sort'), $request->input('dir'));

        $paginator = $this->customers->build($restaurant, $request->filters())
            ->orderBy(CustomersQuery::SORTABLE[$sort], $dir)
            ->orderBy('restaurant_customer.id', 'desc')
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (RestaurantCustomer $pivot) => CustomerData::fromModel($pivot))
                ->all(),
            'meta' => PaginationMetaData::fromPaginator($paginator),
        ]);
    }
}
