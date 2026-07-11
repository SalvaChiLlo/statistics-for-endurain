<?php

declare(strict_types=1);

namespace App\Domain\Endurain\Stream;

use App\Domain\Activity\Stream\ActivityStreams;

/**
 * The result of translating Endurain's raw per-stream-type waypoint payload
 * into this codebase's Stream domain: a time-aligned ActivityStreams
 * collection, plus a re-encoded polyline (Endurain has no pre-encoded
 * polyline of its own, only raw lat/lng waypoints).
 */
final readonly class EndurainParsedStreams
{
    private function __construct(
        private ActivityStreams $streams,
        private ?string $polyline,
    ) {
    }

    public static function create(ActivityStreams $streams, ?string $polyline): self
    {
        return new self($streams, $polyline);
    }

    public function getStreams(): ActivityStreams
    {
        return $this->streams;
    }

    public function getPolyline(): ?string
    {
        return $this->polyline;
    }
}
