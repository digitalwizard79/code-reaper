<?php
require __DIR__ . '/../../../../vendor/autoload.php';
use App\Controllers\HomeController;

$controller = new HomeController();
echo $controller->index();
