<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\SystemSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    public function index(SystemSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => $settings->get(),
            'meta' => [
                'date_formats' => ['Y-m-d', 'm/d/Y', 'd/m/Y', 'M d, Y'],
                'time_formats' => ['H:i', 'h:i A'],
                'currencies' => ['PHP', 'USD'],
                'timezones' => ['Asia/Manila', 'Asia/Singapore', 'UTC'],
            ],
        ]);
    }

    public function update(Request $request, SystemSettings $settings, AuditLogger $auditLogger): JsonResponse
    {
        $unknownKeys = $settings->unknownKeys($request->except('_method'));

        if ($unknownKeys !== []) {
            throw ValidationException::withMessages([
                'settings' => ['Unsupported setting keys: '.implode(', ', $unknownKeys)],
            ]);
        }

        $before = $settings->get();
        $validated = $request->validate($settings->rules());
        $validated = $this->prepareBrandingSettings($request, $validated, $before);
        $validated = $this->prepareImageSettings($request, $validated, $before);
        $settings->persist($validated);
        $after = $settings->get();
        $changes = $settings->changes($before, $after);

        if ($changes !== []) {
            $auditLogger->log($request, 'settings', 'settings_updated', 'System settings updated.', null, [
                'changes' => $changes,
            ]);
        }

        return response()->json([
            'data' => $after,
            'message' => 'Settings saved.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>
     */
    private function prepareImageSettings(Request $request, array $validated, array $before): array
    {
        $validated['about'] ??= [];
        $currentPath = $before['about']['image_path'] ?? null;

        if ($request->boolean('about.remove_image') && $currentPath) {
            $this->deletePublicImage($currentPath);
            $validated['about']['image_path'] = null;
        }

        if ($request->hasFile('about.about_image')) {
            if ($currentPath) {
                $this->deletePublicImage($currentPath);
            }

            $validated['about']['image_path'] = $request->file('about.about_image')->store('settings/about', 'public');
        }

        unset($validated['about']['about_image'], $validated['about']['remove_image']);

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>
     */
    private function prepareBrandingSettings(Request $request, array $validated, array $before): array
    {
        $validated['branding'] ??= [];
        $currentLogoPath = $before['branding']['logo_path'] ?? null;
        $currentFaviconPath = $before['branding']['favicon_path'] ?? null;
        $currentHeroImagePath = $before['branding']['homepage_hero_image_path'] ?? null;

        if ($request->boolean('branding.remove_logo') && $currentLogoPath) {
            $this->deletePublicImage($currentLogoPath);
            $validated['branding']['logo_path'] = null;
        }

        if ($request->boolean('branding.remove_favicon') && $currentFaviconPath) {
            $this->deletePublicImage($currentFaviconPath);
            $validated['branding']['favicon_path'] = null;
        }

        if ($request->boolean('branding.remove_homepage_hero_image') && $currentHeroImagePath) {
            $this->deleteHeroImageIfUnreferenced($currentHeroImagePath, $before, 1);
            $validated['branding']['homepage_hero_image_path'] = null;
        }

        if ($request->hasFile('branding.logo_file')) {
            if ($currentLogoPath) {
                $this->deletePublicImage($currentLogoPath);
            }

            $validated['branding']['logo_path'] = $request->file('branding.logo_file')->store('branding', 'public');
        }

        if ($request->hasFile('branding.favicon_file')) {
            if ($currentFaviconPath) {
                $this->deletePublicImage($currentFaviconPath);
            }

            $validated['branding']['favicon_path'] = $request->file('branding.favicon_file')->store('branding', 'public');
        }

        if ($request->hasFile('branding.homepage_hero_image_file')) {
            if ($currentHeroImagePath) {
                $this->deleteHeroImageIfUnreferenced($currentHeroImagePath, $before, 1);
            }

            $validated['branding']['homepage_hero_image_path'] = $request->file('branding.homepage_hero_image_file')->store('branding', 'public');
        }

        for ($slot = 1; $slot <= 6; $slot++) {
            $fileKey = "hero_image_{$slot}_file";
            $removeKey = "remove_hero_image_{$slot}";
            $pathKey = "hero_image_{$slot}_path";
            $currentPath = $before['branding'][$pathKey] ?? ($slot === 1 ? ($before['branding']['homepage_hero_image_path'] ?? null) : null);

            if ($request->boolean("branding.{$removeKey}") && $currentPath) {
                $this->deleteHeroImageIfUnreferenced($currentPath, $before, $slot);
                $validated['branding'][$pathKey] = null;
                if ($slot === 1) {
                    $validated['branding']['homepage_hero_image_path'] = null;
                }
            }

            if ($request->hasFile("branding.{$fileKey}")) {
                if ($currentPath) {
                    $this->deleteHeroImageIfUnreferenced($currentPath, $before, $slot);
                }

                $storedPath = $request->file("branding.{$fileKey}")->store('branding', 'public');
                $validated['branding'][$pathKey] = $storedPath;
                if ($slot === 1) {
                    $validated['branding']['homepage_hero_image_path'] = $storedPath;
                }
            }
        }

        unset(
            $validated['branding']['logo_file'],
            $validated['branding']['favicon_file'],
            $validated['branding']['homepage_hero_image_file'],
            $validated['branding']['remove_logo'],
            $validated['branding']['remove_favicon'],
            $validated['branding']['remove_homepage_hero_image'],
        );

        for ($slot = 1; $slot <= 6; $slot++) {
            unset(
                $validated['branding']["hero_image_{$slot}_file"],
                $validated['branding']["remove_hero_image_{$slot}"],
            );
        }

        return $validated;
    }

    private function deletePublicImage(string $path): void
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    /**
     * Hero files can be shared by slots, so only remove a file when no other
     * stored hero setting still references it.
     *
     * @param  array<string, mixed>  $before
     */
    private function deleteHeroImageIfUnreferenced(string $path, array $before, int $slot): void
    {
        if (! str_starts_with($path, 'branding/')) {
            return;
        }

        $heroPaths = [];
        for ($candidate = 1; $candidate <= 6; $candidate++) {
            if ($candidate === $slot) {
                continue;
            }

            $candidatePath = $before['branding']["hero_image_{$candidate}_path"] ?? null;
            if ($candidate === 1 && ! $candidatePath) {
                $candidatePath = $before['branding']['homepage_hero_image_path'] ?? null;
            }
            if ($candidatePath) {
                $heroPaths[] = $candidatePath;
            }
        }

        if (! in_array($path, $heroPaths, true)) {
            Storage::disk('public')->delete($path);
        }
    }
}
