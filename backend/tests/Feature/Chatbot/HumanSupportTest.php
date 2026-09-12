<?php

namespace Tests\Feature\Chatbot;

use App\Models\ChatConversation;
use App\Models\User;

class HumanSupportTest extends ChatbotTestCase
{
    public function test_anonymous_escalation_supports_staff_reply_and_public_follow_up_by_conversation_uuid(): void
    {
        $start = $this->postJson('/api/chat/conversations', [
            'message' => 'I need help with my visit.',
        ])->assertCreated();

        $conversationUuid = $start->json('data.conversation.conversation_uuid');
        $token = $start->json('data.conversation.access_token');

        $this->postJson("/api/chat/conversations/{$conversationUuid}/escalate", [], [
            'X-Conversation-Token' => $token,
        ])->assertOk()->assertJsonPath('data.conversation.status', ChatConversation::STATUS_WAITING);

        $staff = $this->frontDeskUser();

        $this->actingAs($staff)
            ->getJson('/api/frontdesk/support/conversations?status=waiting')
            ->assertOk()
            ->assertJsonFragment(['conversation_uuid' => $conversationUuid]);

        $this->actingAs($staff)
            ->getJson("/api/frontdesk/support/conversations/{$conversationUuid}")
            ->assertOk()
            ->assertJsonPath('data.conversation.conversation_uuid', $conversationUuid);

        $this->actingAs($staff)
            ->postJson("/api/frontdesk/support/conversations/{$conversationUuid}/messages", [
                'message' => 'Hello, how can I help you?',
            ])
            ->assertOk()
            ->assertJsonFragment(['sender_type' => 'staff', 'message' => 'Hello, how can I help you?']);

        $this->getJson("/api/chat/conversations/{$conversationUuid}", [
            'X-Conversation-Token' => $token,
        ])
            ->assertOk()
            ->assertJsonFragment(['message' => 'Hello, how can I help you?'])
            ->assertJsonPath('data.conversation.status', ChatConversation::STATUS_ACTIVE);

        $this->postJson("/api/chat/conversations/{$conversationUuid}/messages", [
            'message' => 'I have another question.',
        ], [
            'X-Conversation-Token' => $token,
        ])->assertOk()->assertJsonFragment(['sender_type' => 'guest', 'message' => 'I have another question.']);

        $this->actingAs($staff)
            ->getJson("/api/frontdesk/support/conversations/{$conversationUuid}")
            ->assertOk()
            ->assertJsonFragment(['message' => 'I have another question.']);
    }

    public function test_guest_can_escalate_conversation_to_human_support(): void
    {
        $response = $this->postJson('/api/chat/conversations', [
            'message' => 'Talk to staff',
        ])->assertCreated();

        $conversationUuid = $response->json('data.conversation.conversation_uuid');
        $token = $response->json('data.conversation.access_token');

        $this->postJson("/api/chat/conversations/{$conversationUuid}/escalate", [], [
            'X-Conversation-Token' => $token,
        ])
            ->assertOk()
            ->assertJsonPath('data.conversation.status', ChatConversation::STATUS_WAITING);

        $this->assertDatabaseHas('chat_conversations', [
            'conversation_uuid' => $conversationUuid,
            'status' => ChatConversation::STATUS_WAITING,
        ]);

        $this->assertNotNull(ChatConversation::where('conversation_uuid', $conversationUuid)->value('escalated_at'));
    }

