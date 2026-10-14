<?php

declare(strict_types=1);

namespace App\Domain\Activity\Stream\CombinedStream;

use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\Stream\StreamType;
use App\Infrastructure\Measurement\UnitSystem;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum CombinedStreamType: string implements TranslatableInterface
{
    case ALTITUDE = 'altitude';
    case WATTS = 'watts';
    case CADENCE = 'cadence';
    case STEPS_PER_MINUTE = 'spm';
    case HEART_RATE = 'heartrate';
    case VELOCITY = 'velocity';
    case PACE = 'pace';
    case DISTANCE = 'distance';
    case LAT_LNG = 'latlng';
    case TIME = 'time';
    case GRADE = 'grade';
    case TEMP = 'temp';
    case LEFT_RIGHT_BALANCE = 'left_right_balance';
    case TORQUE_EFFECTIVENESS = 'torque_effectiveness';
    case PEDAL_SMOOTHNESS = 'pedal_smoothness';

    public function getStreamType(): StreamType
    {
        return match ($this) {
            CombinedStreamType::PACE, CombinedStreamType::VELOCITY => StreamType::VELOCITY,
            CombinedStreamType::STEPS_PER_MINUTE => StreamType::CADENCE,
            CombinedStreamType::GRADE => StreamType::GRADE,
            default => StreamType::from($this->value),
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            CombinedStreamType::DISTANCE => $translator->trans('Distance'),
            CombinedStreamType::ALTITUDE => $translator->trans('Elevation'),
            CombinedStreamType::HEART_RATE => $translator->trans('Heart rate'),
            CombinedStreamType::CADENCE,
            CombinedStreamType::STEPS_PER_MINUTE => $translator->trans('Cadence'),
            CombinedStreamType::WATTS => $translator->trans('Power'),
            CombinedStreamType::PACE => $translator->trans('Pace'),
            CombinedStreamType::VELOCITY => $translator->trans('Speed'),
            CombinedStreamType::TEMP => $translator->trans('Temperature'),
            CombinedStreamType::LEFT_RIGHT_BALANCE => $translator->trans('Left/Right balance'),
            CombinedStreamType::TORQUE_EFFECTIVENESS => $translator->trans('Torque effectiveness'),
            CombinedStreamType::PEDAL_SMOOTHNESS => $translator->trans('Pedal smoothness'),
            default => throw new \RuntimeException(sprintf('Cannot translate CombinedStreamType "%s"', $this->value)),
        };
    }

    public function getSuffix(UnitSystem $unitSystem, SportType $sportType): string
    {
        return match ($this) {
            CombinedStreamType::HEART_RATE => 'bpm',
            CombinedStreamType::CADENCE => 'rpm',
            CombinedStreamType::STEPS_PER_MINUTE => 'spm',
            CombinedStreamType::WATTS => 'watt',
            CombinedStreamType::PACE => $unitSystem->paceSymbol(),
            CombinedStreamType::ALTITUDE => $unitSystem->elevationSymbol(),
            CombinedStreamType::VELOCITY => $sportType->speedSymbol($unitSystem),
            CombinedStreamType::TEMP => $unitSystem->temperatureSymbol(),
            CombinedStreamType::LEFT_RIGHT_BALANCE,
            CombinedStreamType::TORQUE_EFFECTIVENESS,
            CombinedStreamType::PEDAL_SMOOTHNESS => '%',
            default => throw new \RuntimeException('Suffix not supported for '.$this->value),
        };
    }

    public function getSeriesColor(): string
    {
        return match ($this) {
            CombinedStreamType::ALTITUDE => '#a6a6a6',
            CombinedStreamType::HEART_RATE => '#ee6666',
            CombinedStreamType::CADENCE,
            CombinedStreamType::STEPS_PER_MINUTE => '#91cc75',
            CombinedStreamType::WATTS => '#73c0de',
            CombinedStreamType::PACE,
            CombinedStreamType::VELOCITY => '#fac858',
            CombinedStreamType::TEMP => '#fc8452',
            CombinedStreamType::LEFT_RIGHT_BALANCE => '#3ba272',
            CombinedStreamType::TORQUE_EFFECTIVENESS => '#5470c6',
            CombinedStreamType::PEDAL_SMOOTHNESS => '#9a60b4',
            default => '#cccccc',
        };
    }

    public function isChartable(): bool
    {
        return match ($this) {
            self::DISTANCE, self::LAT_LNG, self::TIME, self::GRADE, self::TEMP => false,
            default => true,
        };
    }
}
