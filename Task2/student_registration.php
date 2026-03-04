<?php
$conn = new mysqli("localhost", "root", "", "students_db");
if ($conn->connect_error) die("DB error");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $stmt = $conn->prepare("INSERT INTO students (name,email,dob,dept,phone) VALUES(?,?,?,?,?)");
    $stmt->bind_param("sssss", $_POST['name'], $_POST['email'], $_POST['dob'], $_POST['dept'], $_POST['phone']);
    $stmt->execute();
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Student Registration</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{
    font-family:'Poppins',sans-serif;
    background:#f3f4f6;
    display:flex;
    justify-content:center;
    align-items:flex-start;
    min-height:100vh;
    padding:40px 10px;
}
.card{
    background:#ffffff;
    width:100%;
    max-width:420px;
    padding:24px 24px 18px;
    border-radius:14px;
    box-shadow:0 8px 24px rgba(15,23,42,0.12);
}
.card h2{
    font-size:22px;
    margin-bottom:4px;
    color:#111827;
}
.card p{
    font-size:13px;
    color:#6b7280;
    margin-bottom:18px;
}
.field{
    margin-bottom:14px;
}
label{
    display:block;
    font-size:13px;
    color:#374151;
    margin-bottom:4px;
}
input{
    width:100%;
    padding:9px 11px;
    border:1px solid #d1d5db;
    border-radius:8px;
    font-size:13px;
    outline:none;
    transition:border-color 0.15s, box-shadow 0.15s;
}
input:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,0.15);
}
button{
    width:100%;
    margin-top:4px;
    border:none;
    border-radius:999px;
    padding:9px 0;
    background:#2563eb;
    color:white;
    font-size:14px;
    font-weight:500;
    cursor:pointer;
    transition:background 0.15s, transform 0.05s;
}
button:hover{background:#1d4ed8;}
button:active{transform:scale(0.98);}
.table-wrapper{
    width:100%;
    max-width:840px;
    margin-left:24px;
}
@media(max-width:900px){
    body{flex-direction:column;align-items:center;}
    .table-wrapper{margin-left:0;margin-top:20px;}
}
table{
    width:100%;
    border-collapse:collapse;
    font-size:13px;
    background:#ffffff;
    border-radius:12px;
    overflow:hidden;
    box-shadow:0 8px 24px rgba(15,23,42,0.08);
}
th,td{
    padding:8px 10px;
    text-align:left;
}
th{
    background:#111827;
    color:#f9fafb;
    font-weight:500;
}
tr:nth-child(even){background:#f9fafb;}
</style>
</head>
<body>

<div class="card">
    <h2>Student Registration</h2>
    <p>Enter student details to store in the database.</p>
    <form method="POST">
        <div class="field">
            <label for="name">Name</label>
            <input id="name" name="name" type="text" required>
        </div>
        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required>
        </div>
        <div class="field">
            <label for="dob">Date of Birth</label>
            <input id="dob" name="dob" type="date" required>
        </div>
        <div class="field">
            <label for="dept">Department</label>
            <input id="dept" name="dept" type="text" required>
        </div>
        <div class="field">
            <label for="phone">Phone</label>
            <input id="phone" name="phone" type="tel" required>
        </div>
        <button type="submit">Save Student</button>
    </form>
</div>

<div class="table-wrapper">
    <table>
        <tr>
            <th>ID</th><th>Name</th><th>Email</th><th>DOB</th><th>Dept</th><th>Phone</th>
        </tr>
        <?php
        $result = $conn->query("SELECT * FROM students ORDER BY id DESC");
        if ($result && $result->num_rows>0){
            while($row=$result->fetch_assoc()){
                echo "<tr>
                        <td>{$row['id']}</td>
                        <td>{$row['name']}</td>
                        <td>{$row['email']}</td>
                        <td>{$row['dob']}</td>
                        <td>{$row['dept']}</td>
                        <td>{$row['phone']}</td>
                      </tr>";
            }
        }
        $conn->close();
        ?>
    </table>
</div>

</body>
</html>
