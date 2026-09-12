<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatbotCategory;
use App\Models\ChatbotRule;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    public function start(Request $request, ChatbotService $chatbotService): JsonResponse
    {
        $attributes = $request->validate([
            'guest_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'guest_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:1000'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'selected_accommodation_id' => ['sometimes', 'nullable', 'integer', 'exists:accommodations,id'],
        ]);

        $user = $request->user();
        $rawAccessToken = Str::random(64);
        $conversation = ChatConversation::create([
            'conversation_uuid' => (string) Str::uuid(),
            'access_token_hash' => hash('sha256', $rawAccessToken),
            'access_token_expires_at' => now()->addDays((int) config('chatbot.anonymous_token_expire_days', 30)),
            'customer_id' => $user?->normalizedRole() === User::ROLE_GUEST ? $user->id : null,
            'guest_name' => $user?->normalizedRole() === User::ROLE_GUEST ? $user->name : ($attributes['guest_name'] ?? null),
            'guest_email' => $user?->normalizedRole() === User::ROLE_GUEST ? $user->email : ($attributes['guest_email'] ?? null),
            'status' => ChatConversation::STATUS_BOT,
            'last_message_at' => now(),
        ]);

        $message = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => $user?->normalizedRole() === User::ROLE_GUEST ? 'customer' : 'guest',
            'sender_user_id' => $user?->normalizedRole() === User::ROLE_GUEST ? $user->id : null,
            'message' => $this->sanitizeMessage($attributes['message']),
            'message_type' => 'text',
            'is_read' => true,
        ]);

        $match = $chatbotService->match($attributes['message'], $conversation, $this->extractSelection($attributes));
        $this->applyConversationContext($conversation, $match);

        $botMessage = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'bot',
            'message' => $match['answer'],
            'message_type' => 'text',
            'chatbot_rule_id' => $match['rule']?->id,
            'is_read' => true,
        ]);

        return response()->json([
            'data' => $this->conversationData(
                $conversation->load(['messages', 'customer', 'assignedStaff']),
                $botMessage->fresh(),
                true,
                $match['options'] ?? [],
                $rawAccessToken
            ),
        ], 201);
    }

    public function show(Request $request, string $conversationUuid): JsonResponse
    {
        $conversation = ChatConversation::query()
            ->with(['messages.senderUser', 'messages.rule', 'customer', 'assignedStaff'])
            ->where('conversation_uuid', $conversationUuid)
            ->firstOrFail();

        $this->authorizeConversationAccess($request, $conversation);

        return response()->json([
            'data' => $this->conversationData(
                $conversation,
                null,
                $request->header('X-Conversation-Token') || ! $request->user() || $request->user()->normalizedRole() === User::ROLE_GUEST
            ),
        ]);
    }

    public function sendMessage(Request $request, string $conversationUuid, ChatbotService $chatbotService): JsonResponse
    {
        $conversation = ChatConversation::query()->where('conversation_uuid', $conversationUuid)->firstOrFail();
        $this->authorizeConversationAccess($request, $conversation);

        $attributes = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'selected_accommodation_id' => ['sometimes', 'nullable', 'integer', 'exists:accommodations,id'],
        ]);

        $senderType = $request->user()?->normalizedRole() === User::ROLE_GUEST ? 'customer' : 'guest';

        if ($request->user() && in_array($request->user()->normalizedRole(), [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_FRONT_DESK], true)) {
            $senderType = 'staff';
        }

        $message = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => $senderType,
            'sender_user_id' => $request->user()?->id,
            'message' => $this->sanitizeMessage($attributes['message']),
            'message_type' => 'text',
            'is_read' => true,
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        if ($conversation->status === ChatConversation::STATUS_BOT && $senderType !== 'staff') {
            $match = $chatbotService->match($attributes['message'], $conversation, $this->extractSelection($attributes));
            $this->applyConversationContext($conversation, $match);

            $botMessage = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'bot',
                'message' => $match['answer'],
                'message_type' => 'text',
                'chatbot_rule_id' => $match['rule']?->id,
                'is_read' => true,
            ]);

            return response()->json([
            'data' => $this->conversationData(
                $conversation->load(['messages.senderUser', 'messages.rule', 'customer', 'assignedStaff']),
                $botMessage,
                $this->shouldExposeConversationToken($request),
                $match['options'] ?? []
            ),
            'message' => 'Message sent.',
        ]);
        }

        return response()->json([
            'data' => $this->conversationData($conversation->load(['messages.senderUser', 'messages.rule', 'customer', 'assignedStaff']), $message),
            'message' => 'Message sent.',
        ]);
    }

    public function escalate(Request $request, string $conversationUuid): JsonResponse
    {
        $conversation = ChatConversation::query()->where('conversation_uuid', $conversationUuid)->firstOrFail();
        $this->authorizeConversationAccess($request, $conversation);

        $conversation->forceFill([
            'status' => ChatConversation::STATUS_WAITING,
            'escalated_at' => $conversation->escalated_at ?? now(),
            'last_message_at' => now(),
        ])->save();

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'system',
            'message' => 'Your conversation has been sent to our support team. A staff member will reply when available.',
            'message_type' => 'system',
            'is_read' => true,
        ]);

        return response()->json([
            'data' => $this->conversationData(
                $conversation->load(['messages.senderUser', 'messages.rule', 'customer', 'assignedStaff']),
                null,
                $this->shouldExposeConversationToken($request)
            ),
            'message' => 'Conversation escalated.',
        ]);
    }

    public function publicRules(Request $request, ChatbotService $chatbotService): JsonResponse
    {
        $rules = ChatbotRule::query()
            ->with('category')
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->limit(50)
            ->get()
            ->map(fn (ChatbotRule $rule) => $chatbotService->rulePayload($rule))
            ->values();

        return response()->json([
            'data' => $rules,
        ]);
    }

    public function staffIndex(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([ChatConversation::STATUS_WAITING, ChatConversation::STATUS_ASSIGNED, ChatConversation::STATUS_ACTIVE, ChatConversation::STATUS_RESOLVED, 'all'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $query = ChatConversation::query()->with(['customer', 'assignedStaff', 'messages' => fn ($query) => $query->latest()->limit(1)])
            ->latest('last_message_at');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $search = mb_strtolower($search);
            $query->where(function ($query) use ($search) {
                $query->whereRaw('LOWER(conversation_uuid) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(guest_name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(guest_email) LIKE ?', ["%{$search}%"])
                    ->orWhereHas('customer', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]));
            });
        }

        $statusCounts = [
            'all' => (clone $query)->count(),
            'waiting' => (clone $query)->where('status', ChatConversation::STATUS_WAITING)->count(),
            'active' => (clone $query)->whereIn('status', [ChatConversation::STATUS_ASSIGNED, ChatConversation::STATUS_ACTIVE])->count(),
            'resolved' => (clone $query)->where('status', ChatConversation::STATUS_RESOLVED)->count(),
        ];

        if (($filters['status'] ?? 'all') === 'active') {
            $query->whereIn('status', [ChatConversation::STATUS_ASSIGNED, ChatConversation::STATUS_ACTIVE]);
        } elseif (($filters['status'] ?? 'all') !== 'all') {
            $query->where('status', $filters['status']);
        }

        $paginator = $query->paginate(
            (int) ($filters['per_page'] ?? 10),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1),
        );

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (ChatConversation $conversation) => $this->conversationSummary($conversation))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
                'status_counts' => $statusCounts,
            ],
        ]);
    }

    public function staffShow(Request $request, string $conversation): JsonResponse
    {
        $this->authorizeStaff($request);
        $conversation = $this->findStaffConversation($conversation);

        return response()->json([
            'data' => $this->conversationData($conversation->load(['messages.senderUser', 'messages.rule', 'customer', 'assignedStaff'])),
        ]);
    }

    public function claim(Request $request, string $conversation, AuditLogger $auditLogger): JsonResponse
    {
        $user = $this->authorizeStaff($request);
        $conversation = $this->findStaffConversation($conversation);

        if (in_array($conversation->status, [ChatConversation::STATUS_RESOLVED], true)) {
            throw ValidationException::withMessages(['status' => ['Resolved conversations cannot be claimed.']]);
        }

        if ($conversation->assigned_to && (int) $conversation->assigned_to !== (int) $user->id && $user->normalizedRole() === User::ROLE_FRONT_DESK) {
            throw ValidationException::withMessages(['status' => ['This conversation is already assigned to another staff member.']]);
        }

        $conversation->forceFill([
            'status' => ChatConversation::STATUS_ACTIVE,
            'assigned_to' => $user->id,
        ])->save();

        $auditLogger->log($request, 'support', 'conversation_claimed', 'Conversation claimed by staff.', $conversation, [
            'conversation_uuid' => $conversation->conversation_uuid,
        ], $user);

        return response()->json([
            'data' => $this->conversationData($conversation->load(['messages.senderUser', 'messages.rule', 'customer', 'assignedStaff'])),
            'message' => 'Conversation claimed.',
        ]);
    }

    public function staffSendMessage(Request $request, string $conversation): JsonResponse
    {
        $user = $this->authorizeStaff($request);
        $conversation = $this->findStaffConversation($conversation);
        $attributes = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $conversation->forceFill([
            'status' => $conversation->status === ChatConversation::STATUS_WAITING ? ChatConversation::STATUS_ACTIVE : $conversation->status,
            'assigned_to' => $conversation->assigned_to ?? $user->id,
            'last_message_at' => now(),
        ])->save();

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'staff',
            'sender_user_id' => $user->id,
            'message' => $attributes['message'],
            'message_type' => 'text',
            'is_read' => true,
        ]);

        return response()->json([
            'data' => $this->conversationData($conversation->load(['messages.senderUser', 'messages.rule', 'customer', 'assignedStaff'])),
            'message' => 'Reply sent.',
        ]);
    }

    public function resolve(Request $request, string $conversation, AuditLogger $auditLogger): JsonResponse
    {
        $user = $this->authorizeStaff($request);
        $conversation = $this->findStaffConversation($conversation);

        $conversation->forceFill([
            'status' => ChatConversation::STATUS_RESOLVED,
            'resolved_at' => now(),
        ])->save();

        $auditLogger->log($request, 'support', 'conversation_resolved', 'Conversation resolved by staff.', $conversation, [
            'conversation_uuid' => $conversation->conversation_uuid,
        ], $user);

        return response()->json([
            'data' => $this->conversationData($conversation->load(['messages.senderUser', 'messages.rule', 'customer', 'assignedStaff'])),
            'message' => 'Conversation resolved.',
        ]);
    }

    public function adminCategories(): JsonResponse
    {
        return response()->json([
            'data' => ChatbotCategory::query()->orderBy('sort_order')->orderBy('name')->get()->values(),
        ]);
    }

    public function adminRules(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        return response()->json([
            'data' => ChatbotRule::query()
                ->with('category')
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get()
                ->map(fn (ChatbotRule $rule) => $this->ruleData($rule))
                ->values(),
        ]);
    }

    public function adminStoreRule(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $attributes = $this->validateRule($request);

        $rule = ChatbotRule::create($attributes + [
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ])->load('category');

        return response()->json([
            'data' => $this->ruleData($rule),
            'message' => 'Rule created.',
        ], 201);
    }

    public function adminUpdateRule(Request $request, ChatbotRule $rule): JsonResponse
    {
        $this->authorizeAdmin($request);
        $attributes = $this->validateRule($request);

        $rule->forceFill($attributes + ['updated_by' => $request->user()->id])->save();
        $rule->load('category');

        return response()->json([
            'data' => $this->ruleData($rule),
            'message' => 'Rule updated.',
        ]);
    }

    public function adminDeleteRule(Request $request, ChatbotRule $rule): JsonResponse
    {
        $this->authorizeAdmin($request);
        $rule->delete();

        return response()->json([
            'message' => 'Rule deleted.',
        ]);
    }

    public function adminStoreCategory(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', 'unique:chatbot_categories,slug'],
            'icon' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category = ChatbotCategory::create($attributes);

        return response()->json([
            'data' => $category,
            'message' => 'Category created.',
        ], 201);
    }

    public function adminUpdateCategory(Request $request, ChatbotCategory $category): JsonResponse
    {
        $this->authorizeAdmin($request);
        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', Rule::unique('chatbot_categories', 'slug')->ignore($category->id)],
            'icon' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category->forceFill($attributes)->save();

        return response()->json([
            'data' => $category->fresh(),
            'message' => 'Category updated.',
        ]);
    }

    public function adminDeleteCategory(Request $request, ChatbotCategory $category): JsonResponse
    {
        $this->authorizeAdmin($request);
        $category->delete();

        return response()->json([
            'message' => 'Category deleted.',
        ]);
    }

    private function authorizeConversationAccess(Request $request, ChatConversation $conversation): void
    {
        $user = $request->user();
        $providedToken = trim((string) $request->header('X-Conversation-Token', ''));

        if ($providedToken !== '') {
            $storedHash = (string) $conversation->access_token_hash;
            $legacyToken = (string) $conversation->access_token;
            $tokenMatches = $storedHash !== ''
                ? hash_equals($storedHash, hash('sha256', $providedToken))
                : ($legacyToken !== '' && hash_equals($legacyToken, $providedToken));

            if ($tokenMatches && ! $conversation->access_token_revoked_at && (! $conversation->access_token_expires_at || $conversation->access_token_expires_at->isFuture())) {
                if ($storedHash === '' && $legacyToken !== '') {
                    $conversation->forceFill([
                        'access_token_hash' => hash('sha256', $legacyToken),
                        'access_token' => null,
                        'access_token_expires_at' => now()->addDays((int) config('chatbot.anonymous_token_expire_days', 30)),
                    ])->save();
                }

                return;
            }
        }

        if (! $user) {
            abort(401);
        }

        if ($user->normalizedRole() === User::ROLE_GUEST && $conversation->customer_id === $user->id) {
            return;
        }

        if (in_array($user->normalizedRole(), [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_FRONT_DESK], true)) {
            return;
        }

        abort(403);
    }

    private function authorizeStaff(Request $request): User
    {
        $user = $request->user();

        if (! $user || ! in_array($user->normalizedRole(), [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_FRONT_DESK], true)) {
            abort(403);
        }

        return $user;
    }

    private function findStaffConversation(string $conversationKey): ChatConversation
    {
        return ChatConversation::query()
            ->where('conversation_uuid', $conversationKey)
            ->orWhere('id', ctype_digit($conversationKey) ? (int) $conversationKey : 0)
            ->firstOrFail();
    }

    private function authorizeAdmin(Request $request): User
    {
        $user = $request->user();

        if (! $user || $user->normalizedRole() !== User::ROLE_ADMIN) {
            abort(403);
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRule(Request $request): array
    {
        $attributes = $request->validate([
            'category_id' => ['required', 'integer', 'exists:chatbot_categories,id'],
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string', 'max:5000'],
            'keywords' => ['required', 'array', 'min:1'],
            'keywords.*' => ['required', 'string', 'max:80'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $attributes['priority'] = $attributes['priority'] ?? 0;
        $attributes['is_active'] = $attributes['is_active'] ?? true;

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleData(ChatbotRule $rule): array
    {
        return [
            'id' => $rule->id,
            'category_id' => $rule->category_id,
            'question' => $rule->question,
            'answer' => $rule->answer,
            'keywords' => $rule->keywords ?? [],
            'priority' => $rule->priority,
            'is_active' => $rule->is_active,
            'category' => $rule->category?->only(['id', 'name', 'slug', 'icon', 'sort_order', 'is_active']),
        ];
    }

    private function conversationSummary(ChatConversation $conversation, bool $includeAccessToken = false, ?string $rawAccessToken = null): array
    {
        return [
            'id' => $conversation->id,
            'conversation_uuid' => $conversation->conversation_uuid,
            'access_token' => $includeAccessToken ? $rawAccessToken : null,
            'status' => $conversation->status,
            'context' => $conversation->context ?? [],
            'guest_name' => $conversation->guest_name ?? $conversation->customer?->name ?? 'Guest',
            'guest_email' => $conversation->guest_email ?? $conversation->customer?->email,
            'assigned_to' => $conversation->assignedStaff?->publicProfile(),
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'latest_message' => $conversation->messages->first()?->message,
            'unread_count' => $conversation->messages->where('is_read', false)->count(),
        ];
    }

    private function conversationData(
        ChatConversation $conversation,
        ?ChatMessage $focusMessage = null,
        bool $includeAccessToken = false,
        array $botOptions = [],
        ?string $rawAccessToken = null
    ): array
    {
        return [
            'conversation' => $this->conversationSummary($conversation, $includeAccessToken, $rawAccessToken),
            'bot_options' => $botOptions,
            'messages' => $conversation->messages->map(fn (ChatMessage $message) => [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'sender_name' => $message->sender_type === 'staff'
                    ? ($message->senderUser?->name ?? 'Staff')
                    : ($message->sender_type === 'bot' ? 'DMD Family Resort' : 'Guest'),
                'message' => $message->message,
                'message_type' => $message->message_type,
                'chatbot_rule_id' => $message->chatbot_rule_id,
                'is_read' => $message->is_read,
                'created_at' => $message->created_at?->toISOString(),
            ])->values(),
            'focus_message_id' => $focusMessage?->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, int>|null
     */
    private function extractSelection(array $attributes): ?array
    {
        if (! isset($attributes['selected_accommodation_id'])) {
            return null;
        }

        return [
            'selected_accommodation_id' => (int) $attributes['selected_accommodation_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $match
     */
    private function applyConversationContext(ChatConversation $conversation, array $match): void
    {
        $updates = $match['context_updates'] ?? [];

        if (! is_array($updates) || $updates === []) {
            return;
        }

        $nextContext = array_replace_recursive($conversation->context ?? [], $updates);

        ChatConversation::query()
            ->whereKey($conversation->id)
            ->update(['context' => $nextContext]);

        $conversation->forceFill(['context' => $nextContext])->refresh();
    }

    private function sanitizeMessage(string $message): string
    {
        return trim(strip_tags($message));
    }

    private function shouldExposeConversationToken(Request $request): bool
    {
        if ($request->header('X-Conversation-Token')) {
            return true;
        }

        $user = $request->user();

        return ! $user || $user->normalizedRole() === User::ROLE_GUEST;
    }
}
