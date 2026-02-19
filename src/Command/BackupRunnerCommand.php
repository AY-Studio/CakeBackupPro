<?php
declare(strict_types=1);

namespace CakeBackupPro\Command;

use Cake\Console\Arguments;
use Cake\Console\Command;
use Cake\Console\ConsoleIo;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Locator\LocatorAwareTrait;
use Throwable;

class BackupRunnerCommand extends Command
{
    use LocatorAwareTrait;
    private const FILE_COMPONENT_OPTIONS = [
        'config',
        'src',
        'templates',
        'webroot',
        'resources',
        'plugins',
        'uploads',
        'www',
        'db_files',
        'root_files',
        'env_files',
    ];

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $settingsTable = $this->fetchTable('CakeBackupPro.BackupSettings');
        $settings = $settingsTable->find()->first();

        if (!$settings) {
            $io->err('No backup settings found.');

            return static::CODE_ERROR;
        }

        if (!(bool)$settings->schedule_enabled) {
            $io->out('Backup schedule is disabled. Nothing to run.');

            return static::CODE_SUCCESS;
        }

        if (empty($settings->b2_key_id) || empty($settings->b2_application_key) || empty($settings->backup_path)) {
            $io->err('Backup settings are incomplete.');

            return static::CODE_ERROR;
        }

        $script = Plugin::path('CakeBackupPro') . 'scripts' . DS . 'backup-manager.sh';
        if (!is_file($script)) {
            $io->err('Backup manager script not found: ' . $script);

            return static::CODE_ERROR;
        }

        $env = $this->buildRuntimeEnv($settings);

        $command = 'cd ' . escapeshellarg(ROOT) . ' && ';
        foreach ($env as $key => $value) {
            $command .= $key . '=' . escapeshellarg((string)$value) . ' ';
        }

        $command .= '/bin/bash ' . escapeshellarg($script) . ' backup full --prune';

        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        foreach ($output as $line) {
            $io->out($line);
        }

