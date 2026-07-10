<?php

namespace App\Tests\Controller;

use App\Controller\AppRequestHandler;
use App\Tests\ContainerTestCase;
use Spatie\Snapshots\MatchesSnapshots;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AppRequestHandlerTest extends ContainerTestCase
{
    use MatchesSnapshots;

    private AppRequestHandler $appRequestHandler;

    public function testHandle(): void
    {
        /** @var \League\Flysystem\InMemory\InMemoryFilesystemAdapter $buildStorage */
        $buildStorage = $this->getContainer()->get('build_html.storage');
        $buildStorage->write('index.html', 'I am the index', []);

        $this->assertMatchesHtmlSnapshot($this->appRequestHandler->handle()->getContent());
    }

    public function testHandleThrowsWhenTheAppHasNotBeenBuilt(): void
    {
        $this->expectExceptionObject(new NotFoundHttpException('Not found'));

        $this->appRequestHandler->handle();
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->appRequestHandler = new AppRequestHandler(
            $this->getContainer()->get('build_html.storage'),
        );
    }
}
