<?php

function db_errors_to_string(): string {
    $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    if (!$errors) $errors = sqlsrv_errors();

    if (!$errors) return "Error SQL Server desconocido.";

    $lines = [];
    foreach ($errors as $e) {
        $lines[] = "[SQLSTATE {$e['SQLSTATE']}] ({$e['code']}) {$e['message']}";
    }
    return implode(" | ", $lines);
}

function db_query($conn, string $sql, array $params = [], string $context = '') {
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $msg = db_errors_to_string();
        $ctx = $context ? " ($context)" : "";
        throw new RuntimeException("SQL Server error$ctx: $msg");
    }
    return $stmt;
}
