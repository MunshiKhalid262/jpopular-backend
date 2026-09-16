<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Support\ErrorReference;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * How the API answers when something goes wrong.
 *
 * These guard a failure that actually reached production: an invoice finalize
 * correctly raised "insufficient stock", which should have been a 409 with a
 * machine code -- but storage/logs was root-owned while php-fpm runs as
 * www-data, so REPORTING the exception threw, and that replaced the 409 with a
 * bare 500 and an empty body. The operator saw "The request failed" and the
 * network tab showed nothing at all.
 */
class ErrorResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // These tests are about the production renderer, not the debug page.
        config()->set('app.debug', false);

        ErrorReference::forget();

        Route::middleware('api')->get('/_test/boom', function (): void {
            throw new RuntimeException('Something went badly wrong in the till');
        });
    }

    #[Test]
    public function a_server_error_returns_the_json_envelope_with_a_reference(): void
    {
        $response = $this->getJson('/_test/boom');

        $response->assertStatus(500);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('code', 'SERVER_ERROR');

        // The reference ties the response to its log line.
        $reference = $response->json('reference');
        $this->assertIsString($reference);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{8}$/', $reference);
    }

    #[Test]
    public function the_exception_detail_is_withheld_by_default(): void
    {
        config()->set('app.expose_server_errors', false);

        $response = $this->getJson('/_test/boom')->assertStatus(500);

        // The message could carry SQL or filesystem paths, so it is not
        // volunteered unless the operator asks for it.
        $this->assertNull($response->json('debug'));
        $this->assertStringNotContainsString('badly wrong', $response->getContent());
    }

    #[Test]
    public function the_exception_detail_is_returned_when_enabled(): void
    {
        config()->set('app.expose_server_errors', true);

        $response = $this->getJson('/_test/boom')->assertStatus(500);

        $response->assertJsonPath('debug.exception', RuntimeException::class);
        $response->assertJsonPath('debug.message', 'Something went badly wrong in the till');
        $this->assertIsInt($response->json('debug.line'));

        // The file is relative: an absolute server path is not the client's
        // business even when detail is switched on.
        $this->assertStringNotContainsString(base_path(), $response->getContent());

        // The trace stays in the log, never in the response.
        $this->assertNull($response->json('debug.trace'));
    }

    #[Test]
    public function a_broken_logger_cannot_turn_a_handled_error_into_a_blank_500(): void
    {
        /*
         * THE PRODUCTION BUG, reproduced.
         *
         * Reporting is wrapped, so a log write that throws is swallowed and
         * the request still returns the response it was going to return.
         */
        Log::shouldReceive('error')
            ->andThrow(new RuntimeException('Permission denied: storage/logs/laravel.log'));

        $response = $this->getJson('/_test/boom');

        // Still the proper envelope, not an empty body.
        $response->assertStatus(500);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('code', 'SERVER_ERROR');
        $this->assertNotEmpty($response->getContent());
    }

    #[Test]
    public function a_business_rule_error_survives_a_broken_logger_as_its_own_status(): void
    {
        /*
         * The actual regression: "insufficient stock" is a 409 the UI knows how
         * to explain. A failing logger must not downgrade it to a 500.
         */
        Log::shouldReceive('error')->andThrow(new RuntimeException('log is unwritable'));

        Route::middleware('api')->get('/_test/conflict', function (): void {
            throw new BusinessRuleException('Not enough stock.', 'INSUFFICIENT_STOCK');
        });

        $this->getJson('/_test/conflict')
            ->assertStatus(409)
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('message', 'Not enough stock.');
    }

    #[Test]
    public function validation_and_not_found_responses_are_unchanged(): void
    {
        // The new reference applies to server errors only; everything else
        // keeps the envelope it already had.
        $response = $this->getJson('/api/v1/invoices/999999');

        $response->assertStatus(401);
        $this->assertNull($response->json('reference'));
    }
}
