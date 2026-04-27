<?php

function normalize_decimal_or_null($v) {
    $v = trim((string)$v);
    if ($v === '') return null;

    $v = str_replace(' ', '', $v);

    // Si tiene coma, asumimos formato chileno 1.234,56
    if (strpos($v, ',') !== false) {
        $v = str_replace('.', '', $v);   // quitar miles
        $v = str_replace(',', '.', $v);  // coma decimal → punto
    }

    return is_numeric($v) ? (float)$v : null;
}

function normalize_date_or_null($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    if (!$dt) return null;
    return $dt->format('Y-m-d');
}
