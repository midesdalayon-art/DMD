<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationImage;
use App\Services\AccommodationImageOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccommodationImageOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_upload_preserves_original_and_generates_variants(): void
    {
        Storage::fake('public');

        $path = $this->storeAccommodationImage(UploadedFile::fake()->image('main.jpg', 1600, 1200));
        $base = pathinfo($path, PATHINFO_FILENAME);

        $this->assertTrue(Storage::disk('public')->exists($path));
        $this->assertTrue(Storage::disk('public')->exists("accommodations/optimized/{$base}-thumbnail.webp"));
        $this->assertTrue(Storage::disk('public')->exists("accommodations/optimized/{$base}-medium.webp"));
        $this->assertTrue(Storage::disk('public')->exists("accommodations/optimized/{$base}-large.webp"));
        $this->assertGreaterThan(0, Storage::disk('public')->size("accommodations/optimized/{$base}-medium.webp"));
    }

    public function test_public_api_exposes_optimized_image_urls(): void
    {
        Storage::fake('public');
        $accommodation = Accommodation::create([
            'name' => 'Demo Room',
            'slug' => 'demo-room',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 2,
            'price_per_night' => 2500,
            'description' => 'Demo accommodation for tests.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'image_path' => null,
        ]);

        $imagePath = $this->storeAccommodationImage(UploadedFile::fake()->image('gallery.jpg', 1448, 1086));
        $accommodation->images()->create([
            'image_path' => $imagePath,
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        $this->getJson("/api/accommodations/{$accommodation->id}")
            ->assertOk()
            ->assertJsonPath('data.gallery_images.0.original_url', Storage::disk('public')->url($imagePath))
            ->assertJsonPath('data.gallery_images.0.thumbnail_url', Storage::disk('public')->url('accommodations/optimized/'.pathinfo($imagePath, PATHINFO_FILENAME).'-thumbnail.webp'))
            ->assertJsonPath('data.gallery_images.0.medium_url', Storage::disk('public')->url('accommodations/optimized/'.pathinfo($imagePath, PATHINFO_FILENAME).'-medium.webp'))
            ->assertJsonPath('data.gallery_images.0.large_url', Storage::disk('public')->url('accommodations/optimized/'.pathinfo($imagePath, PATHINFO_FILENAME).'-large.webp'))
            ->assertJsonPath('data.gallery_images.0.url', Storage::disk('public')->url('accommodations/optimized/'.pathinfo($imagePath, PATHINFO_FILENAME).'-medium.webp'));
    }

    public function test_existing_image_without_variants_still_works(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('accommodations/legacy.jpg', UploadedFile::fake()->image('legacy.jpg')->get());

        $image = new AccommodationImage([
            'image_path' => 'accommodations/legacy.jpg',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        $data = $image->publicData();

        $this->assertSame(Storage::disk('public')->url('accommodations/legacy.jpg'), $data['url']);
        $this->assertNull($data['thumbnail_url']);
        $this->assertNull($data['medium_url']);
        $this->assertNull($data['large_url']);
        $this->assertNull($data['srcset']);
    }

    public function test_failed_image_processing_preserves_upload(): void
    {
        Storage::fake('public');

        $this->app->instance(AccommodationImageOptimizer::class, new class extends AccommodationImageOptimizer
        {
            public function generateVariants(string $originalPath): array
            {
                throw new \RuntimeException('Optimization failed intentionally.');
            }
        });

        $path = $this->storeAccommodationImage(UploadedFile::fake()->image('broken.jpg', 1200, 900));

        $this->assertTrue(Storage::disk('public')->exists($path));
        $this->assertFalse(Storage::disk('public')->exists('accommodations/optimized/broken-medium.webp'));
    }

    public function test_backfill_command_generates_variants_for_existing_images(): void
    {
        Storage::fake('public');
        $accommodation = Accommodation::create([
            'name' => 'Seed Room',
            'slug' => 'seed-room',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 2,
            'price_per_night' => 2500,
            'description' => 'Seed accommodation for command test.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'image_path' => null,
        ]);
        $imagePath = $this->storeAccommodationImage(UploadedFile::fake()->image('seed.jpg', 1600, 1200));
        $accommodation->images()->create([
            'image_path' => $imagePath,
            'is_primary' => true,
            'sort_order' => 0,
        ]);
        $base = pathinfo($imagePath, PATHINFO_FILENAME);

        Storage::disk('public')->delete([
            "accommodations/optimized/{$base}-thumbnail.webp",
            "accommodations/optimized/{$base}-medium.webp",
            "accommodations/optimized/{$base}-large.webp",
        ]);

        Artisan::call('accommodations:optimize-images');

        $this->assertTrue(Storage::disk('public')->exists($imagePath));
        $this->assertTrue(Storage::disk('public')->exists("accommodations/optimized/{$base}-thumbnail.webp"));
        $this->assertTrue(Storage::disk('public')->exists("accommodations/optimized/{$base}-medium.webp"));
        $this->assertTrue(Storage::disk('public')->exists("accommodations/optimized/{$base}-large.webp"));
    }

    private function storeAccommodationImage(UploadedFile $file): string
    {
        return app(AccommodationImageOptimizer::class)->optimizeUploadedFile($file, 'accommodations');
    }
}
