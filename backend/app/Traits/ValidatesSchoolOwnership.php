<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Trait untuk validasi kepemilikan data cross-school
 * Mencegah akses data antar sekolah (multi-tenant security)
 */
trait ValidatesSchoolOwnership
{
    /**
     * Validasi bahwa model milik sekolah yang sama dengan user yang login
     *
     * @param  Model  $model  Model yang akan divalidasi
     * @param  string  $errorMessage  Pesan error custom (opsional)
     *
     * @throws AccessDeniedHttpException jika school_id tidak cocok
     */
    protected function validateSchoolOwnership(Model $model, ?string $errorMessage = null): void
    {
        $user = auth()->user();

        // Skip validation untuk Super Admin
        if ($user && method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
            return;
        }

        if ($user && $user->school_id !== $model->school_id) {
            throw new AccessDeniedHttpException(
                $errorMessage ?? 'Akses ditolak: Data tidak ditemukan atau bukan milik sekolah Anda.'
            );
        }
    }

    /**
     * Validasi multiple models sekaligus
     *
     * @param  array  $models  Array of models to validate
     * @param  string  $errorMessage  Custom error message
     */
    protected function validateMultipleSchoolOwnership(array $models, ?string $errorMessage = null): void
    {
        foreach ($models as $model) {
            if ($model instanceof Model) {
                $this->validateSchoolOwnership($model, $errorMessage);
            }
        }
    }

    /**
     * Validasi bahwa ID yang diberikan milik sekolah yang sama
     *
     * @param  string  $modelClass  Fully qualified class name
     * @param  int  $id  ID yang akan divalidasi
     * @param  string  $errorMessage  Custom error message
     * @return Model Instance dari model yang sudah tervalidasi
     */
    protected function validateSchoolOwnershipById(string $modelClass, int $id, ?string $errorMessage = null): Model
    {
        $model = $modelClass::findOrFail($id);
        $this->validateSchoolOwnership($model, $errorMessage);

        return $model;
    }
}
