<?php

declare(strict_types=1);

namespace App\Application\Activity\Revision\SwitchActivityRevision;

use App\Application\Import\FileImport\ImportActivityFiles\Pipeline\ActivityImportContext;
use App\Application\Import\FileImport\ImportActivityFiles\Pipeline\MergeActivityFile;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\Revision\ActivityRevision;
use App\Domain\Activity\Revision\ActivityRevisionRepository;
use App\Domain\Import\FileImport;
use App\Domain\Import\FileImportId;
use App\Domain\Import\FileImportRepository;
use App\Domain\Import\FileParser\ActivityFileParsers;
use App\Domain\Import\FileParser\CouldNotParseActivityFile;
use App\Domain\Import\FileParser\RawActivityFile;
use App\Domain\Import\FileParser\UnsupportedFileType;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\CQRS\Command\CouldNotProcessCommand;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\ValueObject\String\Path;

/**
 * Replays the file a version was made of and lets it win.
 *
 * The bytes are replayed rather than the pipeline being run over them again on
 * purpose: an import also applies the automation rules and looks up weather, and
 * switching to an old version must not re-decide things that are not measurements
 * or reach out to the network for a ride that was recorded years ago. What the
 * bytes say is read with the same parsers and written by the same step a merge
 * uses, in replace mode.
 */
final readonly class SwitchActivityRevisionCommandHandler implements CommandHandler
{
    public function __construct(
        private ActivityRepository $activityRepository,
        private ActivityRevisionRepository $activityRevisionRepository,
        private FileImportRepository $fileImportRepository,
        private ActivityFileParsers $activityFileParsers,
        private MergeActivityFile $mergeActivityFile,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof SwitchActivityRevision);

        $activityId = $command->getActivityId();
        $revisions = $this->activityRevisionRepository->findForActivity($activityId);
        $revision = $revisions->find(
            fn (ActivityRevision $revision): bool => $revision->getRevisionNumber() === $command->getRevisionNumber()
        );
        if (!$revision instanceof ActivityRevision) {
            throw CouldNotProcessCommand::withReason(sprintf('Activity "%s" has no version %d.', (string) $activityId, $command->getRevisionNumber()));
        }

        $fileImport = $this->getFileImport($revision->getGeneratedByFileImportId());
        $fileContents = $fileImport->getFileContents();
        if (null === $fileContents || '' === $fileContents) {
            throw CouldNotProcessCommand::withReason('The file that produced this version is no longer stored, so there is nothing to replay. Versions made before file contents were archived can be read but not restored.');
        }

        $filePath = Path::fromString($fileImport->getOriginalFilename());
        $file = RawActivityFile::from($filePath, $fileContents);
        try {
            $parsedFile = $this->activityFileParsers->parse($file);
        } catch (CouldNotParseActivityFile|UnsupportedFileType $exception) {
            throw CouldNotProcessCommand::withReason(sprintf('The file that produced this version could not be read again: %s', $exception->getMessage()));
        }

        $this->mergeActivityFile->process(
            ActivityImportContext::create($filePath)
                ->withFile($file)
                ->withActivity($parsedFile->getActivity())
                ->withStreams($parsedFile->getStreams())
                ->withLaps($parsedFile->getLaps())
                ->withDrivetrainUsages($parsedFile->getDrivetrainUsages())
                ->withMergeTarget($this->getMergeTarget($activityId))
                ->withReplace()
        );
    }

    /**
     * The page a switch is started from can be older than the activity it shows.
     */
    private function getMergeTarget(ActivityId $activityId): ActivityWithRawData
    {
        try {
            return $this->activityRepository->findWithRawData($activityId);
        } catch (EntityNotFound) {
            throw CouldNotProcessCommand::withReason('This activity is no longer in the database, so there is no version to switch to.');
        }
    }

    private function getFileImport(?FileImportId $fileImportId): FileImport
    {
        if (!$fileImportId instanceof FileImportId) {
            throw CouldNotProcessCommand::withReason('This version was not made by importing a file, so there are no file bytes to replay.');
        }

        try {
            return $this->fileImportRepository->find($fileImportId);
        } catch (EntityNotFound) {
            throw CouldNotProcessCommand::withReason('The file import that produced this version has been deleted, so there is nothing to replay.');
        }
    }
}
