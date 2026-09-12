<?php

namespace App\Services;

use App\Models\InventoryAssetCodeSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class InventoryAssetCodeGenerator
{
    /**
     * @var array<string, string>
     */
    private const PREFIX_MAP = [
        'furniture' => 'FUR',
        'appliances' => 'APP',
        'electronics' => 'ELE',
        'kitchen equipment' => 'KIT',
        'bathroom equipment' => 'BAT',
        'bedding & linens' => 'BED',
        'cleaning equipment' => 'CLN',
        'pool equipment' => 'POL',
        'outdoor furniture' => 'OUT',
        'office equipment' => 'OFF',
        'tools & maintenance' => 'TOL',
        'safety equipment' => 'SAF',
    ];

    public function generate(?string $category): string
    {
        $prefix = $this->resolvePrefix($category);

        return DB::transaction(function () use ($prefix) {
            $sequence = InventoryAssetCodeSequence::query()
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                try {
                    InventoryAssetCodeSequence::create([
                        'prefix' => $prefix,
                        'last_number' => 0,
                    ]);
                } catch (QueryException $exception) {
                    if (! $this->isUniqueConstraintViolation($exception)) {
                        throw $exception;
                    }
                }

                $sequence = InventoryAssetCodeSequence::query()
                    ->where('prefix', $prefix)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $sequence->forceFill([
                'last_number' => $sequence->last_number + 1,
            ])->save();

            return sprintf('%s-%04d', $prefix, $sequence->last_number);
        });
    }

    private function resolvePrefix(?string $category): string
    {
        $normalizedCategory = strtolower(trim((string) $category));

        return self::PREFIX_MAP[$normalizedCategory] ?? 'OTH';
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '23505';
    }
}
