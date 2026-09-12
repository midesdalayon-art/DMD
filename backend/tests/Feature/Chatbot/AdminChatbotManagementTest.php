<?php

namespace Tests\Feature\Chatbot;

class AdminChatbotManagementTest extends ChatbotTestCase
{
    public function test_admin_can_create_edit_disable_and_delete_rules_and_categories(): void
    {
        $admin = $this->adminUser();
        $category = $this->createCategory(['name' => 'Booking', 'slug' => 'booking']);

        $this->actingAs($admin)
            ->postJson('/api/admin/chatbot/categories', [
                'name' => 'Policies',
                'slug' => 'policies',
                'icon' => 'shield',
                'sort_order' => 2,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'policies');

        $this->actingAs($admin)
            ->putJson("/api/admin/chatbot/categories/{$category->id}", [
                'name' => 'Booking Help',
                'slug' => 'booking-help',
                'icon' => 'book',
                'sort_order' => 3,
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'booking-help');

        $this->actingAs($admin)
            ->getJson('/api/admin/chatbot/categories')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'booking-help']);

        $ruleResponse = $this->actingAs($admin)
            ->postJson('/api/admin/chatbot/rules', [
                'category_id' => $category->id,
                'question' => 'How do I book?',
                'answer' => 'Use the booking page.',
                'keywords' => ['book', 'booking'],
                'priority' => 9,
                'is_active' => true,
            ])
            ->assertCreated();

        $ruleId = $ruleResponse->json('data.id');

        $this->actingAs($admin)
            ->putJson("/api/admin/chatbot/rules/{$ruleId}", [
                'category_id' => $category->id,
                'question' => 'How do I reserve?',
                'answer' => 'Use the reservation page.',
                'keywords' => ['reserve', 'reservation'],
                'priority' => 11,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($admin)
            ->getJson('/api/admin/chatbot/rules')
            ->assertOk()
            ->assertJsonFragment(['id' => $ruleId]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/chatbot/rules/{$ruleId}")
            ->assertOk();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/chatbot/categories/{$category->id}")
            ->assertOk();
    }

    public function test_non_admin_cannot_manage_chatbot(): void
    {
        $manager = $this->managerUser();

        $this->actingAs($manager)
            ->getJson('/api/admin/chatbot/rules')
            ->assertForbidden();

        $this->actingAs($manager)
            ->postJson('/api/admin/chatbot/rules', [
                'category_id' => 1,
                'question' => 'Test?',
                'answer' => 'Test',
                'keywords' => ['test'],
            ])
            ->assertForbidden();
    }
}
