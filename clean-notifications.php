<?php
require_once __DIR__ . '/config.php';
// clean-notifications.php - Clean old self-notifications from database
// Upload to server and access via browser to run

if (php_sapi_name() !== 'cli' && php_sapi_name() !== 'cli-server') {
    http_response_code(403);
    die('Forbidden: CLI only');
}
header('Content-Type: text/plain');

echo "Cleaning self-notifications...\n\n";

try {
    $dbPath = $CONFIG['db_path'] ?? __DIR__ . '/userdata.db';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Find self-notifications (actor_email = recipient's actual email)
    // These were created with wrong actor_email due to bug
    $stmt = $pdo->prepare('
        SELECT n.id, n.recipient_id, n.actor_id, n.actor_email, n.type, u.email as recipient_email
        FROM notifications n
        LEFT JOIN users u ON n.recipient_id = u.id
        WHERE n.actor_email = u.email AND n.actor_id != n.recipient_id
    ');
    $stmt->execute();
    $badNotifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($badNotifs) . " bad notifications (actor_email matches recipient email):\n\n";

    foreach ($badNotifs as $n) {
        echo "  ID: {$n['id']} - actor:{$n['actor_id']}={$n['actor_email']} -> recipient:{$n['recipient_id']} type:{$n['type']}\n";
    }

    echo "\n";

    // Delete these bad notifications
    $stmt = $pdo->prepare('
        DELETE FROM notifications 
        WHERE id IN (
            SELECT n.id FROM notifications n
            LEFT JOIN users u ON n.recipient_id = u.id
            WHERE n.actor_email = u.email AND n.actor_id != n.recipient_id
        )
    ');
    $stmt->execute();
    $deleted = $stmt->rowCount();

    echo "Deleted: $deleted rows\n\n";

    // Also filter self-notifications (actor_id = recipient_id)
    $stmt = $pdo->prepare('DELETE FROM notifications WHERE actor_id = recipient_id');
    $stmt->execute();
    $selfDeleted = $stmt->rowCount();

    echo "Also deleted $selfDeleted self-notifications (actor_id = recipient_id)\n\n";

    echo "Done!\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}