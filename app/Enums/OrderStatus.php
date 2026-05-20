<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, array<int, string>>
     */
    public static function transitionMap(): array
    {
        return [
            self::Pending->value => [self::Confirmed->value, self::Cancelled->value],
            self::Confirmed->value => [self::Preparing->value, self::Cancelled->value],
            self::Preparing->value => [self::Ready->value, self::Cancelled->value],
            self::Ready->value => [self::Completed->value, self::Cancelled->value],
            self::Completed->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next->value, self::transitionMap()[$this->value] ?? [], true);
    }

    /**
     * @return array<int, self>
     */
    public function allowedNextStatuses(): array
    {
        return array_map(
            fn (string $v): self => self::from($v),
            self::transitionMap()[$this->value] ?? [],
        );
    }
}
