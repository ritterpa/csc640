<?php
/**
 * PHP and MySQL check.
 *
 * 1. If this page shows as HTML (not as raw PHP source), NGINX is passing
 *    .php files to PHP-FPM correctly.
 * 2. It connects to MySQL with the settings in code/nginx/config.php and reports the
 *    row count of every table the InfraLogging schema should have.
 *
 * The database only needs to exist (deployed schema); it does not need data.
 */
declare(strict_types=1);

// Tables created by Deploy-InfraLogging.mysql.sql.
const EXPECTED_TABLES = [
    'ApiUser',
    'ScriptCatalog',
    'ScriptRun',
    'LogEntry',
    'ExceptionLog',
    'PerformanceMetric',
];

$results = [];   // table name => row count, or null if the table is missing
$dbError = null;
$config = null;

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    $dbError = 'code/nginx/config.php was not found. Run code/nginx/setup-nginx.sh to create it.';
} elseif (!extension_loaded('pdo_mysql')) {
    $dbError = 'The PHP MySQL driver (pdo_mysql) is not installed. Install php-mysql and restart PHP-FPM.';
} else {
    $config = require $configFile;
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config['host'], $config['port'], $config['database']),
            $config['user'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Which of the expected tables exist in this database?
        $stmt = $pdo->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?'
        );
        $stmt->execute([$config['database']]);
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach (EXPECTED_TABLES as $table) {
            if (in_array($table, $existing, true)) {
                // Table names come from the fixed list above, never from user input.
                $results[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            } else {
                $results[$table] = null;
            }
        }
    } catch (PDOException $e) {
        $dbError = 'Could not connect to MySQL: ' . $e->getMessage();
    }
}

$missing = array_keys(array_filter($results, fn ($count) => $count === null));
$allFound = $dbError === null && $missing === [];

function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PHP and MySQL test</title>
  <style>
    body { font: 16px/1.5 system-ui, sans-serif; max-width: 640px; margin: 48px auto; padding: 0 16px; color: #1b2a3a; }
    h1 { font-size: 1.5rem; }
    h2 { font-size: 1.125rem; margin-top: 32px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { text-align: left; padding: 6px 10px; border-bottom: 1px solid #d6dfe7; }
    th.num, td.num { text-align: right; font-variant-numeric: tabular-nums; }
    .ok { color: #2c7a4b; }
    .bad { color: #b5382c; }
  </style>
</head>
<body>
  <h1>PHP and MySQL test</h1>

  <h2>PHP</h2>
  <!-- code/nginx/setup-nginx.sh looks for the id="..." markers on this page. -->
  <p id="php-ok" class="ok">PHP <?= e(PHP_VERSION) ?> is running through <?= e(PHP_SAPI) ?>.</p>

  <h2>MySQL</h2>
<?php if ($dbError !== null): ?>
  <p id="mysql-error" class="bad"><?= e($dbError) ?></p>
<?php else: ?>
  <p id="mysql-ok" class="ok">Connected to database <?= e($config['database']) ?> on <?= e($config['host']) ?> as <?= e($config['user']) ?>.</p>
  <table>
    <thead><tr><th>Table</th><th class="num">Rows</th></tr></thead>
    <tbody>
<?php foreach ($results as $table => $count): ?>
      <tr>
        <td><?= e($table) ?></td>
        <td class="num <?= $count === null ? 'bad' : '' ?>"><?= $count === null ? 'missing' : $count ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php if ($allFound): ?>
  <p id="tables-ok" class="ok">All <?= count(EXPECTED_TABLES) ?> tables exist.</p>
<?php else: ?>
  <p id="tables-missing" class="bad">Missing tables: <?= e(implode(', ', $missing)) ?>. Deploy the schema with Deploy-InfraLogging.mysql.sql.</p>
<?php endif; ?>
<?php endif; ?>
</body>
</html>
