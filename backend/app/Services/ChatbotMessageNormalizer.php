<?php

namespace App\Services;

use Illuminate\Support\Str;

class ChatbotMessageNormalizer
{
    /**
     * @var array<string, string>
     */
    private array $numberWords = [
        'zero' => '0',
        'one' => '1',
        'two' => '2',
        'three' => '3',
        'four' => '4',
        'five' => '5',
        'six' => '6',
        'seven' => '7',
        'eight' => '8',
        'nine' => '9',
        'ten' => '10',
        'eleven' => '11',
        'twelve' => '12',
        'thirteen' => '13',
        'fourteen' => '14',
        'fifteen' => '15',
        'sixteen' => '16',
        'seventeen' => '17',
        'eighteen' => '18',
        'nineteen' => '19',
        'twenty' => '20',
    ];

    public function normalize(string $value): string
    {
        $normalized = Str::of($value)
            ->ascii()
            ->lower()
            ->replace(["\u{2019}", "\u{2018}", "\u{02BC}"], "'")
            ->replace(['-', '_', '/'], ' ')
            ->replaceMatches('/[^\pL\pN\s\']+/u', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        foreach ($this->numberWords as $word => $digit) {
            $normalized = preg_replace('/\b'.preg_quote($word, '/').'\b/u', $digit, $normalized) ?? $normalized;
        }

        return Str::of($normalized)
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    /**
     * @return list<string>
     */
    public function tokens(string $value): array
    {
        $normalized = $this->normalize($value);

        if ($normalized === '') {
            return [];
        }

        return preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
