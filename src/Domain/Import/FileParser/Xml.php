<?php

declare(strict_types=1);

namespace App\Domain\Import\FileParser;

final readonly class Xml
{
    public static function load(string $contents): ?\SimpleXMLElement
    {
        // A ".tcx.gz" or ".gpx.gz" is stored as the bytes the user handed over, so
        // the gzip comes off here. A body that refuses to unzip falls through and is
        // reported by whoever asked for the XML, which is why its decode warning has
        // nothing to add.
        if (str_starts_with($contents, "\x1F\x8B")) {
            $uncompressed = @gzdecode($contents);
            if (false !== $uncompressed) {
                $contents = $uncompressed;
            }
        }

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        $contents = ltrim($contents);

        // Strip namespace declarations and prefixes so SimpleXML element access is uniform
        // regardless of the file's namespaces.
        $contents = (string) preg_replace('/xmlns(:\w+)?="[^"]*"/', '', $contents);
        $contents = (string) preg_replace('/(<\/?)\w+:/', '$1', $contents);

        $previousErrorState = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contents);
        libxml_use_internal_errors($previousErrorState);

        return false !== $xml ? $xml : null;
    }
}
