<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity;

use App\Domain\Activity\ActivityDeviceReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ActivityDeviceReferenceTest extends TestCase
{
    #[DataProvider('provideFileIdFields')]
    public function testFromManufacturerProductAndSerial(
        ?int $manufacturerId,
        ?int $productId,
        int|float|string|null $serialNumber,
        ?string $expected,
    ): void {
        $reference = ActivityDeviceReference::fromManufacturerProductAndSerial(
            manufacturerId: $manufacturerId,
            productId: $productId,
            serialNumber: $serialNumber,
        );

        $this->assertSame($expected, null !== $reference ? (string) $reference : null);
    }

    /**
     * @return iterable<string, array{int|null, int|null, int|float|string|null, string|null}>
     */
    public static function provideFileIdFields(): iterable
    {
        yield 'a numeric serial' => [289, 2, 22701926, '289-2-22701926'];
        yield 'a serial spelled out as a string' => [1, 3592, '0fc100b6', '1-3592-0fc100b6'];
        yield 'a serial that came through as a float' => [1, 3592, 3485049140.0, '1-3592-3485049140'];
        yield 'a product only known by name' => [1, null, 4459010314, '1--4459010314'];
        yield 'no manufacturer' => [null, 2, 22701926, '-2-22701926'];
        yield 'no serial means no reference' => [289, 2, null, null];
        yield 'an empty serial means no reference' => [289, 2, '  ', null];
    }

    public function testFromOptionalString(): void
    {
        $this->assertSame('289-2-22701926', (string) ActivityDeviceReference::fromOptionalString('289-2-22701926'));
        $this->assertNull(ActivityDeviceReference::fromOptionalString(null));
        $this->assertNull(ActivityDeviceReference::fromOptionalString(''));
    }

    public function testItSurvivesAStringRoundTrip(): void
    {
        $reference = ActivityDeviceReference::fromManufacturerProductAndSerial(289, 2, 22701926);
        $this->assertInstanceOf(ActivityDeviceReference::class, $reference);

        $this->assertEquals($reference, ActivityDeviceReference::fromString((string) $reference));
    }
}
