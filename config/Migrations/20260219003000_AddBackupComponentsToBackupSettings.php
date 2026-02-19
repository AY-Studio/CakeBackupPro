<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

class AddBackupComponentsToBackupSettings extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('backup_settings')) {
            return;
        }

        $table = $this->table('backup_settings');
        if (!$table->hasColumn('backup_components')) {
            $table
                ->addColumn('backup_components', 'string', ['limit' => 1000, 'null' => true])
                ->update();
        }
    }
}
