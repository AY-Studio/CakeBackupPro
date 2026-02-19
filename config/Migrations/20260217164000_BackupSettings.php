<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

class BackupSettings extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('backup_settings')) {
            return;
        }

        $table = $this->table('backup_settings');
        $table
            ->addColumn('b2_key_id', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('b2_application_key', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('backup_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('b2_region', 'string', ['limit' => 64, 'default' => 'us-west-004'])
            ->addColumn('backup_components', 'string', ['limit' => 1000, 'null' => true])
            ->addColumn('schedule_enabled', 'boolean', ['default' => true])
            ->addColumn('schedule_frequency', 'string', ['limit' => 20, 'default' => 'daily'])
            ->addColumn('schedule_time', 'string', ['limit' => 5, 'default' => '00:00'])
            ->addColumn('schedule_weekday', 'integer', ['default' => 0])
            ->addColumn('retention_days', 'integer', ['default' => 45])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->create();
    }
}
