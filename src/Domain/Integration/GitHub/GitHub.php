<?php

declare(strict_types=1);

namespace App\Domain\Integration\GitHub;

use App\Infrastructure\Serialization\Json;
use GuzzleHttp\Client;

final readonly class GitHub
{
    // This fork's own repo. Used to power the "update available" check for
    // this fork's own independent release line (see AppVersion).
    private const string FORK_REPO = 'SalvaChiLlo/statistics-for-endurain';

    public function __construct(
        private Client $client,
    ) {
    }

    /**
     * Latest release of THIS fork. Used to determine whether this fork
     * itself has an update available, compared against AppVersion.
     */
    public function getLatestRelease(): string
    {
        return $this->fetchLatestReleaseName(self::FORK_REPO);
    }

    private function fetchLatestReleaseName(string $repo): string
    {
        $response = $this->client->request(
            method: 'GET',
            uri: sprintf('https://api.github.com/repos/%s/releases/latest', $repo)
        );

        $json = $response->getBody()->getContents();

        return Json::decode($json)['name'];
    }
}
