<?php

declare(strict_types=1);

namespace App\Domain\Activity;

/**
 * Says which device recorded a file, so a re-export under another name still
 * resolves to the activity it belongs to.
 *
 * The serial number is what makes a reference worth anything. Manufacturer and
 * product are shared by every unit of a model, so a file that does not carry a
 * serial has no reference at all and gets matched on what it contains instead.
 */
final readonly class ActivityDeviceReference implements \Stringable
{
    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public static function fromOptionalString(?string $value): ?self
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return self::fromString($value);
    }

    /**
     * FIT spells a serial out as a number on some devices and as a string on
     * others, and leaves it out of the file entirely on a few.
     */
    public static function fromManufacturerProductAndSerial(
        ?int $manufacturerId,
        ?int $productId,
        int|float|string|null $serialNumber,
    ): ?self {
        $serial = is_float($serialNumber) ? (string) (int) $serialNumber : trim((string) $serialNumber);
        if ('' === $serial) {
            return null;
        }

        return new self(sprintf(
            '%s-%s-%s',
            $manufacturerId ?? '',
            $productId ?? '',
            $serial,
        ));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
