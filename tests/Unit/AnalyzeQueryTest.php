<?php

namespace Tests\Unit;

use App\Queries\Analyze\AnalyzeQuery;
use Carbon\Carbon;
use Tests\TestCase;

class AnalyzeQueryTest extends TestCase
{
    public function test_real_partials_allow_completed_and_partial_status_only(): void
    {
        $query = new AnalyzeQuery();
        $from = Carbon::parse('2026-04-01');
        $to = Carbon::parse('2026-04-30');

        $partialSql = $query->base($from, $to, 'real', 'parcial_status')->toSql();
        $completedSql = $query->base($from, $to, 'real', 'completed')->toSql();
        $allSql = $query->base($from, $to, 'real', null)->toSql();
        $pendingSql = $query->base($from, $to, 'real', 'pending')->toSql();

        $this->assertStringContainsString('purchase_orders`.`status` = ?', $partialSql);
        $this->assertStringContainsString('purchase_orders`.`status` = ?', $completedSql);
        $this->assertStringContainsString('purchase_orders`.`status` in (?, ?)', $allSql);
        $this->assertStringContainsString('1 = 0', $pendingSql);
    }

    public function test_temporal_orders_allow_pending_and_processing_only(): void
    {
        $query = new AnalyzeQuery();
        $from = Carbon::parse('2026-04-01');
        $to = Carbon::parse('2026-04-30');

        $pendingSql = $query->base($from, $to, 'temporal', 'pending')->toSql();
        $processingSql = $query->base($from, $to, 'temporal', 'processing')->toSql();
        $allSql = $query->base($from, $to, 'temporal', null)->toSql();
        $partialSql = $query->base($from, $to, 'temporal', 'parcial_status')->toSql();

        $this->assertStringContainsString('from `purchase_orders`', $pendingSql);
        $this->assertStringContainsString('purchase_orders`.`order_creation_date` between', $pendingSql);
        $this->assertStringNotContainsString('from `partials`', $pendingSql);
        $this->assertStringContainsString('purchase_orders`.`status` = ?', $pendingSql);
        $this->assertStringContainsString('purchase_orders`.`status` = ?', $processingSql);
        $this->assertStringContainsString('purchase_orders`.`status` in (?, ?)', $allSql);
        $this->assertStringContainsString('1 = 0', $partialSql);
    }

    public function test_temporal_client_partials_date_uses_operational_dispatch_date(): void
    {
        $query = new AnalyzeQuery();

        $sql = (function () {
            return $this->temporalDispatchDateExpression();
        })->call($query);

        $this->assertStringContainsString('MIN(pt.dispatch_date)', $sql);
        $this->assertStringContainsString('pt.type = \'temporal\'', $sql);
        $this->assertStringContainsString('purchase_order_product.delivery_date', $sql);
        $this->assertStringNotContainsString('purchase_orders.order_creation_date', $sql);
    }
}
