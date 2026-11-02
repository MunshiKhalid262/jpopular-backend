<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(PermissionName::InvoicesView->value);
    }

    public function view(User $actor, Invoice $invoice): bool
    {
        return $actor->can(PermissionName::InvoicesView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(PermissionName::InvoicesCreate->value);
    }

    /** Drafts only; the Action refuses a finalized invoice regardless. */
    public function update(User $actor, Invoice $invoice): bool
    {
        return $actor->can(PermissionName::InvoicesUpdate->value) && $invoice->isDraft();
    }

    public function finalize(User $actor, Invoice $invoice): bool
    {
        return $actor->can(PermissionName::InvoicesFinalize->value);
    }

    public function cancel(User $actor, Invoice $invoice): bool
    {
        return $actor->can(PermissionName::InvoicesCancel->value);
    }

    /**
     * Any status, not just a draft.
     *
     * Deleting a finalized invoice leaves a gap in the numbered series, which
     * is why cancelling exists and is the safer route for a document that has
     * already gone out. The permission is the gate; the decision is the
     * operator's, and the confirmation says what it costs.
     */
    public function delete(User $actor, Invoice $invoice): bool
    {
        return $actor->can(PermissionName::InvoicesDelete->value);
    }

    /**
     * Gates the PDF, the preview and the print view together: all three render
     * the same document, so allowing one while refusing another would be a
     * distinction without a difference.
     *
     * A cancelled invoice stays printable -- it is a historical record, and
     * the document stamps its own status.
     */
    public function print(User $actor, Invoice $invoice): bool
    {
        return $actor->can(PermissionName::InvoicesPrint->value);
    }
}
