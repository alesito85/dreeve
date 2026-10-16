<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Activity\ActivityId;

interface FileImportRepository
{
    public function add(FileImport $fileImport): void;

    public function find(FileImportId $fileImportId): FileImport;

    public function findForActivity(ActivityId $activityId): FileImport;

    public function hasFileContentsForActivity(ActivityId $activityId): bool;

    public function delete(FileImport $fileImport): void;

    public function deleteForActivity(ActivityId $activityId): void;

    /**
     * The import whose bytes produced what an activity looks like now, which is
     * what a retired version needs to be pointed at.
     */
    public function findMostRecentSuccessfulForActivity(ActivityId $activityId): ?FileImport;
}
