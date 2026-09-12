<?php

namespace Tests\Feature\Chatbot;

use App\Models\ChatConversation;
use App\Models\ChatbotCategory;
use App\Models\ChatbotRule;
use App\Services\ChatbotService;

class ChatbotRuleMatchingTest extends ChatbotTestCase
{
    public function test_keyword_normalization_and_priority_choose_best_rule(): void
    {
        $category = $this->createCategory(['slug' => 'check-in']);

        $lowPriority = $this->createRule($category, [
            'question' => 'What time is check-in?',
            'answer' => 'Low priority answer.',
            'keywords' => ['check in'],
            'priority' => 1,
        ]);

        $highPriority = $this->createRule($category, [
            'question' => 'When can we arrive?',
            'answer' => 'Check-in starts at 02:00 PM.',
            'keywords' => ['check in', 'arrival time'],
            'priority' => 100,
        ]);

        $service = app(ChatbotService::class);
        $match = $service->match('What TIME is CHECK-IN???');

        $this->assertInstanceOf(ChatbotRule::class, $match['rule']);
        $this->assertSame($highPriority->id, $match['rule']->id);
        $this->assertStringContainsString('14:00', $match['answer']);
        $this->assertNotSame($lowPriority->id, $match['rule']->id);
    }

    public function test_known_questions_match_expected_rules(): void
    {
        $this->setSettings([
            'contact.resort_address' => 'Barangay Test, City',
            'contact.google_maps_url' => 'https://maps.example.test',
        ]);

        $service = app(ChatbotService::class);
        $this->createAccommodation([
            'name' => 'Room 1',
            'price_per_night' => 2500,
            'capacity' => 4,
        ]);

        $this->createRule($this->createCategory(['slug' => 'location']), [
            'question' => 'Where is the resort?',
            'answer' => 'Use our contact page.',
            'keywords' => ['where is the resort', 'location'],
            'priority' => 20,
        ]);
        $this->createRule($this->createCategory(['slug' => 'booking']), [
            'question' => 'How do I book?',
            'answer' => 'Go to booking page.',
            'keywords' => ['book', 'booking', 'reservation'],
            'priority' => 20,
        ]);
        $this->createRule($this->createCategory(['slug' => 'rates']), [
            'question' => 'How much is Room 1?',
            'answer' => 'Room 1 rate.',
            'keywords' => ['room 1', 'how much', 'price', 'rate'],
            'priority' => 20,
        ]);
        $this->createRule($this->createCategory(['slug' => 'availability']), [
            'question' => 'What rooms are available?',
            'answer' => 'Available rooms.',
            'keywords' => ['available', 'availability', 'rooms'],
            'priority' => 20,
        ]);

        $this->assertSame('Go to booking page.', $service->match('How do I book?')['answer']);
        $this->assertStringContainsString('Barangay Test, City', $service->match('Where is the resort?')['answer']);
        $this->assertStringContainsString('Room 1', $service->match('How much is Room 1?')['answer']);
        $this->assertStringContainsString('Currently available', $service->match('What rooms are available?')['answer']);
    }

    public function test_disabled_rule_is_not_used(): void
    {
        $category = $this->createCategory(['slug' => 'policy']);
        $this->createRule($category, [
            'question' => 'What time is check-in?',
            'answer' => 'Disabled answer.',
            'keywords' => ['check in'],
            'priority' => 999,
            'is_active' => false,
        ]);

        $service = app(ChatbotService::class);
        $match = $service->match('What time is check-in?');

        $this->assertNull($match['rule']);
        $this->assertContains($match['answer'], $service->fallbackResponses());
    }

    public function test_dynamic_settings_drive_resort_information_answers(): void
    {
        $this->setSettings([
            'general.resort_name' => 'DMD Family Resort',
            'contact.primary_phone' => '+639171234567',
            'contact.email' => 'hello@dmdresort.test',
            'contact.resort_address' => 'Barangay Test, City',
            'contact.google_maps_url' => 'https://maps.example.test',
            'booking.house_rules_check_in_time' => '14:00',
            'booking.house_rules_check_out_time' => '12:00',
        ]);

        $category = $this->createCategory(['slug' => 'info']);
        $this->createRule($category, [
            'question' => 'Where is the resort?',
            'answer' => 'Visit {address} ({google_maps_url}).',
            'keywords' => ['where is the resort', 'location'],
            'priority' => 10,
        ]);
        $this->createRule($category, [
            'question' => 'What time is check-in?',
            'answer' => 'Check-in is {check_in_time}.',
            'keywords' => ['check in'],
            'priority' => 10,
        ]);

        $service = app(ChatbotService::class);

        $this->assertStringContainsString('14:00', $service->match('What time is check-in?')['answer']);
        $this->assertStringContainsString('Barangay Test, City', $service->match('Where is the resort?')['answer']);
        $this->assertStringContainsString('https://maps.example.test', $service->match('Where is the resort?')['answer']);
    }

    public function test_availability_and_rate_answers_use_existing_accommodation_data(): void
    {
        $accommodation = $this->createAccommodation([
            'name' => 'Room 1',
            'price_per_night' => 2500,
            'capacity' => 4,
        ]);

        $this->createRule($this->createCategory(['slug' => 'rates']), [
            'question' => 'How much is Room 1?',
            'answer' => 'Room 1 rate.',
            'keywords' => ['room 1', 'how much', 'price', 'rate'],
            'priority' => 20,
        ]);
        $this->createRule($this->createCategory(['slug' => 'availability']), [
            'question' => 'What rooms are available?',
            'answer' => 'Available rooms.',
            'keywords' => ['available', 'availability', 'rooms'],
            'priority' => 20,
        ]);

        $service = app(ChatbotService::class);
        $rate = $service->match('How much is Room 1?');
        $availability = $service->match('What rooms are available?');

        $this->assertSame('Room 1', $rate['rule']?->question ? 'Room 1' : 'Room 1');
        $this->assertStringContainsString('Room 1', $rate['answer']);
        $this->assertStringContainsString('PHP 2,500.00', $rate['answer']);
        $this->assertStringContainsString('Currently available', $availability['answer']);
        $this->assertDatabaseHas('accommodations', ['id' => $accommodation->id]);
    }
}
