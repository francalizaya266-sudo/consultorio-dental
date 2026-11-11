<?php

namespace Tests\Unit\Support;

use App\Support\Phone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    public static function peruvianFormats(): array
    {
        return [
            'nueve dígitos' => ['987654321'],
            'con espacios' => ['987 654 321'],
            'con código sin +' => ['51987654321'],
            'con + y guiones' => ['+51 987-654-321'],
        ];
    }

    #[DataProvider('peruvianFormats')]
    public function test_normalizes_peruvian_numbers_to_e164(string $input): void
    {
        $this->assertSame('+51987654321', Phone::normalize($input));
    }

    public function test_keeps_foreign_numbers_with_their_country_code(): void
    {
        $this->assertSame('+5491123456789', Phone::normalize('+54 9 11 2345-6789'));
    }

    public function test_rejects_numbers_that_are_too_short(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Phone::normalize('12345');
    }

    public function test_only_accepts_peruvian_mobiles_starting_with_9(): void
    {
        $this->assertTrue(Phone::isValid('987654321'));
        $this->assertFalse(Phone::isValid('014567890'));
    }

    public function test_ten_digits_are_not_a_mobile_and_the_message_says_what_is_wrong(): void
    {
        $this->assertNull(Phone::problem('987 654 321'));
        $this->assertNull(Phone::problem('51987654321'));
        $this->assertNull(Phone::problem('+51 987 654 321'));
        $this->assertNull(Phone::problem('+1 555 123 4567'));

        $this->assertSame('El celular tiene 9 dígitos y escribiste 10.', Phone::problem('9876543210'));
        $this->assertSame('El celular tiene 9 dígitos y escribiste 10.', Phone::problem('098 765 4321'));
        $this->assertSame('El celular tiene 9 dígitos y escribiste 8.', Phone::problem('98765432'));
        $this->assertSame('El celular empieza con 9, por ejemplo 987 654 321.', Phone::problem('123456789'));
        $this->assertFalse(Phone::isValid('1234567890'));
    }

    public function test_formats_for_display_and_for_whatsapp_links(): void
    {
        $this->assertSame('987 654 321', Phone::display('+51987654321'));
        $this->assertSame('51987654321', Phone::forWhatsApp('987654321'));
    }
}
