<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

/**
 * Same shape as creating.
 *
 * The Policy allows this only for a draft, and ManageInvoice refuses a
 * finalized invoice regardless -- a finalized invoice is a legal document with
 * a number allocated and stock already deducted.
 */
class UpdateInvoiceRequest extends StoreInvoiceRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('invoice')) ?? false;
    }
}
