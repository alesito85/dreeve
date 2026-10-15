<?php

declare(strict_types=1);

namespace App\Tests\Domain\Import;

use App\Domain\Activity\ImportSource;
use App\Domain\Import\SupportedFileExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupportedFileExtensionTest extends TestCase
{
    #[DataProvider('provideFilenames')]
    public function testFromExtensionChain(string $filename, ?SupportedFileExtension $expected): void
    {
        $this->assertSame($expected, SupportedFileExtension::fromExtensionChain($filename));
    }

    /**
     * @return iterable<string, array{string, SupportedFileExtension|null}>
     */
    public static function provideFilenames(): iterable
    {
        yield 'a plain fit file' => ['ride.fit', SupportedFileExtension::FIT];
        yield 'an uppercase extension' => ['RIDE_2026-07-02.FIT', SupportedFileExtension::FIT];
        yield 'a path' => ['watch/sub/dir/ride.tcx', SupportedFileExtension::TCX];
        yield 'a gzipped fit file' => ['ride.fit.gz', SupportedFileExtension::FIT];
        yield 'a gzipped tcx file' => ['ride.TCX.gz', SupportedFileExtension::TCX];
        yield 'a gzipped gpx file in a path' => ['watch/Afternoon_Ride (4).gpx.gz', SupportedFileExtension::GPX];
        yield 'an uppercase gzip suffix' => ['RIDE.FIT.GZ', SupportedFileExtension::FIT];
        yield 'double gzip is not a format we read' => ['ride.fit.gz.gz', null];
        yield 'a bare gzip without a format' => ['notes.gz', null];
        yield 'an unsupported extension' => ['notes.txt', null];
        yield 'an unsupported extension behind a gzip' => ['notes.txt.gz', null];
        yield 'no extension at all' => ['ride', null];
        yield 'an empty name' => ['', null];
    }

    public function testAcceptedForUpload(): void
    {
        $this->assertSame(
            ['fit', 'tcx', 'gpx', 'fit.gz', 'tcx.gz', 'gpx.gz'],
            SupportedFileExtension::acceptedForUpload(),
        );
    }

    public function testImportSourceFor(): void
    {
        $this->assertSame(ImportSource::FIT_FILE, SupportedFileExtension::importSourceFor('ride.fit'));
        $this->assertSame(ImportSource::TCX_FILE, SupportedFileExtension::importSourceFor('ride.tcx.gz'));
    }

    public function testImportSourceForThrowsWhenTheFileIsNotSupported(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unsupported file extension "notes.gz"');

        SupportedFileExtension::importSourceFor('notes.gz');
    }
}
