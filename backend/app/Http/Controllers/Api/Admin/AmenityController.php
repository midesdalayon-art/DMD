<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AmenityController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Amenity::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Amenity $amenity) => $amenity->publicData())
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        $name = trim($attributes['name']);

        if (Amenity::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ValidationException::withMessages([
                'name' => ['This amenity already exists.'],
            ]);
        }

        $amenity = Amenity::create([
            'name' => $name,
            'icon' => $attributes['icon'] ?? null,
        ]);

        app(AuditLogger::class)->log($request, 'accommodation_management', 'amenity_created', 'Amenity created.', $amenity, [
            'after' => $amenity->publicData(),
        ]);

        return response()->json([
            'data' => $amenity->publicData(),
            'message' => 'Amenity created.',
        ], 201);
    }
}
