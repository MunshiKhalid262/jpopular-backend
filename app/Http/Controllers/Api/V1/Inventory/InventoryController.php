<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Domain\Inventory\Actions\RecordStockMovement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreStockMovementRequest;
use App\Http\Resources\Inventory\StockMovementResource;
use App\Http\Resources\Inventory\StockSummaryResource;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\ApiResponse;
use App\Support\StockStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Inventory HTTP layer.
 *
 * This controller never writes stock itself. Every mutation goes through
 * RecordStockMovement -> StockLedger, which is the only code permitted to
 * touch `stock_movements` or `products.current_stock`, and which holds the
 * product row lock while it does.
 */
class InventoryController extends Controller
{
    /** Shared cap, matching the catalog list endpoints. */
    private const MAX_PER_PAGE = 100;

    /**
     * GET /inventory/stock — current stock per product.
     */
    public function stock(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockMovement::class);

        $products = Product::query()
            ->with(['category', 'brand'])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('sku', 'like', $term)
                        ->orWhere('model', 'like', $term);
                });
            })
            ->when(
                $request->filled('category_id'),
                fn ($query) => $query->where('category_id', $request->integer('category_id'))
            )
            ->when(
                $request->filled('brand_id'),
                fn ($query) => $query->where('brand_id', $request->integer('brand_id'))
            )
            ->when(
                $request->filled('is_active'),
                fn ($query) => $query->where('is_active', $request->boolean('is_active'))
            )
            /*
             * `low_stock=1` is a shorthand the UI's toggle uses; `stock_status`
             * is the general filter. Both run through StockStatus so the rows
             * returned are exactly the rows that serialise with that status.
             */
            ->when(
                $request->boolean('low_stock'),
                fn ($query) => StockStatus::scope($query, StockStatus::LOW_STOCK)
            )
            ->when(
                $request->filled('stock_status')
                    && in_array($request->string('stock_status')->toString(), StockStatus::values(), true),
                fn ($query) => StockStatus::scope($query, $request->string('stock_status')->toString())
            )
            // Most urgent first: the operator opens this page to find what
            // needs reordering, not to browse alphabetically.
            ->orderBy('current_stock')
            ->orderBy('name')
            ->paginate(perPage: min($request->integer('per_page', 25), self::MAX_PER_PAGE))
            ->withQueryString();

        return ApiResponse::success(StockSummaryResource::collection($products));
    }

    /**
     * GET /inventory/movements — the ledger, newest first.
     */
    public function movements(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockMovement::class);

        $movements = StockMovement::query()
            ->with(['product:id,name,sku,unit', 'createdBy:id,name'])
            ->when(
                $request->filled('product_id'),
                fn ($query) => $query->where('product_id', $request->integer('product_id'))
            )
            ->when(
                $request->filled('type'),
                fn ($query) => $query->where('type', $request->string('type')->toString())
            )
            ->when(
                $request->filled('date_from'),
                fn ($query) => $query->where('occurred_at', '>=', $request->date('date_from')->startOfDay())
            )
            ->when(
                $request->filled('date_to'),
                fn ($query) => $query->where('occurred_at', '<=', $request->date('date_to')->endOfDay())
            )
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';

                $query->whereHas('product', function ($inner) use ($term): void {
                    $inner->where('name', 'like', $term)->orWhere('sku', 'like', $term);
                });
            })
            /*
             * id breaks ties on occurred_at: several movements can share a
             * business date, and without it the page boundary is
             * non-deterministic and rows can repeat or vanish while paging.
             */
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(perPage: min($request->integer('per_page', 25), self::MAX_PER_PAGE))
            ->withQueryString();

        return ApiResponse::success(StockMovementResource::collection($movements));
    }

    /**
     * POST /products/{product}/movements — record one manual movement.
     *
     * Authorization is handled by StoreStockMovementRequest::authorize()
     * (inventory.adjust) plus the `can:` gate on the route.
     */
    public function storeMovement(
        StoreStockMovementRequest $request,
        Product $product,
        RecordStockMovement $action,
    ): JsonResponse {
        $movement = $action->handle(
            product: $product,
            attributes: [
                'type' => $request->movementType(),
                'quantity' => (string) $request->validated('quantity'),
                'note' => $request->validated('note'),
                'unit_cost' => $request->validated('unit_cost'),
                'occurred_at' => $request->validated('occurred_at'),
            ],
            actor: $request->user(),
        );

        // The ledger updated the cached balance inside its transaction; re-read
        // so the client gets the committed value rather than a stale model.
        $product->refresh()->load(['category', 'brand']);

        return ApiResponse::success([
            'movement' => (new StockMovementResource($movement->load(['product:id,name,sku,unit', 'createdBy:id,name'])))->resolve(),
            'product' => (new StockSummaryResource($product))->resolve(),
        ], status: 201);
    }
}
