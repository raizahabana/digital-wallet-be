<?php

use App\Exceptions\ProblemException;
use App\Support\Problem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Unauthenticated API calls must not try to redirect to a "login" route.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->dontReport(ProblemException::class);

        $exceptions->render(fn (ProblemException $e) =>
            Problem::response($e->status(), $e->title(), $e->getMessage()));

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) return null;

            return Problem::response(
                422,
                'Validation failed',
                (string) collect($e->errors())->flatten()->first(),
                ['errors' => $e->errors()],
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) return null;

            return Problem::response(401, 'Unauthenticated', 'Authentication is required to access this resource.');
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (! $request->is('api/*')) return null;

            return Problem::response(403, 'Forbidden', 'You are not allowed to perform this action.');
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (! $request->is('api/*')) return null;

            return Problem::response(404, 'Not found', 'The requested resource was not found.');
        });

        // Laravel turns ModelNotFoundException into NotFoundHttpException before rendering, so this is the
        // handler that actually runs. Its message names the model ("No query results for model [...]").
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) return null;

            return Problem::response(404, 'Not found', 'The requested resource was not found.');
        });

        // Fallback: sanitized. Details go to the log, never to the client.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) return null;

            // FIX 2: already a finished response (e.g. the throttle limiter's 429); don't turn it into a 500.
            if ($e instanceof HttpResponseException) return $e->getResponse();

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $title  = Response::$statusTexts[$status] ?? 'Error';
            $detail = $status >= 500 ? 'An unexpected error occurred.' : ($e->getMessage() ?: $title);

            return Problem::response($status, $title, $detail);
        });
    })->create();