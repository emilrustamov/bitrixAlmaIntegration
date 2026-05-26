<?php

function normalizeBitrixEntityId($value) {
    if ($value === null || $value === '') {
        return null;
    }
    $s = trim((string) $value);
    if (preg_match('/^T\d+_(\d+)$/', $s, $m)) {
        return (int) $m[1];
    }
    return is_numeric($s) ? (int) $s : null;
}

function firstListPropValue(array $element, $key) {
    $prop = $element[$key] ?? null;
    if (!is_array($prop) || $prop === []) {
        return null;
    }
    $v = reset($prop);
    return $v === false ? null : (string) $v;
}
