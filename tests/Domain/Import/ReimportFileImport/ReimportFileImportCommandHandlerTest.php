<?php

declare(strict_types=1);

namespace App\Tests\Domain\Import\ReimportFileImport;

use App\Domain\Activity\ImportSource;
use App\Domain\Import\FileImportId;
use App\Domain\Import\FileImportRepository;
use App\Domain\Import\FileImportStatus;
use App\Domain\Import\ReimportFileImport\ReimportFileImport;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\CQRS\Command\CouldNotProcessCommand;
use App\Tests\ContainerTestCase;
use App\Tests\Domain\Import\FileImportBuilder;
use League\Flysystem\FilesystemOperator;

class ReimportFileImportCommandHandlerTest extends ContainerTestCase
{
    private CommandBus $commandBus;
    private FileImportRepository $fileImportRepository;
    private FilesystemOperator $watchStorage;

    public function testHandlePutsTheArchivedFileBackInTheWatchFolder(): void
    {
        $this->fileImportRepository->add(
            FileImportBuilder::fromDefaults()
                ->withFileImportId(FileImportId::fromUnprefixed('1'))
                ->withOriginalFilename('ride.tcx')
                ->withFileContents('raw-tcx-bytes')
                ->withStatus(FileImportStatus::FAILED)
                ->build()
        );

        $this->commandBus->dispatch(ReimportFileImport::fromPayload([
            'fileImportId' => (string) FileImportId::fromUnprefixed('1'),
        ]));

        $this->assertTrue($this->watchStorage->fileExists('watch/ride.tcx'));
        $this->assertSame('raw-tcx-bytes', $this->watchStorage->read('watch/ride.tcx'));
    }

    public function testHandleDoesNotOverwriteAFileThatIsAlreadyWaiting(): void
    {
        $this->watchStorage->write('watch/ride.tcx', 'the file that is waiting');
        $this->fileImportRepository->add(
            FileImportBuilder::fromDefaults()
                ->withFileImportId(FileImportId::fromUnprefixed('1'))
                ->withOriginalFilename('ride.tcx')
                ->withFileContents('raw-tcx-bytes')
                ->withStatus(FileImportStatus::SKIPPED)
                ->build()
        );

        $this->commandBus->dispatch(ReimportFileImport::fromPayload([
            'fileImportId' => (string) FileImportId::fromUnprefixed('1'),
        ]));

        $this->assertSame('the file that is waiting', $this->watchStorage->read('watch/ride.tcx'));
        $this->assertSame('raw-tcx-bytes', $this->watchStorage->read('watch/ride-1.tcx'));
    }

    public function testHandleCouldNotReimportAFileThatWasNotArchived(): void
    {
        $this->fileImportRepository->add(
            FileImportBuilder::fromDefaults()
                ->withFileImportId(FileImportId::fromUnprefixed('1'))
                ->withOriginalFilename('ride.tcx')
                ->withStatus(FileImportStatus::SUCCESS)
                ->build()
        );

        $this->expectException(CouldNotProcessCommand::class);
        $this->expectExceptionMessageIsOrContains('The file behind this import is not archived');

        $this->commandBus->dispatch(ReimportFileImport::fromPayload([
            'fileImportId' => (string) FileImportId::fromUnprefixed('1'),
        ]));
    }

    public function testHandleCouldNotReimportAnImportThatIsNoLongerInTheLog(): void
    {
        $this->expectException(CouldNotProcessCommand::class);
        $this->expectExceptionMessageIsOrContains('This import is no longer in the log, so its file cannot be put back.');

        $this->commandBus->dispatch(ReimportFileImport::fromPayload([
            'fileImportId' => (string) FileImportId::fromUnprefixed('gone'),
        ]));
    }

    public function testHandleCouldNotReimportAFileThatIsNotAnActivityFile(): void
    {
        $this->fileImportRepository->add(
            FileImportBuilder::fromDefaults()
                ->withFileImportId(FileImportId::fromUnprefixed('1'))
                ->withOriginalFilename('notes.gz')
                ->withFileContents('not a gzipped activity')
                ->withSource(ImportSource::FIT_FILE)
                ->withStatus(FileImportStatus::FAILED)
                ->build()
        );

        $this->expectException(CouldNotProcessCommand::class);
        $this->expectExceptionMessageIsOrContains('The archived file is named "notes.gz", which is not a file Dreeve can import again.');

        $this->commandBus->dispatch(ReimportFileImport::fromPayload([
            'fileImportId' => (string) FileImportId::fromUnprefixed('1'),
        ]));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->commandBus = $this->getContainer()->get(CommandBus::class);
        $this->fileImportRepository = $this->getContainer()->get(FileImportRepository::class);
        $this->watchStorage = $this->getContainer()->get('default.storage');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->watchStorage->deleteDirectory('watch');

        parent::tearDown();
    }
}
