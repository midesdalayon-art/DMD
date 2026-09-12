<?php

namespace Tests\Feature\Chatbot;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;

class ChatbotSecurityTest extends ChatbotTestCase
{
    public function test_logged_in_customer_conversation_is_owned_by_customer(): void
    {
        $customer = $this->guestUser();
        $conversation = $this->createConversationFor($customer);
        $this->addMessages($conversation, [
            ['sender_type' => 'customer', 'sender_user_id' => $customer->id, 'message' => 'Hello'],
        ]);

        $this->actingAs($customer)
            ->getJson("/api/chat/conversations/{$conversation->conversation_uuid}")
            ->assertOk()
            ->assertJsonPath('data.conversation.conversation_uuid', $conversation->conversation_uuid);
    }

    public function test_customer_cannot_access_another_customers_conversation(): void
    {
        $customerA = $this->guestUser();
        $customerB = $this->guestUser();
        $conversation = $this->createConversationFor($customerA);

        $this->actingAs($customerB)
            ->getJson("/api/chat/conversations/{$conversation->conversation_uuid}")
            ->assertForbidden();
    }

    public function test_housekeeping_cannot_access_staff_support_or_admin_chatbot(): void
    {
        $housekeeping = $this->housekeepingUser();

        $this->actingAs($housekeeping)
            ->getJson('/api/frontdesk/support/conversations')
            ->assertForbidden();

        $this->actingAs($housekeeping)
            ->getJson('/api/admin/chatbot/rules')
            ->assertForbidden();
    }

    public function test_customer_cannot_access_staff_support_or_admin_chatbot(): void
    {
        $customer = $this->guestUser();

        $this->actingAs($customer)
            ->getJson('/api/admin/chatbot/rules')
            ->assertForbidden();

        $this->actingAs($customer)
            ->getJson('/api/frontdesk/support/conversations')
            ->assertForbidden();

        $this->actingAs($customer)
            ->getJson('/api/manager/support/conversations')
            ->assertForbidden();
    }

    public function test_public_message_validation_and_xss_payload_are_handled_safely(): void
    {
        $response = $this->postJson('/api/chat/conversations', [
            'message' => str_repeat('a', 1001),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('message');

        $response = $this->postJson('/api/chat/conversations', [
            'message' => "<script>alert('test')</script>",
        ])->assertCreated();

        $this->assertStringNotContainsString('<script>', (string) $response->json('data.messages.0.message'));
        $this->assertStringNotContainsString('<script>', (string) $response->json('data.messages.1.message'));
    }

    public function test_one_anonymous_conversation_cannot_access_another_anonymous_conversation(): void
    {
        $first = $this->postJson('/api/chat/conversations', [
            'message' => 'Hello',
        ])->assertCreated();
        $second = $this->postJson('/api/chat/conversations', [
            'message' => 'Hello again',
        ])->assertCreated();

        $firstUuid = $first->json('data.conversation.conversation_uuid');
        $firstToken = ChatConversation::where('conversation_uuid', $firstUuid)->value('access_token');
        $secondUuid = $second->json('data.conversation.conversation_uuid');

        $this->getJson("/api/chat/conversations/{$secondUuid}", [
            'X-Conversation-Token' => $firstToken,
        ])->assertUnauthorized();
    }

    public function test_resolved_conversation_remains_in_history_and_is_not_claimable(): void
    {
        $staff = $this->frontDeskUser();
        $customer = $this->guestUser();
        $conversation = $this->createConversationFor($customer, ChatConversation::STATUS_RESOLVED);

        $this->actingAs($staff)
            ->postJson("/api/frontdesk/support/conversations/{$conversation->id}/claim")
            ->assertUnprocessable();

        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversation->id,
            'status' => ChatConversation::STATUS_RESOLVED,
        ]);
    }
}
