<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryStockItem;
use App\Models\InventoryStockMovement;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryStockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'status' => ['sometimes', Rule::in(['in_stock', 'low_stock', 'out_of_stock'])],
            'active' => ['sometimes', 'nullable', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $query = InventoryStockItem::query()->latest('updated_at');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function ($query) use ($search): void {
                $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%')
                    ->orWhere('category', 'like', '%'.$search.'%')
                    ->orWhere('location', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (array_key_exists('active', $filters) && $filters['active'] !== null) {
            $query->where('is_active', $filters['active']);
        }

        if (! empty($filters['status'])) {
            $status = $filters['status'];
            $query->when($status === 'out_of_stock', fn ($query) => $query->where('current_quantity', 0));
            $query->when($status === 'low_stock', fn ($query) => $query->where('current_quantity', '>', 0)->whereColumn('current_quantity', '<=', 'reorder_level'));
            $query->when($status === 'in_stock', fn ($query) => $query->whereColumn('current_quantity', '>', 'reorder_level'));
        }

        $paginator = $query->paginate((int) ($filters['per_page'] ?? 10));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (InventoryStockItem $item) => $item->publicData())->values(),
            'pagination' => $this->pagination($paginator),
            'summary' => $this->summary(),
            'meta' => [
                'categories' => InventoryStockItem::query()->select('category')->distinct()->orderBy('category')->pluck('category')->values(),
                'statuses' => ['in_stock', 'low_stock', 'out_of_stock'],
            ],
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $attributes = $this->validatedItemAttributes($request);

        $item = DB::transaction(function () use ($attributes, $request, $auditLogger): InventoryStockItem {
            $item = InventoryStockItem::create($attributes);
            $auditLogger->log($request, 'inventory', 'stock_item_created', 'Inventory stock item created.', $item, [
                'sku' => $item->sku,
                'category' => $item->category,
                'initial_quantity' => $item->current_quantity,
            ]);

            return $item;
        });

        return response()->json([
            'data' => $item->publicData(),
            'message' => 'Stock item created.',
        ], 201);
    }

    public function show(InventoryStockItem $inventoryStockItem): JsonResponse
    {
        return response()->json([
            'data' => $inventoryStockItem->publicData(),
            'movements' => $inventoryStockItem->movements()
                ->with('performer')
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (InventoryStockMovement $movement) => $movement->publicData())
                ->values(),
        ]);
    }

    public function update(Request $request, InventoryStockItem $inventoryStockItem, AuditLogger $auditLogger): JsonResponse
    {
        $attributes = $this->validatedItemAttributes($request, $inventoryStockItem);
        $old = $inventoryStockItem->publicData();
        $inventoryStockItem->update($attributes);

        $auditLogger->log($request, 'inventory', 'stock_item_updated', 'Inventory stock item updated.', $inventoryStockItem, [
            'before' => $old,
            'after' => $inventoryStockItem->fresh()->publicData(),
        ]);

        return response()->json([
            'data' => $inventoryStockItem->fresh()->publicData(),
            'message' => 'Stock item updated.',
        ]);
    }

    public function stockIn(Request $request, InventoryStockItem $inventoryStockItem, AuditLogger $auditLogger): JsonResponse
    {
        return $this->moveStock($request, $inventoryStockItem, InventoryStockMovement::TYPE_IN, $auditLogger);
    }

    public function stockOut(Request $request, InventoryStockItem $inventoryStockItem, AuditLogger $auditLogger): JsonResponse
    {
        return $this->moveStock($request, $inventoryStockItem, InventoryStockMovement::TYPE_OUT, $auditLogger);
    }

    public function movements(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'item_id' => ['sometimes', 'nullable', 'integer', 'exists:inventory_stock_items,id'],
            'type' => ['sometimes', 'nullable', Rule::in(InventoryStockMovement::types())],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'date' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $query = InventoryStockMovement::query()->with(['item', 'performer'])->latest();

        if (! empty($filters['item_id'])) {
            $query->where('inventory_stock_item_id', $filters['item_id']);
        }

        foreach (['type', 'user_id'] as $filter) {
            if (! empty($filters[$filter])) {
                $query->where($filter === 'user_id' ? 'performed_by_user_id' : $filter, $filters[$filter]);
            }
        }

        if (! empty($filters['date'])) {
            $query->whereDate('created_at', $filters['date']);
        }

        $paginator = $query->paginate((int) ($filters['per_page'] ?? 15));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (InventoryStockMovement $movement) => $movement->publicData())->values(),
            'pagination' => $this->pagination($paginator),
            'meta' => [
                'types' => InventoryStockMovement::types(),
                'users' => User::query()
                    ->where('role', User::ROLE_ADMIN)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->values(),
            ],
        ]);
    }

    private function moveStock(Request $request, InventoryStockItem $inventoryStockItem, string $type, AuditLogger $auditLogger): JsonResponse
    {
        $attributes = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'max:160'],
            'reference' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = DB::transaction(function () use ($request, $inventoryStockItem, $type, $attributes, $auditLogger): array {
            $item = InventoryStockItem::query()->lockForUpdate()->findOrFail($inventoryStockItem->id);
            $previous = $item->current_quantity;
            $quantity = (int) $attributes['quantity'];
            $newQuantity = $type === InventoryStockMovement::TYPE_IN
                ? $previous + $quantity
                : $previous - $quantity;

            if ($newQuantity < 0) {
                throw ValidationException::withMessages([
                    'quantity' => ['Stock-out quantity cannot exceed the available stock.'],
                ]);
            }

            $item->forceFill(['current_quantity' => $newQuantity])->save();
            $movement = InventoryStockMovement::create([
                'inventory_stock_item_id' => $item->id,
                'type' => $type,
                'quantity' => $quantity,
                'previous_quantity' => $previous,
                'new_quantity' => $newQuantity,
                'reason' => $attributes['reason'],
                'reference' => $attributes['reference'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'performed_by_user_id' => $request->user()?->id,
            ]);

            $auditLogger->log($request, 'inventory', $type, 'Inventory stock movement recorded.', $item, [
                'movement_id' => $movement->id,
                'quantity' => $quantity,
                'previous_quantity' => $previous,
                'new_quantity' => $newQuantity,
                'reason' => $attributes['reason'],
            ]);

            return ['item' => $item->fresh(), 'movement' => $movement];
        });

        return response()->json([
            'data' => $result['item']->publicData(),
            'movement' => $result['movement']->load(['item', 'performer'])->publicData(),
            'message' => $type === InventoryStockMovement::TYPE_IN ? 'Stock added.' : 'Stock removed.',
        ]);
    }

    private function validatedItemAttributes(Request $request, ?InventoryStockItem $item = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'sku' => ['required', 'string', 'max:80', Rule::unique('inventory_stock_items', 'sku')->ignore($item?->id)],
            'category' => ['required', 'string', 'max:80'],
            'unit' => ['required', 'string', 'max:40'],
            'reorder_level' => ['required', 'integer', 'min:0', 'max:1000000'],
            'location' => ['nullable', 'string', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function summary(): array
    {
        return [
            'total_stock_items' => InventoryStockItem::where('is_active', true)->count(),
            'low_stock' => InventoryStockItem::where('is_active', true)->where('current_quantity', '>', 0)->whereColumn('current_quantity', '<=', 'reorder_level')->count(),
            'out_of_stock' => InventoryStockItem::where('is_active', true)->where('current_quantity', 0)->count(),
            'movements_today' => InventoryStockMovement::whereDate('created_at', today())->count(),
        ];
    }

    private function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
