<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity;

use App\Domain\Activity\ActivityDynamics;
use PHPUnit\Framework\TestCase;

class ActivityDynamicsTest extends TestCase
{
    public function testMergeWithLetsTheNewerAggregateWinFieldByField(): void
    {
        $merged = new ActivityDynamics(
            leftRightBalanceInPercent: 45,
            aerobicTrainingEffect: 4.1,
            jumpCount: 12,
        )->mergeWith(new ActivityDynamics(
            leftRightBalanceInPercent: 48,
            aerobicTrainingEffect: 2.2,
            anaerobicTrainingEffect: 1.1,
            jumpCount: 9,
        ));

        $this->assertSame(45.0, $merged->getLeftRightBalanceInPercent());
        $this->assertSame(4.1, $merged->getAerobicTrainingEffect());
        $this->assertSame(12, $merged->getJumpCount());
        // A newer file that stopped reporting the number does not delete it.
        $this->assertSame(1.1, $merged->getAnaerobicTrainingEffect());
    }

    public function testMergeWithKeepsPerSideNumbersTheNewFileDidNotRecord(): void
    {
        // The sample file in FINDINGS.md is exactly this case: the session
        // summary has no per-side smoothness while the records do, so an import
        // of a second file must not blank out what the first one measured.
        $merged = new ActivityDynamics(leftRightBalanceInPercent: 45)
            ->mergeWith(new ActivityDynamics(
                leftTorqueEffectivenessInPercent: 18,
                rightTorqueEffectivenessInPercent: 16,
                leftPedalSmoothnessInPercent: 30,
                rightPedalSmoothnessInPercent: 26,
                leftPowerPhaseInDegrees: 105,
                rightPowerPhasePeakInDegrees: 15,
            ));

        $this->assertSame(45.0, $merged->getLeftRightBalanceInPercent());
        $this->assertSame(18.0, $merged->getLeftTorqueEffectivenessInPercent());
        $this->assertSame(16.0, $merged->getRightTorqueEffectivenessInPercent());
        $this->assertSame(30.0, $merged->getLeftPedalSmoothnessInPercent());
        $this->assertSame(26.0, $merged->getRightPedalSmoothnessInPercent());
        $this->assertSame(105.0, $merged->getLeftPowerPhaseInDegrees());
        $this->assertSame(15.0, $merged->getRightPowerPhasePeakInDegrees());
        $this->assertNull($merged->getCombinedPedalSmoothnessInPercent());
    }

    public function testMergeWithAnActivityThatHasNoDynamicsYet(): void
    {
        $dynamics = new ActivityDynamics(combinedPedalSmoothnessInPercent: 28);

        $this->assertSame($dynamics, $dynamics->mergeWith(null));
    }
}
