<?php

namespace App\Http\Controllers\Reports;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\ScheduleBlock;
use App\Models\User;
use App\Services\Agenda\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** La agenda de un día en una hoja: las citas de cada doctor, para tenerla en papel si se cae internet o la luz. */
class AgendaDayController extends Controller
{
    public function print(Request $request, AvailabilityService $availability): View
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->can('viewAny', Appointment::class), 403);

        $day = CarbonImmutable::parse($request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? today())->startOfDay();

        // Recepción y administración imprimen la de todos; el doctor, solo la suya.
        $doctors = Doctor::query()
            ->active()
            ->when(! $user->managesAllAgendas(), fn (Builder $query) => $query->whereKey($user->doctorId() ?? 0))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $appointments = Appointment::query()
            ->with(['patient.account', 'treatment'])
            ->whereIn('doctor_id', $doctors->modelKeys())
            ->whereBetween('starts_at', [$day, $day->endOfDay()])
            ->where('status', '!=', AppointmentStatus::Cancelled)
            ->orderBy('starts_at')
            ->get()
            ->groupBy('doctor_id');

        return view('reports.agenda-day-print', [
            'day' => $day,
            'total' => $appointments->flatten()->count(),
            // Quien no atiende ese día ni tiene citas no ocupa lugar en la hoja.
            'agendas' => $doctors
                ->map(fn (Doctor $doctor) => [
                    'doctor' => $doctor,
                    'hours' => $availability->workingRanges($doctor, $day)
                        ->map(fn (array $range) => 'de '.$range['start']->format('H:i').' a '.$range['end']->format('H:i'))
                        ->join(', ', ' y '),
                    'blocks' => ScheduleBlock::query()->affecting($doctor, $day, $day->endOfDay())->orderBy('starts_at')->get(),
                    'appointments' => $appointments->get($doctor->getKey(), collect()),
                ])
                ->filter(fn (array $agenda) => $agenda['hours'] !== '' || $agenda['appointments']->isNotEmpty())
                ->values(),
        ]);
    }
}
