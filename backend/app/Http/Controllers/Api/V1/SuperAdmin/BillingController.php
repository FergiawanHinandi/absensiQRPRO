<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A3-M6 FIX: Ganti semua dummy data generator dengan query real ke database.
 *
 * Sebelumnya BillingController menggunakan array_rand() dan hardcode school names.
 * Sekarang menggunakan Payment model (dengan BelongsToSchool sudah di-set sejak A3-C5)
 * dan Subscription model untuk data nyata.
 */
class BillingController extends Controller
{
    /**
     * GET /super-admin/payments — Riwayat pembayaran nyata dari tabel payments
     */
    public function paymentHistory(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $search  = $request->input('search');
        $status  = $request->input('status');

        $query = Payment::with(['school:id,name', 'package:id,name,price'])
            ->orderByDesc('created_at');

        // Filter by school name atau invoice_number
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('school', function ($sq) use ($search) {
                    $sq->where('name', 'ILIKE', "%{$search}%");
                })->orWhere('invoice_number', 'ILIKE', "%{$search}%");
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        $payments = $query->paginate($perPage);

        // Transform untuk frontend
        $payments->getCollection()->transform(function ($payment) {
            return [
                'id'             => $payment->id,
                'school_id'      => $payment->school_id,
                'school_name'    => $payment->school?->name ?? '—',
                'package'        => $payment->package?->name ?? '—',
                'amount'         => $payment->amount,
                'status'         => $payment->status,
                'payment_method' => $payment->payment_method,
                'invoice_number' => $payment->invoice_number,
                'paid_at'        => $payment->paid_at?->toIsoString(),
                'due_date'       => $payment->due_date?->toIsoString(),
                'created_at'     => $payment->created_at?->toIsoString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $payments,
        ]);
    }

    /**
     * GET /super-admin/invoices — Invoice dari tabel payments (setiap payment = invoice)
     */
    public function invoices(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $status  = $request->input('status');

        $query = Payment::with(['school:id,name', 'package:id,name,price'])
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        $invoices = $query->paginate($perPage);

        $invoices->getCollection()->transform(function ($payment) {
            return [
                'id'          => $payment->invoice_number ?? 'INV-' . str_pad($payment->id, 6, '0', STR_PAD_LEFT),
                'payment_id'  => $payment->id,
                'school_id'   => $payment->school_id,
                'school_name' => $payment->school?->name ?? '—',
                'package'     => $payment->package?->name ?? '—',
                'amount'      => $payment->amount,
                'status'      => $payment->status,
                'issued_at'   => $payment->created_at?->toIsoString(),
                'due_date'    => $payment->due_date?->toIsoString(),
                'paid_at'     => $payment->paid_at?->toIsoString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $invoices,
        ]);
    }

    /**
     * GET /super-admin/billing/statistics — Statistik billing nyata dari DB
     */
    public function statistics(Request $request)
    {
        $now       = now();
        $thisMonth = $now->copy()->startOfMonth();
        $lastMonth = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonth()->endOfMonth();

        $revenueThisMonth = Payment::where('status', 'paid')
            ->where('paid_at', '>=', $thisMonth)
            ->sum('amount');

        $revenueLastMonth = Payment::where('status', 'paid')
            ->whereBetween('paid_at', [$lastMonth, $lastMonthEnd])
            ->sum('amount');

        $growth = $revenueLastMonth > 0
            ? round((($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth) * 100, 1)
            : 0;

        $activeSubscriptions = Subscription::where('status', 'active')->count();
        $pendingPayments     = Payment::where('status', 'pending')->count();
        $overduePayments     = Payment::where('status', 'overdue')->count();
        $totalSchools        = Payment::distinct('school_id')->count('school_id');

        $revenueByPackage = Payment::where('status', 'paid')
            ->join('subscription_packages', 'payments.package_id', '=', 'subscription_packages.id')
            ->select('subscription_packages.name', DB::raw('SUM(payments.amount) as total'))
            ->groupBy('subscription_packages.name')
            ->pluck('total', 'name')
            ->toArray();

        return response()->json([
            'success' => true,
            'data'    => [
                'total_revenue_this_month' => (float) $revenueThisMonth,
                'total_revenue_last_month' => (float) $revenueLastMonth,
                'growth_percentage'        => $growth,
                'active_subscriptions'     => $activeSubscriptions,
                'pending_payments'         => $pendingPayments,
                'overdue_payments'         => $overduePayments,
                'total_schools_subscribed' => $totalSchools,
                'revenue_by_package'       => $revenueByPackage,
            ],
        ]);
    }
}
