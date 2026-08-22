<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Development\DemoCatalogSeeder;
use Database\Seeders\Development\DemoUserSeeder;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Entry point for ALL development/demo data.
 *
 * Two independent guards, because "remember not to run this in production" is
 * not a safety mechanism:
 *
 *   1. DatabaseSeeder only calls this class in a safe environment;
 *   2. this class refuses to run in any other environment even if invoked
 *      directly (`db:seed --class=DevelopmentSeeder`).
 *
 * Everything reachable from here creates obviously fake data with weak,
 * publicly known demo passwords. It must never touch a production database.
 */
class DevelopmentSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    public const SAFE_ENVIRONMENTS = ['local', 'testing', 'development'];

    public static function isSafeEnvironment(): bool
    {
        return app()->environment(self::SAFE_ENVIRONMENTS);
    }

    public function run(): void
    {
        if (! self::isSafeEnvironment()) {
            throw new RuntimeException(sprintf(
                'DevelopmentSeeder refused to run: environment is [%s], expected one of [%s]. '
                .'Demo data must never be seeded outside development.',
                app()->environment(),
                implode(', ', self::SAFE_ENVIRONMENTS),
            ));
        }

        $this->call([
            DemoUserSeeder::class,
            DemoCatalogSeeder::class,
        ]);
    }
}
