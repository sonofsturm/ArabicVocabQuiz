<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'db.php';

$conn = connectToDB();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($newPassword !== $confirmPassword) {

    $error = 'New passwords do not match.';

} elseif (strlen($newPassword) < 8) {

    $error = 'Password must be at least 8 characters.';

} else {

    $stmt = $conn->prepare("
        SELECT password_hash
        FROM users
        WHERE id = :user_id
    ");

    $stmt->execute([
        ':user_id' => $_SESSION['user_id']
    ]);

    $hashedPassword = $stmt->fetchColumn();

    if (!password_verify($currentPassword, $hashedPassword)) {

        $error = 'Current password is incorrect.';

    } elseif (password_verify($newPassword, $hashedPassword)) {

        $error = 'New password must be different from your current password.';

    } else {

        $newHash = password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );

        $stmt = $conn->prepare("
            UPDATE users
            SET password_hash = :password_hash
            WHERE id = :user_id
        ");

        $stmt->execute([
            ':password_hash' => $newHash,
            ':user_id' => $_SESSION['user_id']
        ]);

        $message = 'Password changed successfully.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password</title>
    <link rel="stylesheet" href="style.css?v=4">
</head>
<body>

<?php include 'header.php'; ?>

<div class="quiz-container">

    <h1>Change Password</h1>

    <?php if ($message): ?>
        <p class="green-text">
            <?php echo htmlspecialchars($message); ?>
        </p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p class="red-text">
            <?php echo htmlspecialchars($error); ?>
        </p>
    <?php endif; ?>

    <form method="POST">

        <p>
            <label>Current Password</label><br>
            <input
                type="password"
                name="current_password"
                required
            >
        </p>

        <p>
            <label>New Password</label><br>
            <input
                type="password"
                name="new_password"
                required
            >
        </p>

        <p>
            <label>Confirm New Password</label><br>
            <input
                type="password"
                name="confirm_password"
                required
            >
        </p>

        <button type="submit">
            Change Password
        </button>

    </form>

</div>

</body>
</html>