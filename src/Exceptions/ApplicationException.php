<?php

namespace Lunargraphql\Exceptions;

use Exception;
use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ApplicationException extends Exception implements ClientAware, ProvidesExtensions
{
    /**
     * Render the exception into an HTTP response.
     *
     * @param  Request  $request
     * @return Response|JsonResponse
     *
     * @throws HttpException
     */
    public function render($request)
    {
        $code = $this->getCode();
        $status = $code !== 0 ? $code : 500;

        if ($request->expectsJson()) {
            $error = [
                'code' => $code,
                'message' => $this->getMessage(),
            ];

            if (config('app.debug')) {
                $error['file'] = $this->getFile();
                $error['exception'] = get_class($this);
                $error['line'] = $this->getLine();
                $error['trace'] = $this->getTrace();
            }

            return response()->json($error, $status);
        }

        abort($status);
    }

    public function isClientSafe(): bool
    {
        return false;
    }

    public function getExtensions(): ?array
    {
        return [
            //
        ];
    }
}
