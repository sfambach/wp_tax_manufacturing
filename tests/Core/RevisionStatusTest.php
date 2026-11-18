<?php declare(strict_types=1);

namespace TaxmodManufacturing\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodManufacturing\Core\Assembly;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\RevisionStatus;

/** D-1463: one test per row of the rule — the revision's status from its first order that is not cancelled. */
final class RevisionStatusTest extends TestCase
{
    private const REVISION = 305;

    private function order(int $id, string $step, bool $failed = false, int $revision = self::REVISION): BuildOrder
    {
        return new BuildOrder($id, 'A', 100, 1, $step, null, null, null, [new Assembly($id + 1, $revision, [], [], 0, 1, '', $failed)]);
    }

    public function testWithoutAnOrderItIsNew(): void
    {
        self::assertSame(RevisionStatus::NEW, RevisionStatus::of(self::REVISION, [$this->order(10, 'order_done', false, 999)]));
    }

    public function testCancelledOrdersDoNotCount(): void
    {
        self::assertSame(RevisionStatus::NEW, RevisionStatus::of(self::REVISION, [$this->order(10, 'order_cancelled')]));
        self::assertSame('order_staging', RevisionStatus::of(self::REVISION, [$this->order(10, 'order_cancelled'), $this->order(20, 'order_staging')]));
    }

    public function testWhileTheFirstOrderRunsItShowsThatOrdersStatus(): void
    {
        self::assertSame('order_building', RevisionStatus::of(self::REVISION, [$this->order(10, 'order_building')]));
        self::assertSame('order_test', RevisionStatus::of(self::REVISION, [$this->order(10, 'order_test')]));
    }

    public function testTheFirstIsTheOneCreatedFirstHoweverManyFollow(): void
    {
        self::assertSame('order_planned', RevisionStatus::of(self::REVISION, [$this->order(30, 'order_done'), $this->order(10, 'order_planned'), $this->order(20, 'order_done', true)]));
    }

    public function testFirstOrderDoneIsConfirmed(): void
    {
        self::assertSame(RevisionStatus::CONFIRMED, RevisionStatus::of(self::REVISION, [$this->order(10, 'order_done'), $this->order(20, 'order_done', true)]));
    }

    public function testFirstOrderFailedIsNotConfirmed(): void
    {
        self::assertSame(RevisionStatus::REJECTED, RevisionStatus::of(self::REVISION, [$this->order(10, 'order_failed', true), $this->order(20, 'order_done')]));
    }

    public function testAFailedAssemblyInADoneOrderIsNotConfirmed(): void
    {
        $order = new BuildOrder(10, 'A', 100, 1, 'order_done', null, null, null, [new Assembly(11, self::REVISION, [], [], 0, 1, '', true), new Assembly(12, 306, [], [], 0, 1)]);

        self::assertSame([self::REVISION => RevisionStatus::REJECTED, 306 => RevisionStatus::CONFIRMED], RevisionStatus::ofEach([self::REVISION, 306], [$order]));
        self::assertFalse($order->hasFailed(), 'one of several failed leaves the order «Fertig» (INFERRED)');
    }
}
