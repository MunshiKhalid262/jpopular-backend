<?php

declare(strict_types=1);

namespace Tests\Feature\Seeding;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\Development\DemoCatalogSeeder;
use Database\Seeders\Development\DemoUserSeeder;
use Database\Seeders\DevelopmentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The separation between development demo data and production seeding is a
 * safety property, so it is tested rather than trusted to discipline.
 */
class SeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------ development seeding

    #[Test]
    public function the_development_seeder_creates_the_demo_admin_and_manager(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DevelopmentSeeder::class);

        $admin = User::where('email', 'admin@jpopular.in')->firstOrFail();
        $manager = User::where('email', 'manager@jpopular.in')->firstOrFail();

        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($manager->hasRole('manager'));
        $this->assertTrue($admin->is_active);
        $this->assertTrue($manager->is_active);

        // The documented demo passwords work, and are stored hashed.
        $this->assertTrue(Hash::check('Admin@1234', $admin->password));
        $this->assertTrue(Hash::check('Manager@1234', $manager->password));
        $this->assertStringStartsNotWith('Admin@1234', $admin->password);
    }

    #[Test]
    public function the_development_seeder_creates_the_demo_catalog(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DevelopmentSeeder::class);

        $this->assertSame(3, Category::count());
        $this->assertSame(3, Brand::count());
        $this->assertGreaterThanOrEqual(6, Product::count());

        foreach (DemoCatalogSeeder::demoSkus() as $sku) {
            $this->assertDatabaseHas('products', ['sku' => $sku]);
        }

        // Demo data must be obviously fake.
        foreach (Product::all() as $product) {
            $this->assertStringContainsStringIgnoringCase('demo', $product->name);
        }
    }

    #[Test]
    public function demo_products_start_with_zero_stock(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DevelopmentSeeder::class);

        // Stock belongs to the Inventory ledger, not the catalog seeder.
        $this->assertSame(0, Product::where('current_stock', '>', 0)->count());
    }

    #[Test]
    public function demo_products_use_a_range_of_gst_rates(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DevelopmentSeeder::class);

        $rates = Product::query()->distinct()->pluck('gst_rate');

        // More than one rate, so per-product GST is genuinely exercised.
        $this->assertGreaterThan(1, $rates->count());
    }

    #[Test]
    public function running_the_development_seeder_repeatedly_does_not_duplicate_anything(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DevelopmentSeeder::class);

        $counts = [
            'users' => User::count(),
            'categories' => Category::count(),
            'brands' => Brand::count(),
            'products' => Product::count(),
            'permissions' => Permission::count(),
        ];

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DevelopmentSeeder::class);
        $this->seed(DevelopmentSeeder::class);

        $this->assertSame($counts['users'], User::count());
        $this->assertSame($counts['categories'], Category::count());
        $this->assertSame($counts['brands'], Brand::count());
        $this->assertSame($counts['products'], Product::count());
        $this->assertSame($counts['permissions'], Permission::count());
    }

    // ------------------------------------------------- production seeding

    #[Test]
    public function the_default_seed_path_creates_no_users_or_demo_data_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->assertTrue($this->app->environment('production'));

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        // Roles and permissions ARE required in production.
        $this->assertTrue(Role::query()->where('name', 'admin')->exists());
        $this->assertTrue(Role::query()->where('name', 'manager')->exists());
        $this->assertSame(37, Permission::count());

        // Nothing else. No users, no catalog.
        $this->assertSame(0, User::withTrashed()->count());
        $this->assertSame(0, Category::withTrashed()->count());
        $this->assertSame(0, Brand::withTrashed()->count());
        $this->assertSame(0, Product::withTrashed()->count());
    }

    #[Test]
    public function the_demo_credentials_never_exist_after_a_production_seed(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        foreach (DemoUserSeeder::demoUsers() as $demo) {
            $this->assertDatabaseMissing('users', ['email' => $demo['email']]);
        }
    }

    #[Test]
    public function the_development_seeder_refuses_to_run_in_production_even_if_invoked_directly(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        // The structural guard: forgetting is not enough to cause damage.
        // Invoked directly rather than via $this->seed(), because `db:seed`
        // would first raise Laravel's own production confirmation prompt and we
        // want to assert OUR guard fires.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/refused to run/i');

        (new DevelopmentSeeder)->setContainer($this->app)->run();
    }

    #[Test]
    public function the_demo_user_seeder_refuses_to_run_in_production_on_its_own(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/refused to run/i');

        (new DemoUserSeeder)->setContainer($this->app)->run();
    }

    #[Test]
    public function the_demo_catalog_seeder_refuses_to_run_in_production_on_its_own(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/refused to run/i');

        (new DemoCatalogSeeder)->setContainer($this->app)->run();
    }

    #[Test]
    public function the_seeded_permission_matrix_matches_the_enum(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = Role::findByName('admin', 'web');
        $manager = Role::findByName('manager', 'web');

        $this->assertSame(37, $admin->permissions()->count());
        $this->assertSame(18, $manager->permissions()->count());

        // Catalog specifics relied on by this slice.
        $this->assertTrue($manager->hasPermissionTo('products.view'));
        $this->assertTrue($manager->hasPermissionTo('categories.view'));
        $this->assertFalse($manager->hasPermissionTo('categories.manage'));
        $this->assertFalse($manager->hasPermissionTo('products.create'));
        $this->assertFalse($manager->hasPermissionTo('products.view_purchase_price'));
    }
}
