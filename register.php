<?php
session_start();

require_once 'db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $firstName = trim($_POST['first_name']);
    $lastName = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($password !== $confirmPassword) {
        $message = "Passwords do not match.";
    } elseif (strlen($password) < 8) {
        $message = "Password must be at least 8 characters.";
    } else {
        try {

            $conn = connectToDB();

            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

        $stmt = $conn->prepare("
            INSERT INTO users
            (
                first_name,
                last_name,
                email,
                password_hash,
                role
            )
            VALUES
            (
                :first_name,
                :last_name,
                :email,
                :password_hash,
                :role
            )
        ");

        $stmt->execute([
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':role' => 'user'
        ]);

        $message = "Account created successfully. You may now log in";

        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $message = 'An account with that email already exists.';
            } else {
                error_log('Registration error: ' . $e->getMessage());
                $message = 'Something went wrong. Please try again.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register</title>
    <link rel="stylesheet" href="style.css?v=8">
</head>
<body>
<?php include 'header.php'; ?>

<div class="quiz-container">
<h1>Create Account</h1>

<?php if ($message): ?>
    <p style="color: red;">
        <?php echo htmlspecialchars($message); ?>
    </p>
<?php endif; ?>

<form method="POST">

    <input
        type="text"
        name="first_name"
        placeholder="First Name"
        required
    >
    <br><br>

    <input
        type="text"
        name="last_name"
        placeholder="Last Name"
        required
    >
    <br><br>

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

    <input
        type="password"
        name="confirm_password"
        placeholder="Confirm Password"
        required
    >

    <br><br>
    
    <button type="submit">
        Create Account
    </button>

</form>
    <br><br>

        <a href="login.php">
            Already have an account? Login
        </a>

        <br><br>

        <a href="index.php">
            ← Back to Home
        </a>
</div>
</body>
</html>