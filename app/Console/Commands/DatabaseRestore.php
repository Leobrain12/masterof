<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Восстановление из бэкапа (ТЗ п.106 — must be tested at least once before
 * production acceptance). Дамп собран с --clean --if-exists (см. DatabaseBackup),
 * поэтому безопасно накатывается поверх уже существующей базы той же схемы —
 * так и проверяется: снять бэкап и тут же восстановить его самого на себя.
 */
class DatabaseRestore extends Command
{
    protected $signature = 'db:restore {file : Имя файла в хранилище backups} {--force : Не спрашивать подтверждение}';

    protected $description = 'Восстановить БД из файла в приватном хранилище backups';

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! Storage::disk('backups')->exists($file)) {
            $this->error("Файл не найден в хранилище backups: {$file}");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Это ПЕРЕЗАПИШЕТ текущие данные содержимым {$file}. Продолжить?")) {
            $this->info('Отменено.');

            return self::SUCCESS;
        }

        $connectionName = config('database.default');
        $config = config("database.connections.{$connectionName}");

        if (($config['driver'] ?? null) !== 'pgsql') {
            $this->error('db:restore поддерживает только соединение pgsql.');

            return self::FAILURE;
        }

        $sql = gzdecode(Storage::disk('backups')->get($file));

        if ($sql === false) {
            $this->error('Не удалось распаковать файл бэкапа — повреждён или не gzip.');

            return self::FAILURE;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'restore_').'.sql';
        file_put_contents($tmpFile, $sql);

        try {
            $process = new Process([
                'psql',
                '--host', $config['host'],
                '--port', (string) $config['port'],
                '--username', $config['username'],
                '--dbname', $config['database'],
                '--set', 'ON_ERROR_STOP=1',
                '--file', $tmpFile,
            ]);
            $process->setEnv(['PGPASSWORD' => $config['password'] ?? '']);
            $process->setTimeout(600);
            $process->run();
        } finally {
            unlink($tmpFile);
        }

        if (! $process->isSuccessful()) {
            $this->error('Восстановление прервано: '.$process->getErrorOutput());

            return self::FAILURE;
        }

        $this->info("База восстановлена из {$file}.");

        return self::SUCCESS;
    }
}
