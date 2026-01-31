<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    /**
     * Get payment history (Dummy Generator)
     */
    public function paymentHistory(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);
        $search = $request->input('search');

        // Generate 50 Dummy Payments
        $payments = $this->generateDummyPayments(50);

        // Filter by search
        if ($search) {
            $payments = array_filter($payments, function ($p) use ($search) {
                return stripos($p['school_name'], $search) !== false || stripos($p['package'], $search) !== false;
            });
        }

        // Pagination Logic (Manual Array Slice)
        $total = count($payments);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($payments, $offset, $perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'data' => array_values($items),
                'current_page' => (int) $page,
                'last_page' => ceil($total / $perPage),
                'per_page' => (int) $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Get invoices (Dummy Generator)
     */
    public function invoices(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);
        $status = $request->input('status');

        $invoices = $this->generateDummyInvoices(30);

        if ($status) {
            $invoices = array_filter($invoices, function ($inv) use ($status) {
                return $inv['status'] === $status;
            });
        }

        $total = count($invoices);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($invoices, $offset, $perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'data' => array_values($items),
                'current_page' => (int) $page,
                'last_page' => ceil($total / $perPage),
                'per_page' => (int) $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Get billing statistics
     */
    public function statistics(Request $request)
    {
        // Mock Statistics based on "dummy" reality
        $stats = [
            'total_revenue_this_month' => 45000000,
            'total_revenue_last_month' => 38500000,
            'growth_percentage' => 16.8,
            'active_subscriptions' => 42,
            'pending_payments' => 8,
            'overdue_payments' => 3,
            'total_schools_subscribed' => 45,
            'revenue_by_package' => [
                'Basic' => 12000000,
                'Pro' => 25000000,
                'Premium' => 8000000,
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    private function generateDummyPayments($count)
    {
        $data = [];
        $schools = ['SMA Negeri 1 Jakarta', 'SMP Bakti Mulya', 'SMA Harapan Bangsa', 'SD Tunas Mandiri', 'SMK Teknologi Maju', 'MAN 1 Bandung', 'SMP 5 Surabaya'];
        $packages = [
            ['name' => 'Basic', 'price' => 500000],
            ['name' => 'Pro', 'price' => 1000000],
            ['name' => 'Premium', 'price' => 2500000],
        ];

        for ($i = 1; $i <= $count; $i++) {
            $pkg = $packages[array_rand($packages)];
            $date = Carbon::now()->subDays(rand(0, 60));

            $data[] = [
                'id' => $i,
                'school_id' => rand(1, 10),
                'school_name' => $schools[array_rand($schools)],
                'package' => $pkg['name'],
                'amount' => $pkg['price'],
                'status' => 'paid',
                'payment_method' => rand(0, 1) ? 'Bank Transfer' : 'Credit Card',
                'paid_at' => $date->toIsoString(),
                'period_start' => $date->copy()->startOfMonth()->toIsoString(),
                'period_end' => $date->copy()->endOfMonth()->toIsoString(),
            ];
        }

        // Sort by date desc
        usort($data, function ($a, $b) {
            return strtotime($b['paid_at']) - strtotime($a['paid_at']);
        });

        return $data;
    }

    private function generateDummyInvoices($count)
    {
        $data = [];
        $schools = ['SMA Negeri 1 Jakarta', 'SMP Bakti Mulya', 'SMA Harapan Bangsa', 'SD Tunas Mandiri', 'SMK Teknologi Maju'];
        $statuses = ['paid', 'pending', 'overdue'];

        for ($i = 1; $i <= $count; $i++) {
            $status = $statuses[array_rand($statuses)];
            $date = Carbon::now()->subDays(rand(0, 30));
            $dueDate = $date->copy()->addDays(7);

            $data[] = [
                'id' => 'INV-'.date('Y').'-'.str_pad($i, 4, '0', STR_PAD_LEFT),
                'school_id' => rand(1, 10),
                'school_name' => $schools[array_rand($schools)],
                'package' => ['Basic', 'Pro', 'Premium'][rand(0, 2)],
                'amount' => [500000, 1000000, 2500000][rand(0, 2)],
                'status' => $status,
                'issued_at' => $date->toIsoString(),
                'due_date' => $dueDate->toIsoString(),
                'paid_at' => $status === 'paid' ? $date->copy()->addDays(2)->toIsoString() : null,
            ];
        }

        return $data;
    }
}
