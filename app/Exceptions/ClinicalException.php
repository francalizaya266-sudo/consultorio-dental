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

    public static function teethOutsideDentition(): self
    {
        return new self('Hay marcas en piezas que no existen en esa dentición. Quítalas antes de cambiarla.');
    }
}
