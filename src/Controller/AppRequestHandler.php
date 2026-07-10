<?php

declare(strict_types=1);

namespace App\Controller;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
final readonly class AppRequestHandler
{
    public function __construct(
        private FilesystemOperator $buildHtmlStorage,
        private Environment $twig,
    ) {
    }

    #[Route(path: '/{wildcard?}', requirements: ['wildcard' => '.*'], methods: ['GET'], priority: -10)]
    public function handle(): Response
    {
        if ($this->buildHtmlStorage->fileExists('index.html')) {
            return new Response($this->buildHtmlStorage->read('index.html'), Response::HTTP_OK);
        }

        return new Response($this->twig->render('html/finish-setup.html.twig'), Response::HTTP_OK);
    }
}
