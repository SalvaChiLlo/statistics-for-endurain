<?php

declare(strict_types=1);

namespace App\Domain\Endurain\Stream;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\Stream\ActivityStream;
use App\Domain\Activity\Stream\ActivityStreams;
use App\Domain\Activity\Stream\StreamType;
use App\Infrastructure\ValueObject\Geography\GeoMath;
use App\Infrastructure\ValueObject\Geography\Polyline;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

/**
 * Translates Endurain's raw activities_streams payload into this codebase's
 * Stream domain (ActivityStream/ActivityStreams/StreamType), following the
 * same overall shape as FitFileParser: build an intermediate, time-aligned
 * $streamMap (array<string streamType-value, list<mixed>>, all sub-arrays
 * the same length, index-aligned), then derive both the ActivityStreams
 * collection and the re-encoded polyline from it.
 *
 * Unlike a FIT file (one record = one aligned index across all stream
 * types), Endurain returns one independent waypoint list per stream type,
 * each with its own "time" value and its own count. This class's job is to
 * reconstruct a shared canonical time axis those independent lists can be
 * aligned onto.
 *
 * Endurain also gives us no StreamType::TIME (elapsed seconds) or
 * StreamType::DISTANCE (cumulative meters) data of its own - unlike FIT/
 * GPX/TCX, whose parsers all derive and emit both. addDerivedTimeAndDistanceStreams()
 * fills that gap; see its docblock for why this matters beyond raw
 * completeness (it's required for evolution/over-time charts to work).
 */
