<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Resources\Sales\CustomerResource;
use App\Models\Customer;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customers exist here because a GST invoice cannot be raised without one: the
 * place of supply decides CGST+SGST vs IGST, and that comes from the
 * customer's state code.
 */
class CustomerController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $customers = Customer::query()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('gstin', 'like', $term);
                });
            })
            ->when(
                $request->filled('is_active'),
                fn ($query) => $query->where('is_active', $request->boolean('is_active'))
            )
            ->orderBy('name')
            ->paginate(perPage: min($request->integer('per_page', 25), self::MAX_PER_PAGE))
            ->withQueryString();

        return ApiResponse::success(CustomerResource::collection($customers));
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        return ApiResponse::success(new CustomerResource($customer));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $customer = Customer::create($this->validated($request));

        return ApiResponse::success(new CustomerResource($customer), status: 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $customer->update($this->validated($request));

        return ApiResponse::success(new CustomerResource($customer->fresh()));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        // Archive, never hard delete: the customer appears on historical
        // invoices, and invoices.customer_id is RESTRICT on delete.
        $customer->delete();

        return ApiResponse::success(['message' => 'Customer archived.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            /*
             * The GST state code: exactly two digits. Without it a GST invoice
             * for this customer cannot resolve intra- vs inter-state, so the
             * format is enforced rather than accepted loosely.
             */
            'state_code' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{2}$/'],
            'pincode' => ['sometimes', 'nullable', 'string', 'max:10'],
            'gstin' => ['sometimes', 'nullable', 'string', 'size:15', Rule::unique('customers', 'gstin')->ignore($request->route('customer'))->whereNull('deleted_at')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'state_code.regex' => 'The state code must be the two-digit GST code, e.g. 32 for Kerala.',
            'gstin.size' => 'A GSTIN is exactly 15 characters.',
        ]);
    }
}
