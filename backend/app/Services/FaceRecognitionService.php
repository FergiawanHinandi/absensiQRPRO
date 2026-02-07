<?php

namespace App\Services;

use App\Models\StudentFaceEmbedding;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FaceRecognitionService
{
    protected $baseUrl = 'http://localhost:5001';

    /**
     * Get face embedding from image content
     */
    public function getEmbedding($imageContent, $filename = 'photo.jpg')
    {
        try {
            $response = Http::timeout(10)
                ->attach('image', $imageContent, $filename)
                ->post("{$this->baseUrl}/embed");

            if ($response->successful() && $response['success']) {
                return $response['embedding'];
            }
        } catch (\Exception $e) {
            Log::error('Face embedding service error: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Store or update embedding for a student
     */
    public function updateEmbedding(User $student, $embeddingVector)
    {
        return StudentFaceEmbedding::updateOrCreate(
            ['student_id' => $student->id],
            ['embedding' => $embeddingVector]
        );
    }

    /**
     * Check for duplicates against other students in the same school
     * Returns true if duplicates found, false otherwise
     */
    public function checkForDuplicates(User $student, $newEmbedding)
    {
        // Get all embeddings for the same school (excluding current student)
        $candidates = StudentFaceEmbedding::where('student_id', '!=', $student->id)
            ->whereHas('student', function ($q) use ($student) {
                $q->where('school_id', $student->school_id)
                    ->where('role_type', 'student')
                    ->where('is_active', true);
            })
            ->with('student:id,name,school_id')
            ->get();

        $duplicatesFound = false;

        foreach ($candidates as $candidate) {
            $similarity = $this->calculateCosineSimilarity($newEmbedding, $candidate->embedding);

            if ($similarity > 0.85) {
                $duplicatesFound = true;

                // Log the detection
                Log::channel('audit')->warning('photo_duplicate_detected', [
                    'student_a_id' => $student->id,
                    'student_a_name' => $student->name,
                    'student_b_id' => $candidate->student_id,
                    'student_b_name' => $candidate->student->name,
                    'similarity' => $similarity,
                    'school_id' => $student->school_id,
                ]);
            }
        }

        // Update flag
        $student->photo_duplicate_flag = $duplicatesFound;
        $student->save();

        return $duplicatesFound;
    }

    /**
     * Find all duplicate pairs for a school
     */
    public function findAllDuplicatesInSchool($schoolId)
    {
        $embeddings = StudentFaceEmbedding::whereHas('student', function ($q) use ($schoolId) {
            $q->where('school_id', $schoolId)
                ->where('role_type', 'student')
                ->where('is_active', true);
        })->with('student:id,name,username,photo_path')->get();

        $pairs = [];
        $checked = [];

        foreach ($embeddings as $i => $a) {
            foreach ($embeddings as $j => $b) {
                if ($i >= $j) {
                    continue;
                } // Avoid self-compare and double-counting

                $key = $a->student_id < $b->student_id
                    ? "{$a->student_id}-{$b->student_id}"
                    : "{$b->student_id}-{$a->student_id}";

                if (in_array($key, $checked)) {
                    continue;
                }
                $checked[] = $key;

                $similarity = $this->calculateCosineSimilarity($a->embedding, $b->embedding);

                if ($similarity > 0.85) {
                    $pairs[] = [
                        'student_a' => $a->student,
                        'student_b' => $b->student,
                        'similarity_score' => round($similarity, 4),
                    ];
                }
            }
        }

        return $pairs;
    }

    private function calculateCosineSimilarity($vecA, $vecB)
    {
        if (is_string($vecA)) {
            $vecA = json_decode($vecA);
        }
        if (is_string($vecB)) {
            $vecB = json_decode($vecB);
        }

        if (! is_array($vecA) || ! is_array($vecB)) {
            return 0;
        }
        if (count($vecA) !== count($vecB)) {
            return 0;
        }

        $dotProduct = 0;
        $normA = 0;
        $normB = 0;

        for ($i = 0; $i < count($vecA); $i++) {
            $dotProduct += $vecA[$i] * $vecB[$i];
            $normA += $vecA[$i] * $vecA[$i];
            $normB += $vecB[$i] * $vecB[$i];
        }

        if ($normA == 0 || $normB == 0) {
            return 0;
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }
}
