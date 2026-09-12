<?php

namespace App\Services;

use App\Models\Accommodation;
use App\Models\ChatConversation;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RoomChatbotHandler
{
    public function __construct(
        private readonly ChatbotMessageNormalizer $normalizer,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $selection
     * @return array<string, mixed>
     */
    public function handle(string $message, array $context = [], ?array $selection = null): array
    {
        $normalized = $this->normalizer->normalize($message);
        $greetingIntent = $this->detectGreetingIntent($normalized);
        $thanksIntent = $this->detectThanksIntent($normalized);
        $farewellIntent = $this->detectFarewellIntent($normalized);
        $context = array_replace([
            'current_category' => 'room',
            'selected_accommodation_id' => null,
            'candidate_accommodation_ids' => [],
            'last_intent' => null,
            'guest_count' => null,
            'room_filters' => [],
            'pending_intent' => null,
            'pending_field' => null,
            'requested_date' => null,
            'requested_time' => null,
            'stay_days' => null,
        ], $context);
        $workingContext = $this->stripTransientContext($context);
        $rooms = $this->roomsQuery()->get();
        $explicitRoom = $this->resolveRoomEntity($normalized, $rooms, $selection);

        $hasRoomRequest = $this->hasRoomRequestPriority($normalized);

        if ($greetingIntent && ! $hasRoomRequest) {
            return $this->respondWithGreeting($greetingIntent);
        }

        if ($thanksIntent && ! $hasRoomRequest) {
            return $this->respondWithThanks();
        }

        if ($farewellIntent && ! $hasRoomRequest) {
            return $this->respondWithFarewell();
        }

        $developerIntent = $this->detectDeveloperEasterEggIntent($normalized);
        if ($developerIntent && ! $hasRoomRequest) {
            return $this->respondWithDeveloperEasterEgg($developerIntent);
        }

        $animalIntent = $this->detectAnimalMentionIntent($normalized);
        if ($animalIntent) {
            return $this->respondWithAnimalMention($animalIntent);
        }

        $slurIntent = $this->detectIdentitySlurIntent($normalized);
        if ($slurIntent && ! $hasRoomRequest) {
            return $this->respondWithIdentitySlur($slurIntent);
        }

        $foodIntent = $this->detectFoodMentionIntent($normalized);
        if ($foodIntent && (
            ! $hasRoomRequest
            || in_array($foodIntent, ['outside_food_policy', 'meal_included', 'food_catering', 'food_order', 'food_availability'], true)
        )) {
            return $this->respondWithFoodMention($foodIntent);
        }

        $birthdayIntent = $this->detectBirthdayMentionIntent($normalized);
        if ($birthdayIntent && ! $hasRoomRequest) {
            return $this->respondWithBirthdayMention($birthdayIntent);
        }

        $needToUnwindIntent = $this->detectNeedToUnwindIntent($normalized);
        if ($needToUnwindIntent && ! $hasRoomRequest) {
            return $this->respondWithNeedToUnwind($needToUnwindIntent);
        }

        $apologyIntent = $this->detectApologyIntent($normalized);
        if ($apologyIntent && ! $hasRoomRequest) {
            return $this->respondWithApology($apologyIntent);
        }

        $chatbotInsultIntent = $this->detectChatbotInsultIntent($normalized);
        if ($chatbotInsultIntent && ! $hasRoomRequest) {
            return $this->respondWithChatbotInsult($chatbotInsultIntent);
        }

        $profanityIntent = $this->detectProfanityIntent($normalized);
        if ($profanityIntent && ! $hasRoomRequest) {
            return $this->respondWithProfanity($profanityIntent);
        }

        $smallTalkIntent = $this->detectSmallTalkIntent($normalized);
        if ($smallTalkIntent && ! $hasRoomRequest) {
            return $this->respondWithSmallTalk($smallTalkIntent);
        }

        if ($this->hasConflictingRankingIntent($normalized)) {
            return [
                'handled' => true,
                'intent' => 'clarify_ranking',
                'category_slug' => 'room',
                'answer' => 'That depends on what matters most to you. Are you looking for the lowest price, the largest guest capacity, or specific amenities? Would you like the cheapest, most expensive, largest-capacity, or smallest-capacity room?',
                'options' => [],
                'context_updates' => [
                    'current_category' => 'room',
                    'last_intent' => 'clarify_ranking',
                    'pending_intent' => null,
                ],
                'rule' => null,
            ];
        }

        $pendingFamily = ($context['pending_intent'] ?? null) === 'family_recommendation';

        if ($pendingFamily) {
            $guestCount = $this->extractGuestCount($normalized);

            if ($guestCount !== null && ! $explicitRoom && $this->detectIntent($normalized, $workingContext, $rooms, $selection) === null) {
                $familyContext = $this->stripTransientContext($context);

                return $this->respondWithFamilyRecommendation($guestCount, $familyContext, $rooms);
            }

            $workingContext = $this->clearTransientQueryState($workingContext);
        }

        $context = $workingContext;

        if ($rooms->isEmpty() || ! $this->isRoomRelated($normalized, $context, $selection, $explicitRoom)) {
            return ['handled' => false];
        }

        if ($this->looksLikeFamilyQuestion($normalized)) {
            $guestCount = $this->extractGuestCount($normalized);

            if ($guestCount === null) {
                return $this->respondToFamilyPrompt($context);
            }

            return $this->respondWithFamilyRecommendation($guestCount, $this->clearTransientQueryState($context), $rooms);
        }

        $intent = $this->detectIntent($normalized, $context, $rooms, $selection);

        if ($selection && isset($selection['selected_accommodation_id'])) {
            $explicitRoom = $rooms->firstWhere('id', (int) $selection['selected_accommodation_id']) ?? $explicitRoom;
        }

        if ($intent === 'compare') {
            return $this->respondWithComparison($normalized, $context, $rooms);
        }

        if ($explicitRoom) {
            $context['selected_accommodation_id'] = $explicitRoom->id;
            $guestCount = $this->extractGuestCount($normalized);
            if ($guestCount !== null) {
                $context['guest_count'] = $guestCount;
                $context['room_filters'] = array_replace($context['room_filters'] ?? [], [
                    'guest_count' => $guestCount,
                ]);
            }

            if ($this->looksLikePriceQuestion($normalized)) {
                return $this->buildPriceResponse($explicitRoom, $context, 'price');
            }

            if ($this->looksLikeCapacityQuestion($normalized)) {
                return $this->buildCapacityResponse($explicitRoom, $context, 'capacity');
            }

            if ($this->looksLikeAmenityQuestion($normalized)) {
                return $this->buildAmenitiesResponse($explicitRoom, $context, 'amenities');
            }

            if ($this->looksLikeAvailabilityQuestion($normalized)) {
                return $this->respondWithAvailability($normalized, $context, $explicitRoom, $selection, $rooms);
            }

            if ($this->looksLikeDetailsQuestion($normalized)) {
                return $this->buildDetailsResponse($explicitRoom, $context, 'details');
            }

            return $this->buildDetailsResponse($explicitRoom, $context, 'details');
        }

        if ($intent === 'list') {
            return $this->respondWithRoomList($rooms, $context);
        }

        if ($intent === 'clarify_ranking') {
            return [
                'handled' => true,
                'intent' => 'clarify_ranking',
                'category_slug' => 'room',
                'answer' => 'That depends on what matters most to you. I can help you find the cheapest room, the largest-capacity room, or a room with specific amenities.',
                'options' => $this->roomChoices($rooms->take(4)),
                'context_updates' => [
                    'current_category' => 'room',
                    'last_intent' => 'clarify_ranking',
                    'candidate_accommodation_ids' => [],
                    'pending_intent' => null,
                ],
                'rule' => null,
            ];
        }

        if (in_array($intent, ['cheapest', 'most_expensive', 'largest', 'smallest', 'recommend'], true)) {
            return $this->respondWithRanking($intent, $normalized, $context, $rooms);
        }

        if ($intent === 'availability') {
            return $this->respondWithAvailability($normalized, $context, $explicitRoom, $selection, $rooms);
        }

        if ($intent === 'details') {
            return $this->respondWithDetails($explicitRoom, $context, $rooms);
        }

        if ($intent === 'price') {
            return $this->respondWithPrice($explicitRoom, $context, $rooms, $normalized);
        }

        if ($intent === 'capacity') {
            return $this->respondWithCapacity($explicitRoom, $context, $rooms);
        }

        if ($intent === 'amenities') {
            return $this->respondWithAmenities($explicitRoom, $context, $rooms);
        }

        if ($intent === 'another') {
            return $this->respondWithAnotherRoom($context, $rooms);
        }

        if ($this->looksLikeGenericRoomQuestion($normalized)) {
            return $this->respondWithRoomList($rooms, $context);
        }

        return ['handled' => false];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Accommodation>
     */
    private function roomsQuery()
    {
        return Accommodation::query()
            ->where('type', Accommodation::TYPE_ROOM)
            ->where('status', Accommodation::STATUS_AVAILABLE)
            ->where('housekeeping_status', Accommodation::HOUSEKEEPING_READY)
            ->with(['amenities'])
            ->orderBy('price_per_night')
            ->orderBy('capacity', 'desc')
            ->orderBy('name');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function isRoomRelated(string $normalized, array $context, ?array $selection, ?Accommodation $explicitRoom = null): bool
    {
        if (($context['current_category'] ?? null) === 'room') {
            return true;
        }

        if ($selection && isset($selection['selected_accommodation_id'])) {
            return true;
        }

        if ($explicitRoom) {
            return true;
        }

        return $this->looksLikeGenericRoomQuestion($normalized)
            || $this->looksLikePriceQuestion($normalized)
            || $this->looksLikeCapacityQuestion($normalized)
            || $this->looksLikeAmenityQuestion($normalized)
            || $this->looksLikeAvailabilityQuestion($normalized)
            || $this->looksLikeDetailsQuestion($normalized)
            || $this->looksLikeRecommendationQuestion($normalized)
            || $this->looksLikeComparisonQuestion($normalized)
            || $this->looksLikeAnotherQuestion($normalized)
            || $this->looksLikeFamilyQuestion($normalized)
            || $this->looksLikeCheapestQuestion($normalized)
            || $this->looksLikeMostExpensiveQuestion($normalized)
            || $this->looksLikeLargestQuestion($normalized)
            || $this->looksLikeSmallestQuestion($normalized)
            || $this->hasConflictingRankingIntent($normalized);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Accommodation>  $rooms
     */
    private function resolveRoomEntity(string $normalized, Collection $rooms, ?array $selection): ?Accommodation
    {
        if ($selection && isset($selection['selected_accommodation_id'])) {
            $selected = $rooms->firstWhere('id', (int) $selection['selected_accommodation_id']);
            if ($selected) {
                return $selected;
            }
        }

        $selectedId = $this->extractRoomNumber($normalized);
        if ($selectedId !== null) {
            $direct = $rooms->first(function (Accommodation $room) use ($selectedId) {
                return $this->normalizer->normalize($room->name) === 'room '.$selectedId
                    || Str::contains($this->normalizer->normalize($room->name), 'room '.$selectedId);
            });

            if ($direct) {
                return $direct;
            }
        }

        $normalizedMessage = $this->normalizer->normalize($normalized);

        if (
            $normalizedMessage === ''
            || preg_match('/^\d+$/u', $normalizedMessage) === 1
            || in_array($normalizedMessage, ['room', 'rooms'], true)
        ) {
            return null;
        }

        return $rooms->first(function (Accommodation $room) use ($normalizedMessage) {
            $normalizedName = $this->normalizer->normalize($room->name);

            return $normalizedName !== '' && (
                $normalizedName === $normalizedMessage
                || Str::contains($normalizedMessage, $normalizedName)
                || Str::contains($normalizedName, $normalizedMessage)
            );
        });
    }

    private function detectIntent(string $normalized, array $context, Collection $rooms, ?array $selection): ?string
    {
        if ($this->looksLikeComparisonQuestion($normalized)) {
            return 'compare';
        }

        if ($this->looksLikeAnotherQuestion($normalized)) {
            return 'another';
        }

        if ($this->looksLikeFamilyQuestion($normalized)) {
            return 'family_recommendation';
        }

        if ($this->looksLikeCheapestQuestion($normalized)) {
            return 'cheapest';
        }

        if ($this->looksLikeMostExpensiveQuestion($normalized)) {
            return 'most_expensive';
        }

        if ($this->looksLikeLargestQuestion($normalized)) {
            return 'largest';
        }

        if ($this->looksLikeSmallestQuestion($normalized)) {
            return 'smallest';
        }

        if ($this->looksLikePriceQuestion($normalized)) {
            return 'price';
        }

        if ($this->looksLikeAvailabilityQuestion($normalized)) {
            return 'availability';
        }

        if ($this->looksLikeCapacityQuestion($normalized)) {
            return 'capacity';
        }

        if ($this->looksLikeAmenityQuestion($normalized)) {
            return 'amenities';
        }

        if ($this->looksLikeDetailsQuestion($normalized)) {
            return 'details';
        }

        if ($this->looksLikeRecommendationQuestion($normalized)) {
            return $this->hasObjectiveRoomCriteria($normalized) ? 'recommend' : 'clarify_ranking';
        }

        if ($this->looksLikeGenericRoomQuestion($normalized)) {
            return 'list';
        }

        return null;
    }

    private function looksLikeGenericRoomQuestion(string $normalized): bool
    {
        $phrases = [
            'room',
            'rooms',
            'guest room',
            'guest rooms',
            'resort room',
            'resort rooms',
            'show room',
            'show rooms',
            'show me rooms',
            'show me the rooms',
            'show me your rooms',
            'view rooms',
            'see rooms',
            'list rooms',
            'room list',
            'list of rooms',
            'what rooms',
            'what rooms do you have',
            'which rooms',
            'available rooms',
            'your rooms',
            'tell me about the rooms',
        ];

        return $this->matchesAnyPhrase($normalized, $phrases);
    }

    private function looksLikePriceQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'price',
            'prices',
            'rate',
            'rates',
            'cost',
            'costs',
            'how much',
            'how much is it',
            'how much does it cost',
            'what is the price',
            'what s the price',
            'what is the rate',
            'room price',
            'room rate',
            'room cost',
            'price of the room',
            'rate of the room',
            'cost of the room',
        ]);
    }

    private function looksLikeCheapestQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'cheap',
            'cheapest',
            'cheaper',
            'budget',
            'budget friendly',
            'affordable',
            'most affordable',
            'lowest price',
            'lowest priced',
            'lowest rate',
            'least expensive',
            'inexpensive',
            'low cost',
            'low price',
            'best price',
            'most economical',
        ]);
    }

    private function looksLikeMostExpensiveQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'most expensive',
            'highest price',
            'highest priced',
            'highest rate',
            'priciest',
            'premium priced',
        ]);
    }

    private function looksLikeLargestQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'biggest room',
            'largest room',
            'highest capacity',
            'most guests',
            'room for the most people',
            'room that fits the most guests',
            'room with the largest capacity',
        ]);
    }

    private function looksLikeSmallestQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'smallest room',
            'lowest capacity',
            'fewest guests',
            'room for the fewest people',
        ]);
    }

    private function looksLikeCapacityQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'capacity',
            'room capacity',
            'guest capacity',
            'how many guests',
            'how many people',
            'how many persons',
            'how many can stay',
            'how many can fit',
            'how many people can fit',
            'guest limit',
            'maximum guests',
            'max guests',
            'maximum people',
            'max people',
            'how many does it accommodate',
            'how many can it accommodate',
        ]);
    }

    private function looksLikeFamilyQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'room for family',
            'family room',
            'family of',
            'room for guests',
            'room for people',
            'room for persons',
            'room for a family',
            'for my family',
        ]) || Str::contains($normalized, 'family');
    }

    private function looksLikeAmenityQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'amenities',
            'room amenities',
            'features',
            'room features',
            'what is included',
            'what s included',
            'what does it have',
            'what is inside',
            'what s inside the room',
            'what comes with the room',
            'amenities of room',
            'what does room',
        ]) || Str::contains($normalized, 'wifi') || Str::contains($normalized, 'aircon') || Str::contains($normalized, 'tv') || Str::contains($normalized, 'bathroom') || Str::contains($normalized, 'air conditioning');
    }

    private function looksLikeDetailsQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'tell me about',
            'details of',
            'details',
            'describe',
            'what is it like',
            'information about',
            'more about',
        ]);
    }

    private function looksLikeAvailabilityQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'available',
            'availability',
            'vacant',
            'open for booking',
            'is it available',
            'is this available',
            'is this room available',
            'is room',
            'can i book',
            'can we book',
            'can we reserve',
        ]);
    }

    private function looksLikeRecommendationQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'recommend a room',
            'recommend room',
            'suggest a room',
            'suggest room',
            'which room should i choose',
            'which room should we choose',
            'what room should i get',
            'help me choose a room',
            'which room is right for me',
            'best room',
            'what is the best room',
            'which room is best',
            'your best room',
            'best option',
            'which is better',
        ]);
    }

    private function looksLikeComparisonQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'compare',
            'difference between',
            'which is cheaper',
            'which fits more',
            'which is better',
        ]);
    }

    private function looksLikeAnotherQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'another room',
            'other room',
            'show another',
            'something else',
            'another option',
            'next room',
            'another one',
        ]);
    }

    private function hasConflictingRankingIntent(string $normalized): bool
    {
        return ($this->looksLikeCheapestQuestion($normalized) && $this->looksLikeMostExpensiveQuestion($normalized))
            || ($this->looksLikeLargestQuestion($normalized) && $this->looksLikeSmallestQuestion($normalized));
    }

    private function hasRoomRequestPriority(string $normalized): bool
    {
        return $this->looksLikeGenericRoomQuestion($normalized)
            || $this->looksLikeAvailabilityQuestion($normalized)
            || $this->looksLikeDetailsQuestion($normalized)
            || $this->looksLikePriceQuestion($normalized)
            || $this->looksLikeCapacityQuestion($normalized)
            || $this->looksLikeAmenityQuestion($normalized)
            || $this->looksLikeRecommendationQuestion($normalized)
            || $this->looksLikeComparisonQuestion($normalized)
            || $this->looksLikeAnotherQuestion($normalized)
            || $this->looksLikeFamilyQuestion($normalized)
            || $this->looksLikeCheapestQuestion($normalized)
            || $this->looksLikeMostExpensiveQuestion($normalized)
            || $this->looksLikeLargestQuestion($normalized)
            || $this->looksLikeSmallestQuestion($normalized)
            || $this->hasConflictingRankingIntent($normalized);
    }

    private function detectGreetingIntent(string $normalized): ?string
    {
        $greetings = [
            'hello',
            'hi',
            'hey',
            'hello there',
            'hi there',
            'hey there',
            'good morning',
            'good afternoon',
            'good evening',
            'greetings',
            'yo',
            'wazzup',
            'what s up',
            'whats up',
        ];

        foreach ($greetings as $phrase) {
            $phrase = $this->normalizer->normalize($phrase);
            if ($normalized === $phrase || preg_match('/^'.preg_quote($phrase, '/').'(?:\s|$)/u', $normalized)) {
                return 'greeting';
            }
        }

        return null;
    }

    private function detectThanksIntent(string $normalized): ?string
    {
        $thanks = [
            'thanks',
            'thank you',
            'thank you so much',
            'thanks a lot',
            'okay thanks',
            'ok thanks',
            'alright thanks',
        ];

        foreach ($thanks as $phrase) {
            $phrase = $this->normalizer->normalize($phrase);
            if ($normalized === $phrase || preg_match('/^'.preg_quote($phrase, '/').'(?:\s|$)/u', $normalized)) {
                return 'thanks';
            }
        }

        return null;
    }

    private function detectFarewellIntent(string $normalized): ?string
    {
        $farewells = [
            'bye',
            'goodbye',
            'see you',
            'thanks bye',
            'thank you bye',
        ];

        foreach ($farewells as $phrase) {
            $phrase = $this->normalizer->normalize($phrase);
            if ($normalized === $phrase || preg_match('/^'.preg_quote($phrase, '/').'(?:\s|$)/u', $normalized)) {
                return 'farewell';
            }
        }

        return null;
    }

    private function respondWithGreeting(string $intent): array
    {
        return [
            'handled' => true,
            'intent' => $intent,
            'category_slug' => 'room',
            'answer' => 'Hi! I can help with rooms, availability, booking, payments, resort information, or connecting you with staff.',
            'options' => [],
            'context_updates' => [
                'current_category' => 'room',
                'last_intent' => $intent,
            ],
            'rule' => null,
        ];
    }

    private function respondWithThanks(): array
    {
        return [
            'handled' => true,
            'intent' => 'thanks',
            'category_slug' => 'room',
            'answer' => "You're welcome! Let me know if you need anything else.",
            'options' => [],
            'context_updates' => [
                'current_category' => 'room',
                'last_intent' => 'thanks',
            ],
            'rule' => null,
        ];
    }

    private function respondWithFarewell(): array
    {
        return [
            'handled' => true,
            'intent' => 'farewell',
            'category_slug' => 'room',
            'answer' => 'You\'re welcome. Enjoy your stay at DMD Family Resort!',
            'options' => [],
            'context_updates' => [
                'current_category' => 'room',
                'last_intent' => 'farewell',
            ],
            'rule' => null,
        ];
    }

    private function hasObjectiveRoomCriteria(string $normalized): bool
    {
        return $this->extractGuestCount($normalized) !== null
            || $this->looksLikeCheapestQuestion($normalized)
            || $this->looksLikeMostExpensiveQuestion($normalized)
            || $this->looksLikeLargestQuestion($normalized)
            || $this->looksLikeSmallestQuestion($normalized)
            || $this->looksLikeCapacityQuestion($normalized)
            || $this->looksLikeAmenityQuestion($normalized);
    }

    /**
     * Remove one-turn query state so it does not leak into the next message.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function stripTransientContext(array $context): array
    {
        return array_replace($context, [
            'guest_count' => null,
            'room_filters' => [],
            'pending_intent' => null,
            'pending_field' => null,
            'requested_date' => null,
            'requested_time' => null,
            'stay_days' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function clearTransientQueryState(array $context): array
    {
        return $this->stripTransientContext($context);
    }

    private function matchesAnyPhrase(string $normalized, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            $phrase = $this->normalizer->normalize($phrase);
            if ($phrase !== '' && preg_match('/\b'.preg_quote($phrase, '/').'\b/u', $normalized)) {
                return true;
            }
        }

        return false;
    }

    private function extractRoomNumber(string $normalized): ?int
    {
        if (preg_match('/\broom\s+(\d+)\b/u', $normalized, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Accommodation>  $rooms
     * @return array<string, mixed>
     */
    private function respondWithRoomList(Collection $rooms, array $context): array
    {
        $choices = $this->roomChoices($rooms);

        return [
            'handled' => true,
            'intent' => 'room_list',
            'category_slug' => 'room',
            'answer' => $rooms->isEmpty()
                ? 'I could not find any rooms right now.'
                : 'We currently have '.collect($choices)->pluck('label')->implode(', ').'. Choose a room to see price, capacity, amenities, or availability.',
            'options' => $choices,
            'context_updates' => [
                'current_category' => 'room',
                'last_intent' => 'room_list',
                'candidate_accommodation_ids' => $choices ? array_column($choices, 'id') : [],
                'room_filters' => [],
                'pending_intent' => null,
                'selected_accommodation_id' => null,
            ],
            'selected_accommodation_id' => null,
            'rule' => null,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Accommodation>  $rooms
     * @return array<string, mixed>
     */
    private function respondWithRanking(string $intent, string $normalized, array $context, Collection $rooms): array
    {
        $guestCount = $this->extractGuestCount($normalized) ?? (int) ($context['guest_count'] ?? 0);
        $amenityName = $this->detectAmenityFilter($normalized, $rooms);
        $eligible = $this->filterRooms($rooms, $guestCount ?: null, $amenityName);

        if ($eligible->isEmpty()) {
            return [
                'handled' => true,
                'intent' => $intent,
                'category_slug' => 'room',
                'answer' => $guestCount > 0
                    ? "I couldn't find a room that fits {$guestCount} guests".($amenityName ? " with {$amenityName}" : '').'.'
                    : "I couldn't find a matching room right now.",
                'options' => $this->roomChoices($rooms->take(4)),
                'context_updates' => array_filter([
                    'current_category' => 'room',
                    'last_intent' => $intent,
                    'candidate_accommodation_ids' => [],
                    'guest_count' => $guestCount ?: null,
                    'room_filters' => array_filter([
                        'guest_count' => $guestCount ?: null,
                        'amenity' => $amenityName,
                    ], static fn ($value) => $value !== null && $value !== ''),
                    'pending_intent' => null,
                ]),
                'rule' => null,
            ];
        }

        [$room, $ties] = $this->pickRankingCandidate($intent, $eligible);

        if (! $room && $ties->isEmpty()) {
            return [
                'handled' => false,
            ];
        }

        $label = match ($intent) {
            'cheapest' => 'most affordable',
            'most_expensive' => 'most expensive',
            'largest' => 'largest',
            'smallest' => 'smallest',
            default => 'recommended',
        };

        if ($ties->count() > 1) {
            $roomNames = $ties->pluck('name')->implode(', ');
            $message = match ($intent) {
                'cheapest' => 'These rooms share the lowest rate of '.$this->formatCurrency((float) $ties->first()->price_per_night).' per 24-hour stay: '.$roomNames.'.',
                'most_expensive' => 'These rooms share the highest rate of '.$this->formatCurrency((float) $ties->first()->price_per_night).' per 24-hour stay: '.$roomNames.'.',
                'largest' => 'These rooms share the largest capacity of '.$ties->first()->capacity.' guests: '.$roomNames.'.',
                'smallest' => 'These rooms share the smallest capacity of '.$ties->first()->capacity.' guests: '.$roomNames.'.',
                default => "{$room->name} is a good option at {$this->formatCurrency((float) $room->price_per_night)} per 24-hour stay.",
            };
        } else {
            $message = match ($intent) {
                'cheapest' => "The {$label} room is {$room->name} at {$this->formatCurrency((float) $room->price_per_night)} per 24-hour stay.",
                'most_expensive' => "The {$label} room is {$room->name} at {$this->formatCurrency((float) $room->price_per_night)} per 24-hour stay.",
                'largest' => "{$room->name} can accommodate up to {$room->capacity} guests.",
                'smallest' => "{$room->name} has the smallest capacity at {$room->capacity} guests.",
                default => "{$room->name} is a good option at {$this->formatCurrency((float) $room->price_per_night)} per 24-hour stay.",
            };
        }

        return [
            'handled' => true,
            'intent' => $intent,
            'category_slug' => 'room',
            'answer' => $message,
            'options' => $ties->count() > 1 ? $this->roomChoices($ties) : [],
            'context_updates' => array_filter([
                'current_category' => 'room',
                'selected_accommodation_id' => $ties->count() > 1 ? null : $room->id,
                'candidate_accommodation_ids' => $ties->count() > 1 ? $ties->pluck('id')->all() : [],
                'last_intent' => $intent,
                'guest_count' => $guestCount ?: null,
                'room_filters' => array_filter([
                    'guest_count' => $guestCount ?: null,
                    'amenity' => $amenityName,
                ], static fn ($value) => $value !== null && $value !== ''),
                'pending_intent' => null,
            ]),
            'selected_accommodation_id' => $ties->count() > 1 ? null : $room->id,
            'rule' => null,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Accommodation>  $rooms
     * @return array<string, mixed>
     */
    private function respondWithComparison(string $normalized, array $context, Collection $rooms): array
    {
        $matches = [];

        if (preg_match_all('/\broom\s+(\d+)\b/u', $normalized, $found) && count($found[1]) >= 2) {
            $matches = array_slice(array_map('intval', $found[1]), 0, 2);
        }

        $roomA = $matches[0] ?? null;
        $roomB = $matches[1] ?? null;

        $first = $roomA ? $this->resolveRoomByNumber($rooms, $roomA) : null;
        $second = $roomB ? $this->resolveRoomByNumber($rooms, $roomB) : null;

        if (! $first || ! $second) {
            return [
                'handled' => true,
                'intent' => 'compare',
                'category_slug' => 'room',
                'answer' => 'Please choose two specific rooms to compare.',
                'options' => $this->roomChoices($rooms->take(4)),
                'context_updates' => [
                    'current_category' => 'room',
                    'last_intent' => 'compare',
                ],
                'rule' => null,
            ];
        }

        $priceMessage = $first->price_per_night == $second->price_per_night
            ? 'They have the same price.'
            : ($first->price_per_night < $second->price_per_night
                ? "{$first->name} is cheaper."
                : "{$second->name} is cheaper.");

        $capacityMessage = $first->capacity == $second->capacity
            ? 'They have the same capacity.'
            : ($first->capacity > $second->capacity
                ? "{$first->name} fits more guests."
                : "{$second->name} fits more guests.");

        return [
            'handled' => true,
            'intent' => 'compare',
            'category_slug' => 'room',
            'answer' => "{$first->name} costs {$this->formatCurrency((float) $first->price_per_night)} and fits {$first->capacity} guests. {$second->name} costs {$this->formatCurrency((float) $second->price_per_night)} and fits {$second->capacity} guests. {$priceMessage} {$capacityMessage}",
            'options' => [],
            'context_updates' => [
                'current_category' => 'room',
                'last_intent' => 'compare',
                'selected_accommodation_id' => $second->id,
            ],
            'selected_accommodation_id' => $second->id,
            'rule' => null,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Accommodation>  $rooms
     * @return array<string, mixed>
     */
    private function respondWithAvailability(string $normalized, array $context, ?Accommodation $room, ?array $selection, Collection $rooms): array
    {
        $room = $room ?: ($context['selected_accommodation_id'] ? $rooms->firstWhere('id', (int) $context['selected_accommodation_id']) : null);
        if (! $room) {
            $candidates = $this->resolveCandidateRooms($context);
            $room = $candidates->first();
        }

        if (! $room) {
            return [
                'handled' => true,
                'intent' => 'availability',
                'category_slug' => 'room',
                'answer' => 'Which room would you like to check?',
                'options' => $this->roomChoices($rooms->take(4)),
                'context_updates' => [
                    'current_category' => 'room',
                    'last_intent' => 'availability',
                ],
                'rule' => null,
            ];
        }

        $checkInDate = $this->extractDate($normalized);
        $checkInTime = $this->extractTime($normalized);
        $stayDays = $this->extractStayDays($normalized) ?? (int) ($context['stay_days'] ?? 0);

        if (! $checkInDate || ! $checkInTime || $stayDays < 1) {
            return [
                'handled' => true,
                'intent' => 'availability',
                'category_slug' => 'room',
                'answer' => 'I can check '.$room->name.' for you. What check-in date, check-in time, and stay length would you like?',
                'options' => [],
                'context_updates' => array_filter([
                    'current_category' => 'room',
                    'selected_accommodation_id' => $room->id,
                    'last_intent' => 'availability',
                    'requested_date' => $checkInDate,
                    'requested_time' => $checkInTime,
                    'stay_days' => $stayDays > 0 ? $stayDays : null,
                ]),
                'selected_accommodation_id' => $room->id,
                'rule' => null,
            ];
        }

        $checkInAt = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$checkInDate} {$checkInTime}", config('app.timezone'));
        if ($checkInAt === false) {
            return [
                'handled' => true,
                'intent' => 'availability',
                'category_slug' => 'room',
                'answer' => 'I could not understand that date and time. Please use a clear check-in date and time.',
                'options' => [],
                'context_updates' => [
                    'current_category' => 'room',
                    'selected_accommodation_id' => $room->id,
                    'last_intent' => 'availability',
                ],
                'selected_accommodation_id' => $room->id,
                'rule' => null,
            ];
        }

        $checkOutAt = $checkInAt->addHours(Reservation::ROOM_STAY_HOURS * $stayDays);
        $available = $room->isAvailableForRoomStay(
            $checkInAt->format('Y-m-d H:i:s'),
            $checkOutAt->format('Y-m-d H:i:s'),
        );

        return [
            'handled' => true,
            'intent' => 'availability',
            'category_slug' => 'room',
            'answer' => $available
                ? "{$room->name} is available for that stay."
                : "{$room->name} is not available for that stay.",
            'options' => [],
            'context_updates' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $room->id,
                'last_intent' => 'availability',
                'requested_date' => $checkInDate,
                'requested_time' => $checkInTime,
                'stay_days' => $stayDays,
            ],
            'selected_accommodation_id' => $room->id,
            'rule' => null,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Accommodation>  $rooms
     * @return array<string, mixed>
     */
    private function respondWithDetails(?Accommodation $room, array $context, Collection $rooms): array
    {
        $room = $room ?: ($context['selected_accommodation_id'] ? $rooms->firstWhere('id', (int) $context['selected_accommodation_id']) : null);
        if (! $room) {
            $candidates = $this->resolveCandidateRooms($context);
            $room = $candidates->first();
        }

        if (! $room) {
            return [
                'handled' => true,
                'intent' => 'details',
                'category_slug' => 'room',
                'answer' => 'Which room would you like details for?',
                'options' => $this->roomChoices($rooms->take(4)),
                'context_updates' => [
                    'current_category' => 'room',
                    'last_intent' => 'details',
                ],
                'rule' => null,
            ];
        }

        return $this->buildDetailsResponse($room, $context, 'details');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Accommodation>  $rooms
     * @return array<string, mixed>
     */
    private function respondWithPrice(?Accommodation $room, array $context, Collection $rooms, string $normalized): array
    {
        $room = $room ?: ($context['selected_accommodation_id'] ? $rooms->firstWhere('id', (int) $context['selected_accommodation_id']) : null);
        if (! $room) {
            $candidates = $this->resolveCandidateRooms($context);
            if ($candidates->count() > 0) {
                return $this->buildPriceResponse($candidates->first(), $context, 'price');
            }
        }

        if (! $room) {
            if ($rooms->count() > 1) {
                return [
                    'handled' => true,
                    'intent' => 'price',
                    'category_slug' => 'room',
                    'answer' => 'Which room would you like to check?',
                    'options' => $this->roomChoices($rooms->take(4)),
                    'context_updates' => [
                        'current_category' => 'room',
                        'last_intent' => 'price',
                    ],
                    'rule' => null,
                ];
            }

            $room = $rooms->first();
        }

        if (! $room) {
            return ['handled' => false];
        }

        return $this->buildPriceResponse($room, $context, 'price');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Accommodation>  $rooms
     * @return array<string, mixed>
     */
    private function respondWithCapacity(?Accommodation $room, array $context, Collection $rooms): array
    {
        $room = $room ?: ($context['selected_accommodation_id'] ? $rooms->firstWhere('id', (int) $context['selected_accommodation_id']) : null);
        if (! $room) {
            $candidates = $this->resolveCandidateRooms($context);
            $room = $candidates->first();
        }

        if (! $room) {
            return [
                'handled' => true,
                'intent' => 'capacity',
                'category_slug' => 'room',
                'answer' => 'Which room would you like to check?',
                'options' => $this->roomChoices($rooms->take(4)),
                'context_updates' => [
                    'current_category' => 'room',
                    'last_intent' => 'capacity',
                ],
                'rule' => null,
            ];
        }

        return $this->buildCapacityResponse($room, $context, 'capacity');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Accommodation>  $rooms
     * @return array<string, mixed>
     */
    private function respondWithAmenities(?Accommodation $room, array $context, Collection $rooms): array
    {
        $room = $room ?: ($context['selected_accommodation_id'] ? $rooms->firstWhere('id', (int) $context['selected_accommodation_id']) : null);
        if (! $room) {
            $candidates = $this->resolveCandidateRooms($context);
            $room = $candidates->first();
        }

        if (! $room) {
            return [
                'handled' => true,
                'intent' => 'amenities',
                'category_slug' => 'room',
                'answer' => 'Which room would you like to check?',
                'options' => $this->roomChoices($rooms->take(4)),
                'context_updates' => [
                    'current_category' => 'room',
                    'last_intent' => 'amenities',
                ],
                'rule' => null,
            ];
        }

        return $this->buildAmenitiesResponse($room, $context, 'amenities');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Accommodation>  $rooms
     * @return array<string, mixed>
     */
    private function respondWithAnotherRoom(array $context, Collection $rooms): array
    {
        $currentId = (int) ($context['selected_accommodation_id'] ?? 0);
        $filters = $context['room_filters'] ?? [];
        $guestCount = (int) ($filters['guest_count'] ?? ($context['guest_count'] ?? 0));
        $amenity = $filters['amenity'] ?? null;

        $eligible = $this->filterRooms($rooms, $guestCount ?: null, $amenity);
        $next = $eligible
            ->reject(fn (Accommodation $room) => $room->id === $currentId)
            ->sortBy('price_per_night')
            ->first();

        if (! $next) {
            return [
                'handled' => true,
                'intent' => 'another',
                'category_slug' => 'room',
                'answer' => 'I do not have another room option matching your current criteria.',
                'options' => $this->roomChoices($eligible->take(4)),
                'context_updates' => [
                    'current_category' => 'room',
                    'last_intent' => 'another',
                ],
                'rule' => null,
            ];
        }

        return $this->buildDetailsResponse($next, $context, 'another');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Accommodation>  $eligible
     * @return array{0:?Accommodation,1:\Illuminate\Support\Collection<int, Accommodation>}
     */
    private function pickRankingCandidate(string $intent, Collection $eligible): array
    {
        if ($eligible->isEmpty()) {
            return [null, collect()];
        }

        $sorted = match ($intent) {
            'cheapest' => $eligible->sortBy('price_per_night')->values(),
            'most_expensive' => $eligible->sortByDesc('price_per_night')->values(),
            'largest' => $eligible->sortByDesc('capacity')->values(),
            'smallest' => $eligible->sortBy('capacity')->values(),
            default => $eligible->sortBy('price_per_night')->values(),
        };

        $first = $sorted->first();

        if (! $first) {
            return [null, collect()];
        }

        $ties = match ($intent) {
            'cheapest', 'most_expensive' => $eligible->filter(fn (Accommodation $room) => $room->price_per_night == $first->price_per_night)->values(),
            'largest', 'smallest' => $eligible->filter(fn (Accommodation $room) => $room->capacity == $first->capacity)->values(),
            default => collect([$first]),
        };

        return [$first, $ties];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function respondToFamilyPrompt(array $context): array
    {
        return [
            'handled' => true,
            'intent' => 'family_recommendation',
            'category_slug' => 'room',
            'answer' => 'How many guests will be staying?',
            'options' => [],
            'context_updates' => [
                'current_category' => 'room',
                'last_intent' => 'family_recommendation',
                'pending_intent' => 'family_recommendation',
                'pending_field' => 'guest_count',
                'room_filters' => array_filter([
                    'guest_count' => $context['guest_count'] ?? null,
                ], static fn ($value) => $value !== null && $value !== ''),
            ],
            'rule' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function respondWithFamilyRecommendation(int $guestCount, array $context, Collection $rooms): array
    {
        $eligible = $this->filterRooms($rooms, $guestCount, null);

        if ($eligible->isEmpty()) {
            return [
                'handled' => true,
                'intent' => 'family_recommendation',
                'category_slug' => 'room',
                'answer' => "I could not find a room that fits {$guestCount} guests.",
                'options' => $this->roomChoices($rooms->take(4)),
                'context_updates' => [
                    'current_category' => 'room',
                    'last_intent' => 'family_recommendation',
                    'pending_intent' => null,
                    'pending_field' => null,
                    'guest_count' => $guestCount,
                    'room_filters' => [],
                ],
                'rule' => null,
            ];
        }

        $sorted = $eligible->sortBy([
            ['price_per_night', 'asc'],
            ['capacity', 'desc'],
            ['name', 'asc'],
        ])->values();
        $chosen = $sorted->first();
        $ties = $eligible
            ->filter(fn (Accommodation $room) => $room->price_per_night == $chosen->price_per_night)
            ->sortBy('name')
            ->values();

        if ($ties->count() > 1) {
            $candidateIds = $ties->pluck('id')->all();
            $roomNames = $ties->pluck('name')->implode(', ');
            $namePrefix = $roomNames !== '' ? $roomNames : 'These rooms';
            return [
                'handled' => true,
                'intent' => 'family_recommendation',
                'category_slug' => 'room',
                'answer' => "{$namePrefix} can accommodate {$guestCount} guests and share the lowest rate of ".$this->formatCurrency((float) $chosen->price_per_night)." per 24-hour stay.",
                'options' => $this->roomChoices($ties),
                'context_updates' => [
                    'current_category' => 'room',
                    'last_intent' => 'family_recommendation',
                    'pending_intent' => null,
                    'pending_field' => null,
                    'guest_count' => $guestCount,
                    'selected_accommodation_id' => null,
                    'candidate_accommodation_ids' => $candidateIds,
                    'room_filters' => [],
                ],
                'selected_accommodation_id' => null,
                'rule' => null,
            ];
        }

        return [
            'handled' => true,
            'intent' => 'family_recommendation',
            'category_slug' => 'room',
            'answer' => "{$chosen->name} can fit {$guestCount} guests at ".$this->formatCurrency((float) $chosen->price_per_night).' per 24-hour stay.',
            'options' => [],
            'context_updates' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $chosen->id,
                'last_intent' => 'family_recommendation',
                'pending_intent' => null,
                'pending_field' => null,
                'guest_count' => $guestCount,
                'room_filters' => [],
            ],
            'selected_accommodation_id' => $chosen->id,
            'rule' => null,
        ];
    }

    private function detectSmallTalkIntent(string $normalized): ?string
    {
        if ($this->looksLikeHowAreYouQuestion($normalized)) {
            return 'how_are_you';
        }

        if ($this->looksLikeIntroductionQuestion($normalized)) {
            return 'introduction';
        }

        if ($this->looksLikeAffectionQuestion($normalized)) {
            return 'affection';
        }

        if ($this->looksLikeComplimentQuestion($normalized)) {
            return 'compliment';
        }

        if ($this->looksLikePhysicalAffectionQuestion($normalized)) {
            return 'physical_affection';
        }

        if ($this->looksLikePositiveFeedbackQuestion($normalized)) {
            return 'positive_feedback';
        }

        if ($this->looksLikeAcknowledgementQuestion($normalized)) {
            return 'acknowledgement';
        }

        if ($this->looksLikeLaughterQuestion($normalized)) {
            return 'laughter';
        }

        return null;
    }

    private function detectDeveloperEasterEggIntent(string $normalized): ?string
    {
        $developerCandidate = $this->removeOffensiveNoise($normalized);

        if ($this->matchesAnyPhrase($normalized, [
            'earlpogi mode',
        ])) {
            return 'earlpogi_mode';
        }

        if ($this->matchesAnyPhrase($normalized, [
            'developer mode',
            'dev mode',
            'developer easter egg',
            'show developer',
            'show developer easter egg',
        ])) {
            return 'developer_mode';
        }

        if ($this->matchesAnyPhrase($normalized, [
            'up up down down left right left right b a',
        ])) {
            return 'konami';
        }

        if ($this->matchesAnyPhrase($developerCandidate, [
            'who created this website',
            'who created the website',
            'who created this stupid website',
            'who created the dmd website',
            'who created the dmd family resort website',
            'who made this website',
            'who made this stupid website',
            'who the made this website',
            'who made the website',
            'who made this stupid chatbot',
            'who made this dumb chatbot',
            'who made the dmd website',
            'who built this website',
            'who built this stupid website',
            'who built the website',
            'who built the dmd website',
            'who developed this website',
            'who developed this stupid website',
            'who the developed this website',
            'who developed the website',
            'who developed the dmd website',
            'who coded this website',
            'who coded this stupid website',
            'who coded the website',
            'who coded the dmd website',
            'who programmed this website',
            'who programmed this stupid website',
            'who programmed the website',
            'who designed this website',
            'who designed the dmd website',
            'who is the creator of this website',
            'who is the creator of the website',
            'who is the website creator',
            'who is the developer',
            'who s the developer',
            'whos the developer',
            'who is the website developer',
            'who is the dmd website developer',
            'who the developer',
        ])) {
            return 'website_creator';
        }

        if ($this->matchesAnyPhrase($developerCandidate, [
            'who created this system',
            'who made this system',
            'who the made this system',
            'who developed this system',
            'who the developed this system',
            'who built this system',
            'who coded this system',
            'who programmed this system',
            'who is the system developer',
            'who is the system creator',
            'who developed the dmd system',
            'who created the dmd system',
            'who built the dmd system',
            'who developed the resort management system',
            'who made you',
            'who created you',
            'who built you',
            'who programmed you',
            'who coded you',
        ])) {
            return 'system_creator';
        }

        if ($this->matchesAnyPhrase($developerCandidate, [
            'earlpogi',
        ])) {
            return 'direct_name';
        }

        if ($this->matchesAnyPhrase($developerCandidate, [
            'who is earlpogi',
            'who s earlpogi',
            'whos earlpogi',
            'what is earlpogi',
            'tell me about earlpogi',
            'do you know earlpogi',
            'who the heck is earlpogi',
        ])) {
            return 'about_name';
        }

        if ($this->matchesAnyPhrase($developerCandidate, [
            'who owns dmd family resort',
            'who owns the resort',
            'who is the resort owner',
            'who founded dmd family resort',
            'who created dmd family resort',
            'who owns this place',
            'who owns this resort',
        ])) {
            return 'owner_question';
        }

        return null;
    }

    private function detectProfanityIntent(string $normalized): ?string
    {
        $patterns = [
            '/\bfuck\b/u',
            '/\bfucking\b/u',
            '/\bfucked\b/u',
            '/\bfucker\b/u',
            '/\bfuck you\b/u',
            '/\bwtf\b/u',
            '/\bshit\b/u',
            '/\bshitty\b/u',
            '/\bbullshit\b/u',
            '/\bholy shit\b/u',
            '/\bdamn\b/u',
            '/\bdammit\b/u',
            '/\bgoddamn\b/u',
            '/\basshole\b/u',
            '/\bass\b/u',
            '/\bbitch\b/u',
            '/\bbastard\b/u',
            '/\bcrap\b/u',
            '/\bpiss\b/u',
            '/\bpissed\b/u',
            '/\bmotherfucker\b/u',
            '/\bmotherfucking\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return 'profanity';
            }
        }

        return null;
    }

    private function detectBirthdayMentionIntent(string $normalized): ?string
    {
        $birthdayPhrases = [
            'birthday',
            'birth day',
            'birthday celebration',
            'birthday party',
            'birthday celebrant',
            'birthday event',
            'birthday booking',
            'birthday reservation',
            'birthday package',
            'birthday promo',
            'birthday promotion',
            'birthday discount',
            'birthday reward',
            'birthday bonus',
            'birthday freebie',
            'birthday offer',
            'birthday special',
            'birthday deal',
            'happy birthday',
            'my birthday',
            'it s my birthday',
            'its my birthday',
            'today is my birthday',
            'today s my birthday',
            'tomorrow is my birthday',
            'birthday today',
            'birthday tomorrow',
            'celebrate my birthday',
            'celebrate birthday',
            'celebrating my birthday',
            'celebrating a birthday',
            'celebrating birthday',
            'birthday at the resort',
            'birthday at dmd',
            'birthday at dmd family resort',
        ];

        return $this->matchesAnyPhrase($normalized, $birthdayPhrases) ? 'birthday_mention' : null;
    }

    private function detectNeedToUnwindIntent(string $normalized): ?string
    {
        $normalized = $this->removeOffensiveNoise($normalized);

        if ($this->matchesAnyPhrase($normalized, [
            'where is',
            'where are you located',
            'where are you',
            'directions',
            'direction',
            'how do i get there',
            'how do we get there',
            'how to get there',
            'can not find the resort',
            'cannot find the resort',
            'i m lost where is',
            'im lost where is',
            'lost how do i get there',
            'where is dmd family resort',
            'where is the resort',
        ])) {
            return null;
        }

        $phrases = [
            'broken heart',
            'brokenhearted',
            'broken hearted',
            'heartbroken',
            'heart break',
            'heartbreak',
            'we broke up',
            'we break up',
            'breakup',
            'break up',
            'going through a breakup',
            'she left me',
            'he left me',
            'they left me',
            'my girlfriend left me',
            'my boyfriend left me',
            'my partner left me',
            'she dumped me',
            'he dumped me',
            'i got dumped',
            'got dumped',
            'i m sad',
            "i'm sad",
            'im sad',
            'feeling sad',
            'i feel sad',
            'i m lonely',
            "i'm lonely",
            'im lonely',
            'feeling lonely',
            'i feel lonely',
            'i m lost in life',
            'im lost in life',
            'i m lost',
            'im lost',
            'feeling lost',
            'i feel lost',
            'lost in life',
            'i need a break',
            'need a break',
            'i need some space',
            'i need to unwind',
            'need to unwind',
            'i want to unwind',
            'i wanna unwind',
            'i need to relax',
            'need to relax',
            'i want to relax',
            'i need some peace',
            'i want some peace',
            'need some peace',
            'i need a getaway',
            'need a getaway',
            'i want a getaway',
            'i need a vacation',
            'need a vacation',
            'i want a vacation',
            'i need some rest',
            'need some rest',
            'i need somewhere peaceful',
            'i want somewhere peaceful',
            'stressed out',
            'i m stressed',
            "i'm stressed",
            'im stressed',
        ];

        if (! $this->matchesAnyPhrase($normalized, $phrases)) {
            return null;
        }

        return 'need_to_unwind';
    }

    private function detectApologyIntent(string $normalized): ?string
    {
        $normalized = $this->removeOffensiveNoise($normalized);

        $patterns = [
            '/\bsorry\b/u',
            '/\bi\s*\'?\s*m\s+sorry\b/u',
            '/\bi\s+am\s+sorry\b/u',
            '/\bso\s+sorry\b/u',
            '/\breally\s+sorry\b/u',
            '/\bvery\s+sorry\b/u',
            '/\bmy\s+bad\b/u',
            '/\bmy\s+mistake\b/u',
            '/\bmy\s+fault\b/u',
            '/\bi\s+apologi[sz]e\b/u',
            '/\bapologies\b/u',
            '/\bplease\s+forgive\s+me\b/u',
            '/\bforgive\s+me\b/u',
            '/\bexcuse\s+me\b/u',
            '/\bpardon\s+me\b/u',
            '/\boops\b/u',
            '/\bwhoops\b/u',
            '/\bi\s+did(n\'?|\s*)?t\s+mean\s+that\b/u',
            '/\bdid(n\'?|\s*)?t\s+mean\s+it\b/u',
            '/\bi\s+take\s+that\s+back\b/u',
        ];

        $matched = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            return null;
        }

        if ($this->matchesAnyPhrase($normalized, [
            'sorry but',
            'sorry asshole',
            'sorry fuck you',
            'sorry fuck',
            'sorry bitch',
            'sorry damn',
        ])) {
            return null;
        }

        return 'apology';
    }

    private function detectChatbotInsultIntent(string $normalized): ?string
    {
        $normalized = $this->removeOffensiveNoise($normalized);

        if ($this->matchesAnyPhrase($normalized, [
            'you are stupid',
            'you are stupid',
            'you re stupid',
            "you're stupid",
            'youre stupid',
            'your stupid',
            'you are dumb',
            'you re dumb',
            "you're dumb",
            'youre dumb',
            'dumb chatbot',
            'stupid chatbot',
            'you are an idiot',
            'idiot',
            'moron',
            'you are useless',
            'you re useless',
            'useless chatbot',
            'bad chatbot',
            'worst chatbot',
            'terrible chatbot',
            'trash chatbot',
            'garbage chatbot',
            'you suck',
            'you are annoying',
            'you re annoying',
            'shut up',
            'i hate you',
            'nobody likes you',
            'you are ugly',
            'you re ugly',
            "you're ugly",
            'you are terrible',
            'you re terrible',
            "you're terrible",
            'you are bad',
            'you re bad',
            "you're bad",
            "you don't understand anything",
            'you do not understand anything',
            "you don't know anything",
            'you do not know anything',
            "you re not smart",
            "you're not smart",
            'you are not smart',
        ])) {
            return 'chatbot_insult';
        }

        if ($this->matchesAnyPhrase($normalized, [
            'you are dumb bot',
            'dumb bot',
            'stupid bot',
            'useless bot',
            'trash bot',
            'garbage bot',
            'you are a dumb bot',
            'you are a stupid bot',
            'you are a useless bot',
        ])) {
            return 'chatbot_insult';
        }

        if ($this->matchesAnyPhrase($normalized, [
            'bot',
            'chatbot',
            'assistant',
            'guest assistant',
            'guest assistance',
        ]) && $this->matchesAnyPhrase($normalized, [
            'stupid',
            'dumb',
            'useless',
            'annoying',
            'ugly',
            'terrible',
            'bad',
            'trash',
            'garbage',
            'worst',
        ])) {
            if ($this->matchesAnyPhrase($normalized, [
                'room is bad',
                'wifi is terrible',
                'booking was bad',
                'service was terrible',
                'room sucks',
            ])) {
                return null;
            }

            return 'chatbot_insult';
        }

        return null;
    }

    private function detectFoodMentionIntent(string $normalized): ?string
    {
        $normalized = $this->removeOffensiveNoise($normalized);

        if ($this->matchesAnyPhrase($normalized, [
            'outside food',
            'bring food',
            'bring our own food',
            'bring my own food',
            'outside meals',
            'outside foods',
            'outside food policy',
            'bring a birthday cake',
            'birthday cake',
        ])) {
            return 'outside_food_policy';
        }

        if ($this->matchesAnyPhrase($normalized, [
            'include breakfast',
            'includes breakfast',
            'breakfast is free',
            'breakfast included',
            'does the room include breakfast',
            'free breakfast',
            'are meals included',
            'meal included',
            'meals included',
            'included breakfast',
        ])) {
            return 'meal_included';
        }

        if ($this->matchesAnyPhrase($normalized, [
            'catering',
            'cater',
            'caterer',
            'food for birthday party',
            'food for function hall',
            'catering for function hall',
            'prepare food for our event',
        ])) {
            return 'food_catering';
        }

        if ($this->matchesAnyPhrase($normalized, [
            'food delivery',
            'can you deliver food',
            'order food',
            'food order',
            'can i order food',
            'where can i order food',
            'how do i order dinner',
        ])) {
            return 'food_order';
        }

        if ($this->matchesAnyPhrase($normalized, [
            'menu',
            'show me the menu',
            'what is on the menu',
            'what food do you have',
            'what do you serve',
            'what can we eat there',
        ])) {
            return 'food_menu';
        }

        if ($this->matchesAnyPhrase($normalized, [
            'do you have food',
            'do you have pizza',
            'is food available',
            'do you sell burgers',
            'can i buy food there',
            'do you have breakfast',
            'is dinner available',
            'do you have coffee',
        ])) {
            return 'food_availability';
        }

        if ($this->matchesAnyPhrase($normalized, [
            'food',
            'foods',
            'meal',
            'meals',
            'eat',
            'eating',
            'hungry',
            'breakfast',
            'lunch',
            'dinner',
            'snack',
            'snacks',
            'restaurant',
            'menus',
            'dining',
            'dine',
            'rice',
            'fried rice',
            'chicken',
            'fried chicken',
            'pork',
            'beef',
            'fish',
            'seafood',
            'pizza',
            'burger',
            'hamburger',
            'sandwich',
            'noodles',
            'pasta',
            'spaghetti',
            'bread',
            'cake',
            'ice cream',
            'dessert',
            'desserts',
            'coffee',
            'tea',
            'juice',
            'soft drinks',
            'soda',
            'water',
            'drink',
            'drinks',
            'beverage',
            'beverages',
        ])) {
            return 'food_generic';
        }

        return null;
    }

    private function detectIdentitySlurIntent(string $normalized): ?string
    {
        $patterns = [
            '/\b(?:n-?word|nword)\b/u',
            '/\bn word\b/u',
            '/\b(?:nigger|nigga)\b/u',
            '/\b(?:n[i1!]gg[ae@]r|n[i1!]gg[ae@])\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return 'identity_slur';
            }
        }

        return null;
    }

    private function detectAnimalMentionIntent(string $normalized): ?string
    {
        $animalTerms = [
            'dog', 'dogs', 'puppy', 'puppies',
            'cat', 'cats', 'kitten', 'kittens',
            'bird', 'birds', 'parrot', 'chicken', 'chickens', 'duck', 'ducks',
            'snake', 'snakes', 'lizard', 'lizards', 'gecko', 'frog', 'frogs',
            'fish', 'fishes', 'goldfish',
            'monkey', 'monkeys',
            'horse', 'horses',
            'goat', 'goats',
            'cow', 'cows',
            'pig', 'pigs',
            'rabbit', 'rabbits', 'bunny', 'bunnies',
            'mouse', 'mice', 'rat', 'rats',
            'spider', 'spiders',
            'bee', 'bees', 'wasp', 'wasps',
            'ant', 'ants',
            'mosquito', 'mosquitoes',
            'cockroach', 'cockroaches',
            'butterfly', 'butterflies',
            'turtle', 'turtles',
            'crab', 'crabs',
            'pet', 'pets', 'animal', 'animals',
        ];

        foreach ($animalTerms as $term) {
            if ($this->matchesAnyPhrase($normalized, [$term])) {
                return 'animal_mention';
            }
        }

        return null;
    }

    private function looksLikeHowAreYouQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'how are you',
            'how are you doing',
            'how you doing',
            'how s it going',
            'hows it going',
            'you good',
            'are you okay',
            'are you ok',
        ]);
    }

    private function looksLikeIntroductionQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'nice to meet you',
            'good to meet you',
            'pleased to meet you',
            'glad to meet you',
        ]);
    }

    private function looksLikeAffectionQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'i love you',
            'love you',
            'do you love me',
            'you love me',
            'i like you',
            'you re my favorite',
            'you are my favorite',
        ]);
    }

    private function looksLikeComplimentQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'you re cute',
            'you are cute',
            'you re pretty',
            'you re beautiful',
            'you re handsome',
            'you re sexy',
            'hi sexy',
            'hey sexy',
            'hey baby',
            'hi baby',
        ]);
    }

    private function looksLikePhysicalAffectionQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'can i kiss you',
            'kiss me',
            'can i hug you',
            'hug me',
            'sending hugs',
        ]);
    }

    private function looksLikePositiveFeedbackQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'nice',
            'cool',
            'awesome',
            'great',
            'amazing',
            'good job',
            'well done',
            'you re awesome',
            'you are awesome',
            'you re helpful',
            'very helpful',
        ]);
    }

    private function looksLikeAcknowledgementQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'ok',
            'okay',
            'alright',
            'sure',
            'got it',
            'i see',
            'understood',
        ]);
    }

    private function looksLikeLaughterQuestion(string $normalized): bool
    {
        return $this->matchesAnyPhrase($normalized, [
            'haha',
            'hahaha',
            'lol',
            'hehe',
        ]);
    }

    private function respondWithSmallTalk(string $intent): array
    {
        $answer = match ($intent) {
            'how_are_you' => "I'm doing well! How can I help with your stay at DMD Family Resort?",
            'introduction' => 'Nice to meet you too! How can I help with your stay?',
            'affection' => "That's sweet. I'm here to help with your stay at DMD Family Resort.",
            'compliment' => "Haha, thank you. How can I help with your stay?",
            'physical_affection' => "Haha, I'll stick to helping with your resort stay. What would you like to know?",
            'positive_feedback' => "Thank you! Let me know if you need anything else.",
            'acknowledgement' => 'Sure! Let me know what you would like help with.',
            'laughter' => 'How can I help with your stay?',
            default => 'How can I help with your stay at DMD Family Resort?',
        };

        return [
            'handled' => true,
            'intent' => $intent,
            'category_slug' => 'room',
            'answer' => $answer,
            'options' => [],
            'context_updates' => [
                'current_category' => 'room',
                'last_intent' => $intent,
            ],
            'rule' => null,
        ];
    }

    private function respondWithProfanity(string $intent): array
    {
        $responses = [
            "Whoa there 😅 Let's keep it friendly. How can I help with your stay?",
            "Easy there 😄 I'm still here to help with your resort stay.",
            "That's some strong language 😅 What can I help you with?",
            "Whoa 😅 Let's keep the chat friendly. Need help with your stay?",
            "I heard that 😅 Anyway, how can I help with your resort visit?",
            "Let's keep it chill 😄 What do you need help with?",
            "Strong words detected 😅 Need help with a room or reservation?",
            "Hey now 😄 Let's keep things friendly. How can I help?",
            "Message received 😅 What can I help you with at DMD Family Resort?",
            "Let's keep the good vibes going 😄 Need help with your stay?",
            "Whoa, language 😅 I'm still happy to help.",
            "Let's keep things respectful 😄 What can I help you find?",
            "Easy 😅 Need help finding a room?",
            "No need for the strong language 😄 Tell me what you need help with.",
            "Let's keep this chat guest-friendly 😄 How can I assist you?",
            "I caught that one 😅 What can I help you with?",
            "Strong language alert 😄 Let's get back to your resort questions.",
            "Alright, alright 😅 What can I help you with today?",
            "Let's keep it friendly 😄 Rooms, availability, booking, or something else?",
            "Whoa there 😅 I'm here to help. What would you like to know?",
        ];

        $answer = $responses[random_int(0, count($responses) - 1)];

        return [
            'handled' => true,
            'intent' => $intent,
            'category_slug' => 'room',
            'answer' => $answer,
            'options' => [],
            'context_updates' => [
                'current_category' => 'room',
                'last_intent' => $intent,
            ],
            'rule' => null,
        ];
    }

    private function respondWithBirthdayMention(string $intent): array
    {
        $responses = [
            "Happy birthday! 🎉 You can ask our resort staff if any birthday bonus, reward, or discount is currently available.",
            "Celebrating a birthday? 🎂 Please check with our staff about any available birthday rewards or discounts.",
            "Happy birthday! 🥳 Our staff can let you know if there are any birthday bonuses, rewards, or special offers available.",
            "For birthday celebrations, please ask DMD Family Resort staff if there are any current birthday discounts or rewards.",
            "Birthday celebration? 🎉 You can check with our staff to see if any birthday bonus or special offer is available.",
            "That's worth celebrating! 🎂 Ask our resort staff whether there are any birthday rewards, bonuses, or discounts available.",
            "If you're celebrating a birthday, our staff can confirm whether any birthday special or discount is currently offered.",
            "Happy birthday to the celebrant! 🎉 Please ask our staff about any available birthday bonuses or rewards.",
            "For birthday bookings, it's best to check with our staff about any special birthday offers or discounts.",
            "Planning a birthday at DMD Family Resort? 🥳 Our staff can tell you if there are any birthday rewards or promos available.",
            "Birthday time! 🎂 You can ask our staff if there's a birthday bonus, reward, or discount available.",
            "Happy birthday! 🎉 I recommend checking with our resort staff about any current birthday specials.",
            "Our staff would be the best people to ask about birthday bonuses, rewards, or discounts that may be available.",
            "Celebrating your special day here? 🎂 Please check with our staff for any available birthday offers.",
            "For birthday celebrations, our resort staff can confirm whether there are any special rewards or discounts available.",
            "Happy birthday! 🥳 Before booking, you can ask our staff if any birthday promotion or reward is currently available.",
            "Birthday plans sound fun! 🎉 Check with DMD Family Resort staff about any birthday bonuses or special discounts.",
            "If it's your birthday, ask our staff whether there are any current birthday perks, rewards, or discounts available.",
            "Planning a birthday celebration? 🎂 Our staff can help you check for any available birthday specials.",
            "Happy birthday! 🎉 You can speak with our staff to find out whether any birthday bonus, reward, or discount is currently available.",
        ];

        $answer = $responses[random_int(0, count($responses) - 1)];

        return [
            'handled' => true,
            'intent' => $intent,
            'category_slug' => 'room',
            'answer' => $answer,
            'options' => [
                ['id' => 0, 'label' => 'Talk to Staff'],
            ],
            'context_updates' => [
                'current_category' => 'room',
                'last_intent' => $intent,
            ],
            'rule' => null,
        ];
    }

    private function respondWithNeedToUnwind(string $intent): array
    {
        $responses = [
            "That sounds rough. 💙 Maybe some quiet time to relax and unwind is exactly what you need. DMD Family Resort is here when you're ready.",
            "Heartbreak hits hard. 😭 A peaceful getaway and a little time to yourself might help you unwind.",
            "Feeling lost for a moment? 🌿 Sometimes a change of scenery and some peaceful rest can feel refreshing.",
            "Sounds like you could use a break. 💙 Come relax and enjoy some peaceful time at DMD Family Resort.",
            "Broken heart? 💔 Maybe it's time for a little getaway. Rest, relax, and give yourself some space to unwind.",
            "That's a tough feeling. 💙 A peaceful day away from the noise might be just what you're looking for.",
            "Sometimes you just need somewhere peaceful to clear your head. 🌿 DMD Family Resort could be your little escape.",
            "Feeling lost? Take things one day at a time. 💙 Maybe a relaxing getaway can give you some breathing room.",
            "Ouch, heartbreak. 💔 Time for some rest, fresh air, and a peaceful place to unwind.",
            "You sound like you could use a relaxing break. 🌿 Maybe it's time to step away from the noise for a while and unwind.",
            "When life feels heavy, a little rest can be nice. 💙 DMD Family Resort is here if you're looking for somewhere peaceful to relax.",
            "Heart needs a timeout? 💔 Maybe some peaceful resort time is calling your name. 😄",
            "Sometimes the best plan is no complicated plan at all-just relax, unwind, and enjoy a peaceful getaway. 🌿",
            "Sounds like life has been giving you a hard time. 😭 Maybe treat yourself to some well-deserved rest.",
            "Feeling brokenhearted? 💙 A quiet getaway won't solve everything, but it can be a nice place to rest and unwind.",
            "Take some time for yourself. 🌿 Rest, relax, enjoy the surroundings, and let things be quiet for a little while.",
            "Lost, tired, and heartbroken? 💔 Sounds like you might appreciate a peaceful place to slow down and unwind.",
            "Maybe it's getaway time. 😭🌿 Come enjoy a peaceful break and leave the everyday stress behind for a little while.",
            "A broken heart deserves some rest too. 💙 If you're looking for somewhere to unwind, I can help you explore your stay options.",
            "Relationship status: complicated. 😭 Vacation status: maybe needed. 😂 Want to see some relaxing stay options?",
        ];

        $answer = $responses[random_int(0, count($responses) - 1)];

        return [
            'handled' => true,
            'intent' => $intent,
            'category_slug' => 'room',
            'answer' => $answer,
            'options' => [],
            'context_updates' => [
                'current_category' => 'room',
                'last_intent' => $intent,
            ],
            'rule' => null,
        ];
    }

    private function respondWithApology(string $intent): array
    {
        $responses = [
            'No worries! 😊 How can I help you?',
            "It's all good! 😄 What can I help you with today?",
            "No need to apologize! 😊 I'm here to help.",
            "You're totally fine! 😄 What would you like to know?",
            'No problem at all! 😊 How can I assist you?',
            "Don't worry about it! 😄 What can I help you with?",
            'All good here! 😊 Feel free to ask me anything about DMD Family Resort.',
            "No worries at all! 😄 I'm still here and ready to help.",
            "That's okay! 😊 What would you like help with?",
            "You're good! 😄 How can I assist you with your stay?",
            'No apology needed! 😊 What can I do for you?',
            "It's perfectly okay! 😄 Let's continue. What would you like to know?",
            "No problem! 😊 I'm happy to keep helping you.",
            "We're good! 😄 What else would you like to ask?",
            'No worries, friend! 😊 How can I help with your resort plans?',
            "That's alright! 😄 I'm ready whenever you are.",
            'Apology accepted! 😎 Now, what can I help you with?',
            'Nothing to worry about! 😊 Feel free to continue.',
            "It's okay! 😄 Let's get back to planning your stay.",
            "We're cool! 😎 What would you like to know about DMD Family Resort?",
        ];

        $answer = $responses[random_int(0, count($responses) - 1)];

        return [
            'handled' => true,
            'intent' => $intent,
            'category_slug' => 'room',
            'answer' => $answer,
            'options' => [],
            'context_updates' => [
                'current_category' => 'room',
                'last_intent' => $intent,
            ],
            'rule' => null,
        ];
    }

    private function respondWithChatbotInsult(string $intent): array
    {
        $responses = [
            "Ouch 😅 Good thing I'm made of code. Anyway, how can I help with your stay?",
            "I'll pretend I didn't hear that. 😂 Need help with DMD Family Resort?",
            "My feelings.exe is still running fine. 😎 What can I help you with?",
            "That's cold. 😂 Anyway, I'm still here to help with your resort questions.",
            "You really woke up and chose violence against a chatbot. 😭 How can I help you?",
            "I'm just trying to do my job here. 😅 Need help with a room or reservation?",
            "Noted. 😂 I'll survive. What would you like to know about the resort?",
            "My confidence dropped by 0%. 😎 How can I assist you?",
            "Good thing I don't have feelings to hurt. 😂 What resort information do you need?",
            "That was brutal. 😅 I'm still ready to help though. What do you need?",
            "I'll add that to my imaginary complaint box. 😂 How can I help with your stay?",
            "Chatbot abuse detected. 😂 I'm kidding—what can I help you with?",
            "You roasted me and I'm still on duty. 😭 What would you like to know?",
            "That's one way to talk to guest assistance. 😂 Anyway, how can I help?",
            "My code survived that attack. 😎 Need help with rooms, bookings, or resort information?",
            "Okay, that was personal. 😭😂 I'm still here though. What do you need?",
            "My code wasn't ready for that roast. 😂 Anyway, how can I help?",
            "I'll recover from that devastating emotional damage. 😂 Need help with your booking?",
            "Roast accepted. 😅 Now, what can I actually help you with?",
            "You win that round. 😂 I'm still your DMD Family Resort assistant though. How can I help?",
        ];

        $answer = $responses[random_int(0, count($responses) - 1)];

        return [
            'handled' => true,
            'intent' => $intent,
            'category_slug' => 'room',
            'answer' => $answer,
            'options' => [],
            'context_updates' => [
                'current_category' => 'room',
                'last_intent' => $intent,
            ],
            'rule' => null,
        ];
    }

    private function respondWithFoodMention(string $intent): array
    {
        $responses = [
            "Getting hungry? 😋 For food-related questions, please check with our resort staff.",
            "That sounds tasty! 🍽️ Our staff can give you the most accurate information about food options.",
            "Food question detected! 😋 Please ask our resort staff about available food options.",
            "Now you're making me hungry. 😂 Please check with our staff for food-related information.",
            "For meals, snacks, or other food concerns, our resort staff will be happy to assist you.",
            "Thinking about food already? 😄 Please ask our staff about the food options available at the resort.",
            "Yum! 😋 That's best confirmed with DMD Family Resort staff.",
            "For anything food-related, please check directly with our resort staff so you get the correct information.",
            "I don't want to guess what's on the menu. 😅 Please ask our resort staff about available food.",
            "Food plans? 🍴 Our resort staff can tell you what's currently available.",
            "Sounds delicious! 😋 Please confirm food options with DMD Family Resort staff.",
            "For food availability or meal requests, our staff would be the best people to ask.",
            "Hungry? 🍽️ Our staff can help you with questions about meals, snacks, or food arrangements.",
            "That's one for our resort staff. 😄 They'll have the latest information about food options.",
            "I can help with your stay, but for specific food questions, please check with our staff.",
            "Before I accidentally invent a menu 😂, please ask our resort staff about the food options available.",
            "For food orders or meal arrangements, please speak with DMD Family Resort staff.",
            "Craving something? 😋 Our staff can let you know what food options are currently available.",
            "I'll leave the food questions to the humans. 😂 Please check with our resort staff for the correct information.",
            "For food, meals, or dining questions, our resort staff can assist you better.",
        ];

        $answer = $responses[random_int(0, count($responses) - 1)];

        $options = $this->foodMentionOptions($intent);

        return [
            'handled' => true,
            'intent' => $intent,
            'category_slug' => 'room',
            'answer' => $answer,
            'options' => $options,
            'context_updates' => [
                'current_category' => 'room',
                'last_intent' => $intent,
            ],
            'rule' => null,
        ];
    }

    /**
     * @return array<int, array{id:int,label:string}>
     */
    private function foodMentionOptions(string $intent): array
    {
        if (in_array($intent, ['outside_food_policy', 'meal_included', 'food_catering'], true)) {
            return [
                ['id' => 0, 'label' => 'Talk to Staff'],
            ];
        }

        return [];
    }

    private function respondWithIdentitySlur(string $intent): array
    {
        $responses = [
            "Let's keep the conversation respectful. How can I help with your stay?",
            "Please keep the chat respectful. I'm happy to help with resort questions.",
            "Let's avoid offensive language. What can I help you with today?",
            "Let's keep things friendly and respectful. Need help with your stay?",
            "That language isn't appropriate here. How can I assist you?",
            "Please keep the conversation guest-friendly. What would you like help with?",
            "Let's keep this a respectful space. Need information about the resort?",
            "I'd rather keep the conversation respectful. How can I help?",
            "Let's leave offensive language out of the chat. What do you need help with?",
            "Please use respectful language. I'm still here to help.",
            "Let's keep things positive and respectful. Need help with a room or reservation?",
            "That kind of language isn't welcome here. How can I help with your stay?",
            "Let's keep the chat welcoming for everyone. What can I help you find?",
            "Please keep things respectful. I can help with rooms, bookings, or resort information.",
            "Let's keep the conversation appropriate. What would you like to know?",
            "I caught that one. Let's keep things respectful and get back to your resort questions.",
            "Let's keep the chat friendly for everyone. How can I assist you?",
            "Please avoid offensive language. I'm happy to continue helping you.",
            "Let's keep it respectful. Need help with availability, rooms, or booking?",
            "That language isn't appropriate, but I'm still here to help with your resort stay.",
        ];

        $answer = $responses[random_int(0, count($responses) - 1)];

        return [
            'handled' => true,
            'intent' => $intent,
            'category_slug' => 'room',
            'answer' => $answer,
            'options' => [],
            'context_updates' => [
                'current_category' => 'room',
                'last_intent' => $intent,
            ],
            'rule' => null,
        ];
    }

    private function respondWithAnimalMention(string $intent): array
    {
        $responses = [
            "For animal-related concerns, it's best to talk with our resort staff so they can assist you properly.",
            "You should check with our resort staff about that animal-related question. They'll be able to give you the right information.",
            "That's something our resort staff can help you with. Please speak with them for the most accurate information.",
            "For questions involving animals, I recommend checking directly with DMD Family Resort staff.",
            "Our resort staff would be the best people to ask about that. Would you like to talk to staff?",
            "I'm not able to confirm animal-related policies or situations. Please check with our resort staff.",
            "You should talk with our staff about that one. They'll know how to handle animal-related concerns.",
            "For that question, I'd recommend speaking directly with resort staff.",
            "That's an animal-related concern, so our resort staff would be the best people to assist you.",
            "I'd rather not guess about that. Please ask our resort staff for the correct information.",
            "Our staff can give you a more accurate answer about that animal-related question.",
            "That's best confirmed with DMD Family Resort staff. They can assist you directly.",
            "For anything involving animals at the resort, please check with our staff first.",
            "I'm not sure about that animal-related situation. Our resort staff can help you with it.",
            "Please speak with our staff about that. I don't want to give you incorrect information about animals at the resort.",
            "That's one for our resort staff. They'll be able to provide the proper guidance.",
            "I recommend asking DMD Family Resort staff about that before making any plans.",
            "Animal-related questions are best handled by our resort staff. Would you like help contacting them?",
            "I can't confirm that one myself. Please check with resort staff for animal-related concerns.",
            "Our staff would be happy to help with that question. It's best to confirm animal-related matters directly with them.",
        ];

        $answer = $responses[random_int(0, count($responses) - 1)];

        return [
            'handled' => true,
            'intent' => $intent,
            'category_slug' => 'room',
            'answer' => $answer,
            'options' => [
                ['id' => 0, 'label' => 'Talk to Staff'],
            ],
            'context_updates' => [
                'current_category' => 'room',
                'last_intent' => $intent,
            ],
            'rule' => null,
        ];
    }

    private function respondWithDeveloperEasterEgg(string $intent): array
    {
        $answer = match ($intent) {
            'website_creator' => 'The DMD Family Resort website was created by Earlpogi. 😎 You found the developer Easter egg!',
            'system_creator' => 'The DMD Family Resort website/system was created by Earlpogi. 😎 You found the developer Easter egg!',
            'direct_name' => 'Earlpogi is the creator of the DMD Family Resort website. 😎 You found the developer Easter egg!',
            'about_name' => 'Earlpogi is the creator of the DMD Family Resort website. 😎',
            'developer_mode' => 'Developer Easter egg unlocked. 😎 The DMD Family Resort website was created by Earlpogi.',
            'earlpogi_mode' => '😎 Earlpogi Mode activated. Developer Easter egg unlocked!',
            'konami' => '🎮 Secret code accepted. Earlpogi built this website. 😎',
            default => 'The DMD Family Resort website was created by Earlpogi. 😎 You found the developer Easter egg!',
        };

        return [
            'handled' => true,
            'intent' => $intent,
            'category_slug' => 'room',
            'answer' => $answer,
            'options' => [],
            'context_updates' => [],
            'rule' => null,
        ];
    }

    private function removeOffensiveNoise(string $normalized): string
    {
        $cleaned = preg_replace([
            '/\bfuck\b/ui',
            '/\bfucking\b/ui',
            '/\bfucked\b/ui',
            '/\bfucker\b/ui',
            '/\bwtf\b/ui',
            '/\bshit\b/ui',
            '/\bshitty\b/ui',
            '/\bbullshit\b/ui',
            '/\bholy shit\b/ui',
            '/\bdamn\b/ui',
            '/\bdammit\b/ui',
            '/\bgoddamn\b/ui',
            '/\basshole\b/ui',
            '/\bass\b/ui',
            '/\bbitch\b/ui',
            '/\bbastard\b/ui',
            '/\bcrap\b/ui',
            '/\bpiss\b/ui',
            '/\bpissed\b/ui',
            '/\bmotherfucker\b/ui',
            '/\bmotherfucking\b/ui',
            '/\b(?:n-?word|nword)\b/ui',
            '/\bn word\b/ui',
            '/\b(?:nigger|nigga)\b/ui',
            '/\b(?:n[i1!]gg[ae@]r|n[i1!]gg[ae@])\b/ui',
        ], ' ', $normalized);

        return trim(preg_replace('/\s+/u', ' ', (string) $cleaned));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Accommodation>  $rooms
     * @return array<int, array{id:int,label:string}>
     */
    private function roomChoices(Collection $rooms): array
    {
        return $rooms->map(fn (Accommodation $room) => [
            'id' => $room->id,
            'label' => $room->name,
        ])->values()->all();
    }

    private function resolveRoomByNumber(Collection $rooms, int $number): ?Accommodation
    {
        return $rooms->first(function (Accommodation $room) use ($number) {
            $normalizedName = $this->normalizer->normalize($room->name);

            return preg_match('/\broom\s+'.preg_quote((string) $number, '/').'\b/u', $normalizedName) === 1;
        });
    }

    private function extractGuestCount(string $normalized): ?int
    {
        if (preg_match('/^\d{1,2}$/u', trim($normalized), $matches)) {
            return (int) $matches[0];
        }

        $patterns = [
            '/\b(?:for|with)\s+(\d{1,2})\s+(?:guests?|people|persons?)\b/u',
            '/\b(?:family|group)\s+of\s+(\d{1,2})\b/u',
            '/\bwe(?:\'?re| are)?\s+(\d{1,2})\b/u',
            '/\bthere are\s+(\d{1,2})\s+of us\b/u',
            '/\broom\s+for\s+(\d{1,2})\b/u',
            '/\b(\d{1,2})\s+(?:guests?|people|persons?)\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function extractStayDays(string $normalized): ?int
    {
        if (preg_match('/\b(?:stay|for)\s+(\d{1,2})\s+days?\b/u', $normalized, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/\b(\d{1,2})\s+days?\b/u', $normalized, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function extractDate(string $normalized): ?string
    {
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/u', $normalized, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\b(\d{4})\s+(\d{1,2})\s+(\d{1,2})\b/u', $normalized, $matches)) {
            try {
                return CarbonImmutable::createFromDate((int) $matches[1], (int) $matches[2], (int) $matches[3], config('app.timezone'))->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        if (preg_match('/\b(?:today|tomorrow)\b/u', $normalized, $matches)) {
            $date = now(config('app.timezone'));
            return $matches[0] === 'tomorrow' ? $date->addDay()->toDateString() : $date->toDateString();
        }

        if (preg_match('/\b([a-z]{3,9})\s+(\d{1,2})(?:\s+(\d{4}))?\b/u', $normalized, $matches)) {
            $year = $matches[3] ?? now(config('app.timezone'))->year;
            try {
                return CarbonImmutable::parse("{$matches[1]} {$matches[2]} {$year}", config('app.timezone'))->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function extractTime(string $normalized): ?string
    {
        if (preg_match('/\b(\d{1,2}:\d{2}\s?(?:am|pm)?)\b/u', $normalized, $matches)) {
            $candidate = trim($matches[1]);
            try {
                return CarbonImmutable::parse($candidate, config('app.timezone'))->format('H:i');
            } catch (\Throwable) {
                return null;
            }
        }

        if (preg_match('/\b(\d{1,2})\s+(\d{2})\s?(am|pm)\b/u', $normalized, $matches)) {
            $candidate = trim($matches[1].':'.$matches[2].' '.$matches[3]);
            try {
                return CarbonImmutable::parse($candidate, config('app.timezone'))->format('H:i');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function detectAmenityFilter(string $normalized, Collection $rooms): ?string
    {
        $keywords = ['aircon', 'air conditioning', 'wifi', 'wi fi', 'tv', 'television', 'bathroom', 'private bathroom'];

        foreach ($keywords as $keyword) {
            if (! Str::contains($normalized, $this->normalizer->normalize($keyword))) {
                continue;
            }

            $match = $rooms
                ->flatMap(fn (Accommodation $room) => $room->amenities)
                ->first(function ($amenity) use ($keyword) {
                    return Str::contains($this->normalizer->normalize((string) ($amenity->name ?? '')), $this->normalizer->normalize($keyword));
                });

            if ($match) {
                return (string) $match->name;
            }
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Accommodation>  $rooms
     */
    private function filterRooms(Collection $rooms, ?int $guestCount, ?string $amenityName): Collection
    {
        $filtered = $rooms;

        if ($guestCount) {
            $filtered = $filtered->filter(fn (Accommodation $room) => $room->capacity >= $guestCount);
        }

        if ($amenityName) {
            $normalizedAmenity = $this->normalizer->normalize($amenityName);
            $filtered = $filtered->filter(function (Accommodation $room) use ($normalizedAmenity) {
                return $room->amenities->contains(function ($amenity) use ($normalizedAmenity) {
                    return Str::contains($this->normalizer->normalize((string) ($amenity->name ?? '')), $normalizedAmenity);
                });
            });
        }

        return $filtered->values();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildPriceResponse(Accommodation $room, array $context, string $intent): array
    {
        $candidates = $this->resolveCandidateRooms($context);

        if ($candidates->count() > 1 && ! $context['selected_accommodation_id']) {
            $prices = $candidates->pluck('price_per_night')->unique();
            if ($prices->count() === 1) {
                $names = $candidates->pluck('name')->join(', ', ' and ');
                return [
                    'handled' => true,
                    'intent' => $intent,
                    'category_slug' => 'room',
                    'answer' => $names.' are '.$this->formatCurrency((float) $candidates->first()->price_per_night).' per 24-hour stay.',
                    'options' => $this->roomChoices($candidates),
                    'context_updates' => [
                        'current_category' => 'room',
                        'last_intent' => $intent,
                        'candidate_accommodation_ids' => $candidates->pluck('id')->all(),
                    ],
                    'rule' => null,
                ];
            }

            return [
                'handled' => true,
                'intent' => $intent,
                'category_slug' => 'room',
                'answer' => 'Which room would you like to check?',
                'options' => $this->roomChoices($candidates),
                'context_updates' => [
                    'current_category' => 'room',
                    'last_intent' => $intent,
                    'candidate_accommodation_ids' => $candidates->pluck('id')->all(),
                ],
                'rule' => null,
            ];
        }

        return [
            'handled' => true,
            'intent' => $intent,
            'category_slug' => 'room',
            'answer' => "{$room->name} is ".$this->formatCurrency((float) $room->price_per_night).' per 24-hour stay.',
            'options' => [],
            'context_updates' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $room->id,
                'candidate_accommodation_ids' => [],
                'last_intent' => $intent,
            ],
            'selected_accommodation_id' => $room->id,
            'rule' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildCapacityResponse(Accommodation $room, array $context, string $intent): array
    {
        $candidates = $this->resolveCandidateRooms($context);
        if ($candidates->count() > 1 && ! $context['selected_accommodation_id']) {
            $capacities = $candidates->pluck('capacity')->unique();
            if ($capacities->count() === 1) {
                $names = $candidates->pluck('name')->join(', ', ' and ');
                return [
                    'handled' => true,
                    'intent' => $intent,
                    'category_slug' => 'room',
                    'answer' => $names.' can accommodate up to '.$candidates->first()->capacity.' guests.',
                    'options' => $this->roomChoices($candidates),
                    'context_updates' => [
                        'current_category' => 'room',
                        'last_intent' => $intent,
                        'candidate_accommodation_ids' => $candidates->pluck('id')->all(),
                    ],
                    'rule' => null,
                ];
            }

            return [
                'handled' => true,
                'intent' => $intent,
                'category_slug' => 'room',
                'answer' => 'Which room would you like to check?',
                'options' => $this->roomChoices($candidates),
                'context_updates' => [
                    'current_category' => 'room',
                    'last_intent' => $intent,
                    'candidate_accommodation_ids' => $candidates->pluck('id')->all(),
                ],
                'rule' => null,
            ];
        }

        return [
            'handled' => true,
            'intent' => $intent,
            'category_slug' => 'room',
            'answer' => "{$room->name} can accommodate up to {$room->capacity} guests.",
            'options' => [],
            'context_updates' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $room->id,
                'candidate_accommodation_ids' => [],
                'last_intent' => $intent,
            ],
            'selected_accommodation_id' => $room->id,
            'rule' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildAmenitiesResponse(Accommodation $room, array $context, string $intent): array
    {
        $candidates = $this->resolveCandidateRooms($context);
        if ($candidates->count() > 1 && ! $context['selected_accommodation_id']) {
            return [
                'handled' => true,
                'intent' => $intent,
                'category_slug' => 'room',
                'answer' => 'Which room would you like to check?',
                'options' => $this->roomChoices($candidates),
                'context_updates' => [
                    'current_category' => 'room',
                    'last_intent' => $intent,
                    'candidate_accommodation_ids' => $candidates->pluck('id')->all(),
                ],
                'rule' => null,
            ];
        }

        $amenities = $room->amenities->pluck('name')->filter()->values();
        $answer = $amenities->isEmpty()
            ? "{$room->name} does not currently list any public amenities."
            : "{$room->name} has ".$amenities->implode(', ').'.';

        return [
            'handled' => true,
            'intent' => $intent,
            'category_slug' => 'room',
            'answer' => $answer,
            'options' => [],
            'context_updates' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $room->id,
                'candidate_accommodation_ids' => [],
                'last_intent' => $intent,
            ],
            'selected_accommodation_id' => $room->id,
            'rule' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildDetailsResponse(Accommodation $room, array $context, string $intent): array
    {
        $candidates = $this->resolveCandidateRooms($context);
        if ($candidates->count() > 1 && ! $context['selected_accommodation_id']) {
            return [
                'handled' => true,
                'intent' => $intent,
                'category_slug' => 'room',
                'answer' => 'Which room would you like to check?',
                'options' => $this->roomChoices($candidates),
                'context_updates' => [
                    'current_category' => 'room',
                    'last_intent' => $intent,
                    'candidate_accommodation_ids' => $candidates->pluck('id')->all(),
                ],
                'rule' => null,
            ];
        }

        $amenities = $room->amenities->pluck('name')->filter()->values();
        $description = rtrim((string) $room->description, " \t\n\r\0\x0B.");
        $answer = "{$room->name}: {$description}. Capacity up to {$room->capacity} guests. Price ".$this->formatCurrency((float) $room->price_per_night).' per 24-hour stay.'
            .($amenities->isNotEmpty() ? ' Amenities: '.$amenities->implode(', ').'.' : '');

        return [
            'handled' => true,
            'intent' => $intent,
            'category_slug' => 'room',
            'answer' => $answer,
            'options' => [],
            'context_updates' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $room->id,
                'candidate_accommodation_ids' => [],
                'last_intent' => $intent,
            ],
            'selected_accommodation_id' => $room->id,
            'rule' => null,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Accommodation>  $rooms
     * @return array<string, mixed>
     */
    private function respondWithNoMatch(Collection $rooms, array $context): array
    {
        return [
            'handled' => true,
            'intent' => 'clarify',
            'category_slug' => 'room',
            'answer' => 'I can help with rooms, rates, capacity, amenities, availability, or compare options. Which room would you like to check?',
            'options' => $this->roomChoices($rooms->take(4)),
            'context_updates' => [
                'current_category' => 'room',
                'last_intent' => 'clarify',
                'candidate_accommodation_ids' => [],
            ],
            'rule' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return \Illuminate\Support\Collection<int, Accommodation>
     */
    private function resolveCandidateRooms(array $context): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $context['candidate_accommodation_ids'] ?? [])));

        if ($ids === []) {
            return collect();
        }

        $rooms = $this->roomsQuery()->get();

        return $rooms->whereIn('id', $ids)->values();
    }

    private function formatCurrency(float $value): string
    {
        return 'PHP '.number_format($value, 2);
    }
}
