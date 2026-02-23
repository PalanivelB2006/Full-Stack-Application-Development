<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }

        .container {
            background: #fff;
            padding: 30px 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 480px;
        }

        h2 {
            text-align: center;
            margin-bottom: 24px;
            color: #333;
            font-size: 22px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-size: 14px;
            color: #555;
            font-weight: bold;
        }

        input, select {
            width: 100%;
            padding: 10px 12px;
            margin-bottom: 18px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 14px;
            box-sizing: border-box;
            outline: none;
            transition: border 0.2s;
        }

        input:focus, select:focus {
            border-color: #4a90e2;
        }

        button {
            width: 100%;
            padding: 11px;
            background: #4a90e2;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: 15px;
            cursor: pointer;
        }

        button:hover {
            background: #357abd;
        }

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
    </style>
</head>
<body>

<div class="container">
    <h2>Student Registration</h2>

    <?php if (isset($_GET['success'])): ?>
    <div class="success">✓ Student registered successfully!</div>
    <?php endif; ?>

    <form action="register.php" method="POST">

        <label>Full Name</label>
        <input type="text" name="name" placeholder="Enter full name" required>

        <label>Email</label>
        <input type="email" name="email" placeholder="Enter email" required>

        <label>Date of Birth</label>
        <input type="date" name="dob" required>

        <label>Department</label>
        <select name="department" required>
            <option value="">-- Select Department --</option>
            <option>Computer Science</option>
            <option>Information Technology</option>
            <option>Electronics</option>
            <option>Mechanical</option>
            <option>Civil</option>
            <option>Business Administration</option>
        </select>

        <label>Phone Number</label>
        <input type="tel" name="phone" placeholder="Enter phone number" pattern="[0-9]{10}" maxlength="10" required>

        <button type="submit">Register</button>

    </form>
</div>

</body>
</html>
