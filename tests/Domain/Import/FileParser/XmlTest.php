<?php

declare(strict_types=1);

namespace App\Tests\Domain\Import\FileParser;

use App\Domain\Import\FileParser\Xml;
use PHPUnit\Framework\TestCase;

class XmlTest extends TestCase
{
    public function testLoad(): void
    {
        $xml = Xml::load($this->tcx());

        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);
        $this->assertSame('Morning Ride', (string) $xml->Activities->Activity[0]->Name);
    }

    public function testLoadUnzipsAGzippedBody(): void
    {
        $xml = Xml::load((string) gzencode($this->tcx(), 9));

        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);
        $this->assertSame('Morning Ride', (string) $xml->Activities->Activity[0]->Name);
    }

    public function testLoadUnzipsAGzippedBodyThatCarriesAByteOrderMark(): void
    {
        $xml = Xml::load((string) gzencode("\xEF\xBB\xBF".$this->tcx(), 9));

        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);
        $this->assertSame('Morning Ride', (string) $xml->Activities->Activity[0]->Name);
    }

    public function testLoadFallsThroughWhenTheBodyIsNotActuallyGzipped(): void
    {
        $this->assertNull(Xml::load("\x1F\x8B this is not a gzip stream"));
    }

    public function testLoadReturnsNullForSomethingThatIsNotXml(): void
    {
        $this->assertNull(Xml::load('not xml at all'));
    }

    private function tcx(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <TrainingCenterDatabase xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2">
              <Activities>
                <Activity Sport="Biking">
                  <Id>2026-07-02T08:00:00.000Z</Id>
                  <Name>Morning Ride</Name>
                </Activity>
              </Activities>
            </TrainingCenterDatabase>
            XML;
    }
}
