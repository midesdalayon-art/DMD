<?php

namespace Tests\Feature\Chatbot;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;

class PublicChatbotTest extends ChatbotTestCase
{
    public function test_anonymous_visitor_can_create_conversation_and_receive_bot_response(): void
    {
        $category = $this->createCategory(['slug' => 'booking']);
        $rule = $this->createRule($category, [
            'question' => 'How do I book?',
            'answer' => 'Use the booking page to reserve your stay.',
            'keywords' => ['book', 'booking', 'reservation'],
            'priority' => 50,
        ]);

        $startResponse = $this->postJson('/api/chat/conversations', [
            'message' => 'How do I book?',
        ])->assertCreated();

        $conversationUuid = $startResponse->json('data.conversation.conversation_uuid');
        $token = $startResponse->json('data.conversation.access_token');

        $startResponse->assertJsonPath('data.conversation.status', ChatConversation::STATUS_BOT)
            ->assertJsonPath('data.conversation.access_token', $token)
            ->assertJsonPath('data.messages.0.sender_type', 'guest')
            ->assertJsonPath('data.messages.1.sender_type', 'bot')
            ->assertJsonPath('data.messages.1.chatbot_rule_id', $rule->id);

        $this->assertNotEmpty($token);
        $conversation = ChatConversation::where('conversation_uuid', $conversationUuid)->firstOrFail();
        $this->assertNull($conversation->access_token);
        $this->assertSame(hash('sha256', $token), $conversation->access_token_hash);
        $this->assertNotNull($conversation->access_token_expires_at);

        $this->getJson("/api/chat/conversations/{$conversationUuid}", [
            'X-Conversation-Token' => $token,
        ])
            ->assertOk()
            ->assertJsonPath('data.conversation.conversation_uuid', $conversationUuid);

        $this->postJson("/api/chat/conversations/{$conversationUuid}/messages", [
            'message' => 'What time is check-in?',
        ], [
            'X-Conversation-Token' => $token,
        ])
            ->assertOk()
            ->assertJsonPath('data.messages.2.sender_type', 'guest')
            ->assertJsonPath('data.messages.3.sender_type', 'bot');

        $this->postJson("/api/chat/conversations/{$conversationUuid}/escalate", [], [
            'X-Conversation-Token' => $token,
        ])
            ->assertOk()
            ->assertJsonPath('data.conversation.status', ChatConversation::STATUS_WAITING);

        $this->assertDatabaseHas('chat_conversations', [
            'conversation_uuid' => $conversationUuid,
            'status' => ChatConversation::STATUS_WAITING,
        ]);

        $this->assertGreaterThanOrEqual(1, ChatMessage::where('conversation_id', ChatConversation::where('conversation_uuid', $conversationUuid)->value('id'))->count());
    }

    public function test_public_conversation_token_can_be_reused_for_follow_up_messages(): void
    {
        $startResponse = $this->postJson('/api/chat/conversations', [
            'message' => 'Rooms & Cottages',
        ])->assertCreated();

        $conversationUuid = $startResponse->json('data.conversation.conversation_uuid');
        $token = $startResponse->json('data.conversation.access_token');

        $this->assertNotEmpty($token);

        $this->postJson("/api/chat/conversations/{$conversationUuid}/messages", [
            'message' => 'Check Availability',
        ], [
            'X-Conversation-Token' => $token,
        ])
            ->assertOk()
            ->assertJsonPath('data.conversation.conversation_uuid', $conversationUuid);

        $this->postJson("/api/chat/conversations/{$conversationUuid}/escalate", [], [
            'X-Conversation-Token' => $token,
        ])
            ->assertOk()
            ->assertJsonPath('data.conversation.status', ChatConversation::STATUS_WAITING);
    }

    public function test_repeated_messages_continue_returning_bot_replies_in_bot_mode(): void
    {
        $category = $this->createCategory(['slug' => 'support']);
        $this->createRule($category, [
            'question' => 'Rooms & Cottages',
            'answer' => 'You can browse rooms and cottages on the accommodations page.',
            'keywords' => ['rooms', 'cottages'],
            'priority' => 80,
        ]);

        $startResponse = $this->postJson('/api/chat/conversations', [
            'message' => 'Rooms & Cottages',
        ])->assertCreated();

        $conversationUuid = $startResponse->json('data.conversation.conversation_uuid');
        $token = $startResponse->json('data.conversation.access_token');

        foreach (['Check Availability', 'Booking Help', 'Payments', 'hello', 'poop'] as $message) {
            $response = $this->postJson("/api/chat/conversations/{$conversationUuid}/messages", [
                'message' => $message,
            ], [
                'X-Conversation-Token' => $token,
            ])->assertOk();

            $response->assertJsonPath('data.conversation.status', ChatConversation::STATUS_BOT);
            $messages = $response->json('data.messages');
            $this->assertNotEmpty($messages);
            $this->assertSame('bot', $messages[count($messages) - 1]['sender_type']);
        }
    }

    public function test_anonymous_conversation_cannot_be_accessed_with_wrong_token(): void
    {
        $response = $this->postJson('/api/chat/conversations', [
            'message' => 'Where is the resort?',
        ])->assertCreated();

        $conversationUuid = $response->json('data.conversation.conversation_uuid');

        $this->getJson("/api/chat/conversations/{$conversationUuid}", [
            'X-Conversation-Token' => 'wrong-token',
        ])->assertUnauthorized();
    }

    public function test_unknown_question_returns_fallback_response(): void
    {
        $response = $this->postJson('/api/chat/conversations', [
            'message' => 'What is your quantum breakfast policy?',
        ])->assertCreated();

        $fallback = $response->json('data.messages.1.message');
        $fallbackResponses = app(\App\Services\ChatbotService::class)->fallbackResponses();
        $this->assertTrue(
            in_array($fallback, $fallbackResponses, true)
            || str_contains(strtolower($fallback), 'staff')
        );
    }

    public function test_expired_or_revoked_chat_token_is_rejected(): void
    {
        $startResponse = $this->postJson('/api/chat/conversations', ['message' => 'Hello'])->assertCreated();
        $conversationUuid = $startResponse->json('data.conversation.conversation_uuid');
        $token = $startResponse->json('data.conversation.access_token');

        ChatConversation::where('conversation_uuid', $conversationUuid)->update([
            'access_token_expires_at' => now()->subSecond(),
        ]);
        $this->getJson("/api/chat/conversations/{$conversationUuid}", ['X-Conversation-Token' => $token])->assertUnauthorized();

        ChatConversation::where('conversation_uuid', $conversationUuid)->update([
            'access_token_expires_at' => now()->addDay(),
            'access_token_revoked_at' => now(),
        ]);
        $this->getJson("/api/chat/conversations/{$conversationUuid}", ['X-Conversation-Token' => $token])->assertUnauthorized();
    }
}
