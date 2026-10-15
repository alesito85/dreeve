<?php

declare(strict_types=1);

namespace App\Domain\Import\ReimportFileImport;

use App\Domain\Import\FileImportId;
use App\Infrastructure\CQRS\Command\Deserialize\CouldNotDeserializeCommand;
use App\Infrastructure\CQRS\Command\Deserialize\DeserializableCommand;
use App\Infrastructure\CQRS\Command\Deserialize\ProvidesCommandName;
use App\Infrastructure\CQRS\Command\DomainCommand;
use App\Infrastructure\CQRS\Command\ProvidesFlashMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Puts the file behind an import row back into the watch folder.
 *
 * A file that failed to parse is not lost, its bytes are archived with the log
 * row. This hands them back to the import run, which is the only thing that knows
 * how to import a file, instead of a second import path in the web layer.
 */
final readonly class ReimportFileImport extends DomainCommand implements DeserializableCommand, ProvidesFlashMessage
{
    use ProvidesCommandName;

    private function __construct(
        private FileImportId $fileImportId,
    ) {
    }

    public static function fromPayload(array $payload): self
    {
        if (!isset($payload['fileImportId']) || !is_string($payload['fileImportId'])) {
            throw CouldNotDeserializeCommand::invalidPayload('A "fileImportId" is required.');
        }

        return new self(
            fileImportId: FileImportId::fromString($payload['fileImportId']),
        );
    }

    public function getFileImportId(): FileImportId
    {
        return $this->fileImportId;
    }

    public function getFlashMessage(TranslatorInterface $translator): string
    {
        return $translator->trans('The file has been put back in the watch folder and will be imported again.', [], 'admin');
    }
}
