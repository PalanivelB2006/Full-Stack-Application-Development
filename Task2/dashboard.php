<?php
// Simple dashboard: sorting, filtering, count per department

$conn = new mysqli("localhost", "root", "", "students_db");
if ($conn->connect_error) {
    die("Database connection failed");
}

// Read sort + filter from URL
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'name';   // name | date
$dept = isset($_GET['dept']) ? $_GET['dept'] : '';

// Decide ORDER BY column
if ($sort === 'date') {
    $orderBy = "dob";
} else {
    $orderBy = "name";
}

// Build main SELECT query
if ($dept !== "") {
    $sql = "SELECT * FROM students WHERE dept = ? ORDER BY $orderBy ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $dept);
} else {
    $sql = "SELECT * FROM students ORDER BY $orderBy ASC";
    $stmt = $conn->prepare($sql);
}
$stmt->execute();
$result = $stmt->get_result();

// Get list of departments for dropdown
$deptResult = $conn->query("SELECT DISTINCT dept FROM students ORDER BY dept");

// Get count of students per department
$countResult = $conn->query("SELECT dept, COUNT(*) AS total FROM students GROUP BY dept ORDER BY dept");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Student Dashboard</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background:#f4f4f4;
            padding:20px;
        }
        h1, h2 {
            margin: 0 0 10px 0;
        }
        .box {
            background:#fff;
            padding:15px;
            margin-bottom:20px;
            border:1px solid #ddd;
        }
        form.controls {
            margin-bottom:10px;
        }
        select, button {
            padding:5px 8px;
            margin-right:8px;
        }
        table {
            width:100%;
            border-collapse:collapse;
            margin-top:10px;
        }
        th, td {
            padding:8px;
            border:1px solid #ccc;
            text-align:left;
        }
        th {
            background:#333;
            color:#fff;
        }
        .badge {
            padding:2px 6px;
            background:#eee;
            border-radius:4px;
            font-size:12px;
        }
        .chip {
            display:inline-block;
            padding:5px 10px;
            margin:3px;
            background:#333;
            color:#fff;
            border-radius:12px;
            font-size:12px;
        }
    </style>
</head>
<body>

<div class="box">
    <h1>Student Data Dashboard</h1>
    <form class="controls" method="get">
        <label>Sort by:</label>
        <select name="sort">
            <option value="name" <?php if($sort == 'name') echo 'selected'; ?>>Name (A–Z)</option>
            <option value="date" <?php if($sort == 'date') echo 'selected'; ?>>DOB (oldest first)</option>
        </select>

        <label>Department:</label>
        <select name="dept">
            <option value="">All</option>
            <?php while ($d = $deptResult->fetch_assoc()) { ?>
                <option value="<?php echo $d['dept']; ?>" <?php if($dept == $d['dept']) echo 'selected'; ?>>
                    <?php echo $d['dept']; ?>
                </option>
            <?php } ?>
        </select>

        <button type="submit">Apply</button>
    </form>

    <table>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Email</th>
            <th>DOB</th>
            <th>Department</th>
            <th>Phone</th>
        </tr>
        <?php if ($result->num_rows > 0) { ?>
            <?php while ($row = $result->fetch_assoc()) { ?>
                <tr>
                    <td><?php echo $row['id']; ?></td>
                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                    <td><?php echo $row['dob']; ?></td>
                    <td><span class="badge"><?php echo htmlspecialchars($row['dept']); ?></span></td>
                    <td><?php echo htmlspecialchars($row['phone']); ?></td>
                </tr>
            <?php } ?>
        <?php } else { ?>
            <tr><td colspan="6">No records found.</td></tr>
        <?php } ?>
    </table>
</div>

<div class="box">
    <h2>Count of Students per Department</h2>
    <?php if ($countResult->num_rows > 0) { ?>
        <?php while ($c = $countResult->fetch_assoc()) { ?>
            <span class="chip">
                <?php echo htmlspecialchars($c['dept']); ?> : <?php echo $c['total']; ?>
            </span>
        <?php } ?>
    <?php } else { ?>
        <p>No data to show.</p>
    <?php } ?>
</div>

</body>
</html>
<?php
$stmt->close();
$conn->close();
?>
