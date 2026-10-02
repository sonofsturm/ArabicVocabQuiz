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

$stmt = $conn->query("
    SELECT
        id,
        first_name,
        last_name,
        email
    FROM users
    ORDER BY last_name, first_name
");

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin - Users</title>
    <link rel="stylesheet" href="style.css?v=6">
</head>
<body>

<?php include 'header.php'; ?>

<div class="quiz-container">

    <h1>Admin - Users</h1>

    <table border="1" cellpadding="8">

        <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Action</th>
        </tr>

        <?php foreach ($users as $user): ?>

        <tr>
            <td>
                <?php
                echo htmlspecialchars(
                    $user['first_name'] . ' ' . $user['last_name']
                );
                ?>
            </td>

            <td><?php echo htmlspecialchars($user['email']); ?></td>

            <td>

                <a href="reset_user_password.php?id=<?php echo $user['id']; ?>">
                Reset Password
                </a>
            </td>
        </tr>

        <?php endforeach; ?>

    </table>

</div>

</body>
</html>