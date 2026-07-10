<?php

declare(strict_types=1);

namespace App\Controller;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class AppRequestHandler
{
    public function __construct(
        private FilesystemOperator $buildHtmlStorage,
    ) {
    }

    #[Route(path: '/{wildcard?}', name: 'app', requirements: ['wildcard' => '.*'], methods: ['GET'], priority: -10)]
    public function handle(): Response
    {
        if (!$this->buildHtmlStorage->fileExists('index.html')) {
            throw new NotFoundHttpException('Not found');
        }

        return new Response($this->buildHtmlStorage->read('index.html'), Response::HTTP_OK);
    }
}
