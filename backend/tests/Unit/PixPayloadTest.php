<?php

namespace Tests\Unit;

use App\Support\Pix\PixKey;
use App\Support\Pix\PixPayload;
use PHPUnit\Framework\TestCase;

class PixPayloadTest extends TestCase
{
    /** Lê o BR Code de volta: ID (2) + tamanho (2) + valor. */
    private function parse(string $payload): array
    {
        $fields = [];
        for ($i = 0; $i < strlen($payload);) {
            $id = substr($payload, $i, 2);
            $length = (int) substr($payload, $i + 2, 2);
            $fields[$id] = substr($payload, $i + 4, $length);
            $i += 4 + $length;
        }

        return $fields;
    }

    public function test_crc16_matches_ccitt_false_check_value(): void
    {
        // Valor de verificação padrão do CRC-16/CCITT-FALSE
        $this->assertSame('29B1', PixPayload::crc16('123456789'));
    }

    public function test_builds_valid_br_code(): void
    {
        $payload = (new PixPayload('11222333000181', 'JetCar Mecânica Automotiva Ltda', 'Porto Alegre', 123456, 'OS-00042'))->build();
        $fields = $this->parse($payload);

        $this->assertSame('01', $fields['00']);
        $this->assertSame('0014br.gov.bcb.pix011411222333000181', $fields['26']);
        $this->assertSame('986', $fields['53']);
        $this->assertSame('1234.56', $fields['54']);
        $this->assertSame('BR', $fields['58']);
        $this->assertSame('JETCAR MECANICA AUTOMOTIV', $fields['59']); // sem acento, até 25
        $this->assertSame('PORTO ALEGRE', $fields['60']);
        $this->assertSame('0507OS00042', $fields['62']);
        // CRC confere com o restante do código
        $this->assertSame(PixPayload::crc16(substr($payload, 0, -4)), $fields['63']);
    }

    public function test_without_amount_or_txid(): void
    {
        $fields = $this->parse((new PixPayload('+5551999998888', 'Oficina', 'POA'))->build());

        $this->assertArrayNotHasKey('54', $fields);
        $this->assertSame('0503***', $fields['62']);
    }

    public function test_key_normalization(): void
    {
        $this->assertSame('11222333000181', PixKey::normalize('cnpj', '11.222.333/0001-81'));
        $this->assertNull(PixKey::normalize('cnpj', '11.222.333/0001-82'));
        $this->assertSame('52998224725', PixKey::normalize('cpf', '529.982.247-25'));
        $this->assertSame('+5551999998888', PixKey::normalize('phone', '(51) 99999-8888'));
        $this->assertSame('+5551999998888', PixKey::normalize('phone', '+55 51 99999-8888'));
        $this->assertNull(PixKey::normalize('phone', '(51) 3333-4444'));
        $this->assertSame('pix@jetcar.com.br', PixKey::normalize('email', 'PIX@JetCar.com.br'));
        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', PixKey::normalize('random', '123E4567-E89B-12D3-A456-426614174000'));
        $this->assertNull(PixKey::normalize('random', 'nao-e-uuid'));
    }
}
