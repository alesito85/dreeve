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
    // Charted no more, it follows pedal smoothness almost exactly. The case
    // stays so combined streams built earlier still decode.
    case TORQUE_EFFECTIVENESS = 'torque_effectiveness';
    // Combined pedal smoothness, charted only when a file has no per side series.
    case PEDAL_SMOOTHNESS = 'pedal_smoothness';
    case LEFT_PEDAL_SMOOTHNESS = 'left_pedal_smoothness';
    case RIGHT_PEDAL_SMOOTHNESS = 'right_pedal_smoothness';

    public function isPedalingDynamics(): bool
    {
        return $this->getStreamType()->isPedalingDynamics();
    }

    /**
     * Balance is a share of a whole, so the interesting part is how far it
     * drifts from an even 50/50 rather than its distance from zero.
     */
    public function getDivergingMidValue(): ?int
    {
        return match ($this) {
            CombinedStreamType::LEFT_RIGHT_BALANCE => 50,
            default => null,
        };
    }

    /**
     * The series that share this one's lane, so both sides of a metric get
     * drawn on one axis instead of in two lookalike lanes.
     *
     * @return list<self>
     */
    public function getSeriesPartners(): array
    {
        return match ($this) {
            CombinedStreamType::LEFT_PEDAL_SMOOTHNESS => [CombinedStreamType::RIGHT_PEDAL_SMOOTHNESS],
            default => [],
        };
    }

    /**
     * The lane this series is drawn on when it shares one, which lets a file
     * that only records one side still get a chart of its own.
     */
    public function getSeriesLeader(): ?self
    {
        return match ($this) {
            CombinedStreamType::RIGHT_PEDAL_SMOOTHNESS => CombinedStreamType::LEFT_PEDAL_SMOOTHNESS,
            default => null,
        };
    }

    /**
     * The stream types that need a lane of their own. A series that shares
     * another one's axis only drops out when that other one gets drawn.
     *
     * @param array<self> $streamTypes
     *
     * @return array<self>
     */
    public static function chartLanesFor(array $streamTypes): array
    {
        return array_values(array_filter(
            $streamTypes,
            function (self $streamType) use ($streamTypes): bool {
                $leader = $streamType->getSeriesLeader();

                return is_null($leader) || !in_array($leader, $streamTypes, true);
            }
        ));
    }

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
            CombinedStreamType::PEDAL_SMOOTHNESS,
            CombinedStreamType::LEFT_PEDAL_SMOOTHNESS,
            CombinedStreamType::RIGHT_PEDAL_SMOOTHNESS => $translator->trans('Pedal smoothness'),
            default => throw new \RuntimeException(sprintf('Cannot translate CombinedStreamType "%s"', $this->value)),
        };
    }

    /**
     * Series that share a lane need something besides the unit to tell them
     * apart in the tooltip.
     */
    public function transSeriesLabel(TranslatorInterface $translator): ?string
    {
        return match ($this) {
            CombinedStreamType::LEFT_PEDAL_SMOOTHNESS => $translator->trans('Left'),
            CombinedStreamType::RIGHT_PEDAL_SMOOTHNESS => $translator->trans('Right'),
            default => null,
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
            CombinedStreamType::PEDAL_SMOOTHNESS,
            CombinedStreamType::LEFT_PEDAL_SMOOTHNESS,
            CombinedStreamType::RIGHT_PEDAL_SMOOTHNESS => '%',
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
            CombinedStreamType::PEDAL_SMOOTHNESS,
            CombinedStreamType::LEFT_PEDAL_SMOOTHNESS => '#9a60b4',
            CombinedStreamType::RIGHT_PEDAL_SMOOTHNESS => '#5470c6',
            default => '#cccccc',
        };
    }

    public function isChartable(): bool
    {
        return match ($this) {
            self::DISTANCE, self::LAT_LNG, self::TIME, self::GRADE, self::TEMP, self::TORQUE_EFFECTIVENESS => false,
            default => true,
        };
    }
}
