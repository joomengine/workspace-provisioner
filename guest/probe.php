<?php

declare(strict_types=1);

// Always invoke as the workload UID, never as VM/host root: configuration.php is mutable.
try {
    if (!is_file('/var/www/html/configuration.php')) { exit(3); }
    require '/var/www/html/configuration.php';
    $config = new JConfig();
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $config->dbprefix)) { exit(4); }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($config->host, $config->user, $config->password, $config->db);
    $result = $db->query("SELECT extension_id FROM `{$config->dbprefix}extensions` WHERE element='com_componentbuilder' AND type='component' AND enabled=1");
    if ($result->num_rows !== 1 || is_dir('/var/www/html/installation')) { exit(5); }
    $extensions = get_loaded_extensions();
    sort($extensions);
    echo json_encode(['ready' => true, 'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        'extensions' => $extensions], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable) {
    exit(6);
}
