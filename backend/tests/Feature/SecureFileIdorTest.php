<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SecureFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SEC-4: Secure file endpoints must be scoped to owner (user_id + school_id).
 *
 * Regression guards: a user must NOT be able to read or delete another
 * user's file via show / download / destroy / validate, even within the
 * same school or when guessing the storage path.
 */
class SecureFileIdorTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $owner;
    private User $intruder;
    private SecureFile $file;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('secure_uploads');

        $this->school = School::factory()->create();
        $this->owner = User::factory()->student()->create(['school_id' => $this->school->id]);
        $this->intruder = User::factory()->student()->create(['school_id' => $this->school->id]);

        $this->file = SecureFile::create([
            'user_id' => $this->owner->id,
            'school_id' => $this->school->id,
            'storage_path' => "secure_uploads/{$this->school->id}/documents/victim.pdf",
            'original_name' => 'victim.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1234,
            'category' => 'document',
        ]);

        Storage::disk('secure_uploads')->put($this->file->storage_path, '%PDF victim content');
    }

    #[Test]
    public function owner_can_access_own_file(): void
    {
        $response = $this->actingAs($this->owner)
            ->getJson("/api/v1/files/{$this->file->storage_path}");

        $response->assertOk();
        $response->assertJsonPath('data.file_id', $this->file->id);
    }

    #[Test]
    public function same_school_user_cannot_read_others_file(): void
    {
        $this->actingAs($this->intruder)
            ->getJson("/api/v1/files/{$this->file->storage_path}")
            ->assertNotFound();
    }

    #[Test]
    public function other_school_user_cannot_read_others_file(): void
    {
        $otherSchoolUser = User::factory()->student()->create();

        $this->actingAs($otherSchoolUser)
            ->getJson("/api/v1/files/{$this->file->storage_path}")
            ->assertNotFound();
    }

    #[Test]
    public function same_school_user_cannot_download_others_file(): void
    {
        $this->actingAs($this->intruder)
            ->getJson("/api/v1/files/{$this->file->storage_path}/download")
            ->assertNotFound();
    }

    #[Test]
    public function same_school_user_cannot_delete_others_file(): void
    {
        $this->actingAs($this->intruder)
            ->deleteJson("/api/v1/files/{$this->file->storage_path}")
            ->assertNotFound();

        $this->assertDatabaseHas('secure_files', ['id' => $this->file->id]);
        Storage::disk('secure_uploads')->assertExists($this->file->storage_path);
    }

    #[Test]
    public function same_school_user_cannot_validate_others_file(): void
    {
        $this->actingAs($this->intruder)
            ->postJson("/api/v1/files/{$this->file->storage_path}/validate", ['hash' => 'abc'])
            ->assertNotFound();
    }

    #[Test]
    public function index_lists_only_own_files(): void
    {
        $response = $this->actingAs($this->owner)
            ->getJson('/api/v1/files');

        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.files.0.file_id', $this->file->id);

        $intruderResponse = $this->actingAs($this->intruder)
            ->getJson('/api/v1/files');

        $intruderResponse->assertOk();
        $intruderResponse->assertJsonPath('data.total', 0);
    }

    #[Test]
    public function owner_can_delete_own_file(): void
    {
        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/files/{$this->file->storage_path}")
            ->assertOk();

        $this->assertDatabaseMissing('secure_files', ['id' => $this->file->id]);
        Storage::disk('secure_uploads')->assertMissing($this->file->storage_path);
    }

    #[Test]
    public function upload_records_ownership(): void
    {
        // 1x1 real PNG so MIME sniffing + getimagesize pass validation
        $realPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        $response = $this->actingAs($this->owner)
            ->post('/api/v1/files/upload', [
                'file' => UploadedFile::fake()->createWithContent('foto.png', $realPng),
                'category' => 'student_photo',
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('secure_files', [
            'user_id' => $this->owner->id,
            'school_id' => $this->school->id,
            'category' => 'student_photo',
        ]);
    }
}
