<?php
declare(strict_types=1);

namespace InfraLogging;

use PDO;

/**
 * Shared services for one request: configuration, the database connection
 * (opened only when first needed), and the auth helper.
 */
final class App
{
    public readonly Config $config;
    private ?PDO $pdo = null;
    private ?Auth $auth = null;

    public function __construct(string $rootDir)
    {
        $this->config = Config::load($rootDir . '/.env');
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->config->get('DB_HOST', '127.0.0.1'),
                $this->config->int('DB_PORT', 3306),
                $this->config->get('DB_NAME', 'InfrastructureLogging')
            );
            $this->pdo = new PDO($dsn, $this->config->get('DB_USER'), $this->config->get('DB_PASS', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements and native int types
            ]);
        }
        return $this->pdo;
    }

    public function auth(): Auth
    {
        return $this->auth ??= new Auth(
            $this->config->get('JWT_SECRET'),
            $this->config->int('JWT_TTL', 3600)
        );
    }
}
