<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SENT = 'Te enviamos por correo el enlace para cambiar tu contraseña.';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_the_login_page_offers_to_recover_a_forgotten_password(): void
    {
        $this->get(Filament::getLoginUrl())
            ->assertOk()
            ->assertSee('¿Olvidaste tu contraseña?')
            ->assertSee(Filament::getRequestPasswordResetUrl());
    }

    public function test_while_the_server_cannot_send_mail_the_page_says_who_to_ask_and_sends_nothing(): void
    {
        config(['mail.default' => 'log']);
        $user = User::factory()->create();

        $this->get(Filament::getRequestPasswordResetUrl())
            ->assertOk()
            ->assertSee('Pídele una contraseña nueva al administrador.')
            ->assertSee('Volver al inicio de sesión')
            ->assertDontSee('Enviarme el enlace');

        Livewire::test(RequestPasswordReset::class)
            ->set('data.email', $user->email)
            ->call('request')
            ->assertNotNotified();

        Notification::assertNothingSent();
    }

    public function test_with_mail_set_up_the_link_reaches_the_user_and_lets_them_set_a_new_password(): void
    {
        config(['mail.default' => 'smtp']);
        $user = User::factory()->create(['email' => 'recepcion@clinica.test']);

        $this->get(Filament::getRequestPasswordResetUrl())
            ->assertOk()
            ->assertSee('Enviarme el enlace')
            ->assertSee('pídesela al administrador')
            ->assertDontSee('todavía no está activado');

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'recepcion@clinica.test'])
            ->call('request')
            ->assertNotified(self::SENT);

        $sent = Notification::sent($user, ResetPasswordNotification::class);
        $this->assertCount(1, $sent);

        $this->get($sent[0]->url)->assertOk()->assertSee('Pon tu contraseña nueva');

        Livewire::withQueryParams(['email' => 'recepcion@clinica.test', 'token' => $sent[0]->token])
            ->test(ResetPassword::class)
            ->fillForm(['password' => 'clave-nueva-2026', 'passwordConfirmation' => 'clave-nueva-2026'])
            ->call('resetPassword')
            ->assertHasNoFormErrors()
            ->assertNotified('Tu contraseña se cambió.')
            ->assertRedirect(Filament::getLoginUrl());

        $this->assertTrue(Hash::check('clave-nueva-2026', $user->refresh()->password));
    }

    public function test_two_passwords_that_do_not_match_are_not_saved_and_the_page_says_so_plainly(): void
    {
        $user = User::factory()->create(['email' => 'recepcion@clinica.test']);
        $token = Password::broker()->createToken($user);

        Livewire::withQueryParams(['email' => 'recepcion@clinica.test', 'token' => $token])
            ->test(ResetPassword::class)
            ->fillForm(['password' => 'clave-nueva-2026', 'passwordConfirmation' => 'clave-nueva-2027'])
            ->call('resetPassword')
            ->assertHasFormErrors(['password' => 'Las dos contraseñas no coinciden.']);

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_an_email_that_belongs_to_nobody_gets_the_same_answer_and_no_mail(): void
    {
        config(['mail.default' => 'smtp']);
        User::factory()->create(['email' => 'recepcion@clinica.test']);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'nadie@clinica.test'])
            ->call('request')
            ->assertNotified(self::SENT)
            ->assertSchemaStateSet(['email' => null]);

        Notification::assertNothingSent();
    }

    public function test_asking_again_right_away_answers_the_same_and_sends_a_single_mail(): void
    {
        config(['mail.default' => 'smtp']);
        $user = User::factory()->create(['email' => 'recepcion@clinica.test']);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'recepcion@clinica.test'])
            ->call('request')
            ->fillForm(['email' => 'recepcion@clinica.test'])
            ->call('request')
            ->assertNotified(self::SENT)
            ->assertSchemaStateSet(['email' => null]);

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    }

    public function test_someone_whose_panel_access_is_turned_off_gets_no_link(): void
    {
        config(['mail.default' => 'smtp']);
        User::factory()->create(['email' => 'antes@clinica.test', 'is_active' => false]);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'antes@clinica.test'])
            ->call('request')
            ->assertNotified(self::SENT);

        Notification::assertNothingSent();
    }

    public function test_the_mail_is_written_in_spanish(): void
    {
        $notification = new ResetPasswordNotification('abc123');
        $notification->url = 'https://clinica.test/panel/password-reset/reset?token=abc123';

        $mail = $notification->toMail(User::factory()->make());
        $text = strip_tags((string) $mail->render());

        $this->assertSame('Recupera tu contraseña', $mail->subject);
        $this->assertSame('Poner una contraseña nueva', $mail->actionText);
        $this->assertStringContainsString('¡Hola!', $text);
        $this->assertStringContainsString('El enlace sirve durante 60 minutos.', $text);
        $this->assertStringContainsString('Si no lo pediste tú, no hagas nada: tu contraseña sigue igual.', $text);
        $this->assertStringContainsString('Si el botón "Poner una contraseña nueva" no funciona', $text);
        $this->assertStringContainsString('Saludos,', $text);
        $this->assertStringContainsString('Todos los derechos reservados.', $text);
    }
}
