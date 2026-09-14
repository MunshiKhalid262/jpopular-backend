<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Models\BusinessSettings;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The single business settings row.
 *
 * These are not cosmetic: `state_code` decides CGST+SGST vs IGST on every GST
 * invoice, and `invoice_prefix` plus `financial_year_start_month` feed the
 * numbering sequence. A GST invoice cannot be finalized until the state code
 * is set, which is why this endpoint exists alongside invoicing.
 */
class BusinessSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->can(PermissionName::SettingsView->value) ?? false,
            403,
        );

        return ApiResponse::success($this->present(BusinessSettings::current()));
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->can(PermissionName::SettingsUpdate->value) ?? false,
            403,
        );

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:160'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'gstin' => ['sometimes', 'nullable', 'string', 'size:15'],
            'pan' => ['sometimes', 'nullable', 'string', 'size:10'],

            'address_line1' => ['sometimes', 'nullable', 'string', 'max:200'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state_code' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{2}$/'],
            'pincode' => ['sometimes', 'nullable', 'string', 'max:10'],

            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'website' => ['sometimes', 'nullable', 'string', 'max:160'],

            /*
             * Length is capped at 12 by the column, but the invoice number
             * format ({prefix}/{FY}/{00001}) must also fit GST's 16-character
             * limit. InvoiceNumberGenerator refuses an over-long prefix at
             * allocation time; catching it here gives a field error instead.
             */
            'invoice_prefix' => ['sometimes', 'string', 'max:4', 'regex:/^[A-Za-z0-9]+$/'],
            'invoice_terms' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'bank_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'bank_account_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'bank_account_number' => ['sometimes', 'nullable', 'string', 'max:34'],
            'bank_ifsc' => ['sometimes', 'nullable', 'string', 'max:11'],
            'bank_branch' => ['sometimes', 'nullable', 'string', 'max:120'],
            'upi_id' => ['sometimes', 'nullable', 'string', 'max:100'],

            'default_gst_rate' => ['sometimes', 'numeric', 'between:0,100'],
            'enable_round_off' => ['sometimes', 'boolean'],
            'financial_year_start_month' => ['sometimes', 'integer', 'between:1,12'],
        ], [
            'state_code.regex' => 'The state code must be the two-digit GST code, e.g. 32 for Kerala.',
            'invoice_prefix.regex' => 'The invoice prefix may contain letters and digits only.',
            'invoice_prefix.max' => 'Keep the prefix to 4 characters so invoice numbers stay within the GST 16-character limit.',
        ]);

        $settings = BusinessSettings::current();
        $settings->fill($validated)->save();

        // The model's saved hook clears the cache, so the next invoice sees
        // the new values rather than waiting for a deploy.
        return ApiResponse::success($this->present($settings->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(BusinessSettings $settings): array
    {
        return [
            'business_name' => $settings->business_name,
            'legal_name' => $settings->legal_name,
            'gstin' => $settings->gstin,
            'pan' => $settings->pan,

            'address_line1' => $settings->address_line1,
            'address_line2' => $settings->address_line2,
            'city' => $settings->city,
            'state' => $settings->state,
            'state_code' => $settings->state_code,
            'pincode' => $settings->pincode,

            'phone' => $settings->phone,
            'email' => $settings->email,
            'website' => $settings->website,

            'invoice_prefix' => $settings->invoice_prefix,
            'invoice_terms' => $settings->invoice_terms,

            'bank_name' => $settings->bank_name,
            'bank_account_name' => $settings->bank_account_name,
            'bank_account_number' => $settings->bank_account_number,
            'bank_ifsc' => $settings->bank_ifsc,
            'bank_branch' => $settings->bank_branch,
            'upi_id' => $settings->upi_id,

            'default_gst_rate' => $settings->default_gst_rate,
            'enable_round_off' => $settings->enable_round_off,
            'financial_year_start_month' => $settings->financial_year_start_month,

            // Drives the "you cannot raise a GST invoice yet" warning.
            'can_issue_gst_invoices' => $settings->canIssueGstInvoices(),
        ];
    }
}
