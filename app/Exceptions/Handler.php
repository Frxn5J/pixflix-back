<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (Throwable $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception instanceof ApiException
                ? $exception->statusCode()
                : ($exception instanceof ValidationException
                    ? 422
                    : ($exception instanceof AuthenticationException
                        ? 401
                        : ($exception instanceof HttpExceptionInterface
                            ? $exception->getStatusCode()
                            : 500)));
            $code = $this->apiErrorCode($exception, $status);
            $details = $exception instanceof ApiException
                ? $exception->errorDetails()
                : ($exception instanceof ValidationException ? $exception->errors() : null);

            $response = response()->json([
                'error' => array_filter([
                    'code' => $code,
                    'message' => $exception instanceof ApiException
                        ? $exception->getMessage()
                        : $this->apiErrorMessage($code),
                    'details' => $details,
                ], static fn ($value) => $value !== null),
            ], $status);

            if ($exception instanceof HttpExceptionInterface) {
                foreach ($exception->getHeaders() as $header => $value) {
                    $response->header($header, $value);
                }
            }

            return $response;
        });
    }

    private function apiErrorCode(Throwable $exception, int $status): string
    {
        if ($exception instanceof ValidationException) {
            return 'validation_error';
        }

        if ($exception instanceof ApiException) {
            return $exception->errorCode();
        }

        if ($exception instanceof AuthenticationException) {
            return 'unauthenticated';
        }

        return match ($status) {
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            429 => 'rate_limited',
            default => $status >= 500 ? 'internal_error' : 'request_error',
        };
    }

    private function apiErrorMessage(string $code): string
    {
        return match ($code) {
            'unauthenticated' => 'La autenticacion es requerida.',
            'forbidden' => 'No tienes permiso para realizar esta accion.',
            'not_found' => 'El recurso solicitado no existe.',
            'validation_error' => 'La solicitud no es valida.',
            'rate_limited' => 'Se alcanzo el limite de solicitudes.',
            default => 'No fue posible procesar la solicitud.',
        };
    }
}
