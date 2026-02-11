<?php

namespace App\Services;

use App\Models\Attendance;

class StudentArrivalConsistencyService
{
    /**
     * Calculate arrival time variance and consistency score for a student
     */
    public function calculateConsistencyScore(int $studentId): array
    {
        // Ambil semua jam check-in (dalam menit dari 00:00)
        $times = Attendance::where('student_id', $studentId)
            ->whereNotNull('check_in_time')
            ->pluck('check_in_time')
            ->map(function ($time) {
                [$h, $m, $s] = explode(':', $time);

                return ((int) $h) * 60 + (int) $m + ((int) $s >= 30 ? 1 : 0); // pembulatan menit
            })
            ->toArray();

        $variance = $this->variance($times);
        $score = $this->scoreFromVariance($variance);

        return [
            'variance' => round($variance, 2),
            'score' => $score,
            'category' => $this->categoryFromScore($score),
        ];
    }

    private function variance(array $values): float
    {
        $n = count($values);
        if ($n <= 1) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
        $sumSq = 0.0;
        foreach ($values as $v) {
            $sumSq += pow($v - $mean, 2);
        }

        return $sumSq / ($n - 1);
    }

    private function scoreFromVariance(float $variance): int
    {
        return (int) round(sqrt($variance)); // gunakan standar deviasi sebagai skor
    }

    private function categoryFromScore(int $score): string
    {
        if ($score <= 30) {
            return 'Consistent';
        }
        if ($score <= 60) {
            return 'Moderate';
        }

        return 'Highly inconsistent';
    }
}
