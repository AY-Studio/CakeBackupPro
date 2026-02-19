<?php
declare(strict_types=1);

namespace CakeBackupPro\Controller\Admin;

use App\Controller\AppController;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

class BackupsController extends AppController
{
    private const ALLOWED_BACKUP_TYPES = ['full', 'db', 'files'];
    private const FILE_COMPONENT_OPTIONS = [
        'config' => 'Config',
        'src' => 'Source (src)',
        'templates' => 'Templates',
        'webroot' => 'Webroot',
        'resources' => 'Resources',
        'plugins' => 'Plugins',
        'uploads' => 'Uploads',
        'www' => 'www',
        'db_files' => 'db (migrations/seeds)',
        'root_files' => 'Root files (composer/bin/index.php)',
        'env_files' => 'Env files (.env)',
    ];
    private const CRON_MARKER = '# cake-backup-pro-runner';

    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        if ($this->request->getAttribute('identity') === null) {
            $this->Flash->error('Please log in to access backup tools.');

            return $this->redirect('/admin');
        }

        return null;
    }

    public function index()
    {
        $settings = $this->getSettingsEntity();
        $schemaReady = $settings !== null;
        $isConfigured = $schemaReady && $this->hasRequiredSettings($settings);
        $selectedFileComponents = $this->selectedFileComponents($settings);
        $backupSets = [];

        $snapshotsOutput = 'Configure Backblaze keys and backup path below to enable snapshots.';
        $snapshotsExitCode = 0;
        if (!$schemaReady) {
            $snapshotsOutput = 'Backup settings table is missing. Run migration: bin/cake migrations migrate -p CakeBackupPro';
        } elseif ($isConfigured) {
            [$snapshotsExitCode, $snapshotsOutput] = $this->runManagerCommand(['list'], $this->buildRuntimeEnv($settings));
            [$setsExitCode, $setsOutput] = $this->runManagerCommand(['list-sets'], $this->buildRuntimeEnv($settings));
            if ($setsExitCode === 0) {
                $backupSets = $this->parseBackupSetsOutput($setsOutput);
            }
        }

        $scheduleOutput = $this->getInstalledScheduleLines();
        $scheduleExitCode = 0;

        $logFile = ROOT . DS . 'logs' . DS . 'backup-manager.log';
        $recentLog = '';
        if (is_file($logFile) && is_readable($logFile)) {
            $recentLog = implode('', array_slice(file($logFile), -200));
        }

        $this->set(compact('settings', 'schemaReady', 'isConfigured', 'snapshotsOutput', 'snapshotsExitCode', 'scheduleOutput', 'scheduleExitCode', 'recentLog'));
        $this->set('scheduleFrequencyOptions', ['daily' => 'Daily', 'weekly' => 'Weekly']);
        $this->set('weekdayOptions', [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ]);
        $this->set('fileComponentOptions', self::FILE_COMPONENT_OPTIONS);
        $this->set('restoreComponentOptions', ['database' => 'Database'] + self::FILE_COMPONENT_OPTIONS);
        $restoreDefaultComponents = ['database', 'config', 'src', 'templates', 'webroot', 'resources', 'plugins', 'uploads', 'www', 'db_files', 'root_files'];
        $this->set(compact('selectedFileComponents', 'backupSets', 'restoreDefaultComponents'));
    }

    public function saveSettings()
    {
        $this->request->allowMethod(['post', 'put', 'patch']);

        $settings = $this->getSettingsEntity();
        if ($settings === null) {
            $this->Flash->error('Backup settings table is missing. Run migrations first.');

            return $this->redirect(['action' => 'index']);
        }
        $settingsTable = $this->fetchTable('CakeBackupPro.BackupSettings');
        $data = $this->request->getData();

        if (!empty($data['schedule_time'])) {
            $data['schedule_time'] = substr((string)$data['schedule_time'], 0, 5);
        }
        if (!isset($data['schedule_enabled'])) {
            $data['schedule_enabled'] = 0;
        }
        $data['backup_components'] = implode(',', $this->normalizeFileComponents((array)($data['backup_components'] ?? [])));

        if ($settings->id && empty($data['b2_application_key'])) {
            unset($data['b2_application_key']);
        }
        if ($settings->id && empty($data['b2_key_id'])) {
            unset($data['b2_key_id']);
        }

        $settings = $settingsTable->patchEntity($settings, $data);

        if (!$settingsTable->save($settings)) {
            $errors = $settings->getErrors();
            $firstError = 'Unknown validation error.';
            if (!empty($errors)) {
                $field = (string)array_key_first($errors);
                $messages = (array)$errors[$field];
                $firstMessage = (string)array_values($messages)[0];
                $firstError = $field . ': ' . $firstMessage;
            }
            $this->Flash->error('Failed to save backup settings. ' . $firstError);

            return $this->redirect(['action' => 'index']);
        }

        if (!$this->hasRequiredSettings($settings)) {
            $this->Flash->success('Settings saved. Add Backblaze key ID, application key, and backup path to enable backups.');

            return $this->redirect(['action' => 'index']);
        }

        if ((bool)$settings->schedule_enabled) {
            [$ok, $message] = $this->installSchedule($settings);
            if ($ok) {
                $this->Flash->success('Settings saved and schedule updated. ' . $message);
            } else {
                $this->Flash->error('Settings saved but schedule update failed. ' . $message);
            }
        } else {
            [$ok, $message] = $this->removeSchedule();
            if ($ok) {
                $this->Flash->success('Settings saved and schedule removed.');
            } else {
                $this->Flash->error('Settings saved, but failed to remove schedule. ' . $message);
            }
        }

        return $this->redirect(['action' => 'index']);
    }

    public function runBackup()
    {
        $this->request->allowMethod(['post']);

        $backupType = (string)$this->request->getData('backup_type');
        if (!in_array($backupType, self::ALLOWED_BACKUP_TYPES, true)) {
            $this->Flash->error('Invalid backup type selected.');

            return $this->redirect(['action' => 'index']);
        }

        $settings = $this->getConfiguredSettingsOrRedirect();
        if ($settings === null) {
            return $this->redirect(['action' => 'index']);
        }

        $selectedComponents = $this->normalizeFileComponents((array)$this->request->getData('backup_components'));
        if (empty($selectedComponents)) {
            $selectedComponents = $this->selectedFileComponents($settings);
        }

        $args = ['backup', $backupType, '--prune'];
        if (in_array($backupType, ['full', 'files'], true) && !empty($selectedComponents)) {
            $args[] = '--components=' . implode(',', $selectedComponents);
        }

        $started = $this->runManagerInBackground($args, $this->buildRuntimeEnv($settings));

        if ($this->request->is('ajax')) {
            $payload = [
                'started' => $started,
                'message' => $started
                    ? sprintf('Backup job started for type: %s', strtoupper($backupType))
                    : 'Failed to start backup job. Verify server shell/permissions.',
            ];

            return $this->response
                ->withType('application/json')
                ->withStringBody((string)json_encode($payload));
        }

        if ($started) {
            $this->Flash->success(sprintf('Backup job started for type: %s. Check logs below.', strtoupper($backupType)));
        } else {
            $this->Flash->error('Failed to start backup job. Verify server shell/permissions.');
        }

        return $this->redirect(['action' => 'index']);
    }

    public function status()
    {
        $this->request->allowMethod(['get']);

        $logFile = ROOT . DS . 'logs' . DS . 'backup-manager.log';
        $recentLog = '';
        if (is_file($logFile) && is_readable($logFile)) {
            $recentLog = implode('', array_slice(file($logFile), -120));
        }
        $currentRunLog = $this->extractCurrentRunLog($recentLog);

        $running = $this->isBackupProcessRunning();
        $payload = [
            'running' => $running,
            'recentLog' => $recentLog,
            'currentRunLog' => $currentRunLog,
        ];

        return $this->response
            ->withType('application/json')
            ->withStringBody((string)json_encode($payload));
    }

    public function validateSettings()
    {
        $this->request->allowMethod(['post']);

        $settings = $this->getConfiguredSettingsOrRedirect();
        if ($settings === null) {
            return $this->redirect(['action' => 'index']);
        }

        [$exitCode, $output] = $this->runManagerCommand(['list'], $this->buildRuntimeEnv($settings));
        if ($exitCode === 0) {
            $this->Flash->success('Backup settings validated successfully.');
        } else {
            $preview = trim((string)$output);
            if ($preview !== '') {
                $preview = substr($preview, 0, 300);
                $this->Flash->error('Validation failed: ' . $preview);
            } else {
                $this->Flash->error('Validation failed. Check credentials, backup path, and server access.');
            }
        }

        return $this->redirect(['action' => 'index']);
    }

    public function restoreSnapshot()
    {
        $this->request->allowMethod(['post']);

        $settings = $this->getConfiguredSettingsOrRedirect();
        if ($settings === null) {
            return $this->redirect(['action' => 'index']);
        }

        $setId = trim((string)$this->request->getData('snapshot_set'));
        $restoreComponents = $this->normalizeRestoreComponents((array)$this->request->getData('restore_components'));
        if ($setId === '' || empty($restoreComponents)) {
            $this->Flash->error('Select a backup set and at least one restore component.');

            return $this->redirect(['action' => 'index']);
        }

        $started = $this->runManagerInBackground(
            ['restore-set', $setId, implode(',', $restoreComponents)],
            $this->buildRuntimeEnv($settings),
            'backup-restore.log'
        );

        if ($started) {
            $this->Flash->success('Restore started. Existing local folders/files will be archived as old-* before replacement. Check restore log for health check PASS/FAIL.');
        } else {
            $this->Flash->error('Failed to start restore job.');
        }

        return $this->redirect(['action' => 'index']);
    }

    public function restoreFullSite()
    {
        $this->request->allowMethod(['post']);

        $settings = $this->getConfiguredSettingsOrRedirect();
        if ($settings === null) {
            return $this->redirect(['action' => 'index']);
        }

        $setId = trim((string)$this->request->getData('snapshot_set'));
        if ($setId === '') {
            $setId = 'latest';
        }

        $includeEnvFiles = (bool)$this->request->getData('include_env_files');
        $env = $this->buildRuntimeEnv($settings);
        $env['RESTORE_REWRITE_URLS'] = '1';
        $env['RESTORE_RUN_COMPOSER'] = '1';
        $env['RESTORE_RUN_MIGRATIONS'] = '1';
        $env['RESTORE_CLEAR_CACHE'] = '1';
        $env['RESTORE_INCLUDE_ENV_FILES'] = $includeEnvFiles ? '1' : '0';

        $started = $this->runManagerInBackground(
            ['restore-full', $setId],
            $env,
            'backup-restore.log'
        );

        if ($started) {
            $this->Flash->success('One-click full restore started. This restores app files + DB, rewrites URLs, runs composer and migrations, clears cache, and runs health checks.');
        } else {
            $this->Flash->error('Failed to start one-click full restore.');
        }

        return $this->redirect(['action' => 'index']);
    }

    public function restoreDb()
    {
        $this->request->allowMethod(['post']);

        /** @var \Psr\Http\Message\UploadedFileInterface|null $uploadedFile */
        $uploadedFile = $this->request->getData('backup_file');
        if (!$uploadedFile instanceof UploadedFileInterface || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
            $this->Flash->error('Please upload a valid DB backup file (.sql or .sql.gz).');

            return $this->redirect(['action' => 'index']);
        }

        $settings = $this->getConfiguredSettingsOrRedirect();
        if ($settings === null) {
            return $this->redirect(['action' => 'index']);
        }

        $uploadedPath = $this->storeUploadedBackupFile($uploadedFile, ['sql', 'gz']);
        if ($uploadedPath === null) {
            $this->Flash->error('Invalid DB backup file. Use .sql or .sql.gz.');

            return $this->redirect(['action' => 'index']);
        }

        $started = $this->runManagerInBackground(
            ['restore-db-file', $uploadedPath],
            $this->buildRuntimeEnv($settings),
            'backup-restore.log'
        );

        if ($started) {
            $this->Flash->success('DB restore started from uploaded file.');
        } else {
            $this->Flash->error('Failed to start DB restore.');
        }

        return $this->redirect(['action' => 'index']);
    }

    public function restoreFiles()
    {
        $this->request->allowMethod(['post']);

        /** @var \Psr\Http\Message\UploadedFileInterface|null $uploadedFile */
        $uploadedFile = $this->request->getData('backup_file');
        $targetDir = trim((string)$this->request->getData('target_dir'));

        if (!$uploadedFile instanceof UploadedFileInterface || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
            $this->Flash->error('Please upload a valid files archive (.tar.gz, .tgz, or .zip).');
            return $this->redirect(['action' => 'index']);
        }

        if ($targetDir === '') {
            $this->Flash->error('Target directory is required for file restore.');

            return $this->redirect(['action' => 'index']);
        }

        $settings = $this->getConfiguredSettingsOrRedirect();
        if ($settings === null) {
            return $this->redirect(['action' => 'index']);
        }

        $uploadedPath = $this->storeUploadedBackupFile($uploadedFile, ['tar', 'gz', 'tgz', 'zip']);
        if ($uploadedPath === null) {
            $this->Flash->error('Invalid files archive. Use .tar.gz, .tgz, or .zip.');

            return $this->redirect(['action' => 'index']);
        }

        $started = $this->runManagerInBackground(
            ['restore-files-file', $uploadedPath, $targetDir],
            $this->buildRuntimeEnv($settings),
            'backup-restore.log'
        );

        if ($started) {
            $this->Flash->success('File restore started from uploaded archive.');
        } else {
            $this->Flash->error('Failed to start file restore.');
        }

        return $this->redirect(['action' => 'index']);
    }

    private function getSettingsEntity()
    {
        try {
            $settingsTable = $this->fetchTable('CakeBackupPro.BackupSettings');
            $settings = $settingsTable->find()->first();
            if ($settings) {
                return $settings;
            }

            return $settingsTable->newEntity([
                'b2_region' => 'us-west-004',
                'backup_components' => implode(',', array_keys(self::FILE_COMPONENT_OPTIONS)),
                'schedule_enabled' => true,
                'schedule_frequency' => 'daily',
                'schedule_time' => '00:00',
                'schedule_weekday' => 0,
                'retention_days' => 45,
            ]);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function getConfiguredSettingsOrRedirect()
    {
        $settings = $this->getSettingsEntity();
        if ($settings === null) {
            $this->Flash->error('Backup settings table is missing. Run migrations first.');

            return null;
        }

        if (!$settings->id || !$this->hasRequiredSettings($settings)) {
            $this->Flash->error('Configure Backblaze key ID, application key, and backup path first.');

            return null;
        }

        return $settings;
    }

    private function hasRequiredSettings($settings): bool
    {
        return !empty($settings->b2_key_id)
            && !empty($settings->b2_application_key)
            && !empty($settings->backup_path);
    }

    private function normalizeFileComponents(array $components): array
    {
        $allowed = array_keys(self::FILE_COMPONENT_OPTIONS);
        $normalized = [];

        foreach ($components as $component) {
            $value = strtolower(trim((string)$component));
            if ($value !== '' && in_array($value, $allowed, true)) {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    private function normalizeRestoreComponents(array $components): array
    {
        $allowed = array_merge(['database'], array_keys(self::FILE_COMPONENT_OPTIONS));
        $normalized = [];

        foreach ($components as $component) {
            $value = strtolower(trim((string)$component));
            if ($value !== '' && in_array($value, $allowed, true)) {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    private function selectedFileComponents($settings): array
    {
        $saved = (string)($settings->backup_components ?? '');
        $parts = $saved === '' ? [] : explode(',', $saved);
        $normalized = $this->normalizeFileComponents($parts);

        if (empty($normalized)) {
            return array_keys(self::FILE_COMPONENT_OPTIONS);
        }

        return $normalized;
    }

    private function parseBackupSetsOutput(string $output): array
    {
        $sets = [];
        $lines = preg_split('/\\r\\n|\\r|\\n/', trim($output));
        if (!is_array($lines)) {
            return $sets;
        }

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $parts = explode("\t", $line);
            $setId = trim((string)($parts[0] ?? ''));
            if ($setId === '') {
                continue;
            }

            $components = $this->normalizeFileComponents(explode(',', (string)($parts[1] ?? '')));
            $hasDb = (string)($parts[2] ?? '0') === '1';
            $labelParts = [];
            if ($hasDb) {
                $labelParts[] = 'DB';
            }
            if (!empty($components)) {
                $labelParts[] = implode(', ', $components);
            }

            $sets[$setId] = $setId . (empty($labelParts) ? '' : ' (' . implode(' + ', $labelParts) . ')');
        }

        return $sets;
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

        // DDEV-friendly fallbacks when values are still incomplete.
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

    private function managerScriptPath(): string
    {
        return Plugin::path('CakeBackupPro') . 'scripts' . DS . 'backup-manager.sh';
    }

    private function runManagerCommand(array $args, array $envOverrides = []): array
    {
        $script = $this->managerScriptPath();
        if (!is_file($script)) {
            return [1, 'Backup manager script not found: ' . $script];
        }

        $command = 'cd ' . escapeshellarg(ROOT) . ' && ';
        foreach ($envOverrides as $key => $value) {
            $command .= $key . '=' . escapeshellarg((string)$value) . ' ';
        }

        $command .= '/bin/bash ' . escapeshellarg($script);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg((string)$arg);
        }

        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    private function runManagerInBackground(array $args, array $envOverrides = [], string $logFile = 'backup-manager.log'): bool
    {
        $script = $this->managerScriptPath();
        if (!is_file($script)) {
            return false;
        }

        $logPath = ROOT . DS . 'logs' . DS . $logFile;
        $command = 'cd ' . escapeshellarg(ROOT) . ' && env ';

        foreach ($envOverrides as $key => $value) {
            $command .= $key . '=' . escapeshellarg((string)$value) . ' ';
        }

        $command .= 'nohup /bin/bash ' . escapeshellarg($script);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg((string)$arg);
        }
        $command .= ' >> ' . escapeshellarg($logPath) . ' 2>&1 &';

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return $exitCode === 0;
    }

    private function cronExpressionFromSettings($settings): string
    {
        $time = (string)$settings->schedule_time;
        [$hour, $minute] = explode(':', $time);
        $hour = (int)$hour;
        $minute = (int)$minute;

        if ((string)$settings->schedule_frequency === 'weekly') {
            $weekday = (int)$settings->schedule_weekday;

            return sprintf('%d %d * * %d', $minute, $hour, $weekday);
        }

        return sprintf('%d %d * * *', $minute, $hour);
    }

    private function installSchedule($settings): array
    {
        $cronExpr = $this->cronExpressionFromSettings($settings);
        $phpBinary = PHP_BINARY ?: 'php';
        $cakeBinary = ROOT . DS . 'bin' . DS . 'cake';
        $logPath = ROOT . DS . 'logs' . DS . 'backup-manager.log';

        $cronLine = sprintf(
            "%s cd %s && %s %s backup_runner run >> %s 2>&1 %s",
            $cronExpr,
            escapeshellarg(ROOT),
            escapeshellarg($phpBinary),
            escapeshellarg($cakeBinary),
            escapeshellarg($logPath),
            self::CRON_MARKER
        );

        $cmd = "(crontab -l 2>/dev/null | grep -v " . escapeshellarg(self::CRON_MARKER) . "; echo " . escapeshellarg($cronLine) . ") | crontab -";

        $output = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $output, $exitCode);

        return [$exitCode === 0, implode("\n", $output)];
    }

    private function removeSchedule(): array
    {
        $cmd = "(crontab -l 2>/dev/null | grep -v " . escapeshellarg(self::CRON_MARKER) . ") | crontab -";

        $output = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $output, $exitCode);

        return [$exitCode === 0, implode("\n", $output)];
    }

    private function getInstalledScheduleLines(): string
    {
        $output = [];
        $exitCode = 0;
        exec('crontab -l 2>/dev/null | grep -F ' . escapeshellarg(self::CRON_MARKER), $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return '';
        }

        return implode("\n", $output);
    }

    private function storeUploadedBackupFile(UploadedFileInterface $uploadedFile, array $allowedExtensions): ?string
    {
        $clientName = (string)$uploadedFile->getClientFilename();
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $clientName);
        if ($safeName === null || $safeName === '') {
            return null;
        }

        $lower = strtolower($safeName);
        $valid = false;
        foreach ($allowedExtensions as $ext) {
            if (str_ends_with($lower, '.' . strtolower($ext))) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            if (in_array('gz', $allowedExtensions, true) && str_ends_with($lower, '.sql.gz')) {
                $valid = true;
            }
            if (in_array('tar', $allowedExtensions, true) && in_array('gz', $allowedExtensions, true) && str_ends_with($lower, '.tar.gz')) {
                $valid = true;
            }
        }
        if (!$valid) {
            return null;
        }

        $dir = ROOT . DS . 'tmp' . DS . 'backups' . DS . 'uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $target = $dir . DS . date('YmdHis') . '-' . $safeName;
        $uploadedFile->moveTo($target);

        return $target;
    }

    private function isBackupProcessRunning(): bool
    {
        $lockFile = ROOT . DS . 'tmp' . DS . 'backups' . DS . '.backup-manager.lock';
        if (!is_file($lockFile) || !is_readable($lockFile)) {
            return false;
        }

        $pid = (int)trim((string)file_get_contents($lockFile));
        if ($pid <= 0) {
            @unlink($lockFile);

            return false;
        }

        $output = [];
        $exitCode = 1;
        exec('ps -p ' . $pid . ' -o pid=', $output, $exitCode);
        if ($exitCode !== 0 || empty($output)) {
            @unlink($lockFile);

            return false;
        }

        return true;
    }

    private function extractCurrentRunLog(string $recentLog): string
    {
        if (trim($recentLog) === '') {
            return '';
        }

        $marker = 'Backup start:';
        $pos = strrpos($recentLog, $marker);
        if ($pos === false) {
            return $recentLog;
        }

        return substr($recentLog, max(0, $pos - 40));
    }
}
