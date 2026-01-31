<?php

namespace App\Http\Controllers\Api\V1\School;

use App\Core\Services\Payment\MidtransService;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\SubscriptionPackage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubscriptionController extends Controller
{
    public function __construct(
        private MidtransService $midtransService
    ) {}

    /**
     * Get Available Packages
     */
    public function packages()
    {
        $packages = SubscriptionPackage::where('is_active', true)->get();

        return response()->json(['data' => $packages]);
    }

    /**
     * Initiate Payment for a Package
     */
    public function purchase(Request $request)
    {
        $validated = $request->validate([
            'package_id' => 'required|exists:subscription_packages,id',
        ]);

        $package = SubscriptionPackage::find($validated['package_id']);
        $user = $request->user();
        $schoolId = $user->school_id;

        // Create Pending Payment Record
        $transactionId = 'SUB-'.date('YmdHis').'-'.Str::random(5);

        $payment = Payment::create([
            'school_id' => $schoolId,
            'amount' => $package->price,
            'description' => 'Pembelian Paket '.$package->name,
            'status' => 'pending', // Waiting for payment
            'package_id' => $package->id,
            'transaction_id' => $transactionId,
            'payment_method' => 'midtrans',
        ]);

        try {
            // Generate Snap Token
            $snapToken = $this->midtransService->createSnapToken($payment, $user);

            return response()->json([
                'success' => true,
                'token' => $snapToken,
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/'.$snapToken, // Optional redirect
                'payment' => $payment,
            ]);

        } catch (\Exception $e) {
            // SECURITY FIX: Log error internally but show generic message to user
            \Illuminate\Support\Facades\Log::error('Midtrans payment processing failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json(['message' => 'Gagal memproses pembayaran. Silakan coba lagi atau hubungi support.'], 500);
        }
    }
}
