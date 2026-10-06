<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Trava de segurança: os testes usam RefreshDatabase, que apaga todas as tabelas.
     * Se por qualquer motivo a conexão não for o SQLite em memória (ex.: variáveis
     * DB_* injetadas pelo Docker), aborta antes de tocar no banco de desenvolvimento.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Testes abortados: banco atual é [{$connection}:{$database}], esperado [sqlite::memory:]."
            );
        }

        return $app;
    }
}
