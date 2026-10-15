<?php

declare(strict_types=1);

namespace App\Application\Import\FileImport\ImportActivityFiles\Pipeline;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\ImportSource;
use App\Domain\Activity\Lap\ActivityLaps;
use App\Domain\Activity\Shifting\ActivityDrivetrainUsages;
use App\Domain\Activity\Stream\ActivityStreams;
use App\Domain\Import\FileParser\RawActivityFile;
use App\Domain\Import\SupportedFileExtension;
use App\Infrastructure\ValueObject\String\Path;

final readonly class ActivityImportContext
{
    private function __construct(
        private Path $filePath,
        private ?RawActivityFile $file,
        private ?Activity $activity,
        private ActivityStreams $streams,
        private ActivityLaps $laps,
        private ActivityDrivetrainUsages $drivetrainUsages,
        private ?ActivityWithRawData $mergeTarget,
        private bool $replaces,
    ) {
    }

    public static function create(
        Path $filePath,
    ): self {
        return new self(
            filePath: $filePath,
            file: null,
            activity: null,
            streams: ActivityStreams::empty(),
            laps: ActivityLaps::empty(),
            drivetrainUsages: ActivityDrivetrainUsages::empty(),
            mergeTarget: null,
            replaces: false,
        );
    }

    public function getImportSource(): ImportSource
    {
        return SupportedFileExtension::fromExtensionChain($this->filePath->getFilename())?->getImportSource()
            ?? throw new \RuntimeException(sprintf('Unknown file extension "%s"', $this->filePath->getFilename()));
    }

    public function withActivity(Activity $activity): self
    {
        return clone ($this, [
            'activity' => $activity,
        ]);
    }

    public function withStreams(ActivityStreams $streams): self
    {
        return clone ($this, [
            'streams' => $streams,
        ]);
    }

    public function withLaps(ActivityLaps $laps): self
    {
        return clone ($this, [
            'laps' => $laps,
        ]);
    }

    public function withDrivetrainUsages(ActivityDrivetrainUsages $drivetrainUsages): self
    {
        return clone ($this, [
            'drivetrainUsages' => $drivetrainUsages,
        ]);
    }

    /**
     * The activity this file belongs to. Set by the identity step, and what
     * turns the import from an insert into a merge.
     */
    public function withMergeTarget(ActivityWithRawData $mergeTarget): self
    {
        return clone ($this, [
            'mergeTarget' => $mergeTarget,
        ]);
    }

    /**
     * The file being imported replaces what is stored instead of adding to it.
     * Set when a stored version is replayed, because the version being switched
     * to is the whole truth about that ride, not one more file about it.
     */
    public function withReplace(): self
    {
        return clone ($this, [
            'replaces' => true,
        ]);
    }

    public function withFile(RawActivityFile $file): self
    {
        return clone ($this, [
            'file' => $file,
        ]);
    }

    public function getFile(): ?RawActivityFile
    {
        return $this->file;
    }

    public function getFilePath(): Path
    {
        return $this->filePath;
    }

    public function getActivity(): ?Activity
    {
        return $this->activity;
    }

    public function getStreams(): ActivityStreams
    {
        return $this->streams;
    }

    public function getLaps(): ActivityLaps
    {
        return $this->laps;
    }

    public function getDrivetrainUsages(): ActivityDrivetrainUsages
    {
        return $this->drivetrainUsages;
    }

    public function getMergeTarget(): ?ActivityWithRawData
    {
        return $this->mergeTarget;
    }

    public function isMerge(): bool
    {
        return $this->mergeTarget instanceof ActivityWithRawData;
    }

    public function isReplace(): bool
    {
        return $this->replaces;
    }
}
