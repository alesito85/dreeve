<?php

declare(strict_types=1);

namespace App\Domain\Import\FileParser\Fit;

use App\Domain\Activity\ActivityDynamics;

/**
 * Decodes the cycling dynamics FIT fields into the domain.
 *
 * fit-tool applies each field's scale and offset, which means the balance
 * fields arrive in their raw bit-field form: a record's left_right_balance is
 * a uint8 holding the percentage in 0x7F and a "this is the right side" flag
 * in 0x80, while a session or lap holds the percentage times 100 in 0x3FFF
 * with the same flag in 0x8000. Reading them as plain numbers silently yields
 * a balance of 176 or 327 percent, so the flag has to be taken apart here.
 */
final readonly class FitPedalingDynamics
{
    private const int RECORD_BALANCE_MASK = 0x7F;
    private const int RECORD_BALANCE_RIGHT_FLAG = 0x80;
    private const int SESSION_BALANCE_MASK = 0x3FFF;
    private const int SESSION_BALANCE_RIGHT_FLAG = 0x8000;

    /**
     * @param array<string, mixed> $session
     */
    public static function fromSession(array $session): ?ActivityDynamics
    {
        $dynamics = ActivityDynamics::fromArray([
            'leftRightBalanceInPercent' => self::sessionLeftBalanceInPercent($session['left_right_balance'] ?? null),
            'aerobicTrainingEffect' => self::trainingEffect($session['total_training_effect'] ?? null),
            'anaerobicTrainingEffect' => self::trainingEffect($session['total_anaerobic_training_effect'] ?? null),
            'jumpCount' => is_numeric($session['jump_count'] ?? null) && (float) $session['jump_count'] > 0 ? (int) round((float) $session['jump_count']) : null,
            'leftTorqueEffectivenessInPercent' => self::percent($session['avg_left_torque_effectiveness'] ?? null),
            'rightTorqueEffectivenessInPercent' => self::percent($session['avg_right_torque_effectiveness'] ?? null),
            'leftPedalSmoothnessInPercent' => self::percent($session['avg_left_pedal_smoothness'] ?? null),
            'rightPedalSmoothnessInPercent' => self::percent($session['avg_right_pedal_smoothness'] ?? null),
            'combinedPedalSmoothnessInPercent' => self::percent($session['avg_combined_pedal_smoothness'] ?? null),
            'leftPowerPhaseInDegrees' => self::degrees($session['avg_left_power_phase'] ?? null),
            'leftPowerPhasePeakInDegrees' => self::degrees($session['avg_left_power_phase_peak'] ?? null),
            'rightPowerPhaseInDegrees' => self::degrees($session['avg_right_power_phase'] ?? null),
            'rightPowerPhasePeakInDegrees' => self::degrees($session['avg_right_power_phase_peak'] ?? null),
        ]);

        return $dynamics->isEmpty() ? null : $dynamics;
    }

    /**
     * The left side's share of the total torque for a single record.
     *
     * @param array<string, mixed> $record
     */
    public static function recordLeftBalanceInPercent(array $record): ?float
    {
        $balance = $record['left_right_balance'] ?? null;
        if (!is_numeric($balance)) {
            return null;
        }

        $raw = (int) round((float) $balance);
        if ($raw <= 0 || $raw >= 0xFF) {
            return null;
        }

        $percent = $raw & self::RECORD_BALANCE_MASK;
        if (0 === $percent) {
            return null;
        }

        return 0 !== ($raw & self::RECORD_BALANCE_RIGHT_FLAG)
            ? round(100 - $percent, 1)
            : round($percent, 1);
    }

    /**
     * @param array<string, mixed> $record
     */
    public static function recordTorqueEffectivenessInPercent(array $record): ?float
    {
        return self::averageOfSides(
            self::percent($record['left_torque_effectiveness'] ?? null),
            self::percent($record['right_torque_effectiveness'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $record
     */
    public static function recordPedalSmoothnessInPercent(array $record): ?float
    {
        if (null !== $combined = self::percent($record['combined_pedal_smoothness'] ?? null)) {
            return $combined;
        }

        return self::averageOfSides(
            self::percent($record['left_pedal_smoothness'] ?? null),
            self::percent($record['right_pedal_smoothness'] ?? null),
        );
    }

    private static function sessionLeftBalanceInPercent(mixed $balance): ?float
    {
        if (!is_numeric($balance)) {
            return null;
        }

        $raw = (int) round((float) $balance);
        $isRightSide = 0 !== ($raw & self::SESSION_BALANCE_RIGHT_FLAG);
        $masked = $raw & self::SESSION_BALANCE_MASK;
        if (0 === $masked) {
            return null;
        }

        // The field is unscaled, so the low bits hold the percentage times 100.
        // Anything at or below 100 already is a percentage, which keeps the
        // decode correct should the scale ever get applied upstream.
        $percent = $masked > 100 ? $masked / 100 : (float) $masked;
        if ($percent >= 100) {
            return null;
        }

        return $isRightSide ? round(100 - $percent, 1) : round($percent, 1);
    }

    /**
     * Averages both sides, or returns the only side that was recorded.
     */
    private static function averageOfSides(?float $left, ?float $right): ?float
    {
        $values = array_filter([$left, $right], static fn (?float $value): bool => null !== $value);
        if ([] === $values) {
            return null;
        }

        return round(array_sum($values) / count($values), 1);
    }

    /**
     * Percentages are stored as uint8 divided by two, so the sentinel 127.5
     * has to go as well as anything out of range. Power phase fields are
     * declared as arrays and can come through as a list of samples.
     */
    private static function percent(mixed $value): ?float
    {
        $value = self::first($value);
        if (!is_numeric($value)) {
            return null;
        }

        $percent = (float) $value;
        if ($percent <= 0 || $percent > 100 || 127.5 === $percent) {
            return null;
        }

        return round($percent, 1);
    }

    private static function degrees(mixed $value): ?float
    {
        $value = self::first($value);
        if (!is_numeric($value)) {
            return null;
        }

        $degrees = (float) $value;
        if ($degrees <= 0 || $degrees > 180) {
            return null;
        }

        return round($degrees, 1);
    }

    /**
     * Training effect is stored as a uint8 with a scale of 10, so fit-tool
     * already hands it over in effect points. Anything above the scale's
     * usable range is a sentinel.
     */
    private static function trainingEffect(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $effect = (float) $value;
        if ($effect <= 0 || $effect > 10) {
            return null;
        }

        return round($effect, 1);
    }

    /**
     * Array fields are decoded as lists; the first usable sample is the one
     * every exporter fills for single value summaries.
     */
    private static function first(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $item) {
            if (is_numeric($item)) {
                return $item;
            }
        }

        return null;
    }
}