final readonly class EndurainStreamParser
{
    // Endurain "stream_type" integer codes, per the confirmed live-instance
    // shape captured in #1.
    private const int ENDURAIN_STREAM_TYPE_HEART_RATE = 1;
    private const int ENDURAIN_STREAM_TYPE_POWER = 2;
    private const int ENDURAIN_STREAM_TYPE_CADENCE = 3;
    private const int ENDURAIN_STREAM_TYPE_ALTITUDE = 4;
    private const int ENDURAIN_STREAM_TYPE_VELOCITY = 5;
    private const int ENDURAIN_STREAM_TYPE_LAT_LNG = 7;
    private const int ENDURAIN_STREAM_TYPE_TEMP = 8;
    // Endurain "stream_type" 6 (pace) is intentionally NOT mapped here: this
    // codebase's Activity/chart code is built around Strava's km/h-style
    // "velocity_smooth" stream, not a separate pace stream, and StreamType
    // has no pace case to map onto. Its waypoints are therefore skipped
    // entirely, including from canonical time axis construction.
    //
    // "moving" and "grade_smooth" have no Endurain stream_type equivalent at
    // all, so they can never appear in $streamMap; buildActivityStreams()
    // simply never produces them, with no special-casing required.

    /**
     * @param array<int, array<string, mixed>> $rawStreams raw decoded response from Endurain::getAllActivityStreams()
     */
    public function parse(array $rawStreams, ActivityId $activityId, SerializableDateTime $createdOn): EndurainParsedStreams
    {
        $streamMap = $this->buildStreamMap($rawStreams);

        return EndurainParsedStreams::create(
            streams: $this->buildActivityStreams($streamMap, $activityId, $createdOn),
            polyline: $this->encodePolyline($streamMap),
        );
    }

    /**
     * @param array<string, list<mixed>> $streamMap
     */
    private function buildActivityStreams(array $streamMap, ActivityId $activityId, SerializableDateTime $createdOn): ActivityStreams
    {
        $streams = ActivityStreams::empty();
        foreach ($streamMap as $type => $values) {
            if (!$streamType = StreamType::tryFrom($type)) {
                continue;
            }
            if ([] === array_filter($values, static fn (mixed $value): bool => null !== $value)) {
                continue;
            }
            $streams->add(ActivityStream::create(
                activityId: $activityId,
                streamType: $streamType,
                streamData: $values,
                createdOn: $createdOn,
            ));
        }

        return $streams;
    }

    /**
     * @param array<string, list<mixed>> $streamMap
     */
    private function encodePolyline(array $streamMap): ?string
    {
        /** @var array<int, array{float, float}> $coordinates */
        $coordinates = array_values(array_filter(
            $streamMap[StreamType::LAT_LNG->value] ?? [],
            is_array(...),
        ));

        if ([] === $coordinates) {
            return null;
        }

        return (string) Polyline::fromCoordinates($coordinates)->simplify()->encode();
    }

    /**
     * Design decision (documented per issue #4): the canonical time axis is
     * the UNION of every distinct "time" value seen across all present
     * stream types, sorted ascending (a plain string sort is safe here,
     * Endurain's "time" values are zero-padded ISO 8601 strings without an
     * offset, e.g. "2026-06-22T17:11:56", which sort identically to a
     * chronological sort). Each stream type is then read off this axis by
     * an EXACT match on "time"; a stream type with no waypoint at a given
     * canonical timestamp gets null there, mirroring FIT's own
     * null-for-missing-data convention that buildActivityStreams() above
     * already filters correctly for all-null streams.
     *
     * Exact match (rather than nearest-timestamp) was chosen because
     * sensors on the same recording device typically share a sampling
     * clock; a nearest-timestamp heuristic risks silently pairing up two
     * streams that are genuinely several seconds apart (e.g. one sensor
     * paused/resumed and the other did not). See
     * EndurainStreamParserTest::testTimestampPresentInOneStreamTypeButNotAnotherIsNullAligned()
     * for the explicit gap-handling case this produces.
     *
     * This exact-match assumption was re-verified against a real
     * deployment's data while investigating issue #45 (polylines
     * rendering as a short straight line): on a real ~724-waypoint
     * activity, all of the LAT_LNG stream's waypoint timestamps were
     * exact-matched onto the canonical axis (every one of its waypoints
     * survived alignment; only the couple of axis positions contributed
     * solely by other stream types came back null for LAT_LNG, which is
     * the intended/documented gap behaviour). So the union-axis/exact-
     * match design here was NOT the cause of #45 - the actual cause was
     * an unrelated, far-too-large default tolerance in
     * Polyline::simplify(), which collapsed the correctly-aligned
     * coordinate list down to its two endpoints. See Polyline::simplify()
     * for that fix.
     *
     * @param array<int, array<string, mixed>> $rawStreams
     *
     * @return array<string, list<mixed>>
     */
    private function buildStreamMap(array $rawStreams): array
    {
        $extractors = $this->extractorsByEndurainStreamType();

        /** @var array<string, array<string, mixed>> $valuesByTypeAndTime keyed by [StreamType->value][time] */
        $valuesByTypeAndTime = [];
        /** @var array<string, true> $times */
        $times = [];

        foreach ($rawStreams as $rawStream) {
            $endurainType = $rawStream['stream_type'] ?? null;
            if (!is_int($endurainType) || !isset($extractors[$endurainType])) {
                continue;
            }
            [$streamType, $extractor] = $extractors[$endurainType];

            foreach ($rawStream['stream_waypoints'] ?? [] as $waypoint) {
                if (!is_array($waypoint) || !is_string($waypoint['time'] ?? null)) {
                    continue;
                }
                $time = $waypoint['time'];
                $times[$time] = true;
                $valuesByTypeAndTime[$streamType->value][$time] = $extractor($waypoint);
            }
        }

        $axis = array_keys($times);
        sort($axis);

        $streamMap = [];
        foreach (array_keys($valuesByTypeAndTime) as $streamTypeValue) {
            $streamMap[$streamTypeValue] = array_map(
                static fn (string $time): mixed => $valuesByTypeAndTime[$streamTypeValue][$time] ?? null,
                $axis
            );
        }

        return $this->addDerivedTimeAndDistanceStreams($streamMap, $axis);
    }

    /**
     * Endurain never sends a "time" (elapsed seconds since start) or
     * "distance" (cumulative meters) stream of its own - each waypoint only
     * carries an absolute "time" used for axis alignment above, and no
     * distance figure at all. Every other parser in this codebase
     * (FitFileParser, GpxFileParser, TcxFileParser) DOES produce both, and
     * CalculateCombinedStreams (which "evolution"/over-time charts read
     * from) unconditionally requires a StreamType::TIME stream, skipping an
     * activity entirely without one. Before this method existed, every
     * Endurain-imported activity silently had no TIME stream at all, which
     * is why - discovered while investigating #45 - only distribution
     * (histogram) charts worked for Endurain activities: those bucket
     * whatever values exist regardless of a time axis, while evolution
     * charts need the combined-stream data that was never being built.
     *
     * TIME is derived as elapsed seconds between each canonical axis
     * timestamp and the first one (mirrors GpxFileParser/FitFileParser's
     * "$time - $startTimestamp" pattern). DISTANCE is derived by
     * accumulating the haversine distance between consecutive non-null
     * LAT_LNG axis points (mirrors GpxFileParser's own cumulative-distance
     * calculation, since Endurain gives us no distance figure to read
     * directly); a gap in coordinates simply leaves the cumulative total
     * unchanged rather than null, since distance-so-far is still known
     * even while momentarily missing a GPS fix.
     *
     * @param array<string, list<mixed>> $streamMap
     * @param list<string>               $axis
     *
     * @return array<string, list<mixed>>
     */
    private function addDerivedTimeAndDistanceStreams(array $streamMap, array $axis): array
    {
        if ([] === $axis) {
            return $streamMap;
        }

        $startTimestamp = new \DateTimeImmutable($axis[0])->getTimestamp();
        $streamMap[StreamType::TIME->value] = array_map(
            static fn (string $time): int => new \DateTimeImmutable($time)->getTimestamp() - $startTimestamp,
            $axis
        );

        if (isset($streamMap[StreamType::LAT_LNG->value])) {
            $cumulativeDistance = 0.0;
            $previousLat = $previousLon = null;
            $distanceStream = [];

            foreach ($streamMap[StreamType::LAT_LNG->value] as $point) {
                if (is_array($point) && null !== $previousLat && null !== $previousLon) {
                    $cumulativeDistance += GeoMath::haversineDistance($previousLat, $previousLon, $point[0], $point[1]);
                }
                if (is_array($point)) {
                    [$previousLat, $previousLon] = $point;
                }
                $distanceStream[] = round($cumulativeDistance, 2);
            }

            $streamMap[StreamType::DISTANCE->value] = $distanceStream;
        }

        return $streamMap;
    }

    /**
     * Value extraction is defensive on purpose: "power" (stream_type 2) and
     * "cadence" (stream_type 3) are UNCONFIRMED shapes (assumed "watts" and
     * "cad" keys respectively). If the assumed key is missing from a given
     * waypoint, that waypoint simply contributes null rather than crashing;
     * if the key is missing from every waypoint of that type, the stream
     * type is dropped entirely by buildActivityStreams()'s all-null filter.
     *
     * @return array<int, array{StreamType, callable(array<string, mixed>): mixed}>
     */
    private function extractorsByEndurainStreamType(): array
    {
        return [
            self::ENDURAIN_STREAM_TYPE_HEART_RATE => [StreamType::HEART_RATE, static fn (array $waypoint): ?int => is_numeric($waypoint['hr'] ?? null) ? (int) round((float) $waypoint['hr']) : null],
            self::ENDURAIN_STREAM_TYPE_POWER => [StreamType::WATTS, static fn (array $waypoint): ?int => is_numeric($waypoint['watts'] ?? null) ? (int) round((float) $waypoint['watts']) : null],
            self::ENDURAIN_STREAM_TYPE_CADENCE => [StreamType::CADENCE, static fn (array $waypoint): ?int => is_numeric($waypoint['cad'] ?? null) ? (int) round((float) $waypoint['cad']) : null],
            self::ENDURAIN_STREAM_TYPE_ALTITUDE => [StreamType::ALTITUDE, static fn (array $waypoint): ?float => is_numeric($waypoint['ele'] ?? null) ? (float) $waypoint['ele'] : null],
            self::ENDURAIN_STREAM_TYPE_VELOCITY => [StreamType::VELOCITY, static fn (array $waypoint): ?float => is_numeric($waypoint['vel'] ?? null) ? (float) $waypoint['vel'] : null],
            self::ENDURAIN_STREAM_TYPE_LAT_LNG => [StreamType::LAT_LNG, static fn (array $waypoint): ?array => (is_numeric($waypoint['lat'] ?? null) && is_numeric($waypoint['lon'] ?? null)) ? [(float) $waypoint['lat'], (float) $waypoint['lon']] : null],
            self::ENDURAIN_STREAM_TYPE_TEMP => [StreamType::TEMP, static fn (array $waypoint): ?int => is_numeric($waypoint['temp'] ?? null) ? (int) round((float) $waypoint['temp']) : null],
        ];
    }
}
