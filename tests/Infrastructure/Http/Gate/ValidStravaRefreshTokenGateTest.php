<?php

namespace App\Tests\Infrastructure\Http\Gate;

use App\Domain\Activity\ActivityIdRepository;
use App\Domain\Import\ImportMode;
use App\Domain\Strava\InvalidStravaAccessToken;
use App\Domain\Strava\Strava;
use App\Infrastructure\Http\Gate\ValidStravaRefreshTokenGate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidStravaRefreshTokenGateTest extends TestCase
{
    private Strava&MockObject $strava;
    private ActivityIdRepository $activityIdRepository;

    public function testItPassesThroughWhenNotUsingStravaApiImportMode(): void
    {
        $this->strava->expects($this->never())->method('verifyAccessToken');
        $this->activityIdRepository->expects($this->never())->method('hasImportedFromStravaApi');

        $gate = $this->gate(ImportMode::FILES);

        $this->assertNull($gate->handle(Request::create('/dashboard')));
    }

    public function testItPassesThroughWhenAStravaApiActivityHasBeenImported(): void
    {
        $this->activityIdRepository
            ->expects($this->once())
            ->method('hasImportedFromStravaApi')
            ->willReturn(true);
        $this->strava->expects($this->never())->method('verifyAccessToken');

        $gate = $this->gate(ImportMode::STRAVA_API);

        $this->assertNull($gate->handle(Request::create('/dashboard')));
    }

    public function testItRedirectsWhenTheRefreshTokenCanNotBeVerified(): void
    {
        $this->activityIdRepository
            ->expects($this->once())
            ->method('hasImportedFromStravaApi')
            ->willReturn(false);
        $this->strava
            ->expects($this->once())
            ->method('verifyAccessToken')
            ->willThrowException(new InvalidStravaAccessToken());

        $gate = $this->gate(ImportMode::STRAVA_API);

        $response = $gate->handle(Request::create('/dashboard'));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/strava-oauth', $response->getTargetUrl());
        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    public function testItPassesThroughWhenTheRefreshTokenIsValid(): void
    {
        $this->activityIdRepository
            ->expects($this->once())
            ->method('hasImportedFromStravaApi')
            ->willReturn(false);
        $this->strava
            ->expects($this->once())
            ->method('verifyAccessToken');

        $gate = $this->gate(ImportMode::STRAVA_API);

        $this->assertNull($gate->handle(Request::create('/dashboard')));
    }

    public function testItNeverRedirectsTheOAuthTargetItself(): void
    {
        $this->activityIdRepository
            ->expects($this->once())
            ->method('hasImportedFromStravaApi')
            ->willReturn(false);
        $this->strava
            ->expects($this->once())
            ->method('verifyAccessToken')
            ->willThrowException(new InvalidStravaAccessToken());

        $gate = $this->gate(ImportMode::STRAVA_API);

        $this->assertNull($gate->handle(Request::create('/strava-oauth')));
    }

    private function gate(ImportMode $importMode): ValidStravaRefreshTokenGate
    {
        return new ValidStravaRefreshTokenGate(
            $importMode,
            $this->strava,
            $this->activityIdRepository,
        );
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->strava = $this->createMock(Strava::class);
        $this->activityIdRepository = $this->createMock(ActivityIdRepository::class);
    }
}
