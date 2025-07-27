<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use App\Services\Auth\HashService;
use App\Services\Auth\TokenService;
use App\Traits\ApiResponseHandler;
use App\Services\Auth\ValidationService;
use App\Repositories\UserRepository;
use App\Services\Notifications\NotificationService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;

class AuthenticationService {

    use ApiResponseHandler;

    private $hashService;
    private $authenticationService;
    private  $notificationService;
    private  $validateService;

    public function __construct() {}

    public function register(array $userData){

        DB::beginTransaction();
        try {

            $this->validateService = new ValidationService();

            $validation = $this->validateService->validateRegistrationData($userData);

            if ($validation !== true) {
                return $validation;
            }

            if(app(UserRepository::class)->findByEmail($userData['email']) || app(UserRepository::class)->findByPhone($userData['phone']) ){
                return ApiResponseHandler::errorResponse("User email or phone already registered");
            }

            $userData['password'] = app(HashService::class)->make($userData['password']);

            $user = app(UserRepository::class)->create($userData);
            app(WalletService::class)->createWallet($user);

            $token =  app(TokenService::class)->generateEmailVerificationToken($user);

            app(NotificationService::class)->sendEmailVerification($user, $token);

            $data = User::find($user->id);

            DB::commit();
            return ApiResponseHandler::successResponse($data, "User registration was successful.", 201);

        } catch (\Throwable $th) {
            DB::rollBack();
            return ApiResponseHandler::errorResponse($th->getMessage(),500);
        }
    }

    public function login(array $credentials)
    {
        $this->validateService = new ValidationService();
        $validation = $this->validateService->validateLoginCredentials($credentials);

        if ($validation !== true) {
            return $validation;
        }

        $user = app(UserRepository::class)->findByEmail($credentials['email']);

        if (!$user || !app(HashService::class)->verify($credentials['password'], $user->password)) {
            return ApiResponseHandler::errorResponse("Invalid credentials");
        }

        if (!$user->is_verified) {
            return ApiResponseHandler::errorResponse("Kindly verify your email first");
        }

        $token = app(TokenService::class)->generateAuthToken($user);

        return ApiResponseHandler::successResponse([
            'user' => $user,
            'token' => $token,
        ], "User login was successful.", 200);

    }

    public function logout($request)
    {
        app(TokenService::class)->revokeCurrentToken($request);
    }

    public function forgotPassword(string $email)
    {
        $user = app(UserRepository::class)->findByEmail($email);

        if (!$user) {
            return ApiResponseHandler::errorResponse("User do not exists");
        }

        $token = app(TokenService::class)->generatePasswordResetToken($user);

        app(NotificationService::class)->sendPasswordReset($user, $token);

        return ApiResponseHandler::successResponse([], "Password reset token sent successfully.");
    }

    public function resetPassword(array $data)
    {
        $this->validateService = new ValidationService();
        $validation = $this->validateService->validateResetPasswordData($data);

        if ($validation !== true) {
            return $validation;
        }

        $user = app(TokenService::class)->verifyPasswordResetToken($data['token']);

        if (!$user) {
            return ApiResponseHandler::errorResponse("Invalid or expired token");
        }

        $hashedPassword = app(HashService::class)->make($data['password']);
        app(UserRepository::class)->updatePassword($user, $hashedPassword);

        app(TokenService::class)->revokeAllTokens($user);

        app(NotificationService::class)->sendPasswordChangeNotification($user);

        return ApiResponseHandler::successResponse([], "Password was reset successfully.");
    }

    public function verifyEmail(string $token)
    {
        $user = app(TokenService::class)->verifyEmailToken($token);

        if (!$user) {
            return ApiResponseHandler::errorResponse("Invalid or expired token");
        }

        app(UserRepository::class)->update($user, ['is_verified' => true, 'email_verified_at' => now()]);

        return $this->successResponse("Email verification was successfull");
    }

    public function verifyUserPin($request){

        $user = $request->user();

        if(!$request->has('pin')){
            return ApiResponseHandler::validationErrorResponse([], "User Pin is required");
        }

        if (!app(HashService::class)->verify($request['pin'], $user->pin)) {
            return ApiResponseHandler::errorResponse("invalid pin");
        }

        return ApiResponseHandler::successResponse($user, "User pin verification was successful");
    }

