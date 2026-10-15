<?php

declare(strict_types=1);

namespace App\Application\Activity\Revision\SwitchActivityRevision;

use App\Domain\Activity\ActivityId;
use App\Infrastructure\CQRS\Command\Deserialize\CouldNotDeserializeCommand;
use App\Infrastructure\CQRS\Command\Deserialize\DeserializableCommand;
use App\Infrastructure\CQRS\Command\Deserialize\ProvidesCommandName;
use App\Infrastructure\CQRS\Command\DomainCommand;
use App\Infrastructure\CQRS\Command\ProvidesFlashMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Puts an activity back into a state one of its versions had.
 */
final readonly class SwitchActivityRevision extends DomainCommand implements DeserializableCommand, ProvidesFlashMessage
{
    use ProvidesCommandName;

    public function __construct(
        private ActivityId $activityId,
        private int $revisionNumber,
    ) {
    }

    public static function fromPayload(array $payload): self
    {
        if (!isset($payload['activityId']) || !is_string($payload['activityId'])) {
            throw CouldNotDeserializeCommand::invalidPayload('An "activityId" is required.');
        }
        if (!isset($payload['revisionNumber']) || !is_numeric($payload['revisionNumber'])) {
            throw CouldNotDeserializeCommand::invalidPayload('A "revisionNumber" is required.');
        }

        return new self(
            activityId: ActivityId::fromString($payload['activityId']),
            revisionNumber: (int) $payload['revisionNumber'],
        );
    }

    public function getActivityId(): ActivityId
    {
        return $this->activityId;
    }

    public function getRevisionNumber(): int
    {
        return $this->revisionNumber;
    }

    public function getFlashMessage(TranslatorInterface $translator): string
    {
        return $translator->trans('The activity has been switched to the selected version.', [], 'admin');
    }
}
