<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\InventoryAsset;
use App\Models\User;
use App\Services\InventoryAssetTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminInventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_inventory_assets_with_summary(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $bed = $this->createAsset(['name' => 'Queen Bed', 'category' => 'Furniture']);
        $this->createAsset([
            'name' => 'Damaged Chair',
            'category' => 'Furniture',
            'condition' => InventoryAsset::CONDITION_DAMAGED,
            'status' => InventoryAsset::STATUS_MAINTENANCE,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/inventory-assets?search=bed&category=furniture&status=available&condition=good&location_type=storage')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.asset_code', $bed->asset_code)
            ->assertJsonPath('summary.total_assets', 2)
            ->assertJsonPath('summary.under_maintenance', 1)
            ->assertJsonPath('summary.damaged', 1);
    }

    public function test_non_admin_cannot_access_inventory(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);

        $this->actingAs($guest)
            ->getJson('/api/admin/inventory-assets')
            ->assertForbidden();
    }

    public function test_admin_can_create_asset_and_history_is_recorded(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/admin/inventory-assets', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.asset_code', 'FUR-0001');

        $asset = InventoryAsset::where('asset_code', 'FUR-0001')->firstOrFail();
        $this->assertDatabaseHas('inventory_asset_histories', [
            'inventory_asset_id' => $asset->id,
            'performed_by' => $admin->id,
            'action' => 'created',
        ]);
    }

    public function test_admin_can_update_asset_and_condition(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $asset = $this->createAsset();

        $this->actingAs($admin)
            ->putJson("/api/admin/inventory-assets/{$asset->id}", array_merge($this->validPayload(), [
                'name' => 'Updated Bed',
            ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Bed');

        $this->actingAs($admin)
            ->patchJson("/api/admin/inventory-assets/{$asset->id}/condition", [
                'condition' => InventoryAsset::CONDITION_DAMAGED,
                'remarks' => 'Frame is cracked.',
            ])
            ->assertOk()
            ->assertJsonPath('data.condition', InventoryAsset::CONDITION_DAMAGED);

        $this->assertDatabaseHas('inventory_asset_histories', [
            'inventory_asset_id' => $asset->id,
            'action' => 'condition_changed',
        ]);
    }

    public function test_admin_can_assign_asset_to_existing_accommodation(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $asset = $this->createAsset();
        $accommodation = $this->createAccommodation();
        $sourceLocation = $asset->locations()->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/admin/inventory-assets/{$asset->id}/assign", [
                'source_location_id' => $sourceLocation->id,
                'transfer_quantity' => 1,
                'destination_key' => 'accommodation:'.$accommodation->id,
                'remarks' => 'Assigned to room.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryAsset::STATUS_ASSIGNED)
            ->assertJsonPath('data.accommodation_id', $accommodation->id)
            ->assertJsonPath('data.location_label', $accommodation->name);
    }

    public function test_admin_can_partially_transfer_asset_quantity_and_preserve_total(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $asset = $this->createAsset([
            'quantity' => 70,
            'location_name' => 'Main Storage',
        ]);
        $sourceLocation = $asset->locations()->firstOrFail();
        $accommodation = $this->createAccommodation(['name' => 'Cottage 1', 'slug' => 'cottage-1']);

        $this->actingAs($admin)
            ->patchJson("/api/admin/inventory-assets/{$asset->id}/assign", [
                'source_location_id' => $sourceLocation->id,
                'transfer_quantity' => 5,
                'destination_key' => 'accommodation:'.$accommodation->id,
                'remarks' => 'Move 5 chairs to cottage 1.',
            ])
            ->assertOk()
            ->assertJsonPath('data.quantity', 70)
            ->assertJsonPath('data.status', InventoryAsset::STATUS_ASSIGNED);

        $asset = $asset->fresh(['locations.accommodation']);

        $this->assertCount(2, $asset->locations);
        $this->assertSame(65, $asset->locations->firstWhere('location_type', InventoryAsset::LOCATION_STORAGE)->quantity);
        $this->assertSame(5, $asset->locations->firstWhere('location_type', InventoryAsset::LOCATION_ACCOMMODATION)->quantity);
        $this->assertSame(70, $asset->quantity);
        $this->assertDatabaseHas('inventory_asset_histories', [
            'inventory_asset_id' => $asset->id,
            'action' => 'transferred',
        ]);
    }

    public function test_transfer_rejects_insufficient_quantity(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $asset = $this->createAsset(['quantity' => 3]);
        $sourceLocation = $asset->locations()->firstOrFail();
        $accommodation = $this->createAccommodation(['name' => 'Room 1', 'slug' => 'room-1']);

        $this->actingAs($admin)
            ->patchJson("/api/admin/inventory-assets/{$asset->id}/assign", [
                'source_location_id' => $sourceLocation->id,
                'transfer_quantity' => 5,
                'destination_key' => 'accommodation:'.$accommodation->id,
                'remarks' => 'Too many chairs.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transfer_quantity');
    }

    public function test_transfer_requires_positive_quantity(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $asset = $this->createAsset();
        $sourceLocation = $asset->locations()->firstOrFail();
        $accommodation = $this->createAccommodation();

        $this->actingAs($admin)
            ->patchJson("/api/admin/inventory-assets/{$asset->id}/assign", [
                'source_location_id' => $sourceLocation->id,
                'transfer_quantity' => 0,
                'destination_key' => 'accommodation:'.$accommodation->id,
                'remarks' => 'Invalid quantity.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transfer_quantity');
    }

    public function test_same_source_and_destination_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $asset = $this->createAsset();
        $sourceLocation = $asset->locations()->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/admin/inventory-assets/{$asset->id}/assign", [
                'source_location_id' => $sourceLocation->id,
                'transfer_quantity' => 1,
                'destination_key' => 'storage:main-storage',
                'remarks' => 'No-op transfer.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location_type');
    }

    public function test_arbitrary_free_text_destination_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $asset = $this->createAsset();
        $sourceLocation = $asset->locations()->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/admin/inventory-assets/{$asset->id}/assign", [
                'source_location_id' => $sourceLocation->id,
                'transfer_quantity' => 1,
                'destination_key' => 'poop',
                'remarks' => 'Invalid destination.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destination_key');
    }

    public function test_admin_can_transfer_quantity_to_multiple_destinations_and_keep_total(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $asset = $this->createAsset([
            'quantity' => 70,
            'location_name' => 'Main Storage',
        ]);
        $sourceLocation = $asset->locations()->firstOrFail();
        $cottage = $this->createAccommodation(['name' => 'Cottage 1', 'slug' => 'cottage-1']);
        $room = $this->createAccommodation(['name' => 'Room 1', 'slug' => 'room-1']);

        $this->actingAs($admin)
            ->patchJson("/api/admin/inventory-assets/{$asset->id}/assign", [
                'source_location_id' => $sourceLocation->id,
                'transfer_quantity' => 5,
                'destination_key' => 'accommodation:'.$cottage->id,
                'remarks' => 'First transfer.',
            ])
            ->assertOk();

        $asset = $asset->fresh(['locations.accommodation']);
        $sourceLocation = $asset->locations->firstWhere('location_type', InventoryAsset::LOCATION_STORAGE);

        $this->actingAs($admin)
            ->patchJson("/api/admin/inventory-assets/{$asset->id}/assign", [
                'source_location_id' => $sourceLocation->id,
                'transfer_quantity' => 10,
                'destination_key' => 'accommodation:'.$room->id,
                'remarks' => 'Second transfer.',
            ])
            ->assertOk()
            ->assertJsonPath('data.quantity', 70);

        $asset = $asset->fresh(['locations.accommodation']);
        $this->assertSame(55, $asset->locations->firstWhere('location_type', InventoryAsset::LOCATION_STORAGE)->quantity);
        $this->assertSame(5, $asset->locations->firstWhere('accommodation_id', $cottage->id)->quantity);
        $this->assertSame(10, $asset->locations->firstWhere('accommodation_id', $room->id)->quantity);
        $this->assertSame(70, $asset->quantity);
    }

    public function test_admin_can_transfer_quantity_back_to_storage(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $asset = $this->createAsset([
            'quantity' => 20,
            'location_name' => 'Main Storage',
        ]);
        $sourceLocation = $asset->locations()->firstOrFail();
        $cottage = $this->createAccommodation(['name' => 'Cottage 1', 'slug' => 'cottage-1']);

        $this->actingAs($admin)
            ->patchJson("/api/admin/inventory-assets/{$asset->id}/assign", [
                'source_location_id' => $sourceLocation->id,
                'transfer_quantity' => 5,
                'destination_key' => 'accommodation:'.$cottage->id,
                'remarks' => 'Move to cottage.',
            ])
            ->assertOk();

        $asset = $asset->fresh(['locations.accommodation']);
        $cottageLocation = $asset->locations->firstWhere('accommodation_id', $cottage->id);

        $this->actingAs($admin)
            ->patchJson("/api/admin/inventory-assets/{$asset->id}/assign", [
                'source_location_id' => $cottageLocation->id,
                'transfer_quantity' => 5,
                'destination_key' => 'storage:main-storage',
                'remarks' => 'Return to storage.',
            ])
            ->assertOk()
            ->assertJsonPath('data.quantity', 20);

        $asset = $asset->fresh(['locations.accommodation']);
        $this->assertCount(1, $asset->locations);
        $this->assertSame(20, $asset->locations->first()->quantity);
        $this->assertSame(InventoryAsset::LOCATION_STORAGE, $asset->locations->first()->location_type);
    }

    public function test_admin_can_mark_maintenance_return_and_retire_asset(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $asset = $this->createAsset();

        $this->actingAs($admin)
            ->patchJson("/api/admin/inventory-assets/{$asset->id}/status", [
                'status' => InventoryAsset::STATUS_MAINTENANCE,
                'remarks' => 'Needs repair.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryAsset::STATUS_MAINTENANCE);

        $this->actingAs($admin)
            ->patchJson("/api/admin/inventory-assets/{$asset->id}/status", [
                'status' => InventoryAsset::STATUS_AVAILABLE,
                'remarks' => 'Repair complete.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryAsset::STATUS_AVAILABLE);

        $this->actingAs($admin)
            ->patchJson("/api/admin/inventory-assets/{$asset->id}/status", [
                'status' => InventoryAsset::STATUS_RETIRED,
                'remarks' => 'End of useful life.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryAsset::STATUS_RETIRED);

        $this->assertDatabaseHas('inventory_asset_histories', [
            'inventory_asset_id' => $asset->id,
            'action' => 'retired',
        ]);
    }

    public function test_retired_asset_cannot_be_modified_or_reactivated(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $asset = $this->createAsset(['status' => InventoryAsset::STATUS_RETIRED]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/inventory-assets/{$asset->id}/status", [
                'status' => InventoryAsset::STATUS_AVAILABLE,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->actingAs($admin)
            ->putJson("/api/admin/inventory-assets/{$asset->id}", $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_asset_history_can_be_viewed(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $asset = $this->createAsset();
        $asset->histories()->create([
            'performed_by' => $admin->id,
            'action' => 'created',
            'new_value' => ['asset_code' => $asset->asset_code],
            'remarks' => 'Initial entry.',
        ]);

        $this->actingAs($admin)
            ->getJson("/api/admin/inventory-assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('history.0.action', 'created')
            ->assertJsonPath('history.0.performed_by.email', $admin->email);
    }

    public function test_asset_codes_increment_by_category_and_are_not_reused_after_delete(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $first = $this->actingAs($admin)
            ->postJson('/api/admin/inventory-assets', array_merge($this->validPayload(), ['category' => 'Furniture']))
            ->assertCreated()
            ->json('data');

        $second = $this->actingAs($admin)
            ->postJson('/api/admin/inventory-assets', array_merge($this->validPayload(), ['category' => 'Furniture', 'name' => 'Second Bed']))
            ->assertCreated()
            ->json('data');

        $deletedAsset = InventoryAsset::where('asset_code', $first['asset_code'])->firstOrFail();
        $deletedAsset->delete();

        $third = $this->actingAs($admin)
            ->postJson('/api/admin/inventory-assets', array_merge($this->validPayload(), ['category' => 'Furniture', 'name' => 'Third Bed']))
            ->assertCreated()
            ->json('data');

        $this->assertSame('FUR-0001', $first['asset_code']);
        $this->assertSame('FUR-0002', $second['asset_code']);
        $this->assertSame('FUR-0003', $third['asset_code']);
    }

    public function test_other_category_uses_other_prefix(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/admin/inventory-assets', array_merge($this->validPayload(), [
                'category' => 'Custom Category Name',
                'name' => 'Misc Asset',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.asset_code', 'OTH-0001');
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'name' => 'Queen Bed',
            'category' => 'Furniture',
            'quantity' => 1,
            'condition' => InventoryAsset::CONDITION_GOOD,
            'status' => InventoryAsset::STATUS_AVAILABLE,
            'location_type' => InventoryAsset::LOCATION_STORAGE,
            'location_name' => 'Main storage',
            'acquisition_date' => '2026-08-20',
            'purchase_cost' => 8500,
            'description' => 'Resort room furniture asset.',
            'remarks' => 'Initial inventory entry.',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAsset(array $overrides = []): InventoryAsset
    {
        $asset = InventoryAsset::create(array_merge([
            'name' => 'Queen Bed',
            'category' => 'Furniture',
            'quantity' => 1,
            'condition' => InventoryAsset::CONDITION_GOOD,
            'status' => InventoryAsset::STATUS_AVAILABLE,
            'location_type' => InventoryAsset::LOCATION_STORAGE,
            'location_name' => 'Main storage',
            'acquisition_date' => '2026-08-20',
            'purchase_cost' => 8500,
            'description' => 'Test asset.',
        ], $overrides));

        app(InventoryAssetTransferService::class)->seedInitialLocation($asset);

        return $asset->fresh(['locations.accommodation']);
    }

    private function createAccommodation(array $overrides = []): Accommodation
    {
        return Accommodation::create(array_merge([
            'name' => 'Demo Room',
            'slug' => 'demo-room',
            'type' => 'room',
            'capacity' => 2,
            'price_per_night' => 2500,
            'description' => 'Demo accommodation for tests.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'image_path' => null,
        ], $overrides));
    }
}

