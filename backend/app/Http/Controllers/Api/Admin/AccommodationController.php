<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\AccommodationImage;
use App\Services\AccommodationImageOptimizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\AuditLogger;

class AccommodationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'type' => ['sometimes', 'nullable', Rule::in(Accommodation::types())],
            'status' => ['sometimes', 'nullable', Rule::in($this->statuses())],
        ]);

        $query = Accommodation::query()
            ->with(['images', 'amenities'])
            ->withCount('reservations')
            ->orderBy('type')
            ->orderBy('name');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $search = mb_strtolower($search);
            $query->where(function ($query) use ($search) {
                $query
                    ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(slug) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(description) LIKE ?', ["%{$search}%"]);
            });
        }

        if ($type = $filters['type'] ?? null) {
            $query->where('type', $type);
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        return response()->json([
            'data' => $query->get()->map(fn (Accommodation $accommodation) => $this->adminData($accommodation))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $attributes = $this->validatedAttributes($request);

        if (! $request->hasFile('primary_image') && empty($attributes['image_path'])) {
            throw ValidationException::withMessages([
                'primary_image' => ['The main image is required.'],
            ]);
        }

        $amenityIds = $attributes['amenity_ids'] ?? [];
        unset($attributes['amenity_ids']);

        $accommodation = DB::transaction(function () use ($request, $attributes, $amenityIds) {
            $accommodation = Accommodation::create($attributes);
            $this->syncImages($request, $accommodation, true);
            $accommodation->amenities()->sync($amenityIds);

            return $accommodation;
        });
        app(AuditLogger::class)->log($request, 'accommodation_management', 'accommodation_created', 'Accommodation created.', $accommodation, [
            'after' => $accommodation->fresh(['images', 'amenities'])->publicData(),
        ]);

        return response()->json([
            'data' => $this->adminData($accommodation->load(['images', 'amenities'])->loadCount('reservations')),
            'message' => 'Accommodation created.',
        ], 201);
    }

    public function show(Accommodation $accommodation): JsonResponse
    {
        return response()->json([
            'data' => $this->adminData($accommodation->load(['images', 'amenities'])->loadCount('reservations')),
        ]);
    }

    public function update(Request $request, Accommodation $accommodation): JsonResponse
    {
        $old = $accommodation->load(['images', 'amenities'])->publicData();
        $attributes = $this->validatedAttributes($request, $accommodation);
        $amenityIds = $attributes['amenity_ids'] ?? [];
        unset($attributes['amenity_ids']);

        DB::transaction(function () use ($request, $accommodation, $attributes, $amenityIds) {
            $accommodation->update($attributes);
            $this->syncImages($request, $accommodation, false);
            $accommodation->amenities()->sync($amenityIds);
        });
        app(AuditLogger::class)->log($request, 'accommodation_management', 'accommodation_updated', 'Accommodation updated.', $accommodation, [
            'before' => $old,
            'after' => $accommodation->fresh(['images', 'amenities'])->publicData(),
        ]);

        return response()->json([
            'data' => $this->adminData($accommodation->load(['images', 'amenities'])->loadCount('reservations')),
            'message' => 'Accommodation updated.',
        ]);
    }

    public function updateStatus(Request $request, Accommodation $accommodation): JsonResponse
    {
        $attributes = $request->validate([
            'status' => ['required', Rule::in($this->statuses())],
        ]);

        $old = ['status' => $accommodation->status];
        $accommodation->forceFill([
            'status' => $attributes['status'],
        ])->save();
        app(AuditLogger::class)->log($request, 'accommodation_management', 'status_changed', 'Accommodation status changed.', $accommodation, [
            'before' => $old,
            'after' => ['status' => $accommodation->status],
        ]);

        return response()->json([
            'data' => $this->adminData($accommodation->load(['images', 'amenities'])->loadCount('reservations')),
            'message' => 'Accommodation status updated.',
        ]);
    }

    public function destroy(Accommodation $accommodation): JsonResponse
    {
        if ($accommodation->reservations()->exists() || $accommodation->housekeepingTasks()->exists()) {
            throw ValidationException::withMessages([
                'accommodation' => ['This accommodation has reservation or housekeeping history and cannot be permanently deleted. Change its status instead.'],
            ]);
        }

        $accommodation->load('images');
        foreach ($accommodation->images as $image) {
            $this->deleteStoredImage($image);
        }

        $accommodation->delete();
        app(AuditLogger::class)->log(request(), 'accommodation_management', 'accommodation_deleted', 'Accommodation deleted.', $accommodation, [
            'deleted_accommodation' => $accommodation->publicData(),
        ]);

        return response()->json([
            'message' => 'Accommodation deleted.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAttributes(Request $request, ?Accommodation $accommodation = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => [
                'required',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('accommodations', 'slug')->ignore($accommodation?->id),
            ],
            'type' => ['required', Rule::in(Accommodation::types())],
            'capacity' => ['required', 'integer', 'min:1', 'max:100'],
            'price_per_night' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'status' => ['required', Rule::in($this->statuses())],
            'image_path' => ['nullable', 'string', 'max:2048'],
            'primary_image' => [$accommodation ? 'sometimes' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'gallery_images' => ['sometimes', 'array', 'max:4'],
            'gallery_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_image_ids' => ['sometimes', 'array'],
            'remove_image_ids.*' => ['integer', 'exists:accommodation_images,id'],
            'amenity_ids' => ['sometimes', 'array'],
            'amenity_ids.*' => ['integer', 'distinct', 'exists:amenities,id'],
        ]);
    }

    private function syncImages(Request $request, Accommodation $accommodation, bool $isCreating): void
    {
        $accommodation->load('images');
        $removeIds = collect($request->input('remove_image_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();
        $newGalleryImages = $request->file('gallery_images', []);
        $hasNewPrimary = $request->hasFile('primary_image');
        $primaryToRemove = $removeIds->contains(
            fn (int $id) => $accommodation->images->contains(fn (AccommodationImage $image) => $image->id === $id && $image->is_primary)
        );

        if ($primaryToRemove && ! $hasNewPrimary) {
            throw ValidationException::withMessages([
                'primary_image' => ['The main image cannot be removed without uploading a replacement.'],
            ]);
        }

        $remainingCount = $accommodation->images
            ->reject(fn (AccommodationImage $image) => $removeIds->contains($image->id))
            ->count();

        if ($hasNewPrimary) {
            $remainingCount = $accommodation->images
                ->reject(fn (AccommodationImage $image) => $removeIds->contains($image->id) || $image->is_primary)
                ->count() + 1;
        }

        $incomingCount = count($newGalleryImages);

        if ($remainingCount + $incomingCount > 5) {
            throw ValidationException::withMessages([
                'gallery_images' => ['An accommodation can have a maximum of 5 images.'],
            ]);
        }

        if ($isCreating && ! $hasNewPrimary && $request->filled('image_path')) {
            $accommodation->images()->create([
                'image_path' => (string) $request->input('image_path'),
                'is_primary' => true,
                'sort_order' => 0,
            ]);
        }

        foreach ($accommodation->images()->whereIn('id', $removeIds)->get() as $image) {
            $this->deleteStoredImage($image);
            $image->delete();
        }

        if ($hasNewPrimary) {
            foreach ($accommodation->images()->where('is_primary', true)->get() as $image) {
                $this->deleteStoredImage($image);
                $image->delete();
            }

            $accommodation->images()->create([
                'image_path' => $this->storeImage($request->file('primary_image')),
                'is_primary' => true,
                'sort_order' => 0,
            ]);
        }

        foreach (array_values($newGalleryImages) as $index => $image) {
            $accommodation->images()->create([
                'image_path' => $this->storeImage($image),
                'is_primary' => false,
                'sort_order' => $this->nextSortOrder($accommodation) + $index,
            ]);
        }

        $this->normalizeImageOrder($accommodation);

        if ($accommodation->images()->where('is_primary', true)->count() !== 1) {
            throw ValidationException::withMessages([
                'primary_image' => ['Exactly one main image is required.'],
            ]);
        }
    }

    private function storeImage(UploadedFile $file): string
    {
        return app(AccommodationImageOptimizer::class)->optimizeUploadedFile($file, 'accommodations');
    }

    private function deleteStoredImage(AccommodationImage $image): void
    {
        if (str_starts_with($image->image_path, 'http://')
            || str_starts_with($image->image_path, 'https://')
            || str_starts_with($image->image_path, '/')) {
            return;
        }

        Storage::disk('public')->delete($image->image_path);
    }

    private function nextSortOrder(Accommodation $accommodation): int
    {
        return ((int) $accommodation->images()->max('sort_order')) + 1;
    }

    private function normalizeImageOrder(Accommodation $accommodation): void
    {
        $images = $accommodation->images()->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')->get();

        foreach ($images as $index => $image) {
            $image->forceFill([
                'is_primary' => $index === 0,
                'sort_order' => $index,
            ])->save();
        }
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        return [
            Accommodation::STATUS_AVAILABLE,
            Accommodation::STATUS_UNAVAILABLE,
            Accommodation::STATUS_MAINTENANCE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminData(Accommodation $accommodation): array
    {
        return array_merge($accommodation->publicData(), [
            'reservations_count' => $accommodation->reservations_count ?? $accommodation->reservations()->count(),
            'created_at' => $accommodation->created_at?->toISOString(),
            'updated_at' => $accommodation->updated_at?->toISOString(),
        ]);
    }
}
