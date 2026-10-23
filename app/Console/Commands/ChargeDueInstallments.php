<?php

namespace App\Console\Commands;

use App\Services\Billing\BillingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cuotas:cargar')]
#[Description('Carga al saldo de cada paciente las cuotas de su plan que vencen hoy (o que vencieron y no se cargaron)')]
class ChargeDueInstallments extends Command
{
    public function handle(BillingService $billing): int
    {
        $charged = $billing->chargeDueInstallments();

        $this->info("Cuotas cargadas: {$charged}");

        return self::SUCCESS;
    }
}
