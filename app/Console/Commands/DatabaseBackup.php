<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Ежедневный бэкап БД (ТЗ п.105). Дамп собирается в память и сжимается —
 * для объёма данных этого проекта (заказы, не медиафайлы — те уже в
 * отдельном приватном хранилище) это нормально; если база вырастет на
 * порядки, первое, что стоит поменять — потоковое сжатие вместо gzencode().
 */
class DatabaseBackup extends Command
{
    protected $signature = 'db:backup {--keep-days=30 : Сколько дней хранить бэкапы (ТЗ п.105 рекомендует 14-30)}';

    protected $description = 'Снять дамп БД в приватное хранилище backups и удалить бэкапы старше --keep-days';

    public function handle(): int
    {
        $connectionName = config('database.default');
        $config = config("database.connections.{$connectionName}");

        if (($config['driver'] ?? null) !== 'pgsql') {
            $this->error('db:backup поддерживает только соединение pgsql.');

            return self::FAILURE;
        }

        $process = new Process([
            'pg_dump',
            '--host', $config['host'],
            '--port', (string) $config['port'],
            '--username', $config['username'],
            '--format=plain',
            '--no-owner',
            '--no-privileges',
            // --clean --if-exists: дамп сам сносит старые объекты перед созданием новых,
            // поэтому restore безопасно гонять поверх уже существующей базы той же схемы —
            // именно так и проверяется восстановление (см. vault/Фазы/Фаза 07).
            '--clean',
            '--if-exists',
            $config['database'],
        ]);
        $process->setEnv(['PGPASSWORD' => $config['password'] ?? '']);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('pg_dump завершился с ошибкой: '.$process->getErrorOutput());

            return self::FAILURE;
        }

        $compressed = gzencode($process->getOutput(), 9);
        $filename = 'service-ops-'.now()->format('Y-m-d_His').'.sql.gz';

        Storage::disk('backups')->put($filename, $compressed);

        $this->info(sprintf('Бэкап сохранён: %s (%s КБ)', $filename, number_format(strlen($compressed) / 1024, 1)));

        $this->cleanupOldBackups((int) $this->option('keep-days'));

        return self::SUCCESS;
    }

    private function cleanupOldBackups(int $keepDays): void
    {
        $cutoff = now()->subDays($keepDays)->timestamp;

        foreach (Storage::disk('backups')->files() as $file) {
            if (! str_ends_with($file, '.sql.gz')) {
                continue;
            }

            if (Storage::disk('backups')->lastModified($file) < $cutoff) {
                Storage::disk('backups')->delete($file);
                $this->line("Удалён старый бэкап: {$file}");
            }
        }
    }
}
