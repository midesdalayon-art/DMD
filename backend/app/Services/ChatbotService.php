<?php

namespace App\Services;

use App\Models\Accommodation;
use App\Models\ChatConversation;
use App\Models\ChatbotRule;
use Illuminate\Support\Str;

class ChatbotService
{
    public function __construct(
        private readonly SystemSettings $settings,
        private readonly ChatbotMessageNormalizer $normalizer,
        private readonly ChatbotContextManager $contextManager,
        private readonly RoomChatbotHandler $roomChatbotHandler,
    ) {
    }

    /**
     * @return array{
     *   rule:?ChatbotRule,
     *   answer:string,
     *   category_slug:?string,
     *   options?: array<int, array{id:int,label:string}>,
     *   context_updates?: array<string, mixed>,
     *   selected_accommodation_id?: int|null,
     *   intent?: string
     * }
     */
    public function match(string $message, ?ChatConversation $conversation = null, ?array $selection = null): array
    {
        $normalized = $this->normalizer->normalize($message);
        $context = $conversation ? $this->contextManager->get($conversation) : [];

        $roomMatch = $this->roomChatbotHandler->handle($message, $context, $selection);
        if (($roomMatch['handled'] ?? false) === true) {
            return $this->hydrateMatch($roomMatch);
        }

        $rules = ChatbotRule::query()
            ->with('category')
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            $keywords = collect($rule->keywords ?? [])
                ->map(fn ($keyword) => $this->normalizer->normalize((string) $keyword))
                ->filter()
                ->values();

            $question = $this->normalizer->normalize((string) $rule->question);

            if ($this->matchesRule($normalized, $question, $keywords->all())) {
                return [
                    'rule' => $rule,
                    'answer' => $this->resolveAnswer($rule->answer, $normalized),
                    'category_slug' => $rule->category?->slug,
                    'options' => [],
                ];
            }
        }

