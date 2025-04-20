<?php

namespace App\Traits;

Trait ApiResponseHandler {

    public const STATUS_OK = 200;
    public const STATUS_NOT_FOUND = 400;
    public const STATUS_UNATHOURIZED = 401;
    public const STATUS_FORBIDEEN = 403;

    public static function successResponse($data = [], string $message = "Success", int $statusCode = 200){
        return response()->json([
            'success' =>  true,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    public static function errorResponse(string $message = "Error", int $statusCode = 400, array $errors = []){
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $statusCode);
    }

    public static function validationErrorResponse(array $errors = [], string $message = 'Validation Error'){
        return self::errorResponse($message, 422, $errors);
    }

    public static function notFoundResponse(string $message = 'Resource Not Found'){
        return self::errorResponse($message, self::STATUS_NOT_FOUND);
    }

    public static function unauthorizedResponse(string $message = 'Unauthorized'){
        return self::errorResponse($message, self::STATUS_UNATHOURIZED);
    }

    public static function forbiddenResponse(string $message = 'Forbidden'){
        return self::errorResponse($message, self::STATUS_FORBIDEEN);
    }
}
