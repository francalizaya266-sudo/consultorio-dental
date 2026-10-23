<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\ConfirmationChannel;
use App\Enums\Dentition;
use App\Enums\DiscountType;
use App\Enums\MessageTemplateKey;
use App\Enums\OdontogramType;
use App\Enums\PatientRelationship;
use App\Enums\PaymentMethod;
use App\Enums\PlanItemStatus;
use App\Enums\ToothState;
use App\Enums\TreatmentPlanStatus;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Charge;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\Icd10Code;
use App\Models\OdontogramCondition;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Treatment;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Datos de demostración para probar el panel en local.
 * Usuarios: admin@consultorio.test, recepcion@consultorio.test,
 * dra.torres@consultorio.test y dr.ramos@consultorio.test (clave: password).
 */
class DemoSeeder extends Seeder
{
    public function run(WhatsAppOutbox $outbox): void
    {
        Setting::set('clinic.name', 'Consultorio Dental Sonrisas');
        Setting::set('clinic.address', 'Av. Larco 123, Miraflores');
        Setting::set('clinic.phone', '(01) 445 1234');

        $admin = User::factory()->admin(isDentist: true)->create(['name' => 'Administrador', 'email' => 'admin@consultorio.test']);
        $reception = User::factory()->reception()->create(['name' => 'Recepción', 'email' => 'recepcion@consultorio.test']);

        $torres = $this->doctor('Dra. Ana Torres', 'dra.torres@consultorio.test', 'Odontología general', '#0ea5e9', '24581');
        $ramos = $this->doctor('Dr. Luis Ramos', 'dr.ramos@consultorio.test', 'Ortodoncia', '#a855f7', '19873');

        $treatments = Treatment::all()->keyBy('slug');
        $prices = [
            'evaluacion-o-primera-consulta' => 50, 'limpieza-dental' => 120, 'curacion-con-resina' => 150,
            'extraccion-simple' => 150, 'blanqueamiento' => 450, 'endodoncia' => 600,
            'control-de-ortodoncia' => 150, 'colocacion-de-brackets' => 2500, 'implante' => 3500,
        ];

        foreach ($prices as $slug => $price) {
            $treatments[$slug]?->update(['price_from' => $price]);
        }

        $ids = fn (array $slugs) => array_map(fn (string $slug) => $treatments[$slug]->id, $slugs);

        $torres->treatments()->sync($ids([
            'evaluacion-o-primera-consulta', 'limpieza-dental', 'curacion-con-resina', 'extraccion-simple', 'blanqueamiento', 'endodoncia', 'implante',
        ]));
        $ramos->treatments()->sync($ids(['evaluacion-o-primera-consulta', 'limpieza-dental', 'colocacion-de-brackets']));
        $ramos->treatments()->attach($treatments['control-de-ortodoncia']->id, ['duration_minutes' => 20]);

        // Una familia: la mamá tiene el celular y tiene a cargo a su hijo y a su madre.
        $quispe = Account::factory()->verified()->create(['phone' => '+51987654321', 'accepts_promotions' => true]);
        $maria = Patient::factory()->create(['account_id' => $quispe->id, 'first_name' => 'María', 'last_name' => 'Quispe Huamán', 'birth_date' => '1988-04-12']);
        $mateo = Patient::factory()->dependentOf($quispe)->create(['first_name' => 'Mateo', 'last_name' => 'Rojas Quispe', 'birth_date' => '2018-09-03']);
        $rosa = Patient::factory()->dependentOf($quispe, PatientRelationship::Parent)->create(['first_name' => 'Rosa', 'last_name' => 'Huamán Torres', 'birth_date' => '1956-01-20']);

        $others = Patient::factory()->count(6)->create();

        $today = CarbonImmutable::today();
        $workday = fn (int $days) => $this->nextWorkday($today->addDays($days));

        $evaluation = $treatments['evaluacion-o-primera-consulta'];
        $cleaning = $treatments['limpieza-dental'];
        $resin = $treatments['curacion-con-resina'];
        $ortho = $treatments['control-de-ortodoncia'];

        // Agenda de hoy y los próximos días con distintos estados.
        $this->appointment($maria, $torres, $cleaning, $workday(0)->setTime(9, 0), AppointmentStatus::Confirmed);
        $this->appointment($others[0], $torres, $evaluation, $workday(0)->setTime(10, 0), AppointmentStatus::CheckedIn);
        $this->appointment($others[1], $torres, $resin, $workday(0)->setTime(15, 0), AppointmentStatus::Pending);
        $this->appointment($others[2], $ramos, $ortho, $workday(0)->setTime(9, 30), AppointmentStatus::Confirmed, BookingChannel::Doctor);
        $this->appointment($mateo, $ramos, $evaluation, $workday(1)->setTime(16, 0), AppointmentStatus::Pending)
            ->update(['unconfirmed_flagged_at' => now()]);
        $this->appointment($others[3], $torres, $evaluation, $workday(2)->setTime(11, 0), AppointmentStatus::Pending);
        $web = $this->appointment($others[4], $torres, $cleaning, $workday(3)->setTime(9, 0), AppointmentStatus::Pending);
        $outbox->queueForAppointment($web, MessageTemplateKey::BookingConfirmation);

        // Historia de una paciente atendida: consulta, odontograma, plan y pago.
        $past = $this->appointment($rosa, $torres, $evaluation, $today->subDays(7)->setTime(10, 0), AppointmentStatus::Completed);
        $consultation = Consultation::create([
            'patient_id' => $rosa->id,
            'doctor_id' => $torres->id,
            'appointment_id' => $past->id,
            'attended_at' => $past->starts_at,
            'chief_complaint' => 'Refiere dolor al masticar en pieza 36.',
            'clinical_exam' => 'Extraoral: sin alteraciones. Intraoral: caries profunda en 36, restauración antigua en 46.',
            'procedures' => 'Evaluación completa. Se toma radiografía periapical de 36.',
            'indications' => 'Evitar masticar del lado izquierdo hasta el tratamiento.',
            'next_steps' => 'Endodoncia de 36 en dos sesiones y luego resina.',
            'closed_at' => $past->starts_at->addMinutes(40),
        ]);
        $rosa->medicalBackground()->create([
            'conditions' => ['hypertension'],
            'allergies' => 'Penicilina',
            'medications' => 'Losartán 50 mg cada mañana',
            'updated_by_user_id' => $torres->user_id,
        ]);
        $consultation->diagnoses()->create(['icd10_code_id' => Icd10Code::where('code', 'K02.1')->value('id'), 'tooth' => '36']);

        $odontogram = $rosa->odontograms()->create([
            'consultation_id' => $consultation->id,
            'doctor_id' => $torres->id,
            'type' => OdontogramType::Initial,
            'dentition' => Dentition::Permanent,
        ]);
        $conditions = OdontogramCondition::all()->keyBy('code');
        $odontogram->entries()->createMany([
            ['tooth' => 36, 'surface' => 'O', 'odontogram_condition_id' => $conditions['caries']->id, 'state' => ToothState::Bad, 'abbreviation' => 'CD'],
            ['tooth' => 46, 'surface' => 'O', 'odontogram_condition_id' => $conditions['restauracion']->id, 'state' => ToothState::Good, 'abbreviation' => 'AM'],
            ['tooth' => 18, 'odontogram_condition_id' => $conditions['ausente']->id, 'state' => ToothState::Good],
            ['tooth' => 26, 'odontogram_condition_id' => $conditions['corona']->id, 'state' => ToothState::Good, 'abbreviation' => 'CM'],
            ['tooth' => 21, 'odontogram_condition_id' => $conditions['tratamiento_pulpar']->id, 'state' => ToothState::Good, 'abbreviation' => 'TC'],
            ['tooth' => 38, 'odontogram_condition_id' => $conditions['remanente_radicular']->id, 'state' => ToothState::Bad, 'abbreviation' => 'RR'],
            ['tooth' => 14, 'surface' => 'M', 'odontogram_condition_id' => $conditions['caries']->id, 'state' => ToothState::Bad, 'abbreviation' => 'CE'],
        ]);

        $plan = $rosa->treatmentPlans()->create([
            'doctor_id' => $torres->id,
            'consultation_id' => $consultation->id,
            'title' => 'Plan inicial',
            'status' => TreatmentPlanStatus::Accepted,
            'accepted_at' => now()->subDays(7),
        ]);
        [$endodontics] = $plan->items()->createMany([
            ['treatment_id' => $treatments['endodoncia']->id, 'description' => 'Endodoncia', 'tooth' => '36', 'unit_price' => 600, 'sessions_planned' => 2, 'status' => PlanItemStatus::Pending, 'sort_order' => 1],
            ['treatment_id' => $resin->id, 'description' => 'Curación con resina', 'tooth' => '36', 'surfaces' => 'O', 'unit_price' => 150, 'status' => PlanItemStatus::Pending, 'sort_order' => 2],
            ['treatment_id' => $treatments['extraccion-simple']->id, 'description' => 'Extracción simple', 'tooth' => '38', 'unit_price' => 150, 'status' => PlanItemStatus::Pending, 'sort_order' => 3],
        ]);

        // Primera sesión de la endodoncia, ya agendada desde el plan.
        $this->appointment($rosa, $torres, $treatments['endodoncia'], $workday(1)->setTime(11, 0), AppointmentStatus::Confirmed, BookingChannel::Reception)
            ->update(['treatment_plan_item_id' => $endodontics->id]);

        $prescription = $consultation->prescriptions()->create([
            'patient_id' => $rosa->id,
            'doctor_id' => $torres->id,
            'issued_at' => $consultation->closed_at,
            'indications' => $consultation->indications,
        ]);
        $prescription->items()->create(['medication' => 'Paracetamol 500 mg', 'instructions' => '1 tableta cada 8 horas por 3 días, si hay dolor']);

        Charge::create([
            'patient_id' => $rosa->id, 'appointment_id' => $past->id, 'treatment_id' => $evaluation->id, 'doctor_id' => $torres->id,
            'description' => 'Evaluación', 'amount' => 50, 'charged_at' => $past->starts_at, 'created_by_user_id' => $reception->id,
        ]);
        Payment::create([
            'patient_id' => $rosa->id, 'appointment_id' => $past->id, 'treatment_id' => $evaluation->id, 'doctor_id' => $torres->id,
            'amount' => 50, 'method' => PaymentMethod::Yape, 'paid_at' => $past->starts_at->addHour(), 'received_by_user_id' => $reception->id,
        ]);

        Campaign::create([
            'name' => 'Blanqueamiento 20% de descuento',
            'slug' => 'blanqueamiento-20',
            'summary' => 'Sonrisa más blanca en una sesión. Válido hasta fin de mes.',
            'treatment_id' => $treatments['blanqueamiento']->id,
            'discount_type' => DiscountType::Percent,
            'discount_value' => 20,
            'starts_at' => $today->startOfMonth(),
            'ends_at' => $today->endOfMonth(),
            'promo_code' => 'BLANCO20',
            'max_redemptions' => 30,
            'whatsapp_text' => 'Hola, quiero la promoción de blanqueamiento 20%',
        ]);

        $this->command?->info("Demo lista. Entra a /panel con {$admin->email} / password");
    }

