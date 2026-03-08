<?php
require_once 'c:\XAMPP\htdocs\cms\config.php';
$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    echo "Table: $table\n";
    $columns = $db->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
}
