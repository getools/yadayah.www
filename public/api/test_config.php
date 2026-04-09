<?php
require_once __DIR__ . '/config.php';

$db = getDb();
$SCOPE = 'page';
$GROUP = 'timeline';

// Test UPDATE
$upd = $db->prepare("UPDATE yy_setting SET setting_value = ? WHERE setting_scope_code = ? AND setting_group_code = ? AND setting_code = ?");
try {
    $upd->execute(['#e5a800', $SCOPE, $GROUP, 'color-row-a']);
    echo "UPDATE rowCount: " . $upd->rowCount() . "\n";
} catch (Exception $e) {
    echo "UPDATE error: " . $e->getMessage() . "\n";
}

// Test INSERT for new key
$ins = $db->prepare("INSERT INTO yy_setting (setting_scope_code, setting_group_code, setting_code, setting_value, setting_value_code) VALUES (?, ?, ?, ?, ?)");
try {
    $ins->execute([$SCOPE, $GROUP, 'ticker-heading-size-test', '1.1', 'ticker-heading-size-test']);
    echo "INSERT ok\n";
    // Clean up
    $db->prepare("DELETE FROM yy_setting WHERE setting_code = 'ticker-heading-size-test'")->execute();
    echo "cleanup ok\n";
} catch (Exception $e) {
    echo "INSERT error: " . $e->getMessage() . "\n";
}
