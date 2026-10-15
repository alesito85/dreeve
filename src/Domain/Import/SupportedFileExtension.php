<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Activity\ImportSource;

enum SupportedFileExtension: string
{
    case FIT = 'fit';
    case TCX = 'tcx';
    case GPX = 'gpx';

    /**
     * A ".fit.gz" is still a FIT file, so look through one gzip suffix before
     * giving up on the extension. The gzip itself is handled by the parsers,
     * which is why there is no case for it here.
     */
    public static function fromExtensionChain(string $filename): ?self
    {
        $filename = basename($filename);
        if ('gz' === strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            $filename = substr($filename, 0, -3);
        }

        return self::tryFrom(strtolower(pathinfo($filename, PATHINFO_EXTENSION)));
    }

    /**
     * What a browser should let the user pick, gzip wrappers included.
     *
     * @return list<string>
     */
    public static function acceptedForUpload(): array
    {
        return array_merge(
            array_map(fn (self $extension): string => $extension->value, self::cases()),
            array_map(fn (self $extension): string => $extension->value.'.gz', self::cases()),
        );
    }

    /**
     * The source a file gets imported from. Callers reach this for files they
     * already know to be supported, so a name that is not a bug worth failing
     * on rather than one that silently imports as the wrong source.
     */
    public static function importSourceFor(string $filename): ImportSource
    {
        return (self::fromExtensionChain($filename)
            ?? throw new \InvalidArgumentException(sprintf('Unsupported file extension "%s"', $filename)))
            ->getImportSource();
    }

    public function getImportSource(): ImportSource
    {
        return match ($this) {
            self::FIT => ImportSource::FIT_FILE,
            self::TCX => ImportSource::TCX_FILE,
            self::GPX => ImportSource::GPX_FILE,
        };
    }
}
