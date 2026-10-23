<?php

namespace App\Policies;

use App\Models\TreatmentPlanInstallment;
use App\Models\User;

/** Cuotas de los planes: recepción y administración las siguen para cobrarlas. Las arma la firma del plan. */
class TreatmentPlanInstallmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->managesAllAgendas();
    }

    public function view(User $user, TreatmentPlanInstallment $model): bool
    {
        return $user->managesAllAgendas();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, TreatmentPlanInstallment $model): bool
    {
        return false;
    }

    public function delete(User $user, TreatmentPlanInstallment $model): bool
    {
        return false;
    }
}
