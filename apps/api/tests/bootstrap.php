<?php

/**
 * Runs before vendor/autoload.php (see phpunit.xml.dist bootstrap="tests/bootstrap.php").
 *
 * docker-compose injects APP_ENV/DB_* as real container environment variables (via
 * env_file: docker/api/.env.docker), which the PHP CLI SAPI copies into $_SERVER before
 * PHPUnit ever runs. PHPUnit's <env force="true"> only updates putenv()/$_ENV, not
 * $_SERVER — and Laravel's env() repository checks $_SERVER first, so it silently keeps
 * reading the container's real "local"/"pdo_db" values regardless of phpunit.xml. Setting
 * all three here, before autoload, is the only combination that actually overrides it.
 *
 * Tests must run against Postgres (not sqlite) — pdo_db_test is a separate database on
 * the same Postgres container, isolated from dev data so RefreshDatabase never touches it.
 */

$overrides = [
    'APP_ENV'       => 'testing',
    'DB_CONNECTION' => 'pgsql',
    'DB_HOST'       => 'db',
    'DB_PORT'       => '5432',
    'DB_DATABASE'   => 'pdo_db_test',
    'DB_USERNAME'   => 'pdo_user',
    'DB_PASSWORD'   => 'secret',
];

foreach ($overrides as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key]    = $value;
    $_SERVER[$key] = $value;
}

require __DIR__ . '/../vendor/autoload.php';