    public function test_waiting_conversation_appears_in_support_inbox_and_front_desk_can_claim_reply_and_resolve(): void
    {
        $customer = $this->guestUser();
        $conversation = $this->createConversationFor($customer, ChatConversation::STATUS_WAITING);

        $frontDesk = $this->frontDeskUser();

        $this->actingAs($frontDesk)
            ->getJson('/api/frontdesk/support/conversations?status=waiting')
            ->assertOk()
            ->assertJsonFragment(['conversation_uuid' => $conversation->conversation_uuid]);

        $this->actingAs($frontDesk)
            ->postJson("/api/frontdesk/support/conversations/{$conversation->id}/claim")
            ->assertOk()
            ->assertJsonPath('data.conversation.status', ChatConversation::STATUS_ACTIVE)
            ->assertJsonPath('data.conversation.assigned_to.id', $frontDesk->id);

        $this->actingAs($frontDesk)
            ->postJson("/api/frontdesk/support/conversations/{$conversation->id}/messages", [
                'message' => 'We will assist you shortly.',
            ])
            ->assertOk()
            ->assertJsonPath('data.messages.0.sender_type', 'staff');

        $this->actingAs($frontDesk)
            ->postJson("/api/frontdesk/support/conversations/{$conversation->id}/resolve")
            ->assertOk()
            ->assertJsonPath('data.conversation.status', ChatConversation::STATUS_RESOLVED);

        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversation->id,
            'status' => ChatConversation::STATUS_RESOLVED,
            'assigned_to' => $frontDesk->id,
        ]);
    }

    public function test_manager_can_view_assign_reply_and_resolve_support_conversations(): void
    {
        $customer = $this->guestUser();
        $conversation = $this->createConversationFor($customer, ChatConversation::STATUS_WAITING);
        $manager = $this->managerUser();

        $this->actingAs($manager)
            ->getJson('/api/manager/support/conversations')
            ->assertOk()
            ->assertJsonFragment(['conversation_uuid' => $conversation->conversation_uuid]);

        $this->actingAs($manager)
            ->postJson("/api/manager/support/conversations/{$conversation->id}/claim")
            ->assertOk()
            ->assertJsonPath('data.conversation.status', ChatConversation::STATUS_ACTIVE);

        $this->actingAs($manager)
            ->postJson("/api/manager/support/conversations/{$conversation->id}/messages", [
                'message' => 'Manager response.',
            ])
            ->assertOk()
            ->assertJsonPath('data.messages.0.sender_type', 'staff');

        $this->actingAs($manager)
            ->postJson("/api/manager/support/conversations/{$conversation->id}/resolve")
            ->assertOk()
            ->assertJsonPath('data.conversation.status', ChatConversation::STATUS_RESOLVED);
    }

    public function test_two_staff_cannot_claim_the_same_waiting_conversation(): void
    {
        $customer = $this->guestUser();
        $conversation = $this->createConversationFor($customer, ChatConversation::STATUS_WAITING);
        $frontDesk = $this->frontDeskUser();
        $manager = $this->managerUser();

        $this->actingAs($frontDesk)
            ->postJson("/api/frontdesk/support/conversations/{$conversation->id}/claim")
            ->assertOk();

        $this->actingAs($manager)
            ->postJson("/api/manager/support/conversations/{$conversation->id}/claim")
            ->assertOk()
            ->assertJsonPath('data.conversation.assigned_to.id', $manager->id);

        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversation->id,
            'status' => ChatConversation::STATUS_ACTIVE,
            'assigned_to' => $manager->id,
        ]);
    }

    public function test_admin_can_access_support_inbox_alias_and_manage_conversation(): void
    {
        $customer = $this->guestUser();
        $conversation = $this->createConversationFor($customer, ChatConversation::STATUS_WAITING);
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->getJson('/api/admin/support/conversations?status=waiting')
            ->assertOk()
            ->assertJsonFragment(['conversation_uuid' => $conversation->conversation_uuid]);

        $this->actingAs($admin)
            ->postJson("/api/admin/support/conversations/{$conversation->id}/claim")
            ->assertOk()
            ->assertJsonPath('data.conversation.status', ChatConversation::STATUS_ACTIVE);

        $this->actingAs($admin)
            ->postJson("/api/admin/support/conversations/{$conversation->id}/messages", [
                'message' => 'Admin reply.',
            ])
            ->assertOk()
            ->assertJsonPath('data.messages.0.sender_type', 'staff');

        $this->actingAs($admin)
            ->postJson("/api/admin/support/conversations/{$conversation->id}/resolve")
            ->assertOk()
            ->assertJsonPath('data.conversation.status', ChatConversation::STATUS_RESOLVED);
    }

    public function test_support_inbox_uses_server_side_pagination_with_filters_and_status_counts(): void
    {
        $customer = $this->guestUser();

        foreach (range(1, 12) as $index) {
            $conversation = $this->createConversationFor($customer, ChatConversation::STATUS_WAITING);
            $conversation->forceFill([
                'guest_name' => "Queue Guest {$index}",
                'last_message_at' => now()->addSeconds($index),
            ])->save();
        }

        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->getJson('/api/admin/support/conversations?status=waiting&page=2&per_page=10')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.status_counts.waiting', 12);

        $this->actingAs($admin)
            ->getJson('/api/admin/support/conversations?status=waiting&search=Queue%20Guest%2012&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.guest_name', 'Queue Guest 12');
    }
}
