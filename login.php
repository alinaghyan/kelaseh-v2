<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود به سیستم</title>
    <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="assets/css/font.css">
    <style>
        body {
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            padding: 2rem;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h3 class="text-center mb-4">ورود به سیستم</h3>
        <div id="alert" class="alert d-none"></div>
        <form id="loginForm">
            <input type="hidden" name="action" value="login">
            <div class="mb-3">
                <label for="username" class="form-label">نام کاربری</label>
                <input type="text" class="form-control" id="username" name="username" required autofocus>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">رمز عبور</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">ورود</button>
        </form>
    </div>

    <script src="assets/js/jquery.min.js"></script>
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#loginForm').on('submit', function(e) {
                e.preventDefault();
                var btn = $(this).find('button[type="submit"]');
                var alertBox = $('#alert');
                
                btn.prop('disabled', true).text('در حال بررسی...');
                alertBox.addClass('d-none').removeClass('alert-success alert-danger');

                $.ajax({
                    url: 'api.php',
                    method: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            alertBox.addClass('alert-success').text(response.message).removeClass('d-none');
                            setTimeout(function() {
                                window.location.href = response.data.redirect;
                            }, 1000);
                        } else {
                            alertBox.addClass('alert-danger').text(response.message).removeClass('d-none');
                            btn.prop('disabled', false).text('ورود');
                        }
                    },
                    error: function() {
                        alertBox.addClass('alert-danger').text('خطایی در برقراری ارتباط رخ داد.').removeClass('d-none');
                        btn.prop('disabled', false).text('ورود');
                    }
                });
            });
        });
    </script>
</body>
</html>
