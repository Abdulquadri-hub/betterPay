<?php

namespace App\Services\Auth;

use App\Traits\ApiResponseHandler;
use Illuminate\Support\Facades\Validator;

class ValidationService {

    use ApiResponseHandler;

    public function validateRegistrationData(array $data)
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => 'required|string|max:20',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();
            return $this->validationErrorResponse($errors);
        }

        return true;
    }

    public  function validateLoginCredentials(array $credentials)
    {
        $validator = Validator::make($credentials, [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();
            return $this->validationErrorResponse($errors);
        }

        return true;
    }

    public static function validateResetPasswordData(array $credentials)
    {
        $validator = Validator::make($credentials, [
            'token' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();
            return self::validationErrorResponse($errors);
        }

        return true;
    }

    public function validateProfile(array $credentials){
        $validator = Validator::make($credentials, [
            'bvn' => 'nullable|string',
            'nin' => 'nullable|string',
            'bvn_dob' => 'required|string',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();
            return $this->validationErrorResponse($errors);
        }

        return true;
    }
}
