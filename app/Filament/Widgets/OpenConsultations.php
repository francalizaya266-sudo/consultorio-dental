<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\RefreshesWithToday;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Models\Consultation;
use App\Models\User;
use App\Services\Dashboard\DoctorDay;
use Filament\Widgets\Widget;

/**
 * Las atenciones que quien atiende dejó abiertas, en "Mi consulta" y al costado de su próximo paciente. Las de
 * otro día dicen "sin cobrar": mientras sigan abiertas no se cobra lo atendido ni avanza el plan. Solo aparece
 * si hay alguna al abrir "Hoy"; si se cierran todas mientras tanto, lo dice.
 */
class OpenConsultations extends Widget
{
    use RefreshesWithToday;

    /** Las que se ven en Hoy; el resto queda a un clic en Atenciones. */
    private const SHOWN = 3;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.open-consultations';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return NextPatient::canView() && (new DoctorDay($user->doctorId()))->openConsultations()->isNotEmpty();
    }

    /** @return array{count: int, url: string, consultations: list<array{name: string, detail: string, late: bool, url: string}>} */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $open = (new DoctorDay($user->doctorId()))->openConsultations();

        return [
            'count' => $open->count(),
            'url' => ConsultationResource::getUrl('index', ['tab' => 'open']),
            'consultations' => $open->take(self::SHOWN)->map(function (Consultation $consultation) {
                $since = $consultation->attended_at ?? $consultation->created_at;
                $late = ! $since->isToday();

                return [
                    'name' => $consultation->patient->full_name,
                    'detail' => collect([
                        $consultation->appointment?->treatment?->name,
                        'abierta '.($late ? $since->diffForHumans() : 'hoy a las '.$since->format('H:i')),
                        $late ? 'sin cobrar' : null,
                    ])->filter()->implode(' · '),
                    'late' => $late,
                    'url' => ConsultationResource::urlFor($consultation),
                ];
            })->values()->all(),
        ];
    }
}
