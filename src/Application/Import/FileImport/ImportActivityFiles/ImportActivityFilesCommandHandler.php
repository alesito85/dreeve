<?php

declare(strict_types=1);

namespace App\Application\Import\FileImport\ImportActivityFiles;

use App\Application\Import\FileImport\ImportActivityFiles\Pipeline\ActivityImportContext;
use App\Application\Import\FileImport\ImportActivityFiles\Pipeline\ImportActivityFileStep;
use App\Application\Import\FileImport\ImportActivityFiles\Pipeline\SkipDuplicateActivity;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\Lap\ActivityLapRepository;
use App\Domain\Activity\Scan\ActivityScan;
use App\Domain\Activity\Scan\ActivityScanRepository;
use App\Domain\Activity\Scan\ActivityScanType;
use App\Domain\Activity\Shifting\ActivityDrivetrainUsageRepository;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Import\AmbiguousActivityMatch;
use App\Domain\Import\FileImport;
use App\Domain\Import\FileImportId;
use App\Domain\Import\FileImportRepository;
use App\Domain\Import\FileImportStatus;
use App\Domain\Import\FileParser\CouldNotParseActivityFile;
use App\Domain\Import\FileParser\RawActivityFile;
use App\Domain\Import\FileParser\UnsupportedFileType;
use App\Domain\Import\WatchDirectory;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\DependencyInjection\Mutex\WithMutex;
use App\Infrastructure\Mutex\LockName;
use App\Infrastructure\Mutex\Mutex;
use App\Infrastructure\Time\Clock\Clock;
use League\Flysystem\StorageAttributes;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

