<?php

declare(strict_types=1);

namespace App\Tests\Domain\Import\FileParser\Fit;

use App\Domain\Activity\ActivityDynamics;
use App\Domain\Import\FileParser\Fit\FitPedalingDynamics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FitPedalingDynamicsTest extends TestCase
{
    public function testFromSessionDecodesEveryField(): void
    {
        $dynamics = FitPedalingDynamics::fromSession([
            'left_right_balance' => 4600,
            // fit-tool applies the scale of 10, so the effects arrive in points.
            'total_training_effect' => 2.6,
            'total_anaerobic_training_effect' => 0.1,
            'jump_count' => 12,
            'avg_left_torque_effectiveness' => 95,
            'avg_right_torque_effectiveness' => 93,
            'avg_left_pedal_smoothness' => 74,
            'avg_right_pedal_smoothness' => 72,
            'avg_combined_pedal_smoothness' => 73,
            'avg_left_power_phase' => 28.4,
            'avg_left_power_phase_peak' => 96.5,
            'avg_right_power_phase' => 30.2,
            'avg_right_power_phase_peak' => 98.1,
        ]);

        $this->assertInstanceOf(ActivityDynamics::class, $dynamics);
        $this->assertSame(46.0, $dynamics->getLeftRightBalanceInPercent());
        $this->assertSame(54.0, $dynamics->getRightSideBalanceInPercent());
        $this->assertSame(2.6, $dynamics->getAerobicTrainingEffect());
        $this->assertSame(0.1, $dynamics->getAnaerobicTrainingEffect());
        $this->assertSame(12, $dynamics->getJumpCount());
        $this->assertSame(95.0, $dynamics->getLeftTorqueEffectivenessInPercent());
        $this->assertSame(93.0, $dynamics->getRightTorqueEffectivenessInPercent());
        $this->assertSame(74.0, $dynamics->getLeftPedalSmoothnessInPercent());
        $this->assertSame(72.0, $dynamics->getRightPedalSmoothnessInPercent());
        $this->assertSame(73.0, $dynamics->getCombinedPedalSmoothnessInPercent());
        $this->assertSame(28.4, $dynamics->getLeftPowerPhaseInDegrees());
        $this->assertSame(96.5, $dynamics->getLeftPowerPhasePeakInDegrees());
        $this->assertSame(30.2, $dynamics->getRightPowerPhaseInDegrees());
        $this->assertSame(98.1, $dynamics->getRightPowerPhasePeakInDegrees());
        $this->assertTrue($dynamics->hasPedalingDynamics());
    }

    public function testFromSessionFlipsBalanceReferencedToTheRightSide(): void
    {
        // 0x8000 flags the balance as the right side's share: 4600 masked out of
        // 0x3FFF means the right leg did 46%, so the left did 54%.
        $dynamics = FitPedalingDynamics::fromSession([
            'left_right_balance' => 0x8000 | 4600,
        ]);

        $this->assertSame(54.0, $dynamics?->getLeftRightBalanceInPercent());
        $this->assertSame(46.0, $dynamics?->getRightSideBalanceInPercent());
    }

    public function testFromSessionSupportsAlreadyScaledBalances(): void
    {
        $dynamics = FitPedalingDynamics::fromSession([
            'left_right_balance' => 0x8000 | 46,
        ]);

        $this->assertSame(54.0, $dynamics?->getLeftRightBalanceInPercent());
    }

    public function testFromSessionUsesTheFirstSampleOfThePowerPhaseArrays(): void
    {
        $dynamics = FitPedalingDynamics::fromSession([
            'avg_left_power_phase' => [28.4, null],
            'avg_right_power_phase_peak' => [null, 98.1],
        ]);

        $this->assertSame(28.4, $dynamics?->getLeftPowerPhaseInDegrees());
        $this->assertNull($dynamics?->getRightPowerPhaseInDegrees());
        $this->assertSame(98.1, $dynamics?->getRightPowerPhasePeakInDegrees());
    }

    public function testFromSessionIgnoresSentinelsAndOutOfRangeValues(): void
    {
        $dynamics = FitPedalingDynamics::fromSession([
            'left_right_balance' => 0xFFFF,
            'total_training_effect' => 0,
            'total_anaerobic_training_effect' => 25.5,
            'jump_count' => 0,
            'avg_left_torque_effectiveness' => 255,
            'avg_right_torque_effectiveness' => 0,
            'avg_left_pedal_smoothness' => 127.5,
            'avg_right_pedal_smoothness' => 100.5,
            'avg_combined_pedal_smoothness' => 255,
            'avg_left_power_phase' => 181.3,
            'avg_right_power_phase_peak' => 0,
        ]);

        $this->assertNull($dynamics);
    }

    #[DataProvider(methodName: 'provideRecordBalanceData')]
    public function testRecordLeftBalanceInPercent(mixed $balance, ?float $expected): void
    {
        $this->assertEquals(
            $expected,
            FitPedalingDynamics::recordLeftBalanceInPercent(['left_right_balance' => $balance])
        );
    }

    public static function provideRecordBalanceData(): iterable
    {
        yield 'left referenced' => [52, 52.0];
        yield 'right referenced' => [0x80 | 48, 52.0];
        yield 'missing' => [null, null];
        yield 'not numeric' => ['twelve', null];
        yield 'zero' => [0, null];
        yield 'not recorded' => [0xFF, null];
        yield 'right flag without a percentage' => [0x80, null];
    }

    public function testRecordLeftBalanceIgnoresABalanceThatWasNotRecorded(): void
    {
        $this->assertNull(FitPedalingDynamics::recordLeftBalanceInPercent([]));
    }

    public function testRecordPedalSmoothnessKeepsTheSidesApart(): void
    {
        $this->assertEquals(
            74.0,
            FitPedalingDynamics::recordLeftPedalSmoothnessInPercent([
                'left_pedal_smoothness' => 74,
                'right_pedal_smoothness' => 72,
            ])
        );
        $this->assertEquals(
            72.0,
            FitPedalingDynamics::recordRightPedalSmoothnessInPercent([
                'left_pedal_smoothness' => 74,
                'right_pedal_smoothness' => 72,
            ])
        );
    }

    public function testRecordPedalSmoothnessKeepsCoastingAsAZero(): void
    {
        // A zero is coasting, which the gap filling must not overwrite with the
        // last value from before the stop pedalling.
        $this->assertEquals(
            0.0,
            FitPedalingDynamics::recordLeftPedalSmoothnessInPercent(['left_pedal_smoothness' => 0])
        );
        $this->assertNull(FitPedalingDynamics::recordLeftPedalSmoothnessInPercent([]));
        $this->assertNull(FitPedalingDynamics::recordLeftPedalSmoothnessInPercent(['left_pedal_smoothness' => 127.5]));
        $this->assertNull(FitPedalingDynamics::recordRightPedalSmoothnessInPercent([]));
    }

    public function testRecordCombinedPedalSmoothnessUsesTheCombinedField(): void
    {
        $this->assertEquals(
            90.0,
            FitPedalingDynamics::recordPedalSmoothnessInPercent([
                'left_pedal_smoothness' => 74,
                'right_pedal_smoothness' => 72,
                'combined_pedal_smoothness' => 90,
            ])
        );
        $this->assertNull(FitPedalingDynamics::recordPedalSmoothnessInPercent([
            'left_pedal_smoothness' => 74,
            'right_pedal_smoothness' => 72,
        ]));
    }
}
