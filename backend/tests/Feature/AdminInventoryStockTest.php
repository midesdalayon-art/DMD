<?php

namespace Tests\Feature;

use App\Models\InventoryStockItem;
use App\Models\InventoryStockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminInventoryStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_stock_item_and_record_stock_in_and_out(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/admin/inventory-stock-items', [
                'name' => 'Tissue',
                'sku' => 'CON-TISSUE',
                'category' => 'Toiletries',
                'unit' => 'packs',
                'reorder_level' => 5,
                'location' => 'Main storage',
            ])
            ->assertCreated()
            ->assertJsonPath('data.current_quantity', 0);

        $item = InventoryStockItem::firstOrFail();

        $this->actingAs($admin)
            ->postJson("/api/admin/inventory-stock-items/{$item->id}/stock-in", [
                'quantity' => 20,
                'reason' => 'Initial delivery',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_quantity', 20)
            ->assertJsonPath('movement.previous_quantity', 0)
            ->assertJsonPath('movement.new_quantity', 20);

        $this->actingAs($admin)
            ->postJson("/api/admin/inventory-stock-items/{$item->id}/stock-out", [
                'quantity' => 4,
                'reason' => 'Housekeeping usage',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_quantity', 16);

        $this->assertDatabaseCount('inventory_stock_movements', 2);
        $this->assertDatabaseHas('inventory_stock_movements', [
            'inventory_stock_item_id' => $item->id,
            'type' => InventoryStockMovement::TYPE_OUT,
            'quantity' => 4,
            'previous_quantity' => 20,
            'new_quantity' => 16,
            'performed_by_user_id' => $admin->id,
        ]);
    }

    public function test_stock_out_cannot_make_quantity_negative(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $item = InventoryStockItem::create([
            'name' => 'Soap',
            'sku' => 'CON-SOAP',
            'category' => 'Toiletries',
            'unit' => 'bottles',
            'current_quantity' => 3,
            'reorder_level' => 2,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/inventory-stock-items/{$item->id}/stock-out", [
                'quantity' => 4,
                'reason' => 'Too much',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->assertSame(3, $item->fresh()->current_quantity);
        $this->assertDatabaseCount('inventory_stock_movements', 0);
    }

    public function test_stock_item_listing_supports_filters_pagination_and_status(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        InventoryStockItem::create([
            'name' => 'Low soap',
            'sku' => 'LOW-SOAP',
            'category' => 'Toiletries',
            'unit' => 'bottles',
            'current_quantity' => 2,
            'reorder_level' => 5,
        ]);
        InventoryStockItem::create([
            'name' => 'Available tissue',
            'sku' => 'GOOD-TISSUE',
            'category' => 'Toiletries',
            'unit' => 'packs',
            'current_quantity' => 30,
            'reorder_level' => 5,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/inventory-stock-items?status=low_stock&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.status', 'low_stock')
            ->assertJsonPath('summary.low_stock', 1);
    }

    public function test_non_admin_cannot_manage_stock_items(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($manager)
            ->getJson('/api/admin/inventory-stock-items')
            ->assertForbidden();

        $this->actingAs($manager)
            ->postJson('/api/admin/inventory-stock-items', [
                'name' => 'Water',
                'sku' => 'WATER',
                'category' => 'Consumables',
                'unit' => 'bottles',
                'reorder_level' => 2,
            ])
            ->assertForbidden();
    }

    public function test_stock_movements_are_immutable_from_the_api_surface(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $item = InventoryStockItem::create([
            'name' => 'Trash bags',
            'sku' => 'CLEAN-BAGS',
            'category' => 'Cleaning',
            'unit' => 'rolls',
            'current_quantity' => 10,
            'reorder_level' => 3,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/inventory-stock-items/{$item->id}/stock-out", [
                'quantity' => 2,
                'reason' => 'Cleaning use',
            ])
            ->assertOk();

        $movement = InventoryStockMovement::firstOrFail();

        $this->actingAs($admin)
            ->putJson("/api/admin/inventory-stock-movements/{$movement->id}", ['quantity' => 99])
            ->assertNotFound();

        $this->assertSame(2, $movement->fresh()->quantity);
    }
}
