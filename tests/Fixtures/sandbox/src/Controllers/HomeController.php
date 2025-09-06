<?php
namespace App\Controllers;

use App\Utils\Math;
use App\Utils\Stringy; // intentionally unused

class HomeController {
    public function index(): string {
        return "Sum is " . Math::sum(2, 3);
    }
    private function debugDump(array $a): void { /* unused */ }
}
