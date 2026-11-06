<?php

declare(strict_types=1);

namespace App\Category;

use App\Entity\TransactionType;

/**
 * Color/icon for user-created categories. The approved "New category" dialog only asks for a
 * name, so the look is assigned automatically from the design's category palette.
 */
final class CustomCategoryStyle
{
    private const PALETTE = ['#0E7C66', '#C2410C', '#3A5BA9', '#0369A1', '#8A6A0B', '#BE185D', '#6B4FA0', '#4D7C0F', '#155E75', '#9F3A8A', '#0E7490', '#B54708'];

    /** @return array{0: string, 1: string} [color, icon] */
    public static function pick(TransactionType $type, int $index): array
    {
        return [self::PALETTE[$index % \count(self::PALETTE)], 'tag'];
    }
}
