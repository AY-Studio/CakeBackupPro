<?php
declare(strict_types=1);

namespace CakeBackupPro\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class BackupSettingsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('backup_settings');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->allowEmptyString('b2_key_id')
            ->maxLength('b2_key_id', 255);

        $validator
            ->allowEmptyString('b2_application_key')
            ->maxLength('b2_application_key', 255);

        $validator
            ->allowEmptyString('backup_path')
            ->maxLength('backup_path', 255);

        $validator
            ->allowEmptyString('b2_region')
            ->maxLength('b2_region', 64);

        $validator
            ->allowEmptyString('backup_components')
            ->maxLength('backup_components', 1000);

        $validator
            ->scalar('schedule_frequency')
            ->inList('schedule_frequency', ['daily', 'weekly']);

        $validator
            ->scalar('schedule_time')
            ->regex('schedule_time', '/^([01]\\d|2[0-3]):[0-5]\\d$/', 'Time must be HH:MM');

        $validator
            ->integer('schedule_weekday')
            ->greaterThanOrEqual('schedule_weekday', 0)
            ->lessThanOrEqual('schedule_weekday', 6);

        $validator
            ->integer('retention_days')
            ->greaterThanOrEqual('retention_days', 1)
            ->lessThanOrEqual('retention_days', 3650);

        return $validator;
    }
}
