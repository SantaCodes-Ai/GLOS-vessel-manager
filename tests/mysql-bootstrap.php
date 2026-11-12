<?php

use Tests\Support\MySql\Runtime;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    Runtime::initializeRunner(dirname(__DIR__), $_SERVER['argv'] ?? []);
} catch (Throwable) {
    fwrite(STDERR, "MySQL integration bootstrap refused. Review the private profile, cached configuration, overrides and exclusive test mutex. No database operation was attempted.\n");
    exit(1);
}
