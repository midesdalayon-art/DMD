<?php

namespace App\Services;

use App\Models\ChatConversation;

class ChatbotContextManager
{
    /**
     * @return array<string, mixed>
     */
    public function get(ChatConversation $conversation): array
    {
        $context = $conversation->context;

        return is_array($context) ? $context : [];
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    public function merge(ChatConversation $conversation, array $updates): array
    {
        return array_replace_recursive($this->get($conversation), $updates);
    }
}
