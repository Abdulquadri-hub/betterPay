<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    protected $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function register(array $data): User
    {
        DB::beginTransaction();

        try {
            // Create user
            $user = $this->userRepository->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => Hash::make($data['password']),
            ]);

            // Create wallet for the user
            $wallet = new Wallet([
                'balance' => 0,
                'currency' => 'NGN',
                'is_active' => true
            ]);

            $user->wallet()->save($wallet);

            DB::commit();
            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function login(array $data): array
    {
        if (!Auth::attempt($data)) {
            return [
                'success' => false
            ];
        }

        $user = $this->userRepository->findByEmail($data['email']);
        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'success' => true,
            'user' => $user,
            'token' => $token
        ];
    }

    public function logout(User $user): void
    {
        $user->tokens()->delete();
    }

    public function updateProfile(User $user, array $data): User
    {
        $this->userRepository->update($user, $data);
        return $user->fresh();
    }

    public function updatePassword(User $user, string $currentPassword, string $newPassword): bool
    {
        // Verify current password
        if (!Hash::check($currentPassword, $user->password)) {
            return false;
        }

        // Update to new password
        $this->userRepository->update($user, [
            'password' => Hash::make($newPassword)
        ]);

        return true;
    }

    public function forgotPassword(string $email): bool
    {
        $user = $this->userRepository->findByEmail($email);

        if (!$user) {
            return false;
        }

        // Generate password reset token and send email
        // Implement password reset token generation logic here

        return true;
    }
}
