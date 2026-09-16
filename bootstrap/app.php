<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ForceJsonResponse;
use App\Support\ApiResponse;
use App\Support\ErrorReference;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * REPORTING MUST NEVER BREAK THE RESPONSE.
         *
         * Laravel reports an exception before rendering it. If the log write
         * fails -- an unwritable file, a full disk -- that failure propagates
         * and replaces whatever the API was about to return with a bare 500
         * and an empty body. That is exactly how a perfectly handled
         * "insufficient stock" 409 once reached the browser as an unexplained
         * 500: storage/logs was root-owned and php-fpm runs as www-data.
         *
         * Logging is therefore wrapped here, and returning false stops
         * Laravel's own reporting from running a second, unguarded time.
         */
        $exceptions->report(function (Throwable $e): bool {
            $reference = ErrorReference::current();

            try {
                Log::error($e->getMessage(), [
                    'reference' => $reference,
                    'exception' => $e,
                ]);
            } catch (Throwable $loggingFailure) {
                /*
                 * The log is unavailable. error_log() goes to the php-fpm
                 * error log, which is a different file with different
                 * permissions, so there is still somewhere to look -- and the
                 * request carries on returning a proper response either way.
                 */
                error_log(sprintf(
                    '[jpopular %s] logging failed (%s) while reporting: %s in %s:%d',
                    $reference,
                    $loggingFailure->getMessage(),
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                ));
            }

            return false;
        });

        // Every API error leaves through the same envelope. See ARCHITECTURE-V1.md 9.1.
        $exceptions->render(function (BusinessRuleException $e, Request $request) {
            return $request->expectsJson() ? $e->render() : null;
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                message: 'The given data was invalid.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                errors: $e->errors(),
                code: 'VALIDATION_FAILED',
            );
        });

        $exceptions->render(function (AuthenticationException|RouteNotFoundException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            // RouteNotFoundException surfaces here when unauthenticated web
            // middleware tries to redirect to a non-existent `login` route.
            return ApiResponse::error(
                message: 'Unauthenticated.',
                status: Response::HTTP_UNAUTHORIZED,
                code: 'UNAUTHENTICATED',
            );
        });

        // NOTE: Laravel's handler runs prepareException() BEFORE the render
        // callbacks, so an AuthorizationException has already become an
        // AccessDeniedHttpException (and ModelNotFoundException a
        // NotFoundHttpException) by the time we see it. Matching on the HTTP
        // status below is therefore more reliable than matching on the class.
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                message: 'You do not have permission to perform this action.',
                status: Response::HTTP_FORBIDDEN,
                code: 'FORBIDDEN',
            );
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                message: 'Resource not found.',
                status: Response::HTTP_NOT_FOUND,
                code: 'NOT_FOUND',
            );
        });

        // Catch-all: never leak a stack trace or SQL to an API client.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                [$code, $fallbackMessage] = match ($status) {
                    401 => ['UNAUTHENTICATED', 'Unauthenticated.'],
                    403 => ['FORBIDDEN', 'You do not have permission to perform this action.'],
                    404 => ['NOT_FOUND', 'Resource not found.'],
                    405 => ['METHOD_NOT_ALLOWED', 'That method is not allowed on this endpoint.'],
                    429 => ['RATE_LIMITED', 'Too many requests. Please slow down.'],
                    default => [null, 'Request failed.'],
                };

                return ApiResponse::error(
                    message: $e->getMessage() !== '' ? $e->getMessage() : $fallbackMessage,
                    status: $status,
                    code: $code,
                );
            }

            if (config('app.debug')) {
                return null; // let Laravel's debug renderer help in local dev
            }

            /*
             * Reporting already happened in the guarded callback above, so
             * report() is deliberately NOT called again here.
             *
             * The reference is the same one written to the log, so an operator
             * can quote it and the exact stack trace is one grep away:
             *   grep A1B2C3D4 storage/logs/laravel.log
             */
            $reference = ErrorReference::current();

            /*
             * `expose_server_errors` adds the real exception to the response.
             * Off by default, because the message can carry SQL, filesystem
             * paths and internal class names that a public API should not
             * volunteer. Worth turning on for a small internal system whose
             * only users are its owners.
             *
             * The stack trace is never included even when this is on -- the
             * class, message and location identify the fault, and the trace
             * belongs in the log.
             */
            $extra = ['reference' => $reference];

            if (config('app.expose_server_errors')) {
                $extra['debug'] = [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => str_replace(base_path().'/', '', $e->getFile()),
                    'line' => $e->getLine(),
                ];
            }

            return ApiResponse::error(
                message: 'An unexpected error occurred.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
                code: 'SERVER_ERROR',
                extra: $extra,
            );
        });
    })->create();
