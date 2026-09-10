<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\BusinessSettings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The single-row guarantee for business_settings.
 *
 * This previously relied on `CHECK (id = 1)` applied only when the driver was
 * MySQL, so the test suite -- which runs on SQLite -- never executed it, and
 * the statement failed for the first time in production with MySQL error 3818
 * (a CHECK constraint cannot refer to an AUTO_INCREMENT column).
 *
 * The constraint is now a unique index on a constant column, which behaves the
 * same on both drivers. These tests therefore exercise the real guarantee
 * rather than skipping past a driver-specific branch.
 */
class BusinessSettingsSingletonTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_database_rejects_a_second_settings_row(): void
    {
        BusinessSettings::current();

        $this->expectException(QueryException::class);

        // Bypasses the model deliberately: the guarantee has to hold at the
        // database level, not merely in the accessor.
        DB::table('business_settings')->insert([
            'business_name' => 'Second',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function current_creates_exactly_one_row_with_the_fixed_id(): void
    {
        $settings = BusinessSettings::current();

        $this->assertSame(BusinessSettings::SINGLETON_ID, $settings->id);
        $this->assertSame(1, DB::table('business_settings')->count());
    }

    #[Test]
    public function current_is_idempotent(): void
    {
        $first = BusinessSettings::current();

        BusinessSettings::forgetCache();

        $second = BusinessSettings::current();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('business_settings')->count());
    }
}
