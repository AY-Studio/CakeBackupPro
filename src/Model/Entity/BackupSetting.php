<?php
declare(strict_types=1);

namespace CakeBackupPro\Model\Entity;

use Cake\ORM\Entity;

class BackupSetting extends Entity
{
    protected $_accessible = [
        'b2_key_id' => true,
        'b2_application_key' => true,
        'backup_path' => true,
        'b2_region' => true,
        'backup_components' => true,
        'schedule_enabled' => true,
        'schedule_frequency' => true,
        'schedule_time' => true,
        'schedule_weekday' => true,
        'retention_days' => true,
        'created' => true,
        'modified' => true,
    ];
}
