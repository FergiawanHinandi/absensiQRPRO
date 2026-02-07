<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class SecureFileUploadRequest extends FormRequest
{
    /**
     * Maximum file size in bytes (2MB)
     */
    private const MAX_FILE_SIZE = 2 * 1024 * 1024; // 2MB

    /**
     * Allowed MIME types
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    /**
     * Allowed file extensions
     */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by policies
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:' . (self::MAX_FILE_SIZE / 1024), // in KB
                'mimes:' . implode(',', array_keys(self::ALLOWED_MIME_TYPES)),
                function ($attribute, $value, $fail) {
                    if (!$value instanceof UploadedFile) {
                        $fail('The :attribute must be a file.');
                        return;
                    }

                    // Double-check MIME type to prevent spoofing
                    $mimeType = $value->getMimeType();
                    $extension = strtolower($value->getClientOriginalExtension());

                    if (!array_key_exists($mimeType, self::ALLOWED_MIME_TYPES)) {
                        $fail('File type not allowed. Only JPG, PNG, and PDF files are permitted.');
                        return;
                    }

                    if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
                        $fail('File extension not allowed. Only .jpg, .png, and .pdf files are permitted.');
                        return;
                    }

                    // Verify MIME matches extension
                    $expectedExtension = self::ALLOWED_MIME_TYPES[$mimeType];
                    if ($extension !== $expectedExtension && !($extension === 'jpeg' && $expectedExtension === 'jpg')) {
                        $fail('File extension does not match file type.');
                        return;
                    }

                    // Additional security: Check file content
                    $this->validateFileContent($value, $fail);
                },
            ],
            'category' => 'sometimes|string|in:student_photo,teacher_photo,document,assignment',
            'description' => 'sometimes|string|max:255',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please select a file to upload.',
            'file.file' => 'The uploaded file is invalid.',
            'file.max' => 'File size must not exceed 2MB.',
            'file.mimes' => 'Only JPG, PNG, and PDF files are allowed.',
            'category.in' => 'Invalid file category.',
        ];
    }

    /**
     * Validate file content to prevent script injection
     */
    private function validateFileContent(UploadedFile $file, callable $fail): void
    {
        $content = $file->get();
        $mimeType = $file->getMimeType();

        // Check for script patterns in files
        if ($this->containsMaliciousContent($content, $mimeType)) {
            $fail('File contains potentially malicious content.');
        }

        // Additional checks for images
        if (str_starts_with($mimeType, 'image/')) {
            $this->validateImageContent($file, $fail);
        }

        // Additional checks for PDFs
        if ($mimeType === 'application/pdf') {
            $this->validatePdfContent($file, $fail);
        }
    }

    /**
     * Check for malicious content patterns
     */
    private function containsMaliciousContent(string $content, string $mimeType): bool
    {
        // Common malicious patterns
        $maliciousPatterns = [
            '/<\?php/i',
            '/<script/i',
            '/javascript:/i',
            '/vbscript:/i',
            '/onload=/i',
            '/onerror=/i',
            '/eval\(/i',
            '/exec\(/i',
            '/system\(/i',
            '/shell_exec\(/i',
            '/passthru\(/i',
        ];

        foreach ($maliciousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate image content
     */
    private function validateImageContent(UploadedFile $file, callable $fail): void
    {
        // Get image info
        $imageInfo = @getimagesize($file->getPathname());
        
        if (!$imageInfo) {
            $fail('Invalid image file.');
            return;
        }

        // Check for embedded scripts in EXIF data
        $exifData = @exif_read_data($file->getPathname());
        if ($exifData && isset($exifData['COMMENT'])) {
            if ($this->containsMaliciousContent($exifData['COMMENT'], 'text/plain')) {
                $fail('Image contains potentially malicious content in metadata.');
            }
        }

        // Validate image dimensions (prevent extremely large images)
        list($width, $height) = $imageInfo;
        if ($width > 4000 || $height > 4000) {
            $fail('Image dimensions too large. Maximum allowed is 4000x4000 pixels.');
        }
    }

    /**
     * Validate PDF content
     */
    private function validatePdfContent(UploadedFile $file, callable $fail): void
    {
        // Check PDF header
        $handle = fopen($file->getPathname(), 'r');
        $header = fread($handle, 4);
        fclose($handle);

        if ($header !== '%PDF') {
            $fail('Invalid PDF file.');
            return;
        }

        // Check for JavaScript in PDF (common attack vector)
        $content = $file->get();
        if (preg_match('/\/JavaScript\s*<<|\/JS\s*<<|\/AA\s*<</i', $content)) {
            $fail('PDF contains potentially malicious JavaScript content.');
        }
    }

    /**
     * Get secure filename
     */
    public function getSecureFilename(): string
    {
        $file = $this->file('file');
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        
        // Generate random filename
        $randomString = bin2hex(random_bytes(16));
        $timestamp = now()->format('YmdHis');
        
        return "{$timestamp}_{$randomString}.{$extension}";
    }

    /**
     * Get file storage path (outside public root)
     */
    public function getStoragePath(): string
    {
        $category = $this->input('category', 'general');
        $user = $this->user();
        
        // Store in secure directory outside public root
        return "secure_uploads/{$user->school_id}/{$category}";
    }

    /**
     * Get full file path
     */
    public function getFullFilePath(): string
    {
        return $this->getStoragePath() . '/' . $this->getSecureFilename();
    }
}
