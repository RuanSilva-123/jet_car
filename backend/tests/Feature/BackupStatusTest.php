<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Situação do backup automático (arquivo gravado pelo container "backup").
 */
class BackupStatusTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost');
        $this->path = sys_get_temp_dir().'/jetcar-last-backup-'.uniqid().'.json';
        config(['jetcar.backup.status_path' => $this->path]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function write(array $data): void
    {
        file_put_contents($this->path, json_encode($data));
    }

    public function test_without_backup_container(): void
    {
        $this->actingAs(User::factory()->master()->create());

        $this->getJson('/api/v1/settings/backup')->assertOk()->assertJsonPath('data.configured', false);
    }

    public function test_recent_successful_backup(): void
    {
        $this->write(['ok' => true, 'finished_at' => now()->subHours(3)->toIso8601String(), 'db_file' => 'jetcar_x.dump', 'db_size' => 2048, 'files_size' => 100, 'backups_count' => 14, 'keep_days' => 14, 'error' => null]);
        $this->actingAs(User::factory()->master()->create());

        $this->getJson('/api/v1/settings/backup')
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.stale', false)
            ->assertJsonPath('data.backups_count', 14);
        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.backup.ok', true);
    }

    public function test_old_or_failed_backup_is_flagged(): void
    {
        $this->write(['ok' => false, 'finished_at' => now()->subDays(3)->toIso8601String(), 'error' => 'pg_dump: conexão recusada']);
        $this->actingAs(User::factory()->master()->create());

        $this->getJson('/api/v1/settings/backup')
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.stale', true)
            ->assertJsonPath('data.error', 'pg_dump: conexão recusada');
    }

    public function test_only_master_sees_backup(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/v1/settings/backup')->assertForbidden();
        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.backup', null);
    }
}
