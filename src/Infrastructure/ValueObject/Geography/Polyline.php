<?php

declare(strict_types=1);

namespace App\Infrastructure\ValueObject\Geography;

final readonly class Polyline
{
    /**
     * @param array<int, array{float, float}> $coordinates
     */
    private function __construct(
        private array $coordinates,
    ) {
    }

    /**
     * @param array<int, array{float, float}> $coordinates
     */
    public static function fromCoordinates(array $coordinates): self
    {
        return new self($coordinates);
    }

    /**
     * Default tolerance is expressed in the same unit as the coordinates
     * themselves, i.e. decimal degrees of latitude/longitude - NOT meters,
     * pixels, or any projected unit. This matters a lot: 0.4 degrees is
     * roughly 44km at the equator (less towards the poles for longitude,
     * but still tens of km), so a 0.4 default silently collapsed almost
     * every real-world activity's route down to just its two endpoints,
     * since very few individual activities deviate from the straight
     * start-to-end line by tens of kilometers. This was confirmed against
     * a real ~14km ride's GPS track (issue #45): its full lat/lng
     * bounding box only spanned roughly 0.02 degrees in each axis, well
     * under the old 0.4 tolerance, so Douglas-Peucker discarded every
     * interior point and left only the first/last coordinate - a
     * "polyline" that renders as a single short straight line.
     *
     * 0.00005 degrees (~5.5m at the equator) was chosen instead: still
     * comfortably larger than typical consumer GPS noise/jitter so it
     * keeps reducing point count for near-straight stretches (the
     * original motivation for adding simplify() at all, per #2230's
     * WKT-length overflow), while staying far below the scale of a real
     * route's deviation from its start-end chord, so genuine route shape
     * survives.
     */
    public function simplify(float $tolerance = 0.00005): self
    {
        $points = $this->coordinates;

        if (count($points) < 2) {
            return new self($points);
        }

        $points = $this->simplifyDouglasPeucker(
            coordinates: $points,
            sqTolerance: $tolerance ** 2
        );

        return new self($points);
    }

    public function encode(): EncodedPolyline
    {
        return EncodedPolyline::fromCoordinates($this->coordinates);
    }

    /**
     * @param array<int, array{float, float}> $coordinates
     *
     * @return array<int, array{float, float}>
     */
    private function simplifyDouglasPeucker(array $coordinates, float $sqTolerance): array
    {
        $len = count($coordinates);

        $markers = array_fill(0, $len - 1, null);
        $first = 0;
        $last = $len - 1;

        $firstStack = [];
        $lastStack = [];
        $newPoints = [];

        $markers[$first] = $markers[$last] = 1;

        while (null !== $first && null !== $last) {
            $maxSqDist = 0;
            $index = null;

            for ($i = $first + 1; $i < $last; ++$i) {
                $sqDist = $this->squareSegmentDistance(
                    p: $coordinates[$i],
                    p1: $coordinates[$first],
                    p2: $coordinates[$last]
                );

                if ($sqDist > $maxSqDist) {
                    $index = $i;
                    $maxSqDist = $sqDist;
                }
            }

            if (null !== $index && $maxSqDist > $sqTolerance) {
                $markers[$index] = 1;

                $firstStack[] = $first;
                $lastStack[] = $index;

                $firstStack[] = $index;
                $lastStack[] = $last;
            }

            $first = array_pop($firstStack);
            $last = array_pop($lastStack);
        }

        for ($i = 0; $i < $len; ++$i) {
            if ($markers[$i]) {
                $newPoints[] = $coordinates[$i];
            }
        }

        return $newPoints;
    }

    /**
     * @param array{float, float} $p
     * @param array{float, float} $p1
     * @param array{float, float} $p2
     */
    private function squareSegmentDistance(array $p, array $p1, array $p2): float
    {
        $x = $p1[1]; // longitude
        $y = $p1[0]; // latitude

        $dx = $p2[1] - $x;
        $dy = $p2[0] - $y;

        if (0.0 !== $dx || 0.0 !== $dy) {
            $t = (($p[1] - $x) * $dx + ($p[0] - $y) * $dy) / ($dx * $dx + $dy * $dy);

            if ($t > 1) {
                $x = $p2[1];
                $y = $p2[0];
            } elseif ($t > 0) {
                $x += $dx * $t;
                $y += $dy * $t;
            }
        }

        $dx = $p[1] - $x;
        $dy = $p[0] - $y;

        return $dx * $dx + $dy * $dy;
    }
}
