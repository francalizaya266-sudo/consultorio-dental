<?php

namespace App\Policies;

use App\Models\ClinicalFile;
use App\Models\User;

/** Radiografías y fotos: parte de la historia clínica. Quitar una no la borra del disco. */
class ClinicalFilePolicy
{
    public function __construct(private readonly PatientPolicy $patients) {}

    public function viewAny(User $user): bool
    {
        return $user->canSeeClinical();
    }

    public function view(User $user, ClinicalFile $file): bool
    {
        return $this->patients->viewClinical($user, $file->patient);
    }

    public function create(User $user): bool
    {
        return $user->canSeeClinical() && $user->doctorId() !== null;
    }

    public function update(User $user, ClinicalFile $file): bool
    {
        return $this->patients->updateClinical($user, $file->patient);
    }

    /** Se quita de la historia (borrado suave): el archivo sigue guardado y queda en la auditoría. */
    public function delete(User $user, ClinicalFile $file): bool
    {
        return $this->patients->updateClinical($user, $file->patient);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ClinicalFile $file): bool
    {
        return $user->isAdmin() && $user->canSeeClinical();
    }

    public function forceDelete(User $user, ClinicalFile $file): bool
    {
        return false;
    }
}
