<?php

declare(strict_types=1);

namespace App\Tests\Domain\Integration\GitHub;

use App\Domain\Integration\GitHub\GitHub;
use App\Infrastructure\Serialization\Json;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class GitHubTest extends TestCase
{
    public function testGetLatestReleaseQueriesThisForksOwnRepo(): void
    {
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('request')
            ->with('GET', 'https://api.github.com/repos/SalvaChiLlo/statistics-for-endurain/releases/latest')
            ->willReturn(new Response(status: 200, body: Json::encode(['name' => 'v5.7.0'])));

        $gitHub = new GitHub($client);

        $this->assertSame('v5.7.0', $gitHub->getLatestRelease());
    }
}
