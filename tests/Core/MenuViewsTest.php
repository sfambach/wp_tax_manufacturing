<?php declare(strict_types=1);

namespace TaxmodManufacturing\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodManufacturing\Core\Structure;
use TaxmodManufacturing\WordPress\Admin\OrdersPage;
use TaxmodManufacturing\WordPress\Plugin;

/** The menu under Fertigung and the arrows of the process bar are one list (architecture 2026-11-14). */
final class MenuViewsTest extends TestCase
{
    public function testEveryStepOfTheProcessHasItsMenuPoint(): void
    {
        $views = array_keys(OrdersPage::VIEWS);

        self::assertSame('boxes', $views[0]);

        foreach (Structure::PROCESS as $step) {
            self::assertContains($step === 'order_cancelled' ? 'cancelled' : $step, $views);
        }
    }

    public function testSlugsAreUniqueAndTheFirstKeepsTheOldAddress(): void
    {
        self::assertSame(Plugin::ORDERS_PAGE, OrdersPage::VIEWS['boxes']);
        self::assertCount(count(OrdersPage::VIEWS), array_unique(OrdersPage::VIEWS));

        foreach (OrdersPage::VIEWS as $view => $slug) {
            self::assertStringStartsWith(Plugin::ORDERS_PAGE, $slug);
            self::assertContains($view, OrdersPage::TABS);
        }
    }
}
