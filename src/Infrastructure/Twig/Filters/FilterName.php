<?php

declare(strict_types=1);

namespace App\Infrastructure\Twig\Filters;

enum FilterName: string
{
    case ACTIVITIES = 'activities';
    case PHOTO_WALL = 'photoWall';
    case HEATMAP = 'heatmap';
}
