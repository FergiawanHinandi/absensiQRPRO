<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\SubscriptionPackage;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Get list of payments/invoices
     */
    public function index(Request $request)
    {
        $query = Payment::with(['school', 'package']);

        // Search by School Name, Invoice ID (transaction_id), or Description
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('transaction_id', 'ILIKE', "%{$search}%")
                    ->orWhere('description', 'ILIKE', "%{$search}%")
                    ->orWhereHas('school', function ($sq) use ($search) {
                        $sq->where('name', 'ILIKE', "%{$search}%");
                    });
            });
        }

        // Filter by Status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $payments = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 10);

        return response()->json(['success' => true, 'data' => $payments]);
    }

    /**
     * Create new Invoice (Payment Record)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'school_id' => 'required|exists:schools,id',
            'amount' => 'required|numeric|min:0',
            'description' => 'required|string',
            'status' => 'required|in:pending,paid',
            'package_id' => 'nullable|exists:subscription_packages,id', // Added package_id
            'payment_date' => 'required_if:status,paid|date',
            'payment_method' => 'nullable|string',
        ]);

        // Generate Transaction ID/Invoice Number if not present
        $transactionId = 'INV-'.date('Ymd').'-'.rand(1000, 9999);

        // Auto-fill amount from package if package_id exists and amount not overridden (optional logic, but keeping explicit)
        if (! empty($validated['package_id']) && ! $request->has('amount')) {
            $pkg = SubscriptionPackage::find($validated['package_id']);
            if ($pkg) {
                $validated['amount'] = $pkg->price;
                $validated['description'] = $validated['description'] ?? 'Pembelian Paket '.$pkg->name;
            }
        }

        $payment = Payment::create([
            'school_id' => $validated['school_id'],
            'amount' => $validated['amount'],
            'description' => $validated['description'],
            'status' => $validated['status'],
            'package_id' => $validated['package_id'] ?? null,
            'payment_date' => $validated['status'] === 'paid' ? ($request->payment_date ?? now()) : null,
            'transaction_id' => $transactionId,
            'payment_method' => $request->payment_method,
        ]);

        // If created as PAID immediately, trigger upgrade
        if ($payment->status === 'paid' && $payment->package_id) {
            $this->applyPackageToSchool($payment->school, $payment->package_id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Invoice berhasil dibuat',
            'data' => $payment->load('school', 'package'),
        ]);
    }

    /**
     * Update Payment Status
     */
    public function updateStatus(Request $request, $id)
    {
        $payment = Payment::with('school')->findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:pending,paid,failed',
            'payment_date' => 'required_if:status,paid|date',
            'payment_method' => 'nullable|string',
        ]);

        $oldStatus = $payment->status;
        $payment->status = $validated['status'];

        if ($validated['status'] === 'paid') {
            $payment->payment_date = $request->payment_date ?? now();
        }
        if ($request->has('payment_method')) {
            $payment->payment_method = $request->payment_method;
        }
        $payment->save();

        // Trigger Upgrade if changing to PAID
        if ($oldStatus !== 'paid' && $payment->status === 'paid' && $payment->package_id) {
            $this->applyPackageToSchool($payment->school, $payment->package_id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Status pembayaran diperbarui',
            'data' => $payment,
        ]);
    }

    /**
     * Helper to apply package limits to school
     */
    private function applyPackageToSchool($school, $packageId)
    {
        if (! $school) {
            return;
        }

        $pkg = SubscriptionPackage::find($packageId);
        if (! $pkg) {
            return;
        }

        // Decode features
        $features = is_string($pkg->features) ? json_decode($pkg->features, true) : $pkg->features;

        $school->update([
            'package_type' => $pkg->name,
            'max_students' => $features['max_students'] ?? $school->max_students,
            'max_teachers' => $features['max_teachers'] ?? $school->max_teachers,
            'max_classes' => $features['max_classes'] ?? $school->max_classes,
        ]);
    }

    /**
     * Delete Payment record
     */
    public function destroy($id)
    {
        $payment = Payment::findOrFail($id);
        $payment->delete();

        return response()->json(['success' => true, 'message' => 'Data pembayaran dihapus']);
    }

    /**
     * Get Dashboard/Stats summary for Billing
     */
    public function stats()
    {
        $totalRevenue = Payment::where('status', 'paid')->sum('amount');
        $pendingInvoices = Payment::where('status', 'pending')->count();
        $thisMonthRevenue = Payment::where('status', 'paid')
            ->whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year)
            ->sum('amount');

        return response()->json([
            'success' => true,
            'data' => [
                'total_revenue' => $totalRevenue,
                'pending_invoices' => $pendingInvoices,
                'month_revenue' => $thisMonthRevenue,
            ],
        ]);
    }
}
