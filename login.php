<?php
// timny change
session_start();

require_once 'db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email']);
    $password = $_POST['password'];

    try {

        $conn = connectToDB();

        $stmt = $conn->prepare("
            SELECT *
            FROM users
            WHERE email = :email
            LIMIT 1
        ");

        $stmt->execute([
            ':email' => $email
        ]);

        $user = $stmt->fetch();

        if (
            $user &&
            password_verify(
                $password,
                $user['password_hash']
            )
        ) {

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['role'] = $user['role'];
            
            session_regenerate_id(true);
            
            header('Location: index.php');
            exit();

        } else {

            $message = 'Invalid email or password.';
        }

    } catch (Exception $e) {
        error_log('Login error: ' . $e->getMessage());
        $message = 'Something went wrong. Please try again.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    
<div class="top-bar">
    <a href="register.php">Create Account</a>
</div>

<div class="quiz-container">

     
     
    <h1>Login</h1>  

    <?php if (!empty($message)): ?>
        <p style="color:red;">
            <?php echo htmlspecialchars($message); ?>
        </p>
    <?php endif; ?>

    <form method="POST">

        <input
            type="email"
            name="email"
            placeholder="Email"
            required
        >

        <br><br>

        <input
            type="password"
            name="password"
            placeholder="Password"
            required
        >

        <br><br>

        <button type="submit">
            Login
        </button>

    </form>

    <br>
    
<!-- Navigation Links -->

    <a href="index.php">
        ← Back to Home
    </a>

</div>

</body>
</html>