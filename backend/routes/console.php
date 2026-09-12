<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\AccommodationImage;
use App\Services\AccommodationImageOptimizer;
use Illuminate\Support\Facades\Schedule;

Schedule::command('reservations:expire-pending')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('accommodations:optimize-images', function (AccommodationImageOptimizer $optimizer) {
    $processed = 0;
    $skipped = 0;
    $failed = 0;

    AccommodationImage::query()
        ->whereNotNull('image_path')
        ->orderBy('id')
        ->chunkById(50, function ($images) use ($optimizer, &$processed, &$skipped, &$failed) {
            foreach ($images as $image) {
                if (str_starts_with($image->image_path, 'http://')
                    || str_starts_with($image->image_path, 'https://')
                    || str_starts_with($image->image_path, '/')) {
                    $skipped++;
                    continue;
                }

                try {
                    $optimizer->generateVariants($image->image_path);
                    $processed++;
                    $this->components->info("Optimized image #{$image->id}: {$image->image_path}");
                } catch (\Throwable $exception) {
                    $failed++;
                    $this->components->error("Failed image #{$image->id}: {$image->image_path} ({$exception->getMessage()})");
                }
            }
        });

    $this->components->info("Done. Processed: {$processed}, skipped: {$skipped}, failed: {$failed}");
})->purpose('Generate optimized WebP variants for accommodation images');
