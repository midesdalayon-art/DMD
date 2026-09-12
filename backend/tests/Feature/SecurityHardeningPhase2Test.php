<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class SecurityHardeningPhase2Test extends TestCase
{
    use RefreshDatabase;

    public function test_local_frontend_origin_receives_credentialed_cors_headers(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type, X-XSRF-TOKEN',
        ])->options('/api/login');

        $response
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_unapproved_origin_does_not_receive_credentialed_cors_permission(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://attacker.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type',
        ])->options('/api/login');

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_production_style_api_exception_does_not_expose_sensitive_details(): void
    {
        Route::get('/api/security-hardening-phase2-exception', function () {
            throw new RuntimeException('secret database path and SQL should not leak');
        });

        config()->set('app.debug', false);

        $response = $this->getJson('/api/security-hardening-phase2-exception');

        $response->assertStatus(500);
        $this->assertStringNotContainsString('secret database path', $response->getContent());
        $this->assertStringNotContainsString('SQL should not leak', $response->getContent());
        $this->assertStringNotContainsString('RuntimeException', $response->getContent());
    }
}
