<?php

declare(strict_types=1);

namespace App\Domain\Activity\Stream;

enum StreamType: string
{
    case TIME = 'time';
    case DISTANCE = 'distance';
    case LAT_LNG = 'latlng';
    case ALTITUDE = 'altitude';
    case VELOCITY = 'velocity_smooth';
    case HEART_RATE = 'heartrate';
    case CADENCE = 'cadence';
    case WATTS = 'watts';
    case TEMP = 'temp';
    case MOVING = 'moving';
    case GRADE = 'grade_smooth';
    case LEFT_RIGHT_BALANCE = 'left_right_balance';
    // Per record torque effectiveness is no longer written, it follows pedal
    // smoothness almost exactly and the session average carries the number.
    // The case stays so streams imported earlier still decode.
    case TORQUE_EFFECTIVENESS = 'torque_effectiveness';
    // Combined pedal smoothness, used when a file does not record both sides.
    case PEDAL_SMOOTHNESS = 'pedal_smoothness';
    case LEFT_PEDAL_SMOOTHNESS = 'left_pedal_smoothness';
    case RIGHT_PEDAL_SMOOTHNESS = 'right_pedal_smoothness';

    public function isPedalingDynamics(): bool
    {
        return in_array($this, [
            self::LEFT_RIGHT_BALANCE,
            self::TORQUE_EFFECTIVENESS,
            self::PEDAL_SMOOTHNESS,
            self::LEFT_PEDAL_SMOOTHNESS,
            self::RIGHT_PEDAL_SMOOTHNESS,
        ], true);
    }

    /**
     * @return StreamType[]
     */
    public static function thatSupportDistributionValues(): array
    {
        return [
            self::WATTS, self::HEART_RATE, self::VELOCITY, self::CADENCE,
        ];
    }

    /**
     * The pedaling dynamics streams only exist for files that carry them,
     * Strava does not know them and rejects requests that ask for them.
     *
     * @return StreamType[]
     */
    public static function thatCanBeRequestedFromStravaApi(): array
    {
        $requestable = [];
        foreach (self::cases() as $streamType) {
            if ($streamType->isPedalingDynamics()) {
                continue;
            }

            $requestable[] = $streamType;
        }

        return $requestable;
    }
}
