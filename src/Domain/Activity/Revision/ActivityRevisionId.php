<?php

declare(strict_types=1);

namespace App\Domain\Activity\Revision;

use App\Infrastructure\ValueObject\Identifier\Identifier;

final readonly class ActivityRevisionId extends Identifier
{
    public static function getPrefix(): string
    {
        return 'activityRevision-';
    }
}
