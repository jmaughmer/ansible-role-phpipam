<?php
/**
 * Command line helper used by ansible-role-phpipam.
 *
 *   phpipam-ansible.php <phpipam dir> status
 *       Prints {"code": "1.83.46", "db": "1.83.46", "current": true} as JSON.
 *   phpipam-ansible.php <phpipam dir> upgrade
 *       Runs the database upgrade queries for the code in <phpipam dir>.
 *   phpipam-ansible.php <phpipam dir> set-admin-password
 *       Reads a password on stdin and sets it for the built-in Admin account
 *       (user id 1). Prints "unchanged" when it already matches.
 *
 * Exits non-zero on failure.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}
if ($argc < 3) {
    fwrite(STDERR, "usage: phpipam-ansible.php <phpipam dir> status|upgrade|set-admin-password\n");
    exit(2);
}

$root = rtrim($argv[1], '/');
require_once $root . '/functions/functions.php';

function versions($Database) {
    $Tools = new Tools($Database);
    $settings = $Tools->get_settings();
    $code = VERSION . '.' . DBVERSION;
    $db = $settings->version . '.' . $settings->dbversion;
    return array('code' => $code, 'db' => $db, 'current' => $Tools->cmp_version_strings($db, $code) >= 0);
}

$Database = new Database_PDO;

switch ($argv[2]) {
    case 'status':
        echo json_encode(versions($Database)) . "\n";
        break;

    case 'upgrade':
        $before = versions($Database);
        if ($before['current']) {
            echo "current\n";
            break;
        }
        $Upgrade = new Upgrade($Database);
        ob_start();
        $result = $Upgrade->upgrade_database();
        $output = ob_get_clean();
        // Upgrade keeps its own settings copy; read the versions again.
        $after = versions(new Database_PDO);
        if ($result !== true || !$after['current']) {
            fwrite(STDERR, "upgrade from {$before['db']} to {$before['code']} failed\n" . strip_tags($output) . "\n");
            exit(1);
        }
        echo "upgraded {$before['db']} -> {$after['db']}\n";
        break;

    case 'set-admin-password':
        $password = rtrim(stream_get_contents(STDIN), "\r\n");
        if (strlen($password) < 8) {
            fwrite(STDERR, "password must be at least 8 characters\n");
            exit(1);
        }
        $Admin = new Admin($Database, false);
        $User = new User($Database, true);
        $current = $Admin->fetch_object('users', 'id', 1);
        if ($current === false) {
            fwrite(STDERR, "user id 1 (Admin) not found\n");
            exit(1);
        }
        if (hash_equals((string) $current->password, crypt($password, (string) $current->password)) && $current->passChange === 'No') {
            echo "unchanged\n";
            break;
        }
        $values = array('id' => 1, 'password' => $User->crypt_user_pass($password), 'passChange' => 'No');
        if (!$Admin->object_modify('users', 'edit', 'id', $values)) {
            fwrite(STDERR, "failed to update the Admin password\n");
            exit(1);
        }
        echo "changed\n";
        break;

    default:
        fwrite(STDERR, "unknown command: {$argv[2]}\n");
        exit(2);
}
