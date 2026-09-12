<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_first_settings_load_builds_and_caches_normalized_settings(): void
    {
        $settings = app(\App\Services\SystemSettings::class);
        $result = $settings->get();

        $this->assertSame('DMD Resort', $result['general']['resort_name']);
        $this->assertTrue(Cache::has('system_settings.normalized'));
    }

    public function test_subsequent_settings_load_uses_cached_normalized_settings(): void
    {
        $settings = app(\App\Services\SystemSettings::class);
        $settings->get();

        SystemSetting::updateOrCreate(['key' => 'general.resort_name'], ['value' => 'Changed Without Invalidation']);

        $this->assertSame('DMD Resort', $settings->get()['general']['resort_name']);
    }

    public function test_settings_update_invalidates_normalized_settings_cache(): void
    {
        $settings = app(\App\Services\SystemSettings::class);
        $settings->get();

        $settings->persist(['general' => array_replace($this->validPayload()['general'], [
            'resort_name' => 'Updated Resort',
        ])]);

        $this->assertSame('Updated Resort', $settings->get()['general']['resort_name']);
    }

    public function test_admin_can_view_settings(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.general.resort_name', 'DMD Resort')
            ->assertJsonPath('data.general.resort_name_font', 'default')
            ->assertJsonPath('data.booking.house_rules_check_in_time', '14:00')
            ->assertJsonPath('data.regional.timezone', 'Asia/Manila');
    }

    public function test_admin_can_update_settings(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->putJson('/api/admin/settings', array_replace_recursive($this->validPayload(), [
                'general' => ['resort_name' => 'DMD Resort Updated'],
                'booking' => ['cancellation_cutoff_hours' => 48],
            ]))
            ->assertOk()
            ->assertJsonPath('data.general.resort_name', 'DMD Resort Updated')
            ->assertJsonPath('data.booking.cancellation_cutoff_hours', 48);
    }

    public function test_non_admin_cannot_access_settings(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);

        $this->actingAs($guest)
            ->getJson('/api/admin/settings')
            ->assertForbidden();
    }

    public function test_invalid_values_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->putJson('/api/admin/settings', array_replace_recursive($this->validPayload(), [
                'attendance' => [
                    'standard_work_start_time' => '17:00',
                    'standard_work_end_time' => '08:00',
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attendance.standard_work_end_time');
    }

    public function test_unknown_arbitrary_setting_keys_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $payload = $this->validPayload();
        $payload['payments']['paymongo_secret_key'] = 'sk_test_secret';

        $this->actingAs($admin)
            ->putJson('/api/admin/settings', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('settings');
    }

    public function test_settings_persist_correctly(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->putJson('/api/admin/settings', array_replace_recursive($this->validPayload(), [
                'general' => ['resort_name_font' => 'Georgia'],
                'booking' => [
                    'house_rules_quiet_hours_start' => '09:30',
                    'house_rules_smoking_policy' => 'No smoking indoors.',
                ],
                'devices' => ['attendance_device_enabled' => true],
            ]))
            ->assertOk();

        $this->assertDatabaseHas('system_settings', [
            'key' => 'general.resort_name_font',
        ]);
        $this->assertSame('Georgia', SystemSetting::where('key', 'general.resort_name_font')->firstOrFail()->value);
        $this->assertSame('09:30', SystemSetting::where('key', 'booking.house_rules_quiet_hours_start')->firstOrFail()->value);
        $this->assertDatabaseHas('system_settings', [
            'key' => 'devices.attendance_device_enabled',
        ]);
        $this->assertTrue(SystemSetting::where('key', 'devices.attendance_device_enabled')->firstOrFail()->value);
    }

    public function test_sensitive_configuration_is_never_returned(): void
    {
        config()->set('services.paymongo.secret_key', 'sk_test_sensitive');
        config()->set('services.paymongo.public_key', 'pk_test_public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.payments.paymongo_secret_key_configured', true)
            ->assertJsonPath('data.payments.paymongo_public_key_configured', true)
            ->assertJsonMissing(['sk_test_sensitive']);
    }

    public function test_settings_changes_create_safe_audit_records(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->putJson('/api/admin/settings', array_replace_recursive($this->validPayload(), [
                'booking' => ['cancellation_cutoff_hours' => 48],
            ]))
            ->assertOk();

        $log = AuditLog::where('module', 'settings')->where('action', 'settings_updated')->firstOrFail();

        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(24, $log->metadata['changes']['booking.cancellation_cutoff_hours']['before']);
        $this->assertSame(48, $log->metadata['changes']['booking.cancellation_cutoff_hours']['after']);
        $this->assertStringNotContainsString('secret', strtolower(json_encode($log->metadata)));
    }

    public function test_admin_can_update_about_us_settings(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->putJson('/api/admin/settings', array_replace_recursive($this->validPayload(), [
                'about' => [
                    'title' => 'About DMD Resort',
                    'short_introduction' => 'A relaxing destination for families.',
                    'full_description' => 'Full verified resort description.',
                    'mission' => 'Serve guests well.',
                    'vision' => 'Be a trusted local resort.',
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('data.about.title', 'About DMD Resort')
            ->assertJsonPath('data.about.mission', 'Serve guests well.');

        $this->assertDatabaseHas('system_settings', [
            'key' => 'about.full_description',
        ]);
    }

    public function test_admin_can_update_contact_information_settings(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->putJson('/api/admin/settings', array_replace_recursive($this->validPayload(), [
                'contact' => [
                    'resort_address' => 'Verified Resort Address',
                    'primary_phone' => '09171234567',
                    'secondary_phone' => '09170000000',
                    'email' => 'contact@dmdresort.test',
                    'facebook_url' => 'https://facebook.com/dmdresort',
                    'google_maps_url' => 'https://maps.google.com/?q=DMD+Resort',
                    'business_hours' => 'Daily, 8:00 AM - 8:00 PM',
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('data.contact.resort_address', 'Verified Resort Address')
            ->assertJsonPath('data.contact.email', 'contact@dmdresort.test');
    }

    public function test_public_endpoint_returns_only_public_website_settings(): void
    {
        SystemSetting::updateOrCreate(['key' => 'about.title'], ['value' => 'Public About Title']);
        SystemSetting::updateOrCreate(['key' => 'branding.logo_path'], ['value' => 'branding/logo.png']);
        SystemSetting::updateOrCreate(['key' => 'branding.homepage_hero_image_path'], ['value' => 'branding/hero.jpg']);
        SystemSetting::updateOrCreate(['key' => 'payments.paymongo_enabled'], ['value' => true]);

        $this->getJson('/api/public-settings')
            ->assertOk()
            ->assertJsonPath('data.about.title', 'Public About Title')
            ->assertJsonPath('data.branding.logo_url', 'http://localhost:8000/storage/branding/logo.png')
            ->assertJsonPath('data.branding.homepage_hero_image_url', 'http://localhost:8000/storage/branding/hero.jpg')
            ->assertJsonPath('data.booking.house_rules_quiet_hours_start', '22:00')
            ->assertJsonMissingPath('data.payments')
            ->assertJsonMissingPath('data.notifications');
    }

    public function test_about_us_image_upload_validation(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/settings', array_replace_recursive($this->validPayload(), [
                '_method' => 'PUT',
                'about' => [
                    'about_image' => UploadedFile::fake()->create('not-image.pdf', 20, 'application/pdf'),
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('about.about_image');

        $response = $this->actingAs($admin)
            ->post('/api/admin/settings', array_replace_recursive($this->validPayload(), [
                '_method' => 'PUT',
                'about' => [
                    'about_image' => UploadedFile::fake()->image('about.jpg'),
                ],
            ]))
            ->assertOk();

        $this->assertStringStartsWith('settings/about/', $response->json('data.about.image_path'));
        $this->assertStringContainsString('/storage/settings/about/', $response->json('data.about.image_url'));
    }

    public function test_branding_logo_and_favicon_upload_validation(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/settings', array_replace_recursive($this->validPayload(), [
                '_method' => 'PUT',
                'branding' => [
                    'logo_file' => UploadedFile::fake()->create('logo.pdf', 20, 'application/pdf'),
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branding.logo_file');

        $response = $this->actingAs($admin)
            ->post('/api/admin/settings', array_replace_recursive($this->validPayload(), [
                '_method' => 'PUT',
                'branding' => [
                    'logo_file' => UploadedFile::fake()->image('logo.png'),
                    'favicon_file' => UploadedFile::fake()->image('favicon.png'),
                    'homepage_hero_image_file' => UploadedFile::fake()->image('hero.png'),
                ],
            ]))
            ->assertOk();

        $this->assertStringStartsWith('branding/', $response->json('data.branding.logo_path'));
        $this->assertStringStartsWith('branding/', $response->json('data.branding.favicon_path'));
        $this->assertStringStartsWith('branding/', $response->json('data.branding.homepage_hero_image_path'));
        $this->assertStringContainsString('/storage/branding/', $response->json('data.branding.logo_url'));
        $this->assertStringContainsString('/storage/branding/', $response->json('data.branding.favicon_url'));
        $this->assertStringContainsString('/storage/branding/', $response->json('data.branding.homepage_hero_image_url'));
    }

    public function test_admin_can_upload_hero_slideshow_slots_and_toggle_animation(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $branding = ['hero_animation_enabled' => true];
        for ($slot = 1; $slot <= 6; $slot++) {
            $branding["hero_image_{$slot}_file"] = UploadedFile::fake()->image("hero-{$slot}.png");
        }

        $response = $this->actingAs($admin)
            ->post('/api/admin/settings', array_replace_recursive($this->validPayload(), [
                '_method' => 'PUT',
                'branding' => $branding,
            ]))
            ->assertOk();

        $response->assertJsonPath('data.branding.hero_animation_enabled', true);
        for ($slot = 1; $slot <= 6; $slot++) {
            $this->assertStringContainsString('/storage/branding/', $response->json("data.branding.hero_image_{$slot}_url"));
        }
    }

    public function test_admin_can_replace_and_remove_individual_hero_slots(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $initial = $this->actingAs($admin)
            ->post('/api/admin/settings', array_replace_recursive($this->validPayload(), [
                '_method' => 'PUT',
                'branding' => [
                    'hero_image_1_file' => UploadedFile::fake()->image('first.png'),
                    'hero_image_2_file' => UploadedFile::fake()->image('second.png'),
                ],
            ]))
            ->assertOk();

        $oldFirstPath = $initial->json('data.branding.hero_image_1_path');
        $secondPath = $initial->json('data.branding.hero_image_2_path');

        $replacement = $this->actingAs($admin)
            ->post('/api/admin/settings', array_replace_recursive($this->validPayload(), [
                '_method' => 'PUT',
                'branding' => [
                    'hero_image_1_file' => UploadedFile::fake()->image('replacement.png'),
                    'hero_image_2_path' => $secondPath,
                    'remove_hero_image_2' => true,
                ],
            ]))
            ->assertOk();

        $this->assertNotSame($oldFirstPath, $replacement->json('data.branding.hero_image_1_path'));
        $replacement->assertJsonPath('data.branding.hero_image_2_path', null);
        Storage::disk('public')->assertMissing($oldFirstPath);
        Storage::disk('public')->assertMissing($secondPath);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'general' => [
                'resort_name' => 'DMD Resort',
                'resort_name_font' => 'default',
                'contact_number' => '09171234567',
                'email' => 'info@dmdresort.test',
                'address' => 'DMD Resort Address',
                'short_description' => 'Resort configuration for DMD Resort.',
                'logo_path' => null,
            ],
            'branding' => [
                'logo_path' => null,
                'favicon_path' => null,
                'homepage_hero_image_path' => null,
            ],
            'about' => [
                'title' => 'About DMD Resort',
                'short_introduction' => 'A relaxing destination designed for families, friends, and special occasions.',
                'full_description' => 'Verified DMD Resort description.',
                'mission' => 'Provide a calm and reliable guest experience.',
                'vision' => 'Become a trusted resort destination.',
                'image_path' => null,
            ],
            'contact' => [
                'resort_address' => 'DMD Resort Address',
                'primary_phone' => '09171234567',
                'secondary_phone' => '',
                'email' => 'info@dmdresort.test',
                'facebook_url' => 'https://facebook.com/dmdresort',
                'google_maps_url' => 'https://maps.google.com/?q=DMD+Resort',
                'business_hours' => 'Daily',
            ],
            'booking' => [
                'default_check_in_time' => '14:00',
                'default_check_out_time' => '12:00',
                'house_rules_check_in_time' => '14:00',
                'house_rules_check_out_time' => '12:00',
                'house_rules_quiet_hours_start' => '22:00',
                'house_rules_quiet_hours_end' => '07:00',
                'house_rules_smoking_policy' => 'No smoking indoors.',
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
                'attendance_device_name' => 'Front Desk Fingerprint Device',
                'attendance_device_identifier' => 'AS608-DEV-001',
                'attendance_device_enabled' => false,
            ],
        ];
    }
}
