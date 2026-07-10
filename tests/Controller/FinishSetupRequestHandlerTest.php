<?php

namespace App\Tests\Controller;

use App\Controller\FinishSetupRequestHandler;
use App\Domain\Activity\ActivityIdRepository;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Tests\ContainerTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;
use Spatie\Snapshots\MatchesSnapshots;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class FinishSetupRequestHandlerTest extends ContainerTestCase
{
    use MatchesSnapshots;

    private FinishSetupRequestHandler $finishSetupRequestHandler;

    public function testHandleWhenNotReady(): void
    {
        $this->assertMatchesHtmlSnapshot($this->finishSetupRequestHandler->handle()->getContent());
    }

    public function testHandleRedirectsWhenTheAppIsReady(): void
    {
        /** @var \League\Flysystem\InMemory\InMemoryFilesystemAdapter $buildStorage */
        $buildStorage = $this->getContainer()->get('build_html.storage');
        $buildStorage->write('index.html', 'I am the index', []);

        $this->getContainer()->get(ActivityRepository::class)->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()->build(),
            [],
        ));

        $this->assertEquals(
            new RedirectResponse('/', Response::HTTP_FOUND),
            $this->finishSetupRequestHandler->handle(),
        );
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->finishSetupRequestHandler = new FinishSetupRequestHandler(
            $this->getContainer()->get('build_html.storage'),
            $this->getContainer()->get(ActivityIdRepository::class),
            $this->getContainer()->get(Environment::class),
        );
    }
}
