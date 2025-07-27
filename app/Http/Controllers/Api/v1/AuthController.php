<?php

namespace App\Http\Controllers\Api\v1;

use Illuminate\Http\Request;
use App\Traits\ApiResponseHandler;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthenticationService;

class AuthController extends Controller
{
    private $authService;

    public function __construct(AuthenticationService $authService){
        $this->authService = $authService;
    }

    public function register(Request $request){
        return $this->authService->register($request->all());
    }

    public function verifyEmail(Request $request){
        return $this->authService->verifyEmail($request->token);
    }

    public function login(Request $request){
        return $this->authService->login($request->all());
    }

    public function forgotPassword(Request $request){
        return $this->authService->forgotPassword($request->email);
    }

    public function resetPassword(Request $request){
        return $this->authService->resetPassword($request->all());
    }

    public function verifyUserPin(Request $request){
        return $this->authService->verifyUserPin($request);
    }

    public function logout(Request $request){
        return $this->authService->logout($request);
    }

    public function user(Request $request){
        return ApiResponseHandler::successResponse(new UserResource($request->user()), "User fetched successfully.");
    }

    public function resendVerificationEmail(Request $request)
    {
        return $this->authService->resendVerificationEmail($request->input('email'));
    }

    public function setTransactionPin(Request $request)
    {
        return $this->authService->setTransactionPin($request->all(), $request);
    }

    public function resetTransactionPin(Request $request)
    {
        return $this->authService->resetTransactionPin($request->all(), $request);
    }

    public function verifyTransactionPin(Request $request)
    {
        return $this->authService->verifyTransactionPin($request->all(), $request);
    }
}
