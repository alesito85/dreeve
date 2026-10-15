<?php

declare(strict_types=1);

namespace App\Domain\Import\FileParser;

use App\Infrastructure\ValueObject\String\Path;

final readonly class RawActivityFile
{
    private function __construct(
        private Path $filePath,
        private string $fileContents,
    ) {
    }

    public static function from(
        Path $filePath,
        string $fileContents,
    ): self {
        return new self(
            filePath: $filePath,
            fileContents: $fileContents,
        );
    }

    public function getPath(): Path
    {
        return $this->filePath;
    }

    public function getContents(): string
    {
        return $this->fileContents;
    }

    /**
     * SHA-256 of the bytes as they were handed over, gzip wrapper and all. Two
     * files with the same checksum are the same file, however they are named.
     */
    public function getChecksum(): string
    {
        return hash('sha256', $this->fileContents);
    }
}