        return $exitCode;
    }

    private function buildRuntimeEnv($settings): array
    {
        $backupPath = trim((string)$settings->backup_path, '/');
        $region = trim((string)$settings->b2_region);
        if ($region === '') {
            $region = 'us-west-004';
        }

        $parts = explode('/', $backupPath, 2);
        $bucket = $parts[0] ?? '';
        $prefix = $parts[1] ?? '';

        $repository = 's3:https://s3.' . $region . '.backblazeb2.com/' . $bucket;
        if ($prefix !== '') {
            $repository .= '/' . $prefix;
        }

        $salt = (string)Configure::read('Security.salt');
        $resticPassword = hash('sha256', (string)$settings->b2_application_key . '|' . $backupPath . '|' . $salt);
        $currentSiteUrl = $this->currentSiteUrl();

        $dbEnv = $this->buildDbRuntimeEnv();

        return [
            'BACKUP_DRIVER' => 'native',
            'BACKUP_PATH' => $backupPath,
            'RESTIC_REPOSITORY' => $repository,
            'RESTIC_PASSWORD' => $resticPassword,
            'AWS_ACCESS_KEY_ID' => (string)$settings->b2_key_id,
            'AWS_SECRET_ACCESS_KEY' => (string)$settings->b2_application_key,
            'BACKUP_RETENTION_DAYS' => (string)(int)$settings->retention_days,
            'BACKUP_FILE_COMPONENTS' => implode(',', $this->selectedFileComponents($settings)),
            'BACKUP_SITE_URL' => $currentSiteUrl,
            'RESTORE_TARGET_URL' => $currentSiteUrl,
            'DB_HOST' => $dbEnv['DB_HOST'],
            'DB_PORT' => $dbEnv['DB_PORT'],
            'DB_NAME' => $dbEnv['DB_NAME'],
            'DB_USER' => $dbEnv['DB_USER'],
            'DB_PASSWORD' => $dbEnv['DB_PASSWORD'],
        ];
    }

    private function buildDbRuntimeEnv(): array
    {
        $host = '';
        $port = '';
        $database = '';
        $username = '';
        $password = '';

        try {
            $conn = ConnectionManager::get('default');
            $cfg = $conn->config();
            $host = $this->normalizeDbValue((string)($cfg['host'] ?? ''));
            $port = $this->normalizeDbValue((string)($cfg['port'] ?? ''));
            $database = $this->normalizeDbValue((string)($cfg['database'] ?? ''));
            $username = $this->normalizeDbValue((string)($cfg['username'] ?? ''));
            $password = $this->normalizeDbValue((string)($cfg['password'] ?? ''));
        } catch (Throwable $e) {
            // Fall through to config/env-based defaults.
        }

        $envHost = $this->normalizeDbValue((string)env('DB_HOST', ''));
        $envPort = $this->normalizeDbValue((string)env('DB_PORT', ''));
        $envDatabase = $this->normalizeDbValue((string)env('DB_DATABASE', ''));
        $envUsername = $this->normalizeDbValue((string)(env('DB_USERNAME', env('DB_USER', ''))));
        $envPassword = $this->normalizeDbValue((string)env('DB_PASSWORD', ''));

        if ($host === '' && $envHost !== '') {
            $host = $envHost;
        }
        if ($port === '' && $envPort !== '') {
            $port = $envPort;
        }
        if ($database === '' && $envDatabase !== '') {
            $database = $envDatabase;
        }
        if ($username === '' && $envUsername !== '') {
            $username = $envUsername;
        }
        if ($password === '' && $envPassword !== '') {
            $password = $envPassword;
        }

        if ($host === '') {
            $host = $this->normalizeDbValue((string)Configure::read('Datasources.default.host', '127.0.0.1'));
        }
        if ($port === '') {
            $port = $this->normalizeDbValue((string)Configure::read('Datasources.default.port', '3306'));
        }
        if ($database === '') {
            $database = $this->normalizeDbValue((string)Configure::read('Datasources.default.database', ''));
        }
        if ($username === '') {
            $username = $this->normalizeDbValue((string)Configure::read('Datasources.default.username', ''));
        }
        if ($password === '') {
            $password = $this->normalizeDbValue((string)Configure::read('Datasources.default.password', ''));
        }

        $dsn = (string)Configure::read('Datasources.default.url', env('DATABASE_URL', ''));
        if ($dsn !== '' && ($database === '' || $host === '' || $username === '')) {
            $parts = parse_url($dsn);
            if (is_array($parts)) {
                if ($host === '' && !empty($parts['host'])) {
                    $host = $this->normalizeDbValue((string)$parts['host']);
                }
                if (($port === '' || $port === '3306') && !empty($parts['port'])) {
                    $port = $this->normalizeDbValue((string)$parts['port']);
                }
                if ($database === '' && !empty($parts['path'])) {
                    $database = $this->normalizeDbValue(ltrim((string)$parts['path'], '/'));
                }
                if ($username === '' && !empty($parts['user'])) {
                    $username = $this->normalizeDbValue((string)$parts['user']);
                }
                if ($password === '' && !empty($parts['pass'])) {
                    $password = $this->normalizeDbValue((string)$parts['pass']);
                }
            }
        }

        if ((string)env('DDEV_SITENAME', '') !== '' || $host === 'db') {
            if ($host === '') {
                $host = 'db';
            }
            if ($port === '') {
                $port = '3306';
            }
            if ($username === '') {
                $username = 'db';
            }
            if ($password === '') {
                $password = 'db';
            }
            if ($database === '') {
                $database = 'db';
            }
        }

        return [
            'DB_HOST' => $host,
            'DB_PORT' => $port === '' ? '3306' : $port,
            'DB_NAME' => $database,
            'DB_USER' => $username,
            'DB_PASSWORD' => $password,
        ];
    }

    private function normalizeDbValue(string $value): string
    {
        $value = trim($value);
        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        return trim($value);
    }

    private function selectedFileComponents($settings): array
    {
        $saved = (string)($settings->backup_components ?? '');
        $parts = $saved === '' ? [] : explode(',', $saved);
        $normalized = [];

        foreach ($parts as $part) {
            $value = strtolower(trim((string)$part));
            if ($value !== '' && in_array($value, self::FILE_COMPONENT_OPTIONS, true)) {
                $normalized[$value] = $value;
            }
        }

        if (empty($normalized)) {
            return self::FILE_COMPONENT_OPTIONS;
        }

        return array_values($normalized);
    }

    private function currentSiteUrl(): string
    {
        $candidates = [
            (string)Configure::read('App.fullBaseUrl', ''),
            (string)env('ACCOUNT_DOMAIN', ''),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return rtrim($candidate, '/');
            }
        }

        return '';
    }
}