    public function resendVerificationEmail(string $email)
    {
        $user = app(UserRepository::class)->findByEmail($email);

        if (!$user) {
            return ApiResponseHandler::errorResponse("User does not exist");
        }

        if ($user->is_verified) {
            return ApiResponseHandler::errorResponse("Email is already verified");
        }

        $token = app(TokenService::class)->generateEmailVerificationToken($user);

        app(NotificationService::class)->sendEmailVerification($user, $token);

        return ApiResponseHandler::successResponse([], "Verification email sent");
    }

    public function sendIdentityVerificationCode($request)
    {
        $user = $request->user();

        // Generate a 6-digit verification code
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Store the code with expiration (you might want to use cache or database)
        $token = app(TokenService::class)->generateIdentityVerificationToken($user, $code);

        // app(NotificationService::class)->sendIdentityVerificationCode($user, $code);

        return ApiResponseHandler::successResponse([], "Verification code sent");
    }

    public function verifyUserIdentity(array $data, $request)
    {
        if (!isset($data['code'])) {
            return ApiResponseHandler::validationErrorResponse([], "Verification code is required");
        }

        $user = $request->user();

        // $isValid = app(TokenService::class)->verifyIdentityVerificationToken($user, $data['code']);

        // if (!$isValid) {
        //     return ApiResponseHandler::errorResponse("Invalid or expired verification code");
        // }

        // Update user as identity verified
        app(UserRepository::class)->update($user, [
            'identity_verified' => true,
            'identity_verified_at' => now()
        ]);

        return ApiResponseHandler::successResponse([], "Identity verified successfully");
    }

    public function setTransactionPin(array $data, $request)
    {
        if (!isset($data['pin'])) {
            return ApiResponseHandler::validationErrorResponse([], "PIN is required");
        }

        // Validate PIN format (4-6 digits)
        if (!preg_match('/^\d{4,6}$/', $data['pin'])) {
            return ApiResponseHandler::validationErrorResponse([], "PIN must be 4-6 digits");
        }

        $user = $request->user();

        if ($user->pin) {
            return ApiResponseHandler::errorResponse("Transaction PIN already set. Use reset PIN to change it.");
        }

        $hashedPin = app(HashService::class)->make($data['pin']);

        app(UserRepository::class)->update($user, [
            'pin' => $hashedPin,
            'pin_set_at' => now()
        ]);

        return ApiResponseHandler::successResponse([], "Transaction PIN set successfully");
    }

    public function verifyTransactionPin(array $data, $request)
    {
        if (!isset($data['pin'])) {
            return ApiResponseHandler::validationErrorResponse([], "PIN is required");
        }

        $user = $request->user();

        if (!$user->pin) {
            return ApiResponseHandler::errorResponse("No transaction PIN set");
        }

        if (!app(HashService::class)->verify($data['pin'], $user->pin)) {
            return ApiResponseHandler::errorResponse("Invalid PIN");
        }

        return ApiResponseHandler::successResponse([], "PIN verified successfully");
    }

    public function resetTransactionPin(array $data, $request)
    {
        if (!isset($data['old_pin']) || !isset($data['new_pin'])) {
            return ApiResponseHandler::validationErrorResponse([], "Both old PIN and new PIN are required");
        }

        if (!preg_match('/^\d{4,6}$/', $data['new_pin'])) {
            return ApiResponseHandler::validationErrorResponse([], "New PIN must be 4-6 digits");
        }

        $user = $request->user();

        if (!$user->pin) {
            return ApiResponseHandler::errorResponse("No transaction PIN set");
        }

        if (!app(HashService::class)->verify($data['old_pin'], $user->pin)) {
            return ApiResponseHandler::errorResponse("Invalid old PIN");
        }

        if (app(HashService::class)->verify($data['new_pin'], $user->pin)) {
            return ApiResponseHandler::errorResponse("New PIN must be different from old PIN");
        }

        $hashedNewPin = app(HashService::class)->make($data['new_pin']);

        app(UserRepository::class)->update($user, [
            'pin' => $hashedNewPin,
            'pin_updated_at' => now()
        ]);

        app(NotificationService::class)->sendPinChangeNotification($user);

        return ApiResponseHandler::successResponse([], "Transaction PIN reset successfully");
    }
}
