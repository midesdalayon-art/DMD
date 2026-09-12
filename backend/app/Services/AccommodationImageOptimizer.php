<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AccommodationImageOptimizer
{
    private const VARIANT_WIDTHS = [
        'thumbnail' => 640,
        'medium' => 960,
        'large' => 1440,
    ];

    /**
     * @return array{original_path:string, original_url:string, thumbnail_path:?string, thumbnail_url:?string, medium_path:?string, medium_url:?string, large_path:?string, large_url:?string, srcset:?string}
     */
    public function generateVariants(string $originalPath): array
    {
        $originalPath = $this->normalizeRelativePath($originalPath);
        $absoluteOriginalPath = Storage::disk('public')->path($originalPath);

        if (! is_file($absoluteOriginalPath)) {
            throw new RuntimeException("Accommodation image not found: {$originalPath}");
        }

        $dimensions = @getimagesize($absoluteOriginalPath);

        if (! is_array($dimensions) || empty($dimensions[0]) || empty($dimensions[1])) {
            throw new RuntimeException("Unable to read image dimensions for {$originalPath}");
        }

        $originalWidth = (int) $dimensions[0];
        $createdVariants = [];

        foreach (self::VARIANT_WIDTHS as $variantName => $targetWidth) {
            if ($originalWidth < $targetWidth) {
                continue;
            }

            $variantPath = $this->variantPath($originalPath, $variantName);
            $variantAbsolutePath = Storage::disk('public')->path($variantPath);

            if (is_file($variantAbsolutePath) && filesize($variantAbsolutePath) > 0) {
                $createdVariants[$variantName] = $variantPath;
                continue;
            }

            $this->ensureDirectory(dirname($variantAbsolutePath));
            $this->createWebpVariant($absoluteOriginalPath, $variantAbsolutePath, $targetWidth);

            $createdVariants[$variantName] = $variantPath;
        }

        return $this->variantData($originalPath, $createdVariants);
    }

    /**
     * @return array{original_path:string, original_url:string, thumbnail_path:?string, thumbnail_url:?string, medium_path:?string, medium_url:?string, large_path:?string, large_url:?string, srcset:?string}
     */
    public function publicData(string $originalPath): array
    {
        $originalPath = $this->normalizeRelativePath($originalPath);
        $variants = $this->existingVariants($originalPath);

        return $this->variantData($originalPath, $variants);
    }

    public function optimizeUploadedFile(UploadedFile $file, string $destinationDirectory = 'accommodations'): string
    {
        $storedPath = $file->store($destinationDirectory, 'public');

        try {
            $this->generateVariants($storedPath);
        } catch (\Throwable $exception) {
            Log::warning('Failed to optimize accommodation image.', [
                'stored_path' => $storedPath,
                'error' => $exception->getMessage(),
            ]);
        }

        return $storedPath;
    }

    /**
     * @return array<string, string|null>
     */
    private function existingVariants(string $originalPath): array
    {
        $variants = [];

        foreach (array_keys(self::VARIANT_WIDTHS) as $variantName) {
            $variantPath = $this->variantPath($originalPath, $variantName);
            if (Storage::disk('public')->exists($variantPath)) {
                $variants[$variantName] = $variantPath;
            }
        }

        return $variants;
    }

    /**
     * @param  array<string, string>  $variants
     * @return array{original_path:string, original_url:string, thumbnail_path:?string, thumbnail_url:?string, medium_path:?string, medium_url:?string, large_path:?string, large_url:?string, srcset:?string}
     */
    private function variantData(string $originalPath, array $variants): array
    {
        $data = [
            'original_path' => $originalPath,
            'original_url' => Storage::disk('public')->url($originalPath),
            'thumbnail_path' => $variants['thumbnail'] ?? null,
            'thumbnail_url' => isset($variants['thumbnail']) ? Storage::disk('public')->url($variants['thumbnail']) : null,
            'medium_path' => $variants['medium'] ?? null,
            'medium_url' => isset($variants['medium']) ? Storage::disk('public')->url($variants['medium']) : null,
            'large_path' => $variants['large'] ?? null,
            'large_url' => isset($variants['large']) ? Storage::disk('public')->url($variants['large']) : null,
        ];

        $srcset = collect([
            'thumbnail' => $data['thumbnail_url'] ? "{$data['thumbnail_url']} 640w" : null,
            'medium' => $data['medium_url'] ? "{$data['medium_url']} 960w" : null,
            'large' => $data['large_url'] ? "{$data['large_url']} 1440w" : null,
        ])->filter()->values()->implode(', ');

        $data['srcset'] = $srcset !== '' ? $srcset : null;

        return $data;
    }

    private function normalizeRelativePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }

    private function variantPath(string $originalPath, string $variantName): string
    {
        $directory = trim(str_replace('\\', '/', pathinfo($originalPath, PATHINFO_DIRNAME)), '.');
        $filename = pathinfo($originalPath, PATHINFO_FILENAME);

        return ($directory === '.' ? '' : $directory.'/').'optimized/'.$filename.'-'.$variantName.'.webp';
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }

    private function createWebpVariant(string $sourcePath, string $targetPath, int $targetWidth): void
    {
        [$sourceWidth, $sourceHeight, $type] = @getimagesize($sourcePath) ?: [null, null, null];

        if (! $sourceWidth || ! $sourceHeight || ! $type) {
            throw new RuntimeException("Unable to process image {$sourcePath}");
        }

        $targetWidth = min($targetWidth, (int) $sourceWidth);
        $targetHeight = (int) round(($targetWidth / $sourceWidth) * $sourceHeight);

        $sourceImage = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => imagecreatefromwebp($sourcePath),
            default => throw new RuntimeException("Unsupported image type for {$sourcePath}"),
        };

        if (! $sourceImage) {
            throw new RuntimeException("Failed to open source image {$sourcePath}");
        }

        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($targetImage, false);
            imagesavealpha($targetImage, true);
            $transparent = imagecolorallocatealpha($targetImage, 0, 0, 0, 127);
            imagefilledrectangle($targetImage, 0, 0, $targetWidth, $targetHeight, $transparent);
        }

        imagecopyresampled(
            $targetImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
        );

        if (! imagewebp($targetImage, $targetPath, 82)) {
            imagedestroy($sourceImage);
            imagedestroy($targetImage);
            throw new RuntimeException("Failed to write WebP image {$targetPath}");
        }

        imagedestroy($sourceImage);
        imagedestroy($targetImage);
    }
}
