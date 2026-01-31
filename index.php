<?php
require_once 'core.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>داشبورد</title>
    <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="assets/css/font.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#"><?php echo APP_NAME; ?></a>
            <div class="d-flex align-items-center">
                <span class="navbar-text text-white ms-3">
                    سلام، <?php echo htmlspecialchars($_SESSION['username']); ?>
                </span>
                <button id="logoutBtn" class="btn btn-light btn-sm">خروج</button>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">خوش آمدید!</h5>
                <p class="card-text">شما با موفقیت وارد شدید.</p>
            </div>
        </div>
    </div>

    <script src="assets/js/jquery.min.js"></script>
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#logoutBtn').on('click', function() {
                $.post('api.php', {action: 'logout'}, function(response) {
                    if (response.success) {
                        window.location.href = response.data.redirect;
                    }
                }, 'json');
            });
        });
    </script>
</body>
</html>
