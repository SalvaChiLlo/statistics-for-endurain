<?php

declare(strict_types=1);

namespace App\Tests\Domain\Import\UploadActivityFile;

use App\Domain\Import\ImportMode;
use App\Domain\Import\UploadActivityFile\UploadActivityFile;
use App\Domain\Import\UploadActivityFile\UploadActivityFileCommandHandler;
use App\Domain\Import\WatchDirectory;
use App\Infrastructure\ValueObject\String\KernelProjectDir;
use App\Infrastructure\ValueObject\String\Path;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

class UploadActivityFileCommandHandlerTest extends TestCase
{
    private Filesystem $filesystem;
    private WatchDirectory $watchDirectory;

    public function testHandleWritesFileInFilesMode(): void
    {
        $handler = new UploadActivityFileCommandHandler(ImportMode::FILES, $this->watchDirectory);

        $handler->handle(UploadActivityFile::fromPayload([
            'filename' => 'ride.fit',
            'content' => base64_encode('raw-fit-bytes'),
        ]));

        $this->assertSame('raw-fit-bytes', $this->watchDirectory->readFile(Path::fromString('ride.fit')));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $this->watchDirectory = new WatchDirectory(
            KernelProjectDir::fromString('/project/dir'),
            $this->filesystem,
        );
    }
}
