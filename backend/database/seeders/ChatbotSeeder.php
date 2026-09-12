<?php

namespace Database\Seeders;

use App\Models\ChatbotCategory;
use App\Models\ChatbotRule;
use Illuminate\Database\Seeder;

class ChatbotSeeder extends Seeder
{
    public function run(): void
    {
        $categories = collect([
            ['name' => 'Accommodations', 'slug' => 'accommodations', 'icon' => 'hotel', 'sort_order' => 1],
            ['name' => 'Booking', 'slug' => 'booking', 'icon' => 'calendar', 'sort_order' => 2],
            ['name' => 'Payments', 'slug' => 'payments', 'icon' => 'wallet', 'sort_order' => 3],
            ['name' => 'Check-in', 'slug' => 'check-in', 'icon' => 'clock', 'sort_order' => 4],
            ['name' => 'Facilities', 'slug' => 'facilities', 'icon' => 'sparkles', 'sort_order' => 5],
            ['name' => 'Policies', 'slug' => 'policies', 'icon' => 'shield', 'sort_order' => 6],
            ['name' => 'Location', 'slug' => 'location', 'icon' => 'map-pin', 'sort_order' => 7],
            ['name' => 'Support', 'slug' => 'support', 'icon' => 'headphones', 'sort_order' => 8],
        ])->mapWithKeys(fn ($category) => [
            $category['slug'] => ChatbotCategory::updateOrCreate(
                ['slug' => $category['slug']],
                $category + ['is_active' => true]
            ),
        ]);

        $rules = [
            ['accommodations', 'What rooms are available?', 'You can browse current rooms, cottages, and event spaces on the accommodations page.', ['rooms', 'cottages', 'function hall', 'accommodations']],
            ['accommodations', 'How much does an accommodation cost?', 'Accommodation prices are shown on each listing and update based on the current settings.', ['price', 'rate', 'cost', 'how much']],
            ['booking', 'How do I book?', 'Choose an accommodation, select your dates, then submit your reservation request.', ['book', 'booking', 'reservation', 'reserve']],
            ['booking', 'How do I check availability?', 'Use the search bar to select dates and guest count, then we will show matching stays.', ['available', 'availability', 'check availability']],
            ['booking', 'Where can I see my bookings?', 'Open My Bookings in your Guest Portal to view your reservations.', ['my bookings', 'bookings', 'reservation']],
            ['payments', 'How do I pay?', 'If payment is required, open your reservation and follow the payment instructions shown there.', ['pay', 'payment', 'checkout']],
            ['payments', 'What payment methods are available?', 'Payment methods are shown during checkout based on the current payment configuration.', ['payment methods', 'methods', 'paymongo']],
            ['check-in', 'What time is check-in?', 'Check-in starts at {check_in_time}.', ['check in', 'check-in', 'arrival time']],
            ['check-in', 'What time is check-out?', 'Check-out is before {check_out_time}.', ['check out', 'check-out', 'departure time']],
            ['location', 'Where is DMD Family Resort?', 'DMD Family Resort is located at {address}. View on Google Maps: {google_maps_url}', ['where is', 'location', 'maps']],
            ['location', 'How can I contact the resort?', 'You can contact us at {contact_number} or {email}.', ['contact', 'phone', 'email']],
            ['policies', 'What are the house rules?', 'Check-in starts at {check_in_time}. Check-out is before {check_out_time}.', ['house rules', 'policy', 'rules']],
            ['support', 'Talk to Staff', 'A staff member can help with this. Tap Talk to Staff to send your conversation to support.', ['staff', 'talk to staff', 'support']],
        ];

        foreach ($rules as [$slug, $question, $answer, $keywords]) {
            ChatbotRule::updateOrCreate(
                ['question' => $question],
                [
                    'category_id' => $categories[$slug]->id,
                    'question' => $question,
                    'answer' => $answer,
                    'keywords' => $keywords,
                    'priority' => 50,
                    'is_active' => true,
                ]
            );
        }
    }
}
