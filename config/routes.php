<?php
declare(strict_types=1);

use Cake\Routing\RouteBuilder;

$routes->prefix('Admin', function (RouteBuilder $routes) {
    $routes->connect('/backups', ['plugin' => 'CakeBackupPro', 'controller' => 'Backups', 'action' => 'index']);
    $routes->connect('/backups/:action/*', ['plugin' => 'CakeBackupPro', 'controller' => 'Backups']);
});
