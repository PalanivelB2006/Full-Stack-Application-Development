<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'student_db';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    dob DATE NOT NULL,
    department VARCHAR(100) NOT NULL,
    phone VARCHAR(15) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Insert new student
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = htmlspecialchars(trim($_POST['name']));
    $email = htmlspecialchars(trim($_POST['email']));
    $dob   = $_POST['dob'];
    $dept  = htmlspecialchars(trim($_POST['department']));
    $phone = htmlspecialchars(trim($_POST['phone']));

    $stmt = $conn->prepare("INSERT INTO students (name, email, dob, department, phone) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('sssss', $name, $email, $dob, $dept, $phone);
    $stmt->execute();
    $stmt->close();

    header('Location: register.php?success=1');
    exit();
}

// Fetch all students
$result = $conn->query("SELECT * FROM students ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Records</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f0f2f5;
            margin: 0;
            padding: 30px 20px;
        }

        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 20px;
        }

        .back {
            display: inline-block;
            margin-bottom: 20px;
            color: #4a90e2;
            text-decoration: none;
            font-size: 14px;
        }

        .back:hover { text-decoration: underline; }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        thead {
            background: #4a90e2;
            color: #fff;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            font-size: 14px;
            border-bottom: 1px solid #eee;
        }

        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f5f8ff; }

        .success {
            background: #e6f4ea;
            color: #2e7d32;
            border: 1px solid #a5d6a7;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 16px;
            font-size: 14px;
            text-align: center;
        }

        .empty {
            text-align: center;
            color: #999;
            padding: 30px;
            font-size: 14px;
        }
    </style>
</head>
<body>

<h2>Student Records</h2>

<?php if (isset($_GET['success'])): ?>
<div class="success">✓ Student registered successfully!</div>
<?php endif; ?>

<a class="back" href="index.php">← Register New Student</a>

<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Name</th>
            <th>Email</th>
            <th>Date of Birth</th>
            <th>Department</th>
            <th>Phone</th>
            <th>Registered On</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($result && $result->num_rows > 0): ?>
            <?php $i = 1; while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?php echo $i++; ?></td>
                <td><?php echo $row['name']; ?></td>
                <td><?php echo $row['email']; ?></td>
                <td><?php echo date('d M Y', strtotime($row['dob'])); ?></td>
                <td><?php echo $row['department']; ?></td>
                <td><?php echo $row['phone']; ?></td>
                <td><?php echo date('d M Y', strtotime($row['created_at'])); ?></td>
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="7" class="empty">No students registered yet.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

</body>
</html>
<?php $conn->close(); ?>
