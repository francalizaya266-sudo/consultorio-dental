<?php

namespace App\Exceptions;

use RuntimeException;

/** Error en la historia clínica con un mensaje en español simple, listo para mostrar. */
class ClinicalException extends RuntimeException
{
    public static function noDoctorProfile(): self
    {
        return new self('Tu usuario no tiene perfil de doctor. Pide al administrador que lo active en "Doctores".');
    }

    public static function notYourAppointment(): self
    {
        return new self('Esta cita es de otro doctor.');
    }

    public static function notAuthor(): self
    {
        return new self('Solo el doctor que atendió puede escribir en esta atención.');
    }

    public static function closed(): self
    {
        return new self('Esta atención ya se cerró y no se puede cambiar.');
    }

    public static function reopenExpired(): self
    {
        return new self('Solo se puede reabrir una atención hasta 24 horas después de cerrarla.');
    }

    public static function unknownTooth(int $tooth): self
    {
        return new self("La pieza {$tooth} no está en este odontograma.");
    }

    public static function needsSurface(): self
    {
        return new self('Este hallazgo se marca en una superficie de la pieza.');
    }

    public static function needsSpan(): self
    {
        return new self('Este hallazgo abarca varias piezas: toca la primera y luego la última.');
    }

    public static function spanAcrossArches(): self
    {
        return new self('La primera y la última pieza deben estar en la misma arcada.');
    }

    public static function cannotMarkTreated(): self
    {
        return new self('Este hallazgo no se marca como tratado: quítalo y marca lo que corresponda.');
    }

    public static function teethOutsideDentition(): self
    {
        return new self('Hay marcas en piezas que no existen en esa dentición. Quítalas antes de cambiarla.');
    }

    public static function invalidSignature(): self
    {
        return new self('No se pudo leer la firma. Pide que vuelva a firmar.');
    }

    public static function agreedPrice(): self
    {
        return new self('El paciente ya aceptó este precio. Para cambiarlo, cancela el procedimiento y agrega el correcto.');
    }

    public static function nothingToAccept(): self
    {
        return new self('Este plan no tiene nada nuevo que aceptar.');
    }

    public static function invalidCompanion(): self
    {
        return new self('En un 2x1, la otra persona tiene que ser otro paciente, y el paquete tiene que ser 2x1.');
    }

    public static function invalidPaymentTerms(): self
    {
        return new self('Revisa la forma de pago: la inicial debe ser menor que el total y las cuotas, entre 1 y 24.');
    }
}
