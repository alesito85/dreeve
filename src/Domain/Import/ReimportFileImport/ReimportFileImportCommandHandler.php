<?php

declare(strict_types=1);

namespace App\Domain\Import\ReimportFileImport;

use App\Domain\Import\ActivityFileName;
use App\Domain\Import\FileImport;
use App\Domain\Import\FileImportId;
use App\Domain\Import\FileImportRepository;
use App\Domain\Import\InvalidActivityFileName;
use App\Domain\Import\WatchDirectory;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\CQRS\Command\CouldNotProcessCommand;
use App\Infrastructure\Exception\EntityNotFound;

final readonly class ReimportFileImportCommandHandler implements CommandHandler
{
    public function __construct(
        private FileImportRepository $fileImportRepository,
        private WatchDirectory $watchDirectory,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof ReimportFileImport);

        $fileImport = $this->getFileImport($command->getFileImportId());
        $fileContents = $fileImport->getFileContents();
        if (null === $fileContents || '' === $fileContents) {
            throw CouldNotProcessCommand::withReason('The file behind this import is not archived, so there is nothing to import again. A file is only kept when an import fails or needs a decision.');
        }

        $this->watchDirectory->writeFile(
            $this->toActivityFileName($fileImport->getOriginalFilename()),
            $fileContents,
        );
    }

    private function getFileImport(FileImportId $fileImportId): FileImport
    {
        try {
            return $this->fileImportRepository->find($fileImportId);
        } catch (EntityNotFound) {
            throw CouldNotProcessCommand::withReason('This import is no longer in the log, so its file cannot be put back.');
        }
    }

    /**
     * The archived name, which is the name the file came in with. A collision in
     * the watch folder is suffixed by the write itself, so a name that is taken
     * does not overwrite anything.
     */
    private function toActivityFileName(string $originalFilename): ActivityFileName
    {
        try {
            return ActivityFileName::fromString($originalFilename);
        } catch (InvalidActivityFileName) {
            throw CouldNotProcessCommand::withReason(sprintf('The archived file is named "%s", which is not a file Dreeve can import again.', $originalFilename));
        }
    }
}