    private function doctor(string $name, string $email, string $specialty, string $color, string $cop): Doctor
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email, 'role' => UserRole::Doctor]);

        return Doctor::factory()
            ->withSchedule()
            ->create(['user_id' => $user->id, 'name' => $name, 'specialty' => $specialty, 'agenda_color' => $color, 'cop_number' => $cop]);
    }

    private function appointment(
        Patient $patient,
        Doctor $doctor,
        Treatment $treatment,
        CarbonImmutable $startsAt,
        AppointmentStatus $status,
        BookingChannel $via = BookingChannel::Web,
    ): Appointment {
        $minutes = $doctor->durationFor($treatment);

        return Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'treatment_id' => $treatment->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes($minutes),
            'buffer_minutes' => $treatment->bufferMinutes(),
            'status' => $status,
            'booked_via' => $via,
            'is_new_patient' => $treatment->is_evaluation,
            'confirmed_at' => $status === AppointmentStatus::Pending ? null : $startsAt->subDay(),
            'confirmed_via' => $status === AppointmentStatus::Pending ? null : ConfirmationChannel::PatientLink,
            'checked_in_at' => in_array($status, [AppointmentStatus::CheckedIn, AppointmentStatus::Completed], true) ? $startsAt->subMinutes(5) : null,
            'started_at' => $status === AppointmentStatus::Completed ? $startsAt : null,
            'finished_at' => $status === AppointmentStatus::Completed ? $startsAt->addMinutes($minutes + 5) : null,
        ]);
    }

    private function nextWorkday(CarbonImmutable $date): CarbonImmutable
    {
        return $date->isSunday() ? $date->addDay() : $date;
    }
}