        return [
            'rule' => null,
            'answer' => $this->randomFallbackResponse(),
            'category_slug' => null,
            'options' => [],
        ];
    }

    /**
     * @return list<string>
     */
    public function fallbackResponses(): array
    {
        return [
            "Hmm, I didn't quite understand that. 😅 Could you say it another way?",
            "I'm not sure what you mean. Could you try rephrasing that?",
            "You got me on that one. 😅 Could you ask it another way?",
            "Hmm... what language is that? 😅 Try asking me about the resort.",
            "I didn't catch that one. Could you try again?",
            "My resort brain didn't understand that. 😅 Can you rephrase it?",
            "I'm a little confused by that one. What would you like to know?",
            "That one isn't in my vocabulary yet. 😅 Try saying it differently.",
            "I didn't understand that message. Want to try another way?",
            "Hmm, I'm not following. 😅 Could you rephrase your question?",
            "You found something I don't understand yet. 😄 Try asking it another way.",
            "I'm not sure how to answer that. Can you give me a little more context?",
            "That went over my digital head. 😅 Could you try again?",
            "I might need a translation for that one. 😅 What are you trying to ask?",
            "I didn't quite get that. Try asking me about rooms, bookings, or the resort.",
            "My guest-assistance dictionary failed me there. 😅 Try another phrase.",
            "Hmm... I have no idea what that means. 😅 Could you rephrase it?",
            "I'm lost on that one. 😄 What can I help you with at DMD Family Resort?",
            "That's outside my resort vocabulary. 😅 Try asking it another way.",
            "I couldn't understand that one. Try rephrasing it, or ask me about DMD Family Resort.",
        ];
    }

    private function randomFallbackResponse(): string
    {
        $responses = $this->fallbackResponses();

        return $responses[random_int(0, count($responses) - 1)];
    }

    public function rulePayload(ChatbotRule $rule): array
    {
        return [
            'id' => $rule->id,
            'question' => $rule->question,
            'answer' => $rule->answer,
            'priority' => $rule->priority,
            'is_active' => $rule->is_active,
            'keywords' => $rule->keywords ?? [],
            'category' => $rule->category?->only(['id', 'name', 'slug', 'icon', 'sort_order', 'is_active']),
        ];
    }

    private function matchesRule(string $message, string $question, array $keywords): bool
    {
        if ($question !== '' && Str::contains($message, $question)) {
            return true;
        }

        $hits = 0;

        foreach ($keywords as $keyword) {
            if ($keyword !== '' && Str::contains($message, $keyword)) {
                $hits++;
            }
        }

        return $hits > 0 && ($hits >= max(1, (int) ceil(count($keywords) / 2)) || count($keywords) <= 2);
    }

    private function resolveAnswer(string $answer, string $message): string
    {
        $settings = $this->settings->publicWebsiteSettings();

        return match (true) {
            str_contains($message, 'check in'), str_contains($message, 'arrival time') =>
                'Check-in starts at '.$settings['booking']['house_rules_check_in_time'].' and check-out is at '.$settings['booking']['house_rules_check_out_time'].'.',
            str_contains($message, 'check out') =>
                'Check-in starts at '.$settings['booking']['house_rules_check_in_time'].' and check-out is at '.$settings['booking']['house_rules_check_out_time'].'.',
            str_contains($message, 'where is'), str_contains($message, 'location'), str_contains($message, 'maps') =>
                trim(($settings['contact']['resort_address'] ?? '') ?: 'DMD Family Resort') . (! empty($settings['contact']['google_maps_url']) ? ' View on Google Maps: '.$settings['contact']['google_maps_url'] : ''),
            str_contains($message, 'contact'), str_contains($message, 'phone'), str_contains($message, 'email') =>
                'You can reach us at '.trim((string) ($settings['contact']['primary_phone'] ?? $settings['general']['contact_number'] ?? '')).' or '.trim((string) ($settings['contact']['email'] ?? $settings['general']['email'] ?? '')).'.',
            str_contains($message, 'house rule'), str_contains($message, 'policy') =>
                implode(' ', array_filter([
                    $settings['booking']['house_rules_smoking_policy'] ?? null,
                    $settings['booking']['house_rules_capacity_rule'] ?? null,
                    $settings['booking']['house_rules_pool_safety_rule'] ?? null,
                    $settings['booking']['house_rules_cleanliness_rule'] ?? null,
                    $settings['booking']['house_rules_damage_rule'] ?? null,
                ])),
            str_contains($message, 'how much'), str_contains($message, 'price'), str_contains($message, 'rate') =>
                $this->rateAnswer($message),
            str_contains($message, 'available') =>
                $this->availabilityAnswer($message),
            default => $this->replaceTokens($answer, $settings),
        };
    }

    /**
     * @param  array<string, mixed>  $match
     * @return array<string, mixed>
     */
    private function hydrateMatch(array $match): array
    {
        return array_merge([
            'rule' => null,
            'category_slug' => null,
            'options' => [],
        ], $match, [
            'options' => $match['options'] ?? [],
        ]);
    }

    private function rateAnswer(string $message): string
    {
        $accommodation = Accommodation::query()
            ->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower(trim($message)).'%'])
            ->orWhereRaw('LOWER(type) LIKE ?', ['%room%'])
            ->orderBy('name')
            ->first();

        if (! $accommodation) {
            $accommodation = Accommodation::query()->orderBy('price_per_night')->first();
        }

        if (! $accommodation) {
            return "I couldn't find an accommodation rate right now.";
        }

        return $accommodation->name.' is '.Accommodation::typeLabel($accommodation->type).' pricing at PHP '.number_format((float) $accommodation->price_per_night, 2).' per night.';
    }

    private function availabilityAnswer(string $message): string
    {
        $available = Accommodation::query()->where('status', Accommodation::STATUS_AVAILABLE)->orderBy('name')->limit(5)->get();

        if ($available->isEmpty()) {
            return 'I could not find any currently available accommodations.';
        }

        return 'Currently available: '.$available->pluck('name')->implode(', ').'.';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function replaceTokens(string $answer, array $settings): string
    {
        return strtr($answer, [
            '{resort_name}' => (string) ($settings['general']['resort_name'] ?? 'DMD Resort'),
            '{contact_number}' => (string) ($settings['contact']['primary_phone'] ?? $settings['general']['contact_number'] ?? ''),
            '{email}' => (string) ($settings['contact']['email'] ?? $settings['general']['email'] ?? ''),
            '{address}' => (string) ($settings['contact']['resort_address'] ?? $settings['general']['address'] ?? ''),
            '{check_in_time}' => (string) ($settings['booking']['house_rules_check_in_time'] ?? '14:00'),
            '{check_out_time}' => (string) ($settings['booking']['house_rules_check_out_time'] ?? '12:00'),
            '{google_maps_url}' => (string) ($settings['contact']['google_maps_url'] ?? ''),
        ]);
    }
}
