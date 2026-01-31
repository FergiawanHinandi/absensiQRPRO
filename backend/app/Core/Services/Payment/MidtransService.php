<?php

namespace App\Core\Services\Payment;

use App\Models\Payment;
use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Midtrans\Config as MidtransConfig;
use Midtrans\Snap;

class MidtransService
{
    public function __construct()
    {
        MidtransConfig::$serverKey = Config::get('services.midtrans.server_key');
        MidtransConfig::$isProduction = Config::get('services.midtrans.is_production');
        MidtransConfig::$isSanitized = Config::get('services.midtrans.is_sanitized');
        MidtransConfig::$is3ds = Config::get('services.midtrans.is_3ds');
    }

    /**
     * Create Snap Token for Transaction
     */
    public function createSnapToken(Payment $payment, $user)
    {
        $params = [
            'transaction_details' => [
                'order_id' => $payment->transaction_id,
                'gross_amount' => (int) $payment->amount,
            ],
            'customer_details' => [
                'first_name' => $user->name,
                'email' => $user->email,
                'phone' => $payment->school->phone ?? '08123456789',
            ],
            'item_details' => [
                [
                    'id' => $payment->package_id ? $payment->package_id : 'CUSTOM',
                    'price' => (int) $payment->amount,
                    'quantity' => 1,
                    'name' => $payment->package ? $payment->package->name : $payment->description,
                ],
            ],
            'custom_field1' => $payment->school_id,
        ];

        try {
            $snapToken = Snap::getSnapToken($params);

            // Save token to payment record if column exists, or just return it
            // Assuming we might want to transiently store it or just return to FE
            return $snapToken;
        } catch (Exception $e) {
            Log::error('Midtrans Snap Error: '.$e->getMessage());
            throw $e;
        }
    }
}
