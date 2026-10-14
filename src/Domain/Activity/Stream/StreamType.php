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
    case TORQUE_EFFECTIVENESS = 'torque_effectiveness';
    case PEDAL_SMOOTHNESS = 'pedal_smoothness';

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
        $cannotBeRequested = [
            self::LEFT_RIGHT_BALANCE,
            self::TORQUE_EFFECTIVENESS,
            self::PEDAL_SMOOTHNESS,
        ];

        $requestable = [];
        foreach (self::cases() as $streamType) {
            if (in_array($streamType, $cannotBeRequested, true)) {
                continue;
            }

            $requestable[] = $streamType;
        }

        return $requestable;
    }
}
