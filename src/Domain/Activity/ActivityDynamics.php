<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use App\Infrastructure\Serialization\Json;

/**
 * The cycling dynamics and effort scalars a FIT file can carry on top of the
 * regular summaries. Everything is optional: most files carry none of it.
 */
final readonly class ActivityDynamics implements \JsonSerializable
{
    public function __construct(
        private ?float $leftRightBalanceInPercent = null,
        private ?float $aerobicTrainingEffect = null,
        private ?float $anaerobicTrainingEffect = null,
        private ?int $jumpCount = null,
        private ?float $leftTorqueEffectivenessInPercent = null,
        private ?float $rightTorqueEffectivenessInPercent = null,
        private ?float $leftPedalSmoothnessInPercent = null,
        private ?float $rightPedalSmoothnessInPercent = null,
        private ?float $combinedPedalSmoothnessInPercent = null,
        private ?float $leftPowerPhaseInDegrees = null,
        private ?float $leftPowerPhasePeakInDegrees = null,
        private ?float $rightPowerPhaseInDegrees = null,
        private ?float $rightPowerPhasePeakInDegrees = null,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $optionalFloat = static fn (mixed $value): ?float => is_numeric($value) ? (float) $value : null;

        return new self(
            leftRightBalanceInPercent: $optionalFloat($values['leftRightBalanceInPercent'] ?? null),
            aerobicTrainingEffect: $optionalFloat($values['aerobicTrainingEffect'] ?? null),
            anaerobicTrainingEffect: $optionalFloat($values['anaerobicTrainingEffect'] ?? null),
            jumpCount: is_numeric($values['jumpCount'] ?? null) ? (int) round((float) $values['jumpCount']) : null,
            leftTorqueEffectivenessInPercent: $optionalFloat($values['leftTorqueEffectivenessInPercent'] ?? null),
            rightTorqueEffectivenessInPercent: $optionalFloat($values['rightTorqueEffectivenessInPercent'] ?? null),
            leftPedalSmoothnessInPercent: $optionalFloat($values['leftPedalSmoothnessInPercent'] ?? null),
            rightPedalSmoothnessInPercent: $optionalFloat($values['rightPedalSmoothnessInPercent'] ?? null),
            combinedPedalSmoothnessInPercent: $optionalFloat($values['combinedPedalSmoothnessInPercent'] ?? null),
            leftPowerPhaseInDegrees: $optionalFloat($values['leftPowerPhaseInDegrees'] ?? null),
            leftPowerPhasePeakInDegrees: $optionalFloat($values['leftPowerPhasePeakInDegrees'] ?? null),
            rightPowerPhaseInDegrees: $optionalFloat($values['rightPowerPhaseInDegrees'] ?? null),
            rightPowerPhasePeakInDegrees: $optionalFloat($values['rightPowerPhasePeakInDegrees'] ?? null),
        );
    }

    public static function fromJson(mixed $json): ?self
    {
        if (!is_string($json) || '' === $json) {
            return null;
        }

        $decoded = Json::decode($json);
        if (!is_array($decoded)) {
            return null;
        }

        $dynamics = self::fromArray($decoded);

        return $dynamics->isEmpty() ? null : $dynamics;
    }

    /**
     * Folds this set of aggregates into an older one, value by value. A newer
     * file that carries no per-side smoothness must not blank out what an older
     * file did record.
     */
    public function mergeWith(?self $older): self
    {
        if (!$older instanceof self) {
            return $this;
        }

        return new self(
            leftRightBalanceInPercent: $this->leftRightBalanceInPercent ?? $older->leftRightBalanceInPercent,
            aerobicTrainingEffect: $this->aerobicTrainingEffect ?? $older->aerobicTrainingEffect,
            anaerobicTrainingEffect: $this->anaerobicTrainingEffect ?? $older->anaerobicTrainingEffect,
            jumpCount: $this->jumpCount ?? $older->jumpCount,
            leftTorqueEffectivenessInPercent: $this->leftTorqueEffectivenessInPercent ?? $older->leftTorqueEffectivenessInPercent,
            rightTorqueEffectivenessInPercent: $this->rightTorqueEffectivenessInPercent ?? $older->rightTorqueEffectivenessInPercent,
            leftPedalSmoothnessInPercent: $this->leftPedalSmoothnessInPercent ?? $older->leftPedalSmoothnessInPercent,
            rightPedalSmoothnessInPercent: $this->rightPedalSmoothnessInPercent ?? $older->rightPedalSmoothnessInPercent,
            combinedPedalSmoothnessInPercent: $this->combinedPedalSmoothnessInPercent ?? $older->combinedPedalSmoothnessInPercent,
            leftPowerPhaseInDegrees: $this->leftPowerPhaseInDegrees ?? $older->leftPowerPhaseInDegrees,
            leftPowerPhasePeakInDegrees: $this->leftPowerPhasePeakInDegrees ?? $older->leftPowerPhasePeakInDegrees,
            rightPowerPhaseInDegrees: $this->rightPowerPhaseInDegrees ?? $older->rightPowerPhaseInDegrees,
            rightPowerPhasePeakInDegrees: $this->rightPowerPhasePeakInDegrees ?? $older->rightPowerPhasePeakInDegrees,
        );
    }

    public function getLeftRightBalanceInPercent(): ?float
    {
        return $this->leftRightBalanceInPercent;
    }

    public function getRightSideBalanceInPercent(): ?float
    {
        return null !== $this->leftRightBalanceInPercent ? 100 - $this->leftRightBalanceInPercent : null;
    }

    public function getAerobicTrainingEffect(): ?float
    {
        return $this->aerobicTrainingEffect;
    }

    public function getAnaerobicTrainingEffect(): ?float
    {
        return $this->anaerobicTrainingEffect;
    }

    public function getJumpCount(): ?int
    {
        return $this->jumpCount;
    }

    public function getLeftTorqueEffectivenessInPercent(): ?float
    {
        return $this->leftTorqueEffectivenessInPercent;
    }

    public function getRightTorqueEffectivenessInPercent(): ?float
    {
        return $this->rightTorqueEffectivenessInPercent;
    }

    public function getLeftPedalSmoothnessInPercent(): ?float
    {
        return $this->leftPedalSmoothnessInPercent;
    }

    public function getRightPedalSmoothnessInPercent(): ?float
    {
        return $this->rightPedalSmoothnessInPercent;
    }

    public function getCombinedPedalSmoothnessInPercent(): ?float
    {
        return $this->combinedPedalSmoothnessInPercent;
    }

    public function getLeftPowerPhaseInDegrees(): ?float
    {
        return $this->leftPowerPhaseInDegrees;
    }

    public function getLeftPowerPhasePeakInDegrees(): ?float
    {
        return $this->leftPowerPhasePeakInDegrees;
    }

    public function getRightPowerPhaseInDegrees(): ?float
    {
        return $this->rightPowerPhaseInDegrees;
    }

    public function getRightPowerPhasePeakInDegrees(): ?float
    {
        return $this->rightPowerPhasePeakInDegrees;
    }

    /**
     * True when there is enough left/right pedaling data to show the table.
     */
    public function hasPedalingDynamics(): bool
    {
        return null !== $this->leftTorqueEffectivenessInPercent
            || null !== $this->rightTorqueEffectivenessInPercent
            || null !== $this->leftPedalSmoothnessInPercent
            || null !== $this->rightPedalSmoothnessInPercent
            || null !== $this->combinedPedalSmoothnessInPercent
            || null !== $this->leftPowerPhaseInDegrees
            || null !== $this->leftPowerPhasePeakInDegrees
            || null !== $this->rightPowerPhaseInDegrees
            || null !== $this->rightPowerPhasePeakInDegrees;
    }

    public function isEmpty(): bool
    {
        return null === $this->leftRightBalanceInPercent
            && null === $this->aerobicTrainingEffect
            && null === $this->anaerobicTrainingEffect
            && null === $this->jumpCount
            && !$this->hasPedalingDynamics();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'leftRightBalanceInPercent' => $this->leftRightBalanceInPercent,
            'aerobicTrainingEffect' => $this->aerobicTrainingEffect,
            'anaerobicTrainingEffect' => $this->anaerobicTrainingEffect,
            'jumpCount' => $this->jumpCount,
            'leftTorqueEffectivenessInPercent' => $this->leftTorqueEffectivenessInPercent,
            'rightTorqueEffectivenessInPercent' => $this->rightTorqueEffectivenessInPercent,
            'leftPedalSmoothnessInPercent' => $this->leftPedalSmoothnessInPercent,
            'rightPedalSmoothnessInPercent' => $this->rightPedalSmoothnessInPercent,
            'combinedPedalSmoothnessInPercent' => $this->combinedPedalSmoothnessInPercent,
            'leftPowerPhaseInDegrees' => $this->leftPowerPhaseInDegrees,
            'leftPowerPhasePeakInDegrees' => $this->leftPowerPhasePeakInDegrees,
            'rightPowerPhaseInDegrees' => $this->rightPowerPhaseInDegrees,
            'rightPowerPhasePeakInDegrees' => $this->rightPowerPhasePeakInDegrees,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
