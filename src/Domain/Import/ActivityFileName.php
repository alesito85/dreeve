<?php

declare(strict_types=1);

namespace App\Domain\Import;

final readonly class ActivityFileName implements \Stringable
{
    private const int MAX_LENGTH_IN_BYTES = 200;

    private function __construct(
        private string $name,
        private SupportedFileExtension $extension,
    ) {
    }

    public static function fromString(string $name): self
    {
        if (!mb_check_encoding($name, 'UTF-8') || 1 === preg_match('#[\x00-\x1F\x7F]#', $name)) {
            throw InvalidActivityFileName::isNotValid();
        }

        $name = basename(str_replace('\\', '/', $name));

        if (strlen($name) > self::MAX_LENGTH_IN_BYTES) {
            throw InvalidActivityFileName::isTooLong(self::MAX_LENGTH_IN_BYTES);
        }

        if ('' === pathinfo($name, PATHINFO_FILENAME)) {
            throw InvalidActivityFileName::isNotValid();
        }

        $extension = SupportedFileExtension::fromExtensionChain($name)
            ?? throw InvalidActivityFileName::fileTypeIsNotSupported();

        return new self($name, $extension);
    }

    public function getExtension(): SupportedFileExtension
    {
        return $this->extension;
    }

    public function withSuffix(string $suffix): self
    {
        [$name, $extensionChain] = $this->splitExtensionChain($this->name, $this->extension);

        return new self(
            sprintf('%s-%s.%s', $name, $suffix, $extensionChain),
            $this->extension,
        );
    }

    /**
     * Splits a name into everything before its extensions and the extensions
     * themselves, as written. When the file is gzipped both suffixes are kept,
     * the gzip is part of the name and a collision suffix belongs before it.
     *
     * @return array{string, string}
     */
    private function splitExtensionChain(string $name, SupportedFileExtension $extension): array
    {
        $extensionChain = pathinfo($name, PATHINFO_EXTENSION);
        if (strtolower($extensionChain) !== $extension->value) {
            // The trailing ".gz" only wraps the real format, so cut it off, read
            // the format underneath it, and put both back at the end later on.
            $name = substr($name, 0, -3);
            $extensionChain = pathinfo($name, PATHINFO_EXTENSION).'.gz';
        }

        // Everything up to the dot in front of the last extension is the name.
        return [substr($name, 0, -strlen(pathinfo($name, PATHINFO_EXTENSION)) - 1), $extensionChain];
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
