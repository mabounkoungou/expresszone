<?php

namespace Tests\Unit;

use App\Http\Controllers\SalesController;
use Tests\TestCase;

class SaleReferencePrefixTest extends TestCase
{
    public function test_sale_prefix_normalizes_warehouse_name_token(): void
    {
        $this->assertSame('MAIN-WAREHOUSE', SalesController::normalizeWarehouseReferenceName('Main Warehouse'));
        $this->assertSame('EZL-WAREHOUSE', SalesController::resolveSaleReferencePrefix('EZL-WAREHOUSENAME', null));
    }

    public function test_sale_reference_number_increments_with_hyphenated_prefix(): void
    {
        $code = SalesController::buildNextSaleReference('EZL-MAIN-WAREHOUSE', 'EZL-MAIN-WAREHOUSE-0012');

        $this->assertSame('EZL-MAIN-WAREHOUSE-0013', $code);
    }

    public function test_sale_prefix_keeps_existing_value_without_token(): void
    {
        $this->assertSame('SL', SalesController::resolveSaleReferencePrefix('SL', null));
    }
}
