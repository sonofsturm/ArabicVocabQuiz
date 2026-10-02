<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    die('Access denied.');
}

require_once 'db.php';

$conn = connectToDB();

$userId = (int)($_GET['id'] ?? 0);

$stmt = $conn->prepare("
    SELECT
        first_name,
        last_name,
        email
    FROM users
    WHERE id = :id
");

$stmt->execute([
    ':id' => $userId
]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die('User not found.');
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (
        !hash_equals(
            $_SESSION['csrf_token'] ?? '',
            $_POST['csrf_token'] ?? ''
        )
    ) {
        die('Invalid request.');
    }
    
    $newPassword = trim($_POST['new_password']);

    if (strlen($newPassword) < 8) {

        $message = 'Password must be at least 8 characters.';

    } else {

        $passwordHash = password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );

        $stmt = $conn->prepare("
            UPDATE users
            SET password_hash = :password_hash
            WHERE id = :id
        ");

        $stmt->execute([
            ':password_hash' => $passwordHash,
            ':id' => $userId
        ]);

        $message = 'Password reset successfully.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset User Password</title>
    <link rel="stylesheet" href="style.css?v=7">
</head>
<body>

<?php include 'header.php'; ?>

<div class="quiz-container">

    <h1>Reset User Password</h1>

    <p>
        <strong>Name:</strong>
        <?php echo htmlspecialchars(
            $user['first_name'] . ' ' . $user['last_name']
        ); ?>

    </p>

    <p>
        <strong>Email:</strong>
        <?php echo htmlspecialchars($user['email']); ?>
    </p>

    <?php if (!empty($message)): ?>
        <p>
            <?php echo htmlspecialchars($message); ?>
        </p>
    <?php endif; ?>

    <form method="POST">
        
        <?php if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } ?>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <p>
            <label>Temporary Password</label><br>

            <input
                type="text"
                name="new_password"
                required
            >
        </p>

        <button type="submit">
            Reset Password
        </button>

    </form>

    <br>

    <a href="admin_users.php">
        ← Back to Admin Users
    </a>

</div>

</body>
</html>
