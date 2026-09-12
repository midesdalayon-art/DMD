<?php

namespace App\Console\Commands;

use App\Models\InventoryStockItem;
use App\Models\InventoryStockMovement;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SeedDemoStockData extends Command
{
    protected $signature = 'inventory:seed-demo-stock';

    protected $description = 'Create idempotent development demo data for quantity-based inventory stock.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This command is available only in local or testing environments.');

            return self::FAILURE;
        }

        $performer = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (! $performer) {
            $this->error('No active Admin user is available to perform demo stock movements.');

            return self::FAILURE;
        }

        $created = [];
        $skipped = [];

        DB::transaction(function () use ($performer, &$created, &$skipped): void {
            foreach ($this->demoItems() as $definition) {
                $existing = InventoryStockItem::query()
                    ->where('sku', $definition['sku'])
                    ->first();

                if ($existing) {
                    $skipped[] = sprintf(
                        '%s (%s): already exists at quantity %d with %d movement(s)',
                        $definition['name'],
                        $definition['sku'],
                        $existing->current_quantity,
                        $existing->movements()->count(),
                    );
                    continue;
                }

                $item = InventoryStockItem::create([
                    'name' => $definition['name'],
                    'sku' => $definition['sku'],
                    'category' => $definition['category'],
                    'unit' => $definition['unit'],
                    'current_quantity' => 0,
                    'reorder_level' => $definition['reorder_level'],
                    'location' => $definition['location'],
                    'is_active' => true,
                ]);

                foreach ($definition['movements'] as $movement) {
                    $this->recordMovement($item->id, $movement, $performer->id);
                }

                $created[] = $item->fresh();
            }
        });

        $this->info(sprintf('Demo stock items created: %d', count($created)));
        foreach ($created as $item) {
            $this->line(sprintf(
                '  %s (%s): %d %s, %s',
                $item->name,
                $item->sku,
                $item->current_quantity,
                $item->unit,
                $item->stockStatus(),
            ));
        }

        if ($skipped) {
            $this->warn('Existing demo items were not duplicated:');
            foreach ($skipped as $item) {
                $this->line("  {$item}");
            }
        }

        return self::SUCCESS;
    }

    private function recordMovement(int $itemId, array $movement, int $performerId): void
    {
        $item = InventoryStockItem::query()->lockForUpdate()->findOrFail($itemId);
        $previous = $item->current_quantity;
        $quantity = (int) $movement['quantity'];
        $newQuantity = $movement['type'] === InventoryStockMovement::TYPE_IN
            ? $previous + $quantity
            : $previous - $quantity;

        if ($quantity < 1 || $newQuantity < 0) {
            throw new RuntimeException("Invalid demo stock movement for item {$item->sku}.");
        }

        $item->forceFill(['current_quantity' => $newQuantity])->save();

        InventoryStockMovement::create([
            'inventory_stock_item_id' => $item->id,
            'type' => $movement['type'],
            'quantity' => $quantity,
            'previous_quantity' => $previous,
            'new_quantity' => $newQuantity,
            'reason' => $movement['reason'],
            'reference' => 'DEMO-STOCK-DATA',
            'notes' => 'Development screenshot demo data.',
            'performed_by_user_id' => $performerId,
        ]);
    }

    private function demoItems(): array
    {
        return [
            [
                'name' => 'Bath Tissue', 'sku' => 'BT-001', 'category' => 'Toiletries',
                'unit' => 'rolls', 'reorder_level' => 20, 'location' => 'Stock Room',
                'movements' => [
                    ['type' => InventoryStockMovement::TYPE_IN, 'quantity' => 100, 'reason' => 'Initial stock'],
                    ['type' => InventoryStockMovement::TYPE_OUT, 'quantity' => 20, 'reason' => 'Guest room replenishment'],
                    ['type' => InventoryStockMovement::TYPE_OUT, 'quantity' => 15, 'reason' => 'Room supplies'],
                ],
            ],
            [
                'name' => 'Bath Soap', 'sku' => 'BS-001', 'category' => 'Toiletries',
                'unit' => 'pcs', 'reorder_level' => 15, 'location' => 'Stock Room',
                'movements' => [
                    ['type' => InventoryStockMovement::TYPE_IN, 'quantity' => 80, 'reason' => 'Initial stock'],
                    ['type' => InventoryStockMovement::TYPE_OUT, 'quantity' => 25, 'reason' => 'Guest amenities replenishment'],
                ],
            ],
            [
                'name' => 'Shampoo Sachet', 'sku' => 'SH-001', 'category' => 'Toiletries',
                'unit' => 'pcs', 'reorder_level' => 20, 'location' => 'Stock Room',
                'movements' => [
                    ['type' => InventoryStockMovement::TYPE_IN, 'quantity' => 100, 'reason' => 'Initial stock'],
                    ['type' => InventoryStockMovement::TYPE_OUT, 'quantity' => 85, 'reason' => 'Guest room supplies'],
                ],
            ],
            [
                'name' => 'Bottled Water', 'sku' => 'BW-001', 'category' => 'Guest Supplies',
                'unit' => 'bottles', 'reorder_level' => 24, 'location' => 'Stock Room',
                'movements' => [
                    ['type' => InventoryStockMovement::TYPE_IN, 'quantity' => 120, 'reason' => 'Initial stock'],
                    ['type' => InventoryStockMovement::TYPE_OUT, 'quantity' => 40, 'reason' => 'Guest room replenishment'],
                    ['type' => InventoryStockMovement::TYPE_IN, 'quantity' => 48, 'reason' => 'Supplier restock'],
                ],
            ],
            [
                'name' => 'Trash Bags', 'sku' => 'TB-001', 'category' => 'Cleaning Supplies',
                'unit' => 'pcs', 'reorder_level' => 15, 'location' => 'Cleaning Storage',
                'movements' => [
                    ['type' => InventoryStockMovement::TYPE_IN, 'quantity' => 50, 'reason' => 'Initial stock'],
                    ['type' => InventoryStockMovement::TYPE_OUT, 'quantity' => 38, 'reason' => 'Cleaning operations'],
                ],
            ],
            [
                'name' => 'All-Purpose Cleaner', 'sku' => 'APC-001', 'category' => 'Cleaning Supplies',
                'unit' => 'bottles', 'reorder_level' => 10, 'location' => 'Cleaning Storage',
                'movements' => [
                    ['type' => InventoryStockMovement::TYPE_IN, 'quantity' => 30, 'reason' => 'Initial stock'],
                    ['type' => InventoryStockMovement::TYPE_OUT, 'quantity' => 8, 'reason' => 'Cleaning operations'],
                ],
            ],
        ];
    }
}
