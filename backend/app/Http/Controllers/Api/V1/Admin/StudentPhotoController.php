<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Intervention\Image\ImageManagerStatic as Image;
use ZipArchive;
use Illuminate\Support\Facades\Http;
use App\Services\FaceRecognitionService;

class StudentPhotoController extends Controller
{
    protected $faceService;

    public function __construct(FaceRecognitionService $faceService)
    {
        $this->faceService = $faceService;
    }

    public function bulkUpload(Request $request)
    {
        $this->authorize('update', User::class); // Only school_admin
        $dryRun = filter_var($request->query('dry_run'), FILTER_VALIDATE_BOOLEAN);
        $replace = $request->query('replace', 'true') !== 'false';
        $files = [];
        $unmatched = [];
        $matched = 0;
        $croppedFaces = 0;
        $fallbackCrops = 0;
        $total = 0;
        // Accept ZIP or multiple files
        if ($request->hasFile('file') && $request->file('file')->getClientOriginalExtension() === 'zip') {
            $zip = new ZipArchive;
            $zipPath = $request->file('file')->getRealPath();
            if ($zip->open($zipPath) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    if (preg_match('/\.(jpg|jpeg|png)$/i', $entry)) {
                        $files[] = [
                            'filename' => $entry,
                            'content' => $zip->getFromIndex($i),
                        ];
                    }
                }
                $zip->close();
            }
        } else if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $file) {
                if ($file->isValid() && preg_match('/\.(jpg|jpeg|png)$/i', $file->getClientOriginalName())) {
                    $files[] = [
                        'filename' => $file->getClientOriginalName(),
                        'content' => file_get_contents($file->getRealPath()),
                    ];
                }
            }
        } else {
            return response()->json(['success' => false, 'message' => 'No valid files uploaded.'], 422);
        }
        $total = count($files);
        foreach ($files as $file) {
            $name = pathinfo($file['filename'], PATHINFO_FILENAME);
            $student = User::where('role_type', 'student')
                ->where(function($q) use ($name) {
                    $q->where('nis', $name)
                      ->orWhere('nisn', $name)
                      ->orWhere('id', $name);
                })->first();
            if (!$student) {
                $unmatched[] = ['filename' => $file['filename'], 'reason' => 'Student not found'];
                continue;
            }
            if (!$replace && $student->photo_path) {
                $unmatched[] = ['filename' => $file['filename'], 'reason' => 'Photo exists, replace=false'];
                continue;
            }
            if ($dryRun) {
                $matched++;
                continue;
            }
            if (strlen($file['content']) > 2 * 1024 * 1024) {
                $unmatched[] = ['filename' => $file['filename'], 'reason' => 'File too large'];
                continue;
            }
            // AI face detection microservice
            $method = 'fallback_crop';
            $cropped = null;
            try {
                $resp = Http::timeout(5)->attach('image', $file['content'], $file['filename'])
                    ->post('http://localhost:5001/detect-face');
                if ($resp->ok() && $resp['success']) {
                    $cropped = hex2bin($resp['image']);
                    $method = $resp['method'];
                }
            } catch (\Exception $e) {
                \Log::warning('Face detection service failed', ['student_id' => $student->id, 'error' => $e->getMessage()]);
            }
            if (!$cropped) {
                // Fallback: Intervention resize only
                $img = Image::make($file['content'])
                    ->resize(400, 533, function ($c) { $c->aspectRatio(); $c->upsize(); })
                    ->encode('jpg', 80);
                $cropped = $img;
                $method = 'fallback_crop';
            }
            if ($method === 'face_detected') $croppedFaces++;
            if ($method === 'fallback_crop') $fallbackCrops++;
            $path = 'student-photos/' . $student->id . '.jpg';
            Storage::disk('public')->put($path, $cropped);
            $student->photo_path = $path;
            $student->save();
            $matched++;

            // Face Embedding & Duplicate Check
            try {
                // Determine the content to send. $cropped is an Intervention Image object or binary string?
                // The code above: 
                // $cropped = hex2bin($resp['image']); (string)
                // OR $cropped = $img; (Intervention Image)
                // Storage::disk('public')->put($path, $cropped); handles both usually because Intervention casts to string on string context or we need to encode.
                // But wait, look at fallback: $img->encode('jpg', 80); $cropped = $img;
                // So $cropped is either binary string (from hex2bin) or Intervention Image object.
                
                $imageContent = $cropped;
                if (is_object($cropped) && method_exists($cropped, 'encode')) {
                    $imageContent = (string) $cropped->encode('jpg');
                }

                $embedding = $this->faceService->getEmbedding($imageContent);
                if ($embedding) {
                    $this->faceService->updateEmbedding($student, $embedding);
                    $this->faceService->checkForDuplicates($student, $embedding);
                }
            } catch (\Exception $e) {
                Log::warning('Face embedding failed', ['student_id' => $student->id, 'error' => $e->getMessage()]);
            }

            Log::channel('audit')->info('student_photo_auto_cropped', [
                'student_id' => $student->id,
                'method' => $method,
                'timestamp' => now(),
            ]);
        }
        Log::channel('audit')->info('bulk_student_photo_upload', [
            'admin_id' => $request->user()->id,
            'matched_count' => $matched,
            'unmatched_count' => count($unmatched),
            'timestamp' => now(),
        ]);
        return response()->json([
            'success' => true,
            'total_files' => $total,
            'matched' => $matched,
            'cropped_faces' => $croppedFaces,
            'fallback_center_crop' => $fallbackCrops,
            'unmatched' => $unmatched,
        ]);
    }
}
