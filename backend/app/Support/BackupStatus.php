<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Situação do último backup, lida do last-backup.json que o container "backup" grava
 * (pasta ./backups montada só para leitura nos containers do PHP).
 */
final class BackupStatus
{
    /** Sem backup novo há mais que isto: algo parou (container desligado, disco cheio...). */
    private const STALE_HOURS = 26;

    /**
     * @return array{configured: bool, ok: bool, stale: bool, finished_at: string|null, db_file: string|null,
     *     db_size: int, files_size: int, backups_count: int, keep_days: int|null, error: string|null}
     */
    public static function get(): array
    {
        $path = (string) config('jetcar.backup.status_path');
        $raw = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($raw)) {
            return [
                'configured' => false, 'ok' => false, 'stale' => true, 'finished_at' => null, 'db_file' => null,
                'db_size' => 0, 'files_size' => 0, 'backups_count' => 0, 'keep_days' => null, 'error' => null,
            ];
        }

        $finished = isset($raw['finished_at']) ? Carbon::parse($raw['finished_at']) : null;

        return [
            'configured' => true,
            'ok' => (bool) ($raw['ok'] ?? false),
            'stale' => $finished === null || $finished->lt(now()->subHours(self::STALE_HOURS)),
            'finished_at' => $finished?->toIso8601String(),
            'db_file' => $raw['db_file'] ?? null ?: null,
            'db_size' => (int) ($raw['db_size'] ?? 0),
            'files_size' => (int) ($raw['files_size'] ?? 0),
            'backups_count' => (int) ($raw['backups_count'] ?? 0),
            'keep_days' => isset($raw['keep_days']) ? (int) $raw['keep_days'] : null,
            'error' => $raw['error'] ?? null,
        ];
    }
}
