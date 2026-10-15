<?php

declare(strict_types=1);

namespace App\Application\Import\FileImport\ImportActivityFiles\Pipeline;

use App\Domain\Import\DuplicateActivityScanner;
use App\Domain\Import\FileParser\ActivityFileParsers;
use App\Domain\Import\FileParser\RawActivityFile;
use App\Domain\Import\WatchDirectory;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 100)]
final readonly class ParseActivityFile implements ImportActivityFileStep
{
    public function __construct(
        private WatchDirectory $watchDirectory,
        private ActivityFileParsers $activityFileParsers,
        private DuplicateActivityScanner $duplicateActivityScanner,
    ) {
    }

    public function process(ActivityImportContext $context): ActivityImportContext
    {
        $file = RawActivityFile::from(
            filePath: $context->getFilePath(),
            fileContents: $this->watchDirectory->readFile($context->getFilePath())
        );

        $context = $context->withFile($file);
        $parsedFile = $this->activityFileParsers->parse($file);

        // The checksum is of the bytes as they arrived, gzip wrapper and all, and
        // it is stored with the very first import so a later file can find this
        // activity instead of adding a second one.
        $activity = $parsedFile->getActivity()->withFileChecksum($file->getChecksum());
        if ($this->duplicateActivityScanner->isDuplicate(
            file: $file,
            sportType: $activity->getSportType(),
            startDateTime: $activity->getStartDate()
        )) {
            throw new SkipDuplicateActivity();
        }

        return $context
            ->withActivity($activity)
            ->withStreams($parsedFile->getStreams())
            ->withLaps($parsedFile->getLaps())
            ->withDrivetrainUsages($parsedFile->getDrivetrainUsages());
    }
}
