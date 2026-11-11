<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Password;

/**
 * "¿Olvidaste tu contraseña?": el enlace para poner una nueva llega por correo. Mientras el hosting no tenga
 * un correo configurado nada saldría del servidor, así que la página no promete un correo que no va a llegar:
 * dice a quién pedirle la contraseña nueva.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    /** Con MAIL_MAILER en "log" o "array" (como viene de fábrica) los correos se anotan, no se envían. */
    public static function canSendMail(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    public function request(): void
    {
        if (! self::canSendMail()) {
            return;
        }

        parent::request();
    }

    public function content(Schema $schema): Schema
    {
        if (self::canSendMail()) {
            return parent::content($schema);
        }

        return $schema->components([
            View::make('filament.auth.ask-the-admin'),
        ]);
    }

    protected function getEmailFormComponent(): Component
    {
        /** @var TextInput $email */
        $email = parent::getEmailFormComponent();

        return $email->helperText('Te llegará un enlace para poner una contraseña nueva. Si no usas ese correo, pídesela al administrador: la cambia en Configuración, Usuarios.');
    }

    /**
     * La misma respuesta exista o no el correo, y también si se pidió hace un momento: así esta página, que
     * cualquiera puede abrir, no sirve para averiguar quién tiene usuario en el sistema.
     */
    protected function getFailureNotification(string $status): ?Notification
    {
        $this->form->fill();

        return $this->getSentNotification(Password::RESET_LINK_SENT);
    }
}
