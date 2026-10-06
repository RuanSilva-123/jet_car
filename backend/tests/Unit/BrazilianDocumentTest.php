<?php

namespace Tests\Unit;

use App\Support\BrazilianDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BrazilianDocumentTest extends TestCase
{
    public function test_normalize_removes_punctuation_and_uppercases(): void
    {
        $this->assertSame('52998224725', BrazilianDocument::normalize('529.982.247-25'));
        $this->assertSame('12ABC34501DE35', BrazilianDocument::normalize('12.abc.345/01de-35'));
        $this->assertSame('', BrazilianDocument::normalize(null));
    }

    #[DataProvider('cpfs')]
    public function test_cpf_validation(string $cpf, bool $expected): void
    {
        $this->assertSame($expected, BrazilianDocument::isValidCpf($cpf));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function cpfs(): array
    {
        return [
            'válido' => ['52998224725', true],
            'válido 2' => ['11144477735', true],
            'dígito errado' => ['52998224724', false],
            'repetido' => ['11111111111', false],
            'curto' => ['5299822472', false],
            'com letra' => ['5299822472A', false],
        ];
    }

    #[DataProvider('cnpjs')]
    public function test_cnpj_validation(string $cnpj, bool $expected): void
    {
        $this->assertSame($expected, BrazilianDocument::isValidCnpj($cnpj));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function cnpjs(): array
    {
        return [
            'numérico válido' => ['11222333000181', true],
            'numérico dígito errado' => ['11222333000182', false],
            // Exemplo oficial da Receita Federal para o CNPJ alfanumérico
            'alfanumérico válido' => ['12ABC34501DE35', true],
            'alfanumérico dígito errado' => ['12ABC34501DE36', false],
            'letra no dígito verificador' => ['12ABC34501DE3A', false],
            'repetido' => ['00000000000000', false],
            'curto' => ['1122233300018', false],
        ];
    }
}
