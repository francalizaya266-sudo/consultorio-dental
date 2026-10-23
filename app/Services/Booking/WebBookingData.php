<?php

namespace App\Services\Booking;

use App\Enums\AppointmentOrigin;
use App\Enums\PatientRelationship;
use Carbon\CarbonInterface;

/** Lo que el paciente llenó en el flujo de reserva web (máximo 4 pasos). */
final readonly class WebBookingData
{
    public function __construct(
        public int $doctorId,
        public int $treatmentId,
        public CarbonInterface $startsAt,
        public string $phone,
        // Familiar ya registrado (solo se puede elegir desde un dispositivo recordado).
        public ?int $patientId = null,
        // Paciente nuevo: "para mí" o un familiar a cargo.
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?CarbonInterface $birthDate = null,
        public PatientRelationship $relationship = PatientRelationship::Self,
        public bool $acceptsPrivacy = false,
        public bool $acceptsPromotions = false,
        public ?AppointmentOrigin $origin = null,
        public ?string $promoCode = null,
        public ?string $note = null,
        // La campaña desde cuya página llegó (sin código de promoción).
        public ?string $campaignSlug = null,
    ) {}
}
