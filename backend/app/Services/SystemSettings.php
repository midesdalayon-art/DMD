<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SystemSettings
{
    private const CACHE_KEY = 'system_settings.normalized';

    private const CACHE_TTL_MINUTES = 10;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function defaults(): array
    {
        return [
            'general' => [
                'resort_name' => 'DMD Resort',
                'resort_name_font' => 'default',
                'contact_number' => '',
                'email' => '',
                'address' => '',
                'short_description' => '',
                'logo_path' => null,
            ],
            'branding' => [
                'logo_path' => null,
                'favicon_path' => null,
                'homepage_hero_image_path' => null,
                'hero_animation_enabled' => false,
                'hero_image_1_path' => null,
                'hero_image_2_path' => null,
                'hero_image_3_path' => null,
                'hero_image_4_path' => null,
                'hero_image_5_path' => null,
                'hero_image_6_path' => null,
            ],
            'about' => [
                'title' => 'About DMD Resort',
                'short_introduction' => 'A relaxing destination designed for families, friends, and special occasions.',
                'full_description' => '',
                'mission' => '',
                'vision' => '',
                'image_path' => null,
            ],
            'contact' => [
                'resort_address' => '',
                'primary_phone' => '',
                'secondary_phone' => '',
                'email' => '',
                'facebook_url' => '',
                'google_maps_url' => '',
                'business_hours' => '',
            ],
            'booking' => [
                'default_check_in_time' => '14:00',
                'default_check_out_time' => '12:00',
                'house_rules_check_in_time' => '14:00',
                'house_rules_check_out_time' => '12:00',
                'house_rules_quiet_hours_start' => '22:00',
                'house_rules_quiet_hours_end' => '07:00',
                'house_rules_smoking_policy' => 'No smoking inside accommodations.',
                'house_rules_capacity_rule' => 'Respect the maximum guest capacity.',
                'house_rules_pool_safety_rule' => 'Follow swimming pool and resort safety rules.',
                'house_rules_cleanliness_rule' => 'Keep the accommodation clean.',
                'house_rules_damage_rule' => 'Guests may be responsible for damaged or lost resort property.',
                'max_advance_booking_days' => 365,
                'minimum_booking_notice_hours' => 0,
                'cancellation_cutoff_hours' => 24,
                'guest_cancellation_enabled' => true,
            ],
            'attendance' => [
                'standard_work_start_time' => '08:00',
                'standard_work_end_time' => '17:00',
                'late_grace_period_minutes' => 15,
            ],
            'notifications' => [
                'booking_confirmation_email' => false,
                'booking_cancellation_email' => false,
                'announcement_notifications' => false,
                'low_priority_operational_notifications' => false,
            ],
            'regional' => [
                'timezone' => 'Asia/Manila',
                'date_format' => 'Y-m-d',
                'time_format' => 'H:i',
                'currency' => 'PHP',
            ],
            'payments' => [
                'paymongo_enabled' => false,
                'paymongo_mode' => 'test',
            ],
            'devices' => [
                'attendance_device_name' => '',
                'attendance_device_identifier' => '',
                'attendance_device_enabled' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'general.resort_name' => ['required', 'string', 'max:160'],
            'general.resort_name_font' => ['required', Rule::in([
                'default',
                'Times New Roman',
                'Georgia',
                'Arial',
                'Verdana',
                'Trebuchet MS',
                'Courier New',
                'Garamond',
            ])],
            'general.contact_number' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s().-]{7,20}$/'],
            'general.email' => ['nullable', 'email', 'max:255'],
            'general.address' => ['nullable', 'string', 'max:500'],
            'general.short_description' => ['nullable', 'string', 'max:1000'],
            'general.logo_path' => ['nullable', 'string', 'max:2048'],
            'branding.logo_path' => ['nullable', 'string', 'max:2048'],
            'branding.favicon_path' => ['nullable', 'string', 'max:2048'],
            'branding.homepage_hero_image_path' => ['nullable', 'string', 'max:2048'],
            'branding.hero_animation_enabled' => ['sometimes', 'boolean'],
            'branding.hero_image_1_path' => ['nullable', 'string', 'max:2048'],
            'branding.hero_image_2_path' => ['nullable', 'string', 'max:2048'],
            'branding.hero_image_3_path' => ['nullable', 'string', 'max:2048'],
            'branding.hero_image_4_path' => ['nullable', 'string', 'max:2048'],
            'branding.hero_image_5_path' => ['nullable', 'string', 'max:2048'],
            'branding.hero_image_6_path' => ['nullable', 'string', 'max:2048'],
            'branding.logo_file' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'branding.favicon_file' => ['sometimes', 'nullable', 'image', 'mimes:png,ico,jpg,jpeg,webp', 'max:1024'],
            'branding.homepage_hero_image_file' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'branding.hero_image_1_file' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'branding.hero_image_2_file' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'branding.hero_image_3_file' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'branding.hero_image_4_file' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'branding.hero_image_5_file' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'branding.hero_image_6_file' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'branding.remove_logo' => ['sometimes', 'boolean'],
            'branding.remove_favicon' => ['sometimes', 'boolean'],
            'branding.remove_homepage_hero_image' => ['sometimes', 'boolean'],
            'branding.remove_hero_image_1' => ['sometimes', 'boolean'],
            'branding.remove_hero_image_2' => ['sometimes', 'boolean'],
            'branding.remove_hero_image_3' => ['sometimes', 'boolean'],
            'branding.remove_hero_image_4' => ['sometimes', 'boolean'],
            'branding.remove_hero_image_5' => ['sometimes', 'boolean'],
            'branding.remove_hero_image_6' => ['sometimes', 'boolean'],
            'about.title' => ['required', 'string', 'max:160'],
            'about.short_introduction' => ['nullable', 'string', 'max:500'],
            'about.full_description' => ['nullable', 'string', 'max:5000'],
            'about.mission' => ['nullable', 'string', 'max:2000'],
            'about.vision' => ['nullable', 'string', 'max:2000'],
            'about.image_path' => ['nullable', 'string', 'max:2048'],
            'about.about_image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'about.remove_image' => ['sometimes', 'boolean'],
            'contact.resort_address' => ['nullable', 'string', 'max:500'],
            'contact.primary_phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s().-]{7,20}$/'],
            'contact.secondary_phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s().-]{7,20}$/'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'contact.facebook_url' => ['nullable', 'url', 'max:2048'],
            'contact.google_maps_url' => ['nullable', 'url', 'max:2048'],
            'contact.business_hours' => ['nullable', 'string', 'max:1000'],
            'booking.default_check_in_time' => ['required', 'date_format:H:i'],
            'booking.default_check_out_time' => ['required', 'date_format:H:i'],
            'booking.house_rules_check_in_time' => ['required', 'date_format:H:i'],
            'booking.house_rules_check_out_time' => ['required', 'date_format:H:i'],
            'booking.house_rules_quiet_hours_start' => ['required', 'date_format:H:i'],
            'booking.house_rules_quiet_hours_end' => ['required', 'date_format:H:i'],
            'booking.house_rules_smoking_policy' => ['required', 'string', 'max:255'],
            'booking.house_rules_capacity_rule' => ['required', 'string', 'max:255'],
            'booking.house_rules_pool_safety_rule' => ['required', 'string', 'max:255'],
            'booking.house_rules_cleanliness_rule' => ['required', 'string', 'max:255'],
            'booking.house_rules_damage_rule' => ['required', 'string', 'max:255'],
            'booking.max_advance_booking_days' => ['required', 'integer', 'min:1', 'max:730'],
            'booking.minimum_booking_notice_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'booking.cancellation_cutoff_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'booking.guest_cancellation_enabled' => ['required', 'boolean'],
            'attendance.standard_work_start_time' => ['required', 'date_format:H:i'],
            'attendance.standard_work_end_time' => ['required', 'date_format:H:i', 'after:attendance.standard_work_start_time'],
            'attendance.late_grace_period_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'notifications.booking_confirmation_email' => ['required', 'boolean'],
            'notifications.booking_cancellation_email' => ['required', 'boolean'],
            'notifications.announcement_notifications' => ['required', 'boolean'],
            'notifications.low_priority_operational_notifications' => ['required', 'boolean'],
            'regional.timezone' => ['required', Rule::in(timezone_identifiers_list())],
            'regional.date_format' => ['required', Rule::in(['Y-m-d', 'm/d/Y', 'd/m/Y', 'M d, Y'])],
            'regional.time_format' => ['required', Rule::in(['H:i', 'h:i A'])],
            'regional.currency' => ['required', Rule::in(['PHP', 'USD'])],
            'payments.paymongo_enabled' => ['required', 'boolean'],
            'payments.paymongo_mode' => ['required', Rule::in(['test', 'live'])],
            'devices.attendance_device_name' => ['nullable', 'string', 'max:160'],
            'devices.attendance_device_identifier' => ['nullable', 'string', 'max:160'],
            'devices.attendance_device_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn (): array => $this->buildNormalizedSettings(),
        );
    }

    /**
     * Clear the normalized settings cache after a settings write.
     */
    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildNormalizedSettings(): array
    {
        $settings = $this->defaults();

        foreach (SystemSetting::all() as $setting) {
            data_set($settings, $setting->key, $setting->value);
        }

        $settings['payments']['paymongo_public_key_configured'] = (bool) config('services.paymongo.public_key');
        $settings['payments']['paymongo_secret_key_configured'] = (bool) config('services.paymongo.secret_key');
        $settings['payments']['payment_processing_implemented'] = false;
        $settings['notifications']['email_delivery_implemented'] = false;
        $settings['about']['image_url'] = $this->publicUrl($settings['about']['image_path'] ?? null);
        $settings['branding']['logo_url'] = $this->publicUrl($settings['branding']['logo_path'] ?? null);
        $settings['branding']['favicon_url'] = $this->publicUrl($settings['branding']['favicon_path'] ?? null);
        $settings['branding']['homepage_hero_image_url'] = $this->publicUrl($settings['branding']['homepage_hero_image_path'] ?? null);
        if (! $settings['branding']['hero_image_1_path']) {
            $settings['branding']['hero_image_1_path'] = $settings['branding']['homepage_hero_image_path'];
        }
        for ($slot = 1; $slot <= 6; $slot++) {
            $pathKey = "hero_image_{$slot}_path";
            $settings['branding']["hero_image_{$slot}_url"] = $this->publicUrl($settings['branding'][$pathKey] ?? null);
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function persist(array $validated): void
    {
        foreach ($this->flatten($validated) as $key => $value) {
            if (in_array($key, $this->transientKeys(), true)) {
                continue;
            }

            SystemSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $this->forgetCache();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function unknownKeys(array $payload): array
    {
        $allowed = array_merge(array_keys($this->flatten($this->defaults())), $this->transientKeys());

        return collect(array_keys($this->flatten($payload)))
            ->reject(fn (string $key) => in_array($key, $allowed, true))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && ! array_is_list($value)) {
                $flat += $this->flatten($value, $path);
                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    /**
     * @return list<string>
     */
    public function transientKeys(): array
    {
        return [
            'branding.logo_file',
            'branding.favicon_file',
            'branding.homepage_hero_image_file',
            'branding.remove_logo',
            'branding.remove_favicon',
            'branding.remove_homepage_hero_image',
            'branding.hero_image_1_file',
            'branding.hero_image_2_file',
            'branding.hero_image_3_file',
            'branding.hero_image_4_file',
            'branding.hero_image_5_file',
            'branding.hero_image_6_file',
            'branding.remove_hero_image_1',
            'branding.remove_hero_image_2',
            'branding.remove_hero_image_3',
            'branding.remove_hero_image_4',
            'branding.remove_hero_image_5',
            'branding.remove_hero_image_6',
            'about.about_image',
            'about.remove_image',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicWebsiteSettings(): array
    {
        $settings = $this->get();

        return [
            'general' => [
                'resort_name' => $settings['general']['resort_name'],
                'short_description' => $settings['general']['short_description'],
                'resort_name_font' => $settings['general']['resort_name_font'],
            ],
            'booking' => [
                'default_check_in_time' => $settings['booking']['default_check_in_time'],
                'default_check_out_time' => $settings['booking']['default_check_out_time'],
                'house_rules_check_in_time' => $settings['booking']['house_rules_check_in_time'],
                'house_rules_check_out_time' => $settings['booking']['house_rules_check_out_time'],
                'house_rules_quiet_hours_start' => $settings['booking']['house_rules_quiet_hours_start'],
                'house_rules_quiet_hours_end' => $settings['booking']['house_rules_quiet_hours_end'],
                'house_rules_smoking_policy' => $settings['booking']['house_rules_smoking_policy'],
                'house_rules_capacity_rule' => $settings['booking']['house_rules_capacity_rule'],
                'house_rules_pool_safety_rule' => $settings['booking']['house_rules_pool_safety_rule'],
                'house_rules_cleanliness_rule' => $settings['booking']['house_rules_cleanliness_rule'],
                'house_rules_damage_rule' => $settings['booking']['house_rules_damage_rule'],
            ],
            'branding' => [
                'logo_url' => $settings['branding']['logo_url'],
                'favicon_url' => $settings['branding']['favicon_url'],
                'homepage_hero_image_url' => $settings['branding']['homepage_hero_image_url'],
                'hero_animation_enabled' => (bool) $settings['branding']['hero_animation_enabled'],
                'hero_image_1_url' => $settings['branding']['hero_image_1_url'],
                'hero_image_2_url' => $settings['branding']['hero_image_2_url'],
                'hero_image_3_url' => $settings['branding']['hero_image_3_url'],
                'hero_image_4_url' => $settings['branding']['hero_image_4_url'],
                'hero_image_5_url' => $settings['branding']['hero_image_5_url'],
                'hero_image_6_url' => $settings['branding']['hero_image_6_url'],
            ],
            'about' => [
                'title' => $settings['about']['title'],
                'short_introduction' => $settings['about']['short_introduction'],
                'full_description' => $settings['about']['full_description'],
                'mission' => $settings['about']['mission'],
                'vision' => $settings['about']['vision'],
                'image_url' => $settings['about']['image_url'],
            ],
            'contact' => $settings['contact'],
        ];
    }

    public function publicUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array<string, mixed>>
     */
    public function changes(array $before, array $after): array
    {
        $beforeFlat = $this->flatten($before);
        $afterFlat = $this->flatten($after);
        $changes = [];

        foreach ($afterFlat as $key => $value) {
            if (str_contains($key, 'secret') || ! array_key_exists($key, $beforeFlat) || $beforeFlat[$key] === $value) {
                continue;
            }

            $changes[$key] = [
                'before' => $beforeFlat[$key],
                'after' => $value,
            ];
        }

        return $changes;
    }
}
