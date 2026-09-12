<?php

namespace Tests\Feature\Chatbot;

use App\Models\Accommodation;
use App\Models\ChatConversation;
use App\Models\Reservation;
use App\Services\ChatbotService;
use Illuminate\Support\Str;

class RoomChatbotAssistantTest extends ChatbotTestCase
{
    public function test_room_lookup_returns_structured_room_choices(): void
    {
        $room1 = $this->createAccommodation([
            'name' => 'Room 1',
            'slug' => 'room-1',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 2500,
            'description' => 'Room 1 description.',
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $this->createAccommodation([
            'name' => 'Room 2',
            'slug' => 'room-2',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 6,
            'price_per_night' => 3200,
            'description' => 'Room 2 description.',
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $response = app(ChatbotService::class)->match('show rooms');

        $this->assertTrue($response['handled']);
        $this->assertSame('room', $response['category_slug']);
        $this->assertSame('room_list', $response['intent']);
        $this->assertNull($response['selected_accommodation_id'] ?? null);
        $this->assertStringContainsString('Room 1', $response['answer']);
        $this->assertStringContainsString('Room 2', $response['answer']);
        $this->assertSame($room1->id, $response['options'][0]['id']);
        $this->assertSame('Room 1', $response['options'][0]['label']);
    }

    public function test_room_follow_up_uses_selected_room_context_for_price(): void
    {
        $room = $this->createAccommodation([
            'name' => 'Room 1',
            'slug' => 'room-1',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 2500,
            'description' => 'Room 1 description.',
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $conversation = ChatConversation::create([
            'conversation_uuid' => (string) Str::uuid(),
            'access_token' => Str::random(64),
            'status' => ChatConversation::STATUS_BOT,
            'context' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $room->id,
                'last_intent' => 'details',
            ],
            'last_message_at' => now(),
        ]);

        $response = app(ChatbotService::class)->match('How much?', $conversation);

        $this->assertTrue($response['handled']);
        $this->assertSame('room', $response['category_slug']);
        $this->assertSame('price', $response['intent']);
        $this->assertStringContainsString('Room 1', $response['answer']);
        $this->assertStringContainsString('PHP 2,500.00', $response['answer']);
    }

    public function test_room_availability_uses_existing_reservation_overlap(): void
    {
        $room = $this->createAccommodation([
            'name' => 'Room 1',
            'slug' => 'room-1',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 2500,
            'description' => 'Room 1 description.',
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $guest = $this->guestUser();
        Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $room->id,
            'check_in' => '2026-08-30',
            'check_out' => '2026-08-31',
            'check_in_at' => '2026-08-30 14:00:00',
            'check_out_at' => '2026-08-31 14:00:00',
            'guests' => 2,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'total_amount' => 2500,
            'status' => Reservation::STATUS_CONFIRMED,
            'booking_reference' => 'CHAT-ROOM-1',
        ]);

        $response = app(ChatbotService::class)->match(
            'Is it available on 2026-08-30 at 2:00 pm for 1 day?',
            null,
            ['selected_accommodation_id' => $room->id]
        );

        $this->assertTrue($response['handled']);
        $this->assertSame('availability', $response['intent']);
        $this->assertStringContainsString('not available', strtolower($response['answer']));
    }

    public function test_room_for_family_prompts_for_guest_count_then_filters_capacity(): void
    {
        $room3 = $this->createAccommodation([
            'name' => 'Room 3',
            'slug' => 'room-3',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 3,
            'price_per_night' => 2200,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);
        $room5 = $this->createAccommodation([
            'name' => 'Room 5',
            'slug' => 'room-5',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 5,
            'price_per_night' => 3200,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $conversation = ChatConversation::create([
            'conversation_uuid' => (string) Str::uuid(),
            'access_token' => Str::random(64),
            'status' => ChatConversation::STATUS_BOT,
            'context' => [
                'current_category' => 'room',
                'pending_intent' => 'family_recommendation',
            ],
            'last_message_at' => now(),
        ]);

        $prompt = app(ChatbotService::class)->match('room for family', $conversation);
        $this->assertSame('family_recommendation', $prompt['intent']);
        $this->assertStringContainsString('How many guests', $prompt['answer']);

        $answer = app(ChatbotService::class)->match('5', $conversation, null);
        $this->assertSame('family_recommendation', $answer['intent']);
        $this->assertStringContainsString('Room 5', $answer['answer']);
        $this->assertStringNotContainsString('Room 3', $answer['answer']);
    }

    public function test_room_family_direct_query_with_ties_returns_all_tied_rooms(): void
    {
        $roomA = $this->createAccommodation([
            'name' => 'Room A',
            'slug' => 'room-a',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 5,
            'price_per_night' => 3000,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);
        $roomB = $this->createAccommodation([
            'name' => 'Room B',
            'slug' => 'room-b',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 6,
            'price_per_night' => 3000,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $response = app(ChatbotService::class)->match('room for family of 5');

        $this->assertSame('family_recommendation', $response['intent']);
        $this->assertStringContainsString('share the lowest rate', $response['answer']);
        $this->assertCount(2, $response['options']);
        $this->assertNull($response['selected_accommodation_id'] ?? null);
        $optionIds = array_column($response['options'], 'id');
        sort($optionIds);
        $expectedIds = [$roomA->id, $roomB->id];
        sort($expectedIds);
        $this->assertSame($expectedIds, $optionIds);
        $this->assertSame($roomA->id, $response['options'][0]['id']);
        $this->assertSame($roomB->id, $response['options'][1]['id']);
    }

    public function test_family_result_lists_matching_rooms_and_keeps_candidates_for_follow_up(): void
    {
        $room1 = $this->createAccommodation([
            'name' => 'Room 1',
            'slug' => 'room-1',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);
        $room2 = $this->createAccommodation([
            'name' => 'Room 2',
            'slug' => 'room-2',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 5,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $conversation = ChatConversation::create([
            'conversation_uuid' => (string) Str::uuid(),
            'access_token' => Str::random(64),
            'status' => ChatConversation::STATUS_BOT,
            'context' => [
                'current_category' => 'room',
                'pending_intent' => 'family_recommendation',
                'pending_field' => 'guest_count',
            ],
            'last_message_at' => now(),
        ]);

        $prompt = app(ChatbotService::class)->match('room for family', $conversation);
        $this->assertSame('family_recommendation', $prompt['intent']);
        $this->assertStringContainsString('How many guests', $prompt['answer']);

        $response = app(ChatbotService::class)->match('2', $conversation);
        $this->assertSame('family_recommendation', $response['intent']);
        $this->assertStringContainsString('Room 1', $response['answer']);
        $this->assertStringContainsString('Room 2', $response['answer']);
        $this->assertNull($response['selected_accommodation_id'] ?? null);
        $this->assertCount(2, $response['options']);

        $conversation->forceFill([
            'context' => array_replace_recursive($conversation->context ?? [], $response['context_updates'] ?? []),
        ])->save();

        $followUp = app(ChatbotService::class)->match('how much?', $conversation);
        $this->assertSame('price', $followUp['intent']);
        $this->assertStringContainsString('Room 1', $followUp['answer']);
        $this->assertStringContainsString('Room 2', $followUp['answer']);
        $this->assertStringContainsString('PHP 2,500.00', $followUp['answer']);
    }

    public function test_best_room_requests_criteria_instead_of_picking_arbitrarily(): void
    {
        $this->createAccommodation([
            'name' => 'Room 1',
            'slug' => 'room-1',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 3,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $response = app(ChatbotService::class)->match('best room');

        $this->assertSame('clarify_ranking', $response['intent']);
        $this->assertStringContainsString('i can help you find the cheapest room', strtolower($response['answer']));
        $this->assertNull($response['selected_accommodation_id'] ?? null);
    }

    public function test_small_talk_phrases_are_handled_without_overriding_room_requests(): void
    {
        $room = $this->createAccommodation([
            'name' => 'Room 1',
            'slug' => 'room-1',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 3,
            'price_per_night' => 2500,
            'description' => 'Room 1 description.',
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $cases = [
            ['how are you?', 'how_are_you', "I'm doing well"],
            ['nice to meet you', 'introduction', 'Nice to meet you too'],
            ['i love you', 'affection', "That's sweet"],
            ['you are cute', 'compliment', 'thank you'],
            ['can i hug you?', 'physical_affection', "I'll stick to helping"],
            ['awesome', 'positive_feedback', 'Thank you'],
            ['okay', 'acknowledgement', 'Let me know what you would like help with'],
            ['haha', 'laughter', 'How can I help with your stay'],
        ];

        foreach ($cases as [$message, $intent, $expected]) {
            $response = app(ChatbotService::class)->match($message);
            $this->assertSame($intent, $response['intent']);
            $this->assertStringContainsString($expected, $response['answer']);
        }

        $roomContext = ChatConversation::create([
            'conversation_uuid' => (string) Str::uuid(),
            'access_token' => Str::random(64),
            'status' => ChatConversation::STATUS_BOT,
            'context' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $room->id,
            ],
            'last_message_at' => now(),
        ]);

        $smallTalk = app(ChatbotService::class)->match('how are you?', $roomContext);
        $this->assertSame('how_are_you', $smallTalk['intent']);

        $followUp = app(ChatbotService::class)->match('amenities?', $roomContext);
        $this->assertSame('amenities', $followUp['intent']);
        $this->assertStringContainsString('Room 1', $followUp['answer']);

        $roomRequest = app(ChatbotService::class)->match('hi sexy how much is Room 1?');
        $this->assertSame('price', $roomRequest['intent']);
        $this->assertStringContainsString('Room 1', $roomRequest['answer']);

        $availability = app(ChatbotService::class)->match('do you love me and is Room 1 available?');
        $this->assertSame('availability', $availability['intent']);
        $this->assertStringContainsString('Room 1', $availability['answer']);

        $listing = app(ChatbotService::class)->match('okay show rooms');
        $this->assertSame('room_list', $listing['intent']);
        $this->assertStringContainsString('Room 1', $listing['answer']);
    }

    public function test_scoped_fallback_covers_unrelated_questions(): void
    {
        $response = app(ChatbotService::class)->match('1+1?');

        $this->assertNull($response['rule'] ?? null);
        $this->assertContains($response['answer'], app(ChatbotService::class)->fallbackResponses());
    }

    public function test_unknown_message_does_not_clear_room_context(): void
    {
        $room = $this->createAccommodation([
            'name' => 'Room 2',
            'slug' => 'room-2',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 3,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $conversation = ChatConversation::create([
            'conversation_uuid' => (string) Str::uuid(),
            'access_token' => Str::random(64),
            'status' => ChatConversation::STATUS_BOT,
            'context' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $room->id,
            ],
            'last_message_at' => now(),
        ]);

        $unknown = app(ChatbotService::class)->match('asdfghjkl', $conversation);
        $this->assertContains($unknown['answer'], app(ChatbotService::class)->fallbackResponses());

        $followUp = app(ChatbotService::class)->match('capacity?', $conversation);
        $this->assertSame('capacity', $followUp['intent']);
        $this->assertStringContainsString('Room 2', $followUp['answer']);
    }

    public function test_developer_easter_egg_and_safe_priority_behavior(): void
    {
        $room = $this->createAccommodation([
            'name' => 'Room 1',
            'slug' => 'room-1',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 3,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        foreach (['Earlpogi', 'earlpogi', 'EARLPOGI', 'EarlPogi', 'earlpogi!'] as $message) {
            $response = app(ChatbotService::class)->match($message);
            $this->assertSame('direct_name', $response['intent']);
            $this->assertStringContainsString('Earlpogi', $response['answer']);
        }

        $websiteCreator = app(ChatbotService::class)->match('who created this website?');
        $this->assertSame('website_creator', $websiteCreator['intent']);
        $this->assertStringContainsString('Earlpogi', $websiteCreator['answer']);

        $systemCreator = app(ChatbotService::class)->match('who created this system?');
        $this->assertSame('system_creator', $systemCreator['intent']);
        $this->assertStringContainsString('Earlpogi', $systemCreator['answer']);

        $developerMode = app(ChatbotService::class)->match('developer mode');
        $this->assertSame('developer_mode', $developerMode['intent']);
        $this->assertStringContainsString('Developer Easter egg unlocked', $developerMode['answer']);

        $earlpogiMode = app(ChatbotService::class)->match('earlpogi mode');
        $this->assertSame('earlpogi_mode', $earlpogiMode['intent']);
        $this->assertStringContainsString('Earlpogi Mode activated', $earlpogiMode['answer']);

        $konami = app(ChatbotService::class)->match('up up down down left right left right b a');
        $this->assertSame('konami', $konami['intent']);
        $this->assertStringContainsString('Secret code accepted', $konami['answer']);

        $ownerQuestion = app(ChatbotService::class)->match('who owns DMD Family Resort?');
        $this->assertNotSame('direct_name', $ownerQuestion['intent']);
        $this->assertStringNotContainsString('Earlpogi', $ownerQuestion['answer']);

        $roomRequest = app(ChatbotService::class)->match('Earlpogi show me rooms');
        $this->assertSame('room_list', $roomRequest['intent']);

        $priceRequest = app(ChatbotService::class)->match('hey Earlpogi how much is Room 1?');
        $this->assertSame('price', $priceRequest['intent']);
        $this->assertStringContainsString('Room 1', $priceRequest['answer']);

        $availabilityRequest = app(ChatbotService::class)->match('Earlpogi is Room 1 available?');
        $this->assertSame('availability', $availabilityRequest['intent']);
        $this->assertStringContainsString('Room 1', $availabilityRequest['answer']);

        $conversation = ChatConversation::create([
            'conversation_uuid' => (string) Str::uuid(),
            'access_token' => Str::random(64),
            'status' => ChatConversation::STATUS_BOT,
            'context' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $room->id,
            ],
            'last_message_at' => now(),
        ]);

        $developer = app(ChatbotService::class)->match('who created this website?', $conversation);
        $this->assertSame('website_creator', $developer['intent']);

        $followUp = app(ChatbotService::class)->match('capacity?', $conversation);
        $this->assertSame('capacity', $followUp['intent']);
        $this->assertStringContainsString('Room 1', $followUp['answer']);
    }

    public function test_profanity_is_handled_with_friendly_randomized_responses(): void
    {
        $room1 = $this->createAccommodation([
            'name' => 'Room 1',
            'slug' => 'room-1',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);
        $room2 = $this->createAccommodation([
            'name' => 'Room 2',
            'slug' => 'room-2',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 3,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

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

        foreach (['fuck', 'FUCK', 'shit', 'damn', 'fuck you', 'wtf', 'asshole'] as $message) {
            $response = app(ChatbotService::class)->match($message);
            $this->assertSame('profanity', $response['intent']);
            $this->assertContains($response['answer'], $responses);
        }

        $roomContext = ChatConversation::create([
            'conversation_uuid' => (string) Str::uuid(),
            'access_token' => Str::random(64),
            'status' => ChatConversation::STATUS_BOT,
            'context' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $room2->id,
            ],
            'last_message_at' => now(),
        ]);

        $profanity = app(ChatbotService::class)->match('damn', $roomContext);
        $this->assertSame('profanity', $profanity['intent']);

        $followUp = app(ChatbotService::class)->match('capacity?', $roomContext);
        $this->assertSame('capacity', $followUp['intent']);
        $this->assertStringContainsString('Room 2', $followUp['answer']);

        $roomRequest = app(ChatbotService::class)->match('show me the fucking rooms');
        $this->assertSame('room_list', $roomRequest['intent']);

        $priceRequest = app(ChatbotService::class)->match('how much is fucking Room 1?');
        $this->assertSame('price', $priceRequest['intent']);
        $this->assertStringContainsString('Room 1', $priceRequest['answer']);

        $cheapest = app(ChatbotService::class)->match("what's the cheapest fucking room?");
        $this->assertSame('cheapest', $cheapest['intent']);

        $availability = app(ChatbotService::class)->match('is Room 2 fucking available?');
        $this->assertSame('availability', $availability['intent']);

        $developer = app(ChatbotService::class)->match('who the fuck made this website?');
        $this->assertSame('website_creator', $developer['intent']);
        $this->assertStringContainsString('Earlpogi', $developer['answer']);

        $falsePositive = app(ChatbotService::class)->match('class');
        $this->assertFalse($falsePositive['handled'] ?? false);
    }

    public function test_identity_based_slur_is_handled_with_respectful_randomized_responses(): void
    {
        $room2 = $this->createAccommodation([
            'name' => 'Room 2',
            'slug' => 'room-2',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 3,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

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

        $variants = [
            'n-word',
            'N-WORD',
            'nword',
        ];

        foreach ($variants as $message) {
            $response = app(ChatbotService::class)->match($message);
            $this->assertSame('identity_slur', $response['intent']);
            $this->assertContains($response['answer'], $responses);
            $this->assertStringNotContainsStringIgnoringCase('n-word', $response['answer']);
        }

        $roomContext = ChatConversation::create([
            'conversation_uuid' => (string) Str::uuid(),
            'access_token' => Str::random(64),
            'status' => ChatConversation::STATUS_BOT,
            'context' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $room2->id,
            ],
            'last_message_at' => now(),
        ]);

        $slurOnly = app(ChatbotService::class)->match('n-word', $roomContext);
        $this->assertSame('identity_slur', $slurOnly['intent']);

        $followUp = app(ChatbotService::class)->match('capacity?', $roomContext);
        $this->assertSame('capacity', $followUp['intent']);
        $this->assertStringContainsString('Room 2', $followUp['answer']);

        $roomRequest = app(ChatbotService::class)->match('show me the n-word rooms');
        $this->assertSame('room_list', $roomRequest['intent']);

        $priceRequest = app(ChatbotService::class)->match('how much is Room 2 n-word?');
        $this->assertSame('price', $priceRequest['intent']);
        $this->assertStringContainsString('Room 2', $priceRequest['answer']);

        $developer = app(ChatbotService::class)->match('who the n-word made this website?');
        $this->assertSame('website_creator', $developer['intent']);
        $this->assertStringContainsString('Earlpogi', $developer['answer']);

        $falsePositive = app(ChatbotService::class)->match('banana');
        $this->assertFalse($falsePositive['handled'] ?? false);
    }

    public function test_animal_mentions_recommend_talking_to_staff_without_breaking_room_context(): void
    {
        $room2 = $this->createAccommodation([
            'name' => 'Room 2',
            'slug' => 'room-2',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 3,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

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

        foreach (['dog', 'cat', 'snake', 'spider', 'bird'] as $message) {
            $response = app(ChatbotService::class)->match($message);
            $this->assertSame('animal_mention', $response['intent']);
            $this->assertContains($response['answer'], $responses);
            $this->assertStringNotContainsStringIgnoringCase('there may be', $response['answer']);
            $this->assertStringNotContainsStringIgnoringCase('allowed', $response['answer']);
        }

        foreach (['can I bring my dog?', 'are pets allowed?', 'is this resort pet friendly?'] as $message) {
            $response = app(ChatbotService::class)->match($message);
            $this->assertSame('animal_mention', $response['intent']);
            $this->assertContains($response['answer'], $responses);
        }

        foreach (['are there snakes?', 'do you have cats?'] as $message) {
            $response = app(ChatbotService::class)->match($message);
            $this->assertSame('animal_mention', $response['intent']);
            $this->assertContains($response['answer'], $responses);
        }

        $roomRequest = app(ChatbotService::class)->match('which room allows dogs?');
        $this->assertSame('animal_mention', $roomRequest['intent']);

        $roomAndAnimal = app(ChatbotService::class)->match('is Room 2 available for me and my dog?');
        $this->assertSame('animal_mention', $roomAndAnimal['intent']);

        $conversation = ChatConversation::create([
            'conversation_uuid' => (string) Str::uuid(),
            'access_token' => Str::random(64),
            'status' => ChatConversation::STATUS_BOT,
            'context' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $room2->id,
            ],
            'last_message_at' => now(),
        ]);

        $animalOnly = app(ChatbotService::class)->match('dog', $conversation);
        $this->assertSame('animal_mention', $animalOnly['intent']);

        $followUp = app(ChatbotService::class)->match('capacity?', $conversation);
        $this->assertSame('capacity', $followUp['intent']);
        $this->assertStringContainsString('Room 2', $followUp['answer']);

        $petQuestion = app(ChatbotService::class)->match('can my dog stay in Room 1?');
        $this->assertSame('animal_mention', $petQuestion['intent']);
        $this->assertContains($petQuestion['answer'], $responses);
    }

    public function test_birthday_mentions_recommend_staff_without_promising_discounts(): void
    {
        $room1 = $this->createAccommodation([
            'name' => 'Room 1',
            'slug' => 'room-1',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $room2 = $this->createAccommodation([
            'name' => 'Room 2',
            'slug' => 'room-2',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 3,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

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

        foreach (['birthday', "it's my birthday", 'today is my birthday', 'birthday celebration', 'birthday discount?', 'do I get a birthday reward?', 'is there a birthday bonus?', 'birthday promo?', 'can I celebrate my birthday there?'] as $message) {
            $response = app(ChatbotService::class)->match($message);
            $this->assertSame('birthday_mention', $response['intent']);
            $this->assertContains($response['answer'], $responses);
            $this->assertStringNotContainsString('20% off', $response['answer']);
            $this->assertStringNotContainsString('free', strtolower($response['answer']));
        }

        $conversation = ChatConversation::create([
            'conversation_uuid' => (string) Str::uuid(),
            'access_token' => Str::random(64),
            'status' => ChatConversation::STATUS_BOT,
            'context' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $room2->id,
            ],
            'last_message_at' => now(),
        ]);

        $birthday = app(ChatbotService::class)->match("it's my birthday", $conversation);
        $this->assertSame('birthday_mention', $birthday['intent']);

        $followUp = app(ChatbotService::class)->match('capacity?', $conversation);
        $this->assertSame('capacity', $followUp['intent']);
        $this->assertStringContainsString('Room 2', $followUp['answer']);

        $roomRequest = app(ChatbotService::class)->match('show me rooms for my birthday');
        $this->assertSame('room_list', $roomRequest['intent']);

        $priceRequest = app(ChatbotService::class)->match('how much is Room 1 for my birthday?');
        $this->assertSame('price', $priceRequest['intent']);
        $this->assertStringContainsString('Room 1', $priceRequest['answer']);
        $this->assertStringContainsString('PHP 2,500.00', $priceRequest['answer']);

        $cheapest = app(ChatbotService::class)->match('cheapest room for my birthday');
        $this->assertSame('cheapest', $cheapest['intent']);

        $availability = app(ChatbotService::class)->match('is Room 2 available for my birthday?');
        $this->assertSame('availability', $availability['intent']);

        $developer = app(ChatbotService::class)->match('Earlpogi');
        $this->assertSame('direct_name', $developer['intent']);

        $animal = app(ChatbotService::class)->match('dog');
        $this->assertSame('animal_mention', $animal['intent']);

        $greeting = app(ChatbotService::class)->match('hello');
        $this->assertSame('greeting', $greeting['intent']);

        $profanity = app(ChatbotService::class)->match('fuck');
        $this->assertSame('profanity', $profanity['intent']);

        $slur = app(ChatbotService::class)->match('n-word');
        $this->assertSame('identity_slur', $slur['intent']);

        $unknown = app(ChatbotService::class)->match('asdfghjkl');
        $this->assertFalse($unknown['handled'] ?? false);
    }

    public function test_food_mentions_are_handled_with_randomized_staff_guidance(): void
    {
        $foodResponses = [
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

        $room = $this->createAccommodation([
            'name' => 'Room 2',
            'slug' => 'room-2',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 3,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        foreach (['food', 'pizza', "I'm hungry", 'menu', 'can I order food?', 'can we bring outside food?', 'is breakfast free?', 'do you have catering?'] as $message) {
            $response = app(ChatbotService::class)->match($message);
            $this->assertContains($response['answer'], $foodResponses);
        }

        $availability = app(ChatbotService::class)->match('do you have pizza?');
        $this->assertContains($availability['answer'], $foodResponses);

        $outsideFood = app(ChatbotService::class)->match('can I bring a birthday cake?');
        $this->assertContains($outsideFood['answer'], $foodResponses);
        $this->assertNotEmpty($outsideFood['options']);

        $mealIncluded = app(ChatbotService::class)->match('does Room 1 include breakfast?');
        $this->assertContains($mealIncluded['answer'], $foodResponses);
        $this->assertNotEmpty($mealIncluded['options']);

        $conversation = ChatConversation::create([
            'conversation_uuid' => (string) Str::uuid(),
            'access_token' => Str::random(64),
            'status' => ChatConversation::STATUS_BOT,
            'context' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $room->id,
            ],
            'last_message_at' => now(),
        ]);

        $foodOnly = app(ChatbotService::class)->match('pizza', $conversation);
        $this->assertSame('food_generic', $foodOnly['intent']);

        $followUp = app(ChatbotService::class)->match('capacity?', $conversation);
        $this->assertSame('capacity', $followUp['intent']);
        $this->assertStringContainsString('Room 2', $followUp['answer']);

        $roomRequest = app(ChatbotService::class)->match('what is the cheapest room and do you have food?');
        $this->assertSame('food_availability', $roomRequest['intent']);
        $this->assertContains($roomRequest['answer'], $foodResponses);

        $dogQuestion = app(ChatbotService::class)->match('can my dog eat there?');
        $this->assertSame('animal_mention', $dogQuestion['intent']);

        $birthdayFood = app(ChatbotService::class)->match('can I bring a birthday cake?');
        $this->assertNotSame('birthday_mention', $birthdayFood['intent']);

        $developer = app(ChatbotService::class)->match('Earlpogi');
        $this->assertSame('direct_name', $developer['intent']);

        $greeting = app(ChatbotService::class)->match('hello');
        $this->assertSame('greeting', $greeting['intent']);

        $unknown = app(ChatbotService::class)->match('asdfghjkl');
        $this->assertFalse($unknown['handled'] ?? false);

        $profanity = app(ChatbotService::class)->match('fuck');
        $this->assertSame('profanity', $profanity['intent']);

        $slur = app(ChatbotService::class)->match('n-word');
        $this->assertSame('identity_slur', $slur['intent']);
    }

    public function test_basic_greetings_thanks_and_farewell_are_handled_deterministically(): void
    {
        $greetings = ['hello', 'hi', 'hey', 'good morning', 'wazzup'];

        foreach ($greetings as $greeting) {
            $response = app(ChatbotService::class)->match($greeting);
            $this->assertSame('greeting', $response['intent']);
            $this->assertStringContainsString('i can help with rooms', strtolower($response['answer']));
        }

        $thanks = app(ChatbotService::class)->match('thank you');
        $this->assertSame('thanks', $thanks['intent']);
        $this->assertStringContainsString("you're welcome", strtolower($thanks['answer']));

        $farewell = app(ChatbotService::class)->match('bye');
        $this->assertSame('farewell', $farewell['intent']);
        $this->assertStringContainsString('enjoy your stay', strtolower($farewell['answer']));
    }

    public function test_apology_messages_are_handled_with_randomized_responses(): void
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

        $room1 = $this->createAccommodation([
            'name' => 'Room 1',
            'slug' => 'room-1',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $room2 = $this->createAccommodation([
            'name' => 'Room 2',
            'slug' => 'room-2',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 6,
            'price_per_night' => 3200,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        foreach (['sorry', 'Sorry!', "I'm sorry", 'im sorry', 'I am sorry', 'my bad', 'my mistake', 'I apologize', 'apologies', 'forgive me', 'oops', 'whoops', "I didn't mean it", 'I take that back'] as $message) {
            $response = app(ChatbotService::class)->match($message);
            $this->assertSame('apology', $response['intent']);
            $this->assertContains($response['answer'], $responses);
        }

        $roomRequest = app(ChatbotService::class)->match('sorry how much is Room 1?');
        $this->assertSame('price', $roomRequest['intent']);
        $this->assertStringContainsString('Room 1', $roomRequest['answer']);

        $roomList = app(ChatbotService::class)->match('sorry show me rooms');
        $this->assertSame('room_list', $roomList['intent']);
        $this->assertStringContainsString('Room 1', $roomList['answer']);

        $cheapest = app(ChatbotService::class)->match('sorry cheapest room');
        $this->assertSame('cheapest', $cheapest['intent']);
        $this->assertStringContainsString('Room 1', $cheapest['answer']);

        $capacity = app(ChatbotService::class)->match('sorry capacity of Room 2');
        $this->assertSame('capacity', $capacity['intent']);
        $this->assertStringContainsString('Room 2', $capacity['answer']);

        $dog = app(ChatbotService::class)->match('sorry can I bring my dog?');
        $this->assertSame('animal_mention', $dog['intent']);

        $food = app(ChatbotService::class)->match('sorry can I bring outside food?');
        $this->assertSame('outside_food_policy', $food['intent']);

        $pizza = app(ChatbotService::class)->match('sorry do you have pizza?');
        $this->assertSame('food_availability', $pizza['intent']);

        $birthday = app(ChatbotService::class)->match("sorry it's my birthday");
        $this->assertSame('birthday_mention', $birthday['intent']);

        $developer = app(ChatbotService::class)->match('sorry who made this website?');
        $this->assertSame('website_creator', $developer['intent']);

        $profanity = app(ChatbotService::class)->match('sorry for saying fuck');
        $this->assertSame('apology', $profanity['intent']);

        $stillProfanity = app(ChatbotService::class)->match('sorry but fuck you');
        $this->assertSame('profanity', $stillProfanity['intent']);

        $conversation = ChatConversation::create([
            'conversation_uuid' => (string) Str::uuid(),
            'access_token' => Str::random(64),
            'status' => ChatConversation::STATUS_BOT,
            'context' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $room2->id,
            ],
            'last_message_at' => now(),
        ]);

        $apologyContext = app(ChatbotService::class)->match('sorry', $conversation);
        $this->assertSame('apology', $apologyContext['intent']);

        $followUp = app(ChatbotService::class)->match('how much?', $conversation);
        $this->assertSame('price', $followUp['intent']);
        $this->assertStringContainsString('Room 2', $followUp['answer']);
    }

    public function test_need_to_unwind_messages_are_handled_with_randomized_responses(): void
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

        foreach ([
            'heartbroken',
            "I'm heartbroken",
            'my girlfriend left me',
            'my boyfriend left me',
            'we broke up',
            'I got dumped',
            "I'm lonely",
            "I'm sad",
            'I feel lost in life',
            'I need a break',
            'I need to unwind',
            'I need to relax',
            'I need a getaway',
            'I need a vacation',
            "I'm stressed",
        ] as $message) {
            $response = app(ChatbotService::class)->match($message);
            $this->assertSame('need_to_unwind', $response['intent']);
            $this->assertContains($response['answer'], $responses);
        }

        $room1 = $this->createAccommodation([
            'name' => 'Room 1',
            'slug' => 'room-1',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $roomRequest = app(ChatbotService::class)->match('my girlfriend left me show me rooms');
        $this->assertSame('room_list', $roomRequest['intent']);
        $this->assertStringContainsString('Room 1', $roomRequest['answer']);

        $price = app(ChatbotService::class)->match('I got dumped, how much is Room 1?');
        $this->assertSame('price', $price['intent']);
        $this->assertStringContainsString('Room 1', $price['answer']);

        $cheapest = app(ChatbotService::class)->match("I'm heartbroken, what's the cheapest room?");
        $this->assertSame('cheapest', $cheapest['intent']);

        $availability = app(ChatbotService::class)->match('I need to unwind, is Room 2 available?');
        $this->assertSame('availability', $availability['intent']);

        $location = app(ChatbotService::class)->match("I'm lost, where is DMD Family Resort?");
        $this->assertFalse(($location['intent'] ?? null) === 'need_to_unwind');

        $dog = app(ChatbotService::class)->match('I need to unwind, can I bring my dog?');
        $this->assertSame('animal_mention', $dog['intent']);

        $food = app(ChatbotService::class)->match("I'm heartbroken and hungry, do you have food?");
        $this->assertSame('food_availability', $food['intent']);

        $birthday = app(ChatbotService::class)->match("I got dumped on my birthday, do I get a discount?");
        $this->assertSame('birthday_mention', $birthday['intent']);

        $room2 = $this->createAccommodation([
            'name' => 'Room 2',
            'slug' => 'room-2',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 6,
            'price_per_night' => 3200,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $conversation = ChatConversation::create([
            'conversation_uuid' => (string) Str::uuid(),
            'access_token' => Str::random(64),
            'status' => ChatConversation::STATUS_BOT,
            'context' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $room2->id,
            ],
            'last_message_at' => now(),
        ]);

        $unwind = app(ChatbotService::class)->match('my girlfriend left me', $conversation);
        $this->assertSame('need_to_unwind', $unwind['intent']);

        $followUp = app(ChatbotService::class)->match('how much?', $conversation);
        $this->assertSame('price', $followUp['intent']);
        $this->assertStringContainsString('Room 2', $followUp['answer']);
    }

    public function test_chatbot_insult_messages_are_handled_with_randomized_responses(): void
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

        $room1 = $this->createAccommodation([
            'name' => 'Room 1',
            'slug' => 'room-1',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $room2 = $this->createAccommodation([
            'name' => 'Room 2',
            'slug' => 'room-2',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 6,
            'price_per_night' => 3200,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        foreach ([
            'you are stupid',
            "you're stupid",
            'your stupid',
            'you are dumb',
            "you're dumb",
            'dumb chatbot',
            'stupid chatbot',
            'idiot',
            'you are useless',
            'worst chatbot',
            'you suck',
            'you are annoying',
            'shut up',
            'I hate you',
            'you are ugly',
            "you're bad",
            "you don't know anything",
            'you are not smart',
        ] as $message) {
            $response = app(ChatbotService::class)->match($message);
            $this->assertSame('chatbot_insult', $response['intent']);
            $this->assertContains($response['answer'], $responses);
        }

        $roomRequest = app(ChatbotService::class)->match('you are stupid how much is Room 1?');
        $this->assertSame('price', $roomRequest['intent']);
        $this->assertStringContainsString('Room 1', $roomRequest['answer']);

        $roomList = app(ChatbotService::class)->match('dumb bot show me rooms');
        $this->assertSame('room_list', $roomList['intent']);

        $cheapest = app(ChatbotService::class)->match('you are useless, what\'s the cheapest room?');
        $this->assertSame('cheapest', $cheapest['intent']);

        $capacity = app(ChatbotService::class)->match('idiot how many people fit in Room 2?');
        $this->assertSame('capacity', $capacity['intent']);

        $availability = app(ChatbotService::class)->match('stupid chatbot is Room 1 available?');
        $this->assertSame('availability', $availability['intent']);

        $dog = app(ChatbotService::class)->match('you suck can I bring my dog?');
        $this->assertSame('animal_mention', $dog['intent']);

        $food = app(ChatbotService::class)->match('terrible bot do you have pizza?');
        $this->assertSame('food_availability', $food['intent']);

        $birthday = app(ChatbotService::class)->match('stupid bot do I get a birthday discount?');
        $this->assertSame('birthday_mention', $birthday['intent']);

        $developer = app(ChatbotService::class)->match('who made this stupid chatbot?');
        $this->assertSame('website_creator', $developer['intent']);

        $conversation = ChatConversation::create([
            'conversation_uuid' => (string) Str::uuid(),
            'access_token' => Str::random(64),
            'status' => ChatConversation::STATUS_BOT,
            'context' => [
                'current_category' => 'room',
                'selected_accommodation_id' => $room2->id,
            ],
            'last_message_at' => now(),
        ]);

        $insultContext = app(ChatbotService::class)->match('you are stupid', $conversation);
        $this->assertSame('chatbot_insult', $insultContext['intent']);

        $followUp = app(ChatbotService::class)->match('how much?', $conversation);
        $this->assertSame('price', $followUp['intent']);
        $this->assertStringContainsString('Room 2', $followUp['answer']);
    }

    public function test_greeting_does_not_swallow_room_requests(): void
    {
        $room = $this->createAccommodation([
            'name' => 'Room 1',
            'slug' => 'room-1',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 3,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $price = app(ChatbotService::class)->match('hello how much is Room 1?');
        $this->assertSame('price', $price['intent']);
        $this->assertStringContainsString('Room 1', $price['answer']);

        $availability = app(ChatbotService::class)->match('hey is Room 1 available?');
        $this->assertSame('availability', $availability['intent']);
        $this->assertStringContainsString('Room 1', $availability['answer']);
    }

    public function test_thanks_does_not_swallow_room_requests(): void
    {
        $this->createAccommodation([
            'name' => 'Room 1',
            'slug' => 'room-1',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 3,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $price = app(ChatbotService::class)->match('thanks, how much is Room 1?');
        $this->assertSame('price', $price['intent']);
        $this->assertStringContainsString('Room 1', $price['answer']);
    }

    public function test_contradictory_ranking_intents_request_clarification(): void
    {
        $response = app(ChatbotService::class)->match('cheapest room most expensive room biggest room smallest room');

        $this->assertSame('clarify_ranking', $response['intent']);
        $this->assertStringContainsString('Would you like the cheapest, most expensive, largest-capacity, or smallest-capacity room', $response['answer']);
    }

    public function test_pending_family_prompt_does_not_lock_follow_up_intents(): void
    {
        $room1 = $this->createAccommodation([
            'name' => 'Room 1',
            'slug' => 'room-1',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 3,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $room2 = $this->createAccommodation([
            'name' => 'Room 2',
            'slug' => 'room-2',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 5,
            'price_per_night' => 3200,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $conversation = ChatConversation::create([
            'conversation_uuid' => (string) Str::uuid(),
            'access_token' => Str::random(64),
            'status' => ChatConversation::STATUS_BOT,
            'context' => [
                'current_category' => 'room',
                'pending_intent' => 'family_recommendation',
                'pending_field' => 'guest_count',
            ],
            'last_message_at' => now(),
        ]);

        $prompt = app(ChatbotService::class)->match('room for family', $conversation);
        $this->assertSame('family_recommendation', $prompt['intent']);
        $conversation->forceFill([
            'context' => array_replace_recursive($conversation->context ?? [], $prompt['context_updates'] ?? []),
        ])->save();

        $answer = app(ChatbotService::class)->match('5', $conversation, null);
        $this->assertSame('family_recommendation', $answer['intent']);
        $this->assertStringContainsString('Room 2', $answer['answer']);
        $conversation->forceFill([
            'context' => array_replace_recursive($conversation->context ?? [], $answer['context_updates'] ?? []),
        ])->save();

        $cheapest = app(ChatbotService::class)->match('cheapest', $conversation);
        $this->assertSame('cheapest', $cheapest['intent']);
        $this->assertStringContainsString('Room 1', $cheapest['answer']);
        $this->assertStringNotContainsString('could not find a room that fits 5 guests', strtolower($cheapest['answer']));
        $this->assertSame($room1->id, $cheapest['selected_accommodation_id'] ?? null);
        $conversation->forceFill([
            'context' => array_replace_recursive($conversation->context ?? [], $cheapest['context_updates'] ?? []),
        ])->save();

        $amenities = app(ChatbotService::class)->match('amenities?', $conversation);
        $this->assertSame('amenities', $amenities['intent']);
        $this->assertStringContainsString('Room 1', $amenities['answer']);
        $conversation->forceFill([
            'context' => array_replace_recursive($conversation->context ?? [], $amenities['context_updates'] ?? []),
        ])->save();

        $comparison = app(ChatbotService::class)->match('compare Room 1 and Room 2', $conversation);
        $this->assertSame('compare', $comparison['intent']);
        $this->assertStringContainsString('Room 1', $comparison['answer']);
        $this->assertStringContainsString('Room 2', $comparison['answer']);
        $conversation->forceFill([
            'context' => array_replace_recursive($conversation->context ?? [], $comparison['context_updates'] ?? []),
        ])->save();

        $followUpCapacity = app(ChatbotService::class)->match('capacity?', $conversation);
        $this->assertSame('capacity', $followUpCapacity['intent']);
        $this->assertStringContainsString('Room 2', $followUpCapacity['answer']);
    }

    public function test_explicit_room_selection_clears_family_pending_state(): void
    {
        $room1 = $this->createAccommodation([
            'name' => 'Room 1',
            'slug' => 'room-1',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 3,
            'price_per_night' => 2500,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $room2 = $this->createAccommodation([
            'name' => 'Room 2',
            'slug' => 'room-2',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 6,
            'price_per_night' => 3200,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $conversation = ChatConversation::create([
            'conversation_uuid' => (string) Str::uuid(),
            'access_token' => Str::random(64),
            'status' => ChatConversation::STATUS_BOT,
            'context' => [
                'current_category' => 'room',
                'pending_intent' => 'family_recommendation',
                'pending_field' => 'guest_count',
            ],
            'last_message_at' => now(),
        ]);

        $selected = app(ChatbotService::class)->match('Room 1', $conversation);
        $this->assertSame('details', $selected['intent']);
        $this->assertStringContainsString('Room 1', $selected['answer']);
        $this->assertSame($room1->id, $selected['selected_accommodation_id'] ?? null);
        $conversation->forceFill([
            'context' => array_replace_recursive($conversation->context ?? [], $selected['context_updates'] ?? []),
        ])->save();

        $capacity = app(ChatbotService::class)->match('how many guests?', $conversation);
        $this->assertSame('capacity', $capacity['intent']);
        $this->assertStringContainsString('Room 1', $capacity['answer']);

        $explicitSwitch = app(ChatbotService::class)->match('what about Room 2?', $conversation);
        $this->assertSame('details', $explicitSwitch['intent']);
        $this->assertStringContainsString('Room 2', $explicitSwitch['answer']);
        $conversation->forceFill([
            'context' => array_replace_recursive($conversation->context ?? [], $explicitSwitch['context_updates'] ?? []),
        ])->save();

        $availability = app(ChatbotService::class)->match('is it available?', $conversation);
        $this->assertSame('availability', $availability['intent']);
        $this->assertStringContainsString('Room 2', $availability['answer']);
    }
}
