<?php

namespace App\Tests\Domain\Activity\Stream\CombinedStream;

use App\Domain\Activity\Stream\CombinedStream\CombinedStreamType;
use App\Domain\Activity\Stream\StreamType;
use App\Infrastructure\ValueObject\Measurement\UnitSystem;
use App\Tests\ContainerTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class CombinedStreamTypeTest extends ContainerTestCase
{
    public function testTempMapsToTempStreamType(): void
    {
        $this->assertEquals(StreamType::TEMP, CombinedStreamType::TEMP->getStreamType());
    }

    public function testTempTranslation(): void
    {
        $translator = $this->getContainer()->get(TranslatorInterface::class);

        $this->assertEquals('Temperature', CombinedStreamType::TEMP->trans($translator));
    }

    public function testTempSuffixForMetricUnitSystem(): void
    {
        $this->assertEquals('°C', CombinedStreamType::TEMP->getSuffix(UnitSystem::METRIC));
    }

    public function testTempSuffixForImperialUnitSystem(): void
    {
        $this->assertEquals('°F', CombinedStreamType::TEMP->getSuffix(UnitSystem::IMPERIAL));
    }

    public function testTempSeriesColorIsDistinctFromSiblingMetrics(): void
    {
        $usedColors = [];
        foreach (CombinedStreamType::cases() as $combinedStreamType) {
            if (CombinedStreamType::TEMP === $combinedStreamType) {
                continue;
            }
            $usedColors[] = $combinedStreamType->getSeriesColor();
        }

        $this->assertNotContains(CombinedStreamType::TEMP->getSeriesColor(), $usedColors);
        $this->assertEquals('#fc8452', CombinedStreamType::TEMP->getSeriesColor());
    }

    public function testTempIsChartable(): void
    {
        $this->assertTrue(CombinedStreamType::TEMP->isChartable());
    }
}
