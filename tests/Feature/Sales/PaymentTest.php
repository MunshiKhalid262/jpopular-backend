<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Payments\Actions\RecordPayment;
use App\Domain\Payments\Actions\VoidPayment;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Sales\Actions\CancelInvoice;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ApiTestCase;
use Tests\Concerns\MakesInvoices;

class PaymentTest extends ApiTestCase
{
    use MakesInvoices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureBusiness();
    }

    // ------------------------------------------------------- recording

    #[Test]
    public function recording_a_payment_updates_the_invoice_balance(): void
    {
        $actor = $this->admin();
        $invoice = $this->finalizedInvoice(['quantity' => '2']); // 2 x 1000 + 18% = 2360

        $this->assertSame('0.00', $invoice->paid_amount);

        app(RecordPayment::class)->handle($invoice, [
            'amount' => '1000.00',
            'payment_method' => PaymentMethod::Cash,
        ], $actor);

        $invoice->refresh();

        $this->assertSame('1000.00', $invoice->paid_amount);
        $this->assertSame('1360.00', $invoice->due_amount);
        $this->assertSame(PaymentStatus::PartiallyPaid, $invoice->payment_status);
    }

    #[Test]
    public function paying_the_full_amount_marks_the_invoice_paid(): void
    {
        $actor = $this->admin();
        $invoice = $this->finalizedInvoice(['quantity' => '2']);

        app(RecordPayment::class)->handle($invoice, [
            'amount' => '2360.00',
            'payment_method' => PaymentMethod::Upi,
        ], $actor);

        $invoice->refresh();

        $this->assertSame('2360.00', $invoice->paid_amount);
        $this->assertSame('0.00', $invoice->due_amount);
        $this->assertSame(PaymentStatus::Paid, $invoice->payment_status);
    }

    #[Test]
    public function a_payment_may_not_exceed_the_outstanding_balance(): void
    {
        $actor = $this->admin();
        $invoice = $this->finalizedInvoice(['quantity' => '2']);

        $this->expectException(PaymentException::class);

        try {
            app(RecordPayment::class)->handle($invoice, [
                'amount' => '5000.00',
                'payment_method' => PaymentMethod::Cash,
            ], $actor);
        } finally {
            $this->assertSame('0.00', $invoice->fresh()->paid_amount);
            $this->assertDatabaseCount('payments', 0);
        }
    }

    #[Test]
    public function a_cancelled_invoice_cannot_take_a_payment(): void
    {
        $actor = $this->admin();
        $invoice = $this->finalizedInvoice();

        app(CancelInvoice::class)->handle($invoice, 'Returned', $actor);

        $this->expectException(PaymentException::class);

        app(RecordPayment::class)->handle($invoice->fresh(), [
            'amount' => '10.00',
            'payment_method' => PaymentMethod::Cash,
        ], $actor);
    }

    // ---------------------------------------------------------- voiding

    #[Test]
    public function voiding_a_payment_returns_the_balance_and_keeps_the_row(): void
    {
        $actor = $this->admin();
        $invoice = $this->finalizedInvoice(['quantity' => '2']);

        $payment = app(RecordPayment::class)->handle($invoice, [
            'amount' => '2360.00',
            'payment_method' => PaymentMethod::Cash,
        ], $actor);

        $this->assertSame(PaymentStatus::Paid, $invoice->fresh()->payment_status);

        app(VoidPayment::class)->handle($payment, 'Cheque bounced', $actor);

        $invoice->refresh();

        $this->assertSame('0.00', $invoice->paid_amount);
        $this->assertSame('2360.00', $invoice->due_amount);
        $this->assertSame(PaymentStatus::Unpaid, $invoice->payment_status);

        // The record survives: a receipt may already be in the customer's hands.
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'void_reason' => 'Cheque bounced',
        ]);
        $this->assertNotNull($payment->fresh()->voided_at);
    }

    #[Test]
    public function a_payment_cannot_be_voided_twice(): void
    {
        $actor = $this->admin();
        $invoice = $this->finalizedInvoice();

        $payment = app(RecordPayment::class)->handle($invoice, [
            'amount' => '100.00',
            'payment_method' => PaymentMethod::Cash,
        ], $actor);

        app(VoidPayment::class)->handle($payment, 'First', $actor);

        $this->expectException(PaymentException::class);

        app(VoidPayment::class)->handle($payment->fresh(), 'Second', $actor);
    }

    #[Test]
    public function a_voided_payment_frees_the_balance_for_a_new_one(): void
    {
        $actor = $this->admin();
        $invoice = $this->finalizedInvoice(['quantity' => '2']);

        $payment = app(RecordPayment::class)->handle($invoice, [
            'amount' => '2360.00',
            'payment_method' => PaymentMethod::Cash,
        ], $actor);

        app(VoidPayment::class)->handle($payment, 'Wrong method', $actor);

        // The full amount is payable again, which it would not be if the void
        // had left the cached balance untouched.
        app(RecordPayment::class)->handle($invoice->fresh(), [
            'amount' => '2360.00',
            'payment_method' => PaymentMethod::Upi,
        ], $actor);

        $this->assertSame('2360.00', $invoice->fresh()->paid_amount);
        $this->assertSame(2, Payment::count());
    }

    // -------------------------------------------------------------- API

    #[Test]
    public function an_admin_can_record_a_payment_over_http(): void
    {
        Sanctum::actingAs($this->admin());
        $invoice = $this->finalizedInvoice(['quantity' => '2']);

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => '500.00',
            'payment_method' => 'cash',
            'reference' => 'Counter',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.payment.amount', '500.00')
            ->assertJsonPath('data.payment.payment_method', 'cash')
            ->assertJsonPath('data.invoice.paid_amount', '500.00')
            ->assertJsonPath('data.invoice.due_amount', '1860.00')
            ->assertJsonPath('data.invoice.payment_status', 'partially_paid');
    }

    #[Test]
    public function overpayment_is_refused_with_a_conflict(): void
    {
        Sanctum::actingAs($this->admin());
        $invoice = $this->finalizedInvoice(['quantity' => '2']);

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => '99999.00',
            'payment_method' => 'cash',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PAYMENT_EXCEEDS_DUE');
    }

    #[Test]
    public function payment_input_is_validated(): void
    {
        Sanctum::actingAs($this->admin());
        $invoice = $this->finalizedInvoice();

        foreach (['0', '-5', '1.234', 'abc'] as $amount) {
            $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
                'amount' => $amount,
                'payment_method' => 'cash',
            ])->assertStatus(422)->assertJsonValidationErrors('amount');
        }

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => '10.00',
            'payment_method' => 'bitcoin',
        ])->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }

    #[Test]
    public function a_manager_can_record_but_not_void_a_payment(): void
    {
        // The matrix grants a manager payments.record but withholds
        // payments.void: reversing money already received is the owner's call.
        Sanctum::actingAs($this->manager());
        $invoice = $this->finalizedInvoice(['quantity' => '2']);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => '100.00',
            'payment_method' => 'cash',
        ])->assertStatus(201);

        $paymentId = $response->json('data.payment.id');

        $this->postJson("/api/v1/payments/{$paymentId}/void", ['reason' => 'Trying anyway'])
            ->assertStatus(403);
    }

    #[Test]
    public function a_user_without_payment_permissions_is_refused(): void
    {
        $invoice = $this->finalizedInvoice();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/payments')->assertStatus(403);
        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => '10.00',
            'payment_method' => 'cash',
        ])->assertStatus(403);
    }

    #[Test]
    public function payment_methods_come_from_the_enum(): void
    {
        Sanctum::actingAs($this->admin());

        $methods = collect($this->getJson('/api/v1/payments/methods')->assertOk()->json('data.payment_methods'))
            ->pluck('value')
            ->all();

        $this->assertSame(
            ['cash', 'upi', 'card', 'bank_transfer', 'cheque', 'other'],
            $methods,
        );
    }
}
