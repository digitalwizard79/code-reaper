<?php
namespace App\Utils;

class Stringy {
    public static function snake(string $s): string {
        return strtolower(preg_replace('/[^\w]+/', '_', $s));
    }
}
