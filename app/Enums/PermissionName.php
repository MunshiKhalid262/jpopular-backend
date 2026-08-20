<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The single source of truth for every V1 permission.
 *
 * Mirrors the role/permission matrix in ARCHITECTURE-V1.md section 8. Code must
 * always authorize against these permissions, never against a role name.
 */
enum PermissionName: string
{
    // Dashboard
    case DashboardView = 'dashboard.view';

    // Catalog
    case CategoriesView = 'categories.view';
    case CategoriesManage = 'categories.manage';
    case BrandsView = 'brands.view';
    case BrandsManage = 'brands.manage';
    case ProductsView = 'products.view';
    case ProductsCreate = 'products.create';
    case ProductsUpdate = 'products.update';
    case ProductsDelete = 'products.delete';
    case ProductsViewPurchasePrice = 'products.view_purchase_price';

    // Inventory
    case InventoryView = 'inventory.view';
    case InventoryAdjust = 'inventory.adjust';
    case InventoryPurchase = 'inventory.purchase';

    // Customers
    case CustomersView = 'customers.view';
    case CustomersManage = 'customers.manage';
    case CustomersDelete = 'customers.delete';

    // Sales
    case InvoicesView = 'invoices.view';
    case InvoicesCreate = 'invoices.create';
    case InvoicesUpdate = 'invoices.update';
    case InvoicesFinalize = 'invoices.finalize';
    case InvoicesCancel = 'invoices.cancel';
    case InvoicesDelete = 'invoices.delete';
    case InvoicesPrint = 'invoices.print';

    // Payments
    case PaymentsView = 'payments.view';
    case PaymentsRecord = 'payments.record';
    case PaymentsVoid = 'payments.void';

    // Reports
    case ReportsSales = 'reports.sales';
    case ReportsProductWise = 'reports.product_wise';
    case ReportsStock = 'reports.stock';
    case ReportsGst = 'reports.gst';

    // Administration
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';
    case SettingsView = 'settings.view';
    case SettingsUpdate = 'settings.update';
    case AuditView = 'audit.view';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Permissions granted to the Admin role: every permission in V1.
     *
     * @return list<string>
     */
    public static function adminDefaults(): array
    {
        return self::values();
    }

    /**
     * Permissions granted to the Manager role BY DEFAULT.
     *
     * Deliberately excludes everything the matrix marks as optional/grantable
     * per manager (products.create, products.update,
     * products.view_purchase_price, inventory.purchase). Those are granted
     * individually to a specific manager, never inherited from the role.
     *
     * settings.view is included because a manager needs the business name and
     * GSTIN to raise an invoice; settings.update is not.
     *
     * @return list<string>
     */
    public static function managerDefaults(): array
    {
        return array_map(static fn (self $case): string => $case->value, [
            self::DashboardView,

            self::CategoriesView,
            self::BrandsView,
            self::ProductsView,

            self::InventoryView,

            self::CustomersView,
            self::CustomersManage,

            self::InvoicesView,
            self::InvoicesCreate,
            self::InvoicesUpdate,
            self::InvoicesFinalize,
            self::InvoicesPrint,

            self::PaymentsView,
            self::PaymentsRecord,

            self::ReportsSales,
            self::ReportsProductWise,
            self::ReportsStock,

            self::SettingsView,
        ]);
    }

    /**
     * Permissions a Manager may be granted individually but never receives
     * from the role itself. Matrix legend: "optional, grantable per manager".
     *
     * @return list<string>
     */
    public static function managerGrantable(): array
    {
        return array_map(static fn (self $case): string => $case->value, [
            self::ProductsCreate,
            self::ProductsUpdate,
            self::ProductsViewPurchasePrice,
            self::InventoryPurchase,
        ]);
    }
}