#[WithMutex(lockName: LockName::IMPORT_DATA)]
final readonly class ImportActivityFilesCommandHandler implements CommandHandler
{
    /**
     * @param iterable<ImportActivityFileStep> $steps
     */
    public function __construct(
        private WatchDirectory $watchDirectory,
        #[AutowireIterator('app.activity_import_file.pipeline_step')]
        private iterable $steps,
        private ActivityRepository $activityRepository,
        private ActivityStreamRepository $activityStreamRepository,
        private ActivityScanRepository $activityScanRepository,
        private ActivityLapRepository $activityLapRepository,
        private ActivityDrivetrainUsageRepository $activityDrivetrainUsageRepository,
        private FileImportRepository $fileImportRepository,
        private Mutex $mutex,
        private Clock $clock,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof ImportActivityFiles);
        $output = $command->getOutput();
        $output->writeln('Importing activity files...');

        if (!$this->watchDirectory->exists()) {
            $output->writeln('  => No "watch" directory found, nothing to import');

            return;
        }

        $countImported = 0;
        $countSkipped = 0;
        $countFailed = 0;

        // Anything that is not an activity file is left in the folder, a watch
        // folder can hold whatever the user put there. It does need saying,
        // otherwise it looks like an import that keeps getting stuck.
        $ignoredFiles = $this->watchDirectory->listFilesThatAreNotActivityFiles()->toArray();
        if ([] !== $ignoredFiles) {
            $output->writeln(sprintf(
                '  => Ignored %d file(s) in "watch" that are not activity files: %s',
                count($ignoredFiles),
                implode(', ', array_map(
                    fn (StorageAttributes $file): string => $this->watchDirectory->getAbsolutePathFor($file)->getFilename(),
                    $ignoredFiles,
                )),
            ));
        }

        $files = $this->watchDirectory->listFilesThatCanBeProcessed()->toArray();
        $countTotalFilesInWatchDirectory = count($files);
        $delta = 0;
        foreach ($files as $item) {
            $this->mutex->heartbeat();
            ++$delta;
            $filePath = $this->watchDirectory->getAbsolutePathFor($item);
            $context = ActivityImportContext::create($filePath);

            try {
                foreach ($this->steps as $step) {
                    $context = $step->process($context);
                }
            } catch (SkipDuplicateActivity) {
                $this->watchDirectory->deleteFile($filePath);
                $this->fileImportRepository->add(FileImport::create(
                    fileImportId: FileImportId::random(),
                    originalFilename: $context->getFilePath()->getFilename(),
                    source: $context->getImportSource(),
                    status: FileImportStatus::SKIPPED,
                    errorMessage: 'Skipped, activity was already imported',
                    activityId: null,
                    importedOn: $this->clock->getCurrentDateTimeImmutable(),
                ));

                $output->writeln(sprintf('  => [%d/%d] Skipping "%s", activity was already imported', $delta, $countTotalFilesInWatchDirectory, $filePath->getFilename()));
                ++$countSkipped;
                continue;
            } catch (AmbiguousActivityMatch $e) {
                // The bytes are stored with the row, so the file itself can go.
                // It is in the import log, where it can be looked at and, if the
                // decision is made by hand, imported from there.
                $file = $context->getFile() ?? throw new \RuntimeException('File not set on $context');
                $this->fileImportRepository->add(FileImport::createFromRawFile(
                    fileImportId: FileImportId::random(),
                    file: $file,
                    source: $context->getImportSource(),
                    status: FileImportStatus::SKIPPED,
                    errorMessage: sprintf(
                        'Skipped, %s. It needs a manual decision on which activity it belongs to',
                        strtolower($e->getMessage())
                    ),
                    activityId: null,
                    importedOn: $this->clock->getCurrentDateTimeImmutable(),
                ));
                $this->watchDirectory->deleteFile($filePath);

                $output->writeln(sprintf('  => [%d/%d] Skipping "%s", %s', $delta, $countTotalFilesInWatchDirectory, $filePath->getFilename(), $e->getMessage()));
                ++$countSkipped;
                continue;
            } catch (UnsupportedFileType) {
                $output->writeln(sprintf('  => [%d/%d] Skipping "%s", unsupported file type', $delta, $countTotalFilesInWatchDirectory, $filePath->getFilename()));
                ++$countSkipped;
                continue;
            } catch (CouldNotParseActivityFile $e) {
                $this->fileImportRepository->add(FileImport::createFromRawFile(
                    fileImportId: FileImportId::random(),
                    file: $e->getActivityFile(),
                    source: $context->getImportSource(),
                    status: FileImportStatus::FAILED,
                    errorMessage: $e->getMessage(),
                    activityId: null,
                    importedOn: $this->clock->getCurrentDateTimeImmutable(),
                ));
                $this->watchDirectory->deleteFile($filePath);

                $output->writeln(sprintf('  => <error>[%d/%d] Could not import "%s": %s</error>', $delta, $countTotalFilesInWatchDirectory, $filePath->getFilename(), $e->getMessage()));
                ++$countFailed;
                continue;
            }

            $activity = $context->getActivity() ?? throw new \RuntimeException('Activity not set on $context');
            $activityId = $activity->getId();

            if ($context->isMerge()) {
                // The merge step already wrote the activity, its streams and the
                // version it replaced. What is left is the log row for this file
                // and the file itself. It is not a new activity, so it stays out
                // of the count the success notification reports on.
                $this->fileImportRepository->add(FileImport::createFromRawFile(
                    fileImportId: FileImportId::random(),
                    file: $context->getFile() ?? throw new \RuntimeException('File not set on $context'),
                    source: $context->getImportSource(),
                    status: FileImportStatus::SUCCESS,
                    errorMessage: null,
                    activityId: $activityId,
                    importedOn: $this->clock->getCurrentDateTimeImmutable(),
                ));
                $this->watchDirectory->deleteFile($filePath);

                $output->writeln(sprintf(
                    '  => [%d/%d] Merged "%s" into activity "%s - %s"',
                    $delta,
                    $countTotalFilesInWatchDirectory,
                    $filePath->getFilename(),
                    $activity->getName(),
                    $activity->getStartDate()->format('d-m-Y'),
                ));
                ++$countImported;

                continue;
            }

            $this->activityRepository->add(ActivityWithRawData::fromState(
                activity: $activity,
                rawData: [],
            ));
            $command->getImportedActivities()->add($activity);

            foreach ($context->getStreams() as $stream) {
                $this->activityStreamRepository->add($stream);
            }
            $this->activityScanRepository->add(ActivityScan::create(activityId: $activityId, type: ActivityScanType::STREAMS));

            foreach ($context->getLaps() as $lap) {
                $this->activityLapRepository->add($lap);
            }

            foreach ($context->getDrivetrainUsages() as $drivetrainUsage) {
                $this->activityDrivetrainUsageRepository->add($drivetrainUsage);
            }

            $file = $context->getFile();
            assert($file instanceof RawActivityFile);

            $this->fileImportRepository->add(FileImport::createFromRawFile(
                fileImportId: FileImportId::random(),
                file: $file,
                source: $activity->getImportSource(),
                status: FileImportStatus::SUCCESS,
                errorMessage: null,
                activityId: $activityId,
                importedOn: $this->clock->getCurrentDateTimeImmutable(),
            ));

            $this->watchDirectory->deleteFile($filePath);

            $output->writeln(sprintf(
                '  => [%d/%d] Imported "%s" as activity "%s - %s"',
                $delta,
                $countTotalFilesInWatchDirectory,
                $filePath->getFilename(),
                $activity->getName(),
                $activity->getStartDate()->format('d-m-Y'),
            ));
            ++$countImported;
        }

        $output->writeln(sprintf(
            '  => Imported %d, skipped %d, failed %d activity file(s)',
            $countImported,
            $countSkipped,
            $countFailed,
        ));
    }
}
