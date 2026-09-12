<?php

namespace Tests\Feature\Chatbot;

use App\Models\Accommodation;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatbotCategory;
use App\Models\ChatbotRule;
use App\Models\Reservation;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ChatbotTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createCategory(array $overrides = []): ChatbotCategory
    {
        return ChatbotCategory::create(array_merge([
            'name' => 'Booking',
            'slug' => 'booking',
            'icon' => 'message-circle',
            'sort_order' => 1,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createRule(ChatbotCategory $category, array $overrides = []): ChatbotRule
    {
        return ChatbotRule::create(array_merge([
            'category_id' => $category->id,
            'question' => 'How do I book?',
            'answer' => 'Please use the booking page.',
            'keywords' => ['book', 'booking', 'reservation'],
            'priority' => 10,
            'is_active' => true,
        ], $overrides));
    }

    protected function guestUser(): User
    {
        return User::factory()->create(['role' => User::ROLE_GUEST]);
    }

    protected function adminUser(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    protected function managerUser(): User
    {
        return User::factory()->create(['role' => User::ROLE_MANAGER]);
    }

    protected function frontDeskUser(): User
    {
        return User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
    }

    protected function housekeepingUser(): User
    {
        return User::factory()->create(['role' => User::ROLE_HOUSEKEEPING]);
    }

    protected function createAccommodation(array $overrides = []): Accommodation
    {
        return Accommodation::create(array_merge([
            'name' => 'Room 1',
            'slug' => 'room-1-'.Accommodation::count(),
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 2500,
            'description' => 'Test room.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_CLEAN,
            'image_path' => null,
        ], $overrides));
    }

    protected function createReservation(User $user, Accommodation $accommodation, array $overrides = []): Reservation
    {
        return Reservation::create(array_merge([
            'user_id' => $user->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-26',
            'check_out' => '2026-08-28',
            'guests' => 2,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'total_amount' => 5000,
            'status' => Reservation::STATUS_PENDING,
            'booking_reference' => 'DMD-CHAT-'.strtoupper(substr(md5((string) Reservation::count()), 0, 6)),
        ], $overrides));
    }

    protected function setSettings(array $values): void
    {
        foreach ($values as $key => $value) {
            SystemSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return string
     */
    protected function signWebhook(array $payload): string
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = '1724551200';
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test_secret');

        return "t={$timestamp},te={$signature}";
    }

    protected function createConversationFor(User $user, ?string $status = null): ChatConversation
    {
        return ChatConversation::create([
            'conversation_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'access_token' => \Illuminate\Support\Str::random(64),
            'customer_id' => $user->id,
            'guest_name' => $user->name,
            'guest_email' => $user->email,
            'status' => $status ?? ChatConversation::STATUS_BOT,
            'last_message_at' => now(),
        ]);
    }

    protected function addMessages(ChatConversation $conversation, array $messages): void
    {
        foreach ($messages as $message) {
            ChatMessage::create(array_merge([
                'conversation_id' => $conversation->id,
                'sender_type' => 'guest',
                'message_type' => 'text',
                'is_read' => true,
            ], $message));
        }
    }
}
