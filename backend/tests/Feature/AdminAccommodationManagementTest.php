<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationImage;
use App\Models\Amenity;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminAccommodationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_and_filter_accommodations(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->createAccommodation(['name' => 'Garden Cottage', 'slug' => 'garden-cottage', 'type' => 'cottage']);
        $this->createAccommodation(['name' => 'Pool Room', 'slug' => 'pool-room', 'type' => 'room']);

        $this->actingAs($admin)
            ->getJson('/api/admin/accommodations?search=garden&type=cottage')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Garden Cottage')
            ->assertJsonPath('data.0.type', 'cottage');
    }

    public function test_admin_can_create_all_supported_accommodation_types(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        foreach ([Accommodation::TYPE_ROOM, Accommodation::TYPE_COTTAGE, Accommodation::TYPE_FUNCTION_HALL] as $index => $type) {
            $this->actingAs($admin)
                ->post('/api/admin/accommodations', array_merge($this->validPayload(), [
                    'name' => "Supported Type {$index}",
                    'slug' => "supported-type-{$index}",
                    'type' => $type,
                    'primary_image' => UploadedFile::fake()->image("main-{$index}.jpg"),
                ]))
                ->assertCreated()
                ->assertJsonPath('data.type', $type);
        }
    }

    public function test_admin_can_edit_accommodation_type(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $accommodation = $this->createAccommodation(['type' => Accommodation::TYPE_ROOM]);

        $this->actingAs($admin)
            ->post("/api/admin/accommodations/{$accommodation->id}", array_merge($this->validPayload(), [
                '_method' => 'PUT',
                'type' => Accommodation::TYPE_FUNCTION_HALL,
                'primary_image' => UploadedFile::fake()->image('hall.jpg'),
            ]))
            ->assertOk()
            ->assertJsonPath('data.type', Accommodation::TYPE_FUNCTION_HALL)
            ->assertJsonPath('data.type_label', 'Function Hall');
    }

    public function test_family_room_is_not_valid_accommodation_type(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/admin/accommodations', array_merge($this->validPayload(), [
                'type' => 'family',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_admin_can_create_accommodation(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post('/api/admin/accommodations', array_merge($this->validPayload(), [
                'primary_image' => UploadedFile::fake()->image('main.jpg'),
            ]))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'new-garden-cottage')
            ->assertJsonPath('data.status', Accommodation::STATUS_AVAILABLE)
            ->assertJsonCount(1, 'data.gallery_images')
            ->assertJsonPath('data.gallery_images.0.is_primary', true);

        $this->assertDatabaseHas('accommodations', [
            'slug' => 'new-garden-cottage',
            'type' => 'cottage',
        ]);
        $this->assertDatabaseHas('accommodation_images', [
            'is_primary' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_admin_can_update_accommodation(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $accommodation = $this->createAccommodation();

        $this->actingAs($admin)
            ->post("/api/admin/accommodations/{$accommodation->id}", array_merge($this->validPayload(), [
                '_method' => 'PUT',
                'name' => 'Updated Room',
                'slug' => 'updated-room',
                'type' => 'room',
                'primary_image' => UploadedFile::fake()->image('updated.jpg'),
            ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Room')
            ->assertJsonPath('data.slug', 'updated-room');
    }

    public function test_admin_can_change_accommodation_status(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $accommodation = $this->createAccommodation();

        $this->actingAs($admin)
            ->patchJson("/api/admin/accommodations/{$accommodation->id}/status", [
                'status' => Accommodation::STATUS_MAINTENANCE,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Accommodation::STATUS_MAINTENANCE);
    }

    public function test_admin_can_delete_accommodation_without_reservation_history(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $accommodation = $this->createAccommodation();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/accommodations/{$accommodation->id}")
            ->assertOk();

        $this->assertDatabaseMissing('accommodations', [
            'id' => $accommodation->id,
        ]);
    }

    public function test_accommodation_with_reservation_history_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = $this->createAccommodation();
        $this->createReservation($guest, $accommodation);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/accommodations/{$accommodation->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accommodation');

        $this->assertDatabaseHas('accommodations', [
            'id' => $accommodation->id,
        ]);
        $this->assertDatabaseHas('reservations', [
            'accommodation_id' => $accommodation->id,
        ]);
    }

    public function test_admin_accommodation_validation_rejects_invalid_input(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/admin/accommodations', [
                'name' => '',
                'slug' => 'Invalid Slug',
                'type' => '',
                'capacity' => 0,
                'price_per_night' => -1,
                'description' => 'short',
                'status' => 'booked',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'slug',
                'type',
                'capacity',
                'price_per_night',
                'description',
                'status',
            ]);
    }

    public function test_accommodation_image_upload_supports_maximum_five_images(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post('/api/admin/accommodations', array_merge($this->validPayload(), [
                'primary_image' => UploadedFile::fake()->image('main.jpg'),
                'gallery_images' => [
                    UploadedFile::fake()->image('one.jpg'),
                    UploadedFile::fake()->image('two.png'),
                    UploadedFile::fake()->image('three.jpg'),
                    UploadedFile::fake()->image('four.jpeg'),
                ],
            ]))
            ->assertCreated()
            ->assertJsonCount(5, 'data.gallery_images');

        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/accommodations', array_merge($this->validPayload(), [
                'slug' => 'too-many-images',
                'primary_image' => UploadedFile::fake()->image('main.jpg'),
                'gallery_images' => [
                    UploadedFile::fake()->image('one.jpg'),
                    UploadedFile::fake()->image('two.png'),
                    UploadedFile::fake()->image('three.jpg'),
                    UploadedFile::fake()->image('four.jpeg'),
                    UploadedFile::fake()->image('five.jpg'),
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gallery_images');
    }

    public function test_accommodation_keeps_only_one_primary_image_when_replaced(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $accommodation = $this->createAccommodation();
        $accommodation->images()->create([
            'image_path' => 'accommodations/original.jpg',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        $this->actingAs($admin)
            ->post("/api/admin/accommodations/{$accommodation->id}", array_merge($this->validPayload(), [
                '_method' => 'PUT',
                'primary_image' => UploadedFile::fake()->image('replacement.jpg'),
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data.gallery_images')
            ->assertJsonPath('data.gallery_images.0.is_primary', true);

        $this->assertSame(1, $accommodation->images()->where('is_primary', true)->count());
    }

    public function test_accommodation_gallery_image_can_be_removed(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $accommodation = $this->createAccommodation();
        $accommodation->images()->create([
            'image_path' => 'accommodations/main.jpg',
            'is_primary' => true,
            'sort_order' => 0,
        ]);
        $galleryImage = $accommodation->images()->create([
            'image_path' => 'accommodations/secondary.jpg',
            'is_primary' => false,
            'sort_order' => 1,
        ]);

        $this->actingAs($admin)
            ->post("/api/admin/accommodations/{$accommodation->id}", array_merge($this->validPayload(), [
                '_method' => 'PUT',
                'remove_image_ids' => [$galleryImage->id],
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data.gallery_images');

        $this->assertDatabaseMissing('accommodation_images', [
            'id' => $galleryImage->id,
        ]);
    }

    public function test_public_accommodation_response_includes_image_gallery_data(): void
    {
        $accommodation = $this->createAccommodation();
        $accommodation->images()->create([
            'image_path' => 'accommodations/main.jpg',
            'is_primary' => true,
            'sort_order' => 0,
        ]);
        $accommodation->images()->create([
            'image_path' => 'accommodations/side.jpg',
            'is_primary' => false,
            'sort_order' => 1,
        ]);

        $this->getJson("/api/accommodations/{$accommodation->id}")
            ->assertOk()
            ->assertJsonPath('data.primary_image_url', config('app.url').'/storage/accommodations/main.jpg')
            ->assertJsonCount(2, 'data.gallery_images');
    }

    public function test_admin_can_create_accommodation_with_amenities(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $wifi = Amenity::create(['name' => 'Wi-Fi']);
        $parking = Amenity::create(['name' => 'Parking']);

        $response = $this->actingAs($admin)
            ->post('/api/admin/accommodations', array_merge($this->validPayload(), [
                'primary_image' => UploadedFile::fake()->image('main.jpg'),
                'amenity_ids' => [$wifi->id, $parking->id],
            ]))
            ->assertCreated()
            ->assertJsonCount(2, 'data.amenities');

        $accommodationId = $response->json('data.id');

        $this->assertDatabaseHas('accommodation_amenity', [
            'accommodation_id' => $accommodationId,
            'amenity_id' => $wifi->id,
        ]);
        $this->assertDatabaseHas('accommodation_amenity', [
            'accommodation_id' => $accommodationId,
            'amenity_id' => $parking->id,
        ]);
    }

    public function test_admin_can_update_accommodation_amenities(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $wifi = Amenity::create(['name' => 'Wi-Fi']);
        $kitchen = Amenity::create(['name' => 'Kitchen']);
        $accommodation = $this->createAccommodation();
        $accommodation->amenities()->attach($wifi->id);

        $this->actingAs($admin)
            ->post("/api/admin/accommodations/{$accommodation->id}", array_merge($this->validPayload(), [
                '_method' => 'PUT',
                'primary_image' => UploadedFile::fake()->image('updated.jpg'),
                'amenity_ids' => [$wifi->id, $kitchen->id],
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'data.amenities');

        $this->assertDatabaseHas('accommodation_amenity', [
            'accommodation_id' => $accommodation->id,
            'amenity_id' => $wifi->id,
        ]);
        $this->assertDatabaseHas('accommodation_amenity', [
            'accommodation_id' => $accommodation->id,
            'amenity_id' => $kitchen->id,
        ]);
    }

    public function test_admin_can_remove_amenity_during_accommodation_edit(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $wifi = Amenity::create(['name' => 'Wi-Fi']);
        $parking = Amenity::create(['name' => 'Parking']);
        $accommodation = $this->createAccommodation();
        $accommodation->amenities()->attach([$wifi->id, $parking->id]);

        $this->actingAs($admin)
            ->post("/api/admin/accommodations/{$accommodation->id}", array_merge($this->validPayload(), [
                '_method' => 'PUT',
                'primary_image' => UploadedFile::fake()->image('updated.jpg'),
                'amenity_ids' => [$wifi->id],
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data.amenities')
            ->assertJsonPath('data.amenities.0.name', 'Wi-Fi');

        $this->assertDatabaseHas('accommodation_amenity', [
            'accommodation_id' => $accommodation->id,
            'amenity_id' => $wifi->id,
        ]);
        $this->assertDatabaseMissing('accommodation_amenity', [
            'accommodation_id' => $accommodation->id,
            'amenity_id' => $parking->id,
        ]);
    }

    public function test_public_accommodation_response_includes_assigned_amenities(): void
    {
        $accommodation = $this->createAccommodation();
        $amenity = Amenity::create(['name' => 'Private Bathroom', 'icon' => 'bath']);
        $accommodation->amenities()->attach($amenity->id);

        $this->getJson("/api/accommodations/{$accommodation->id}")
            ->assertOk()
            ->assertJsonPath('data.amenities.0.id', $amenity->id)
            ->assertJsonPath('data.amenities.0.name', 'Private Bathroom')
            ->assertJsonPath('data.amenities.0.icon', 'bath');
    }

    public function test_admin_accommodation_rejects_invalid_amenity_ids(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/accommodations', array_merge($this->validPayload(), [
                'primary_image' => UploadedFile::fake()->image('main.jpg'),
                'amenity_ids' => [999],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amenity_ids.0');
    }

    public function test_admin_accommodation_routes_reject_non_admin_users(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);

        $this->actingAs($guest)
            ->getJson('/api/admin/accommodations')
            ->assertForbidden();
    }

    public function test_admin_accommodation_routes_require_authentication(): void
    {
        $this->getJson('/api/admin/accommodations')->assertUnauthorized();
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'name' => 'New Garden Cottage',
            'slug' => 'new-garden-cottage',
            'type' => 'cottage',
            'capacity' => 4,
            'price_per_night' => 3200,
            'description' => 'Development accommodation details ready for public browsing.',
            'status' => Accommodation::STATUS_AVAILABLE,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
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

    private function createReservation(User $user, Accommodation $accommodation): Reservation
    {
        return Reservation::create([
            'user_id' => $user->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-22',
            'guests' => 2,
            'total_amount' => 5000,
            'status' => Reservation::STATUS_PENDING,
            'booking_reference' => 'DMD-20260819-MGT001',
        ]);
    }
}

