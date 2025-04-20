<?php

namespace App\Services\External;

use App\Exceptions\VTUApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VTUProviderService
{
    protected $apiUrl;
    protected $apiKey;
    protected $secretKey;

    public function __construct()
    {
        $this->apiUrl = config('services.vtu_provider.url');
        $this->apiKey = config('services.vtu_provider.api_key');
        $this->secretKey = config('services.vtu_provider.secret_key');
    }

    protected function makeRequest(string $endpoint, array $data)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->post("{$this->apiUrl}/{$endpoint}", $data);

            $responseData = $response->json();

            if (!$response->successful()) {
                Log::error('VTU Provider Error', [
                    'endpoint' => $endpoint,
                    'request' => $data,
                    'response' => $responseData,
                    'status' => $response->status()
                ]);

                return [
                    'success' => false,
                    'message' => $responseData['message'] ?? 'VTU Provider API error',
                    'data' => null
                ];
            }

            return [
                'success' => true,
                'message' => $responseData['message'] ?? 'Success',
                'data' => $responseData['data'] ?? null
            ];

        } catch (\Exception $e) {
            Log::error('VTU Provider Exception', [
                'endpoint' => $endpoint,
                'request' => $data,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Service provider unavailable: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    public function purchaseAirtime(string $providerCode, string $phoneNumber, float $amount, string $reference)
    {
        return $this->makeRequest('airtime/purchase', [
            'provider' => $providerCode,
            'phone' => $phoneNumber,
            'amount' => $amount,
            'reference' => $reference
        ]);
    }

    public function purchaseData(string $providerCode, string $phoneNumber, string $packageCode, string $reference)
    {
        return $this->makeRequest('data/purchase', [
            'provider' => $providerCode,
            'phone' => $phoneNumber,
            'package_code' => $packageCode,
            'reference' => $reference
        ]);
    }

    public function verifyMeter(string $providerCode, string $meterNumber, string $meterType)
    {
        return $this->makeRequest('electricity/verify', [
            'provider' => $providerCode,
            'meter_number' => $meterNumber,
            'meter_type' => $meterType
        ]);
    }

    public function payElectricity(string $providerCode, string $meterNumber, string $meterType, float $amount, string $phoneNumber, string $reference)
    {
        return $this->makeRequest('electricity/pay', [
            'provider' => $providerCode,
            'meter_number' => $meterNumber,
            'meter_type' => $meterType,
            'amount' => $amount,
            'phone' => $phoneNumber,
            'reference' => $reference
        ]);
    }

    public function verifySmartCard(string $providerCode, string $smartCardNumber)
    {
        return $this->makeRequest('cable/verify', [
            'provider' => $providerCode,
            'smart_card_number' => $smartCardNumber
        ]);
    }

    public function subscribeCable(string $providerCode, string $smartCardNumber, string $packageCode, string $phoneNumber, string $reference)
    {
        return $this->makeRequest('cable/subscribe', [
            'provider' => $providerCode,
            'smart_card_number' => $smartCardNumber,
            'package_code' => $packageCode,
            'phone' => $phoneNumber,
            'reference' => $reference
        ]);
    }
}
