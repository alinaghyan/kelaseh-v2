<?php

$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
$cfg = is_file($configPath) ? require $configPath : [];
$appName = is_array($cfg) ? (string)($cfg['app']['name'] ?? 'کلاسه') : 'کلاسه';

?><!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.rtl.min.css" />
  <link rel="stylesheet" href="assets/css/app.css" />
</head>
<body>
  <div class="container py-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div>
        <h1 class="h5 m-0"><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></h1>
      </div>
      <div class="d-flex gap-2">
        <span id="currentRole" class="badge text-bg-light d-none"></span>
        <button id="btnLogout" type="button" class="btn btn-outline-danger btn-sm d-none">خروج</button>
      </div>
    </div>

    <div id="toastHost" class="toast-container position-fixed top-0 start-0 p-3"></div>

    <div id="viewLoading" class="card">
      <div class="card-body">
        <div class="d-flex align-items-center gap-2">
          <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
          <div>در حال آماده‌سازی…</div>
        </div>
      </div>
    </div>

    <div id="viewAuth" class="row justify-content-center d-none">
      <div class="col-12 col-md-6 col-lg-5">
        <div class="card shadow-sm">
          <div class="card-body">
            <form id="formLogin" class="vstack gap-2">
              <div>
                <label class="form-label">ایمیل</label>
                <input name="email" type="email" class="form-control" autocomplete="email" required />
              </div>
              <div>
                <label class="form-label">رمز عبور</label>
                <input name="password" type="password" class="form-control" autocomplete="current-password" required />
              </div>
              <button class="btn btn-primary" type="submit">ورود</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <div id="viewApp" class="d-none">
      <div class="row g-3">
        <div class="col-12 col-lg-4">
          <div class="card">
            <div class="card-header">پروفایل</div>
            <div class="card-body">
              <div class="mb-2">
                <div class="text-secondary small">ایمیل</div>
                <div id="profileEmail" class="fw-semibold"></div>
              </div>
              <div class="mb-2">
                <div class="text-secondary small">نام نمایشی</div>
                <div id="profileName" class="fw-semibold"></div>
              </div>
              <div class="mb-2">
                <div class="text-secondary small">نقش</div>
                <div id="profileRole" class="fw-semibold"></div>
              </div>
              <div class="text-secondary small">تقویم نمایش: شمسی (تهران)</div>
            </div>
          </div>

          <div id="adminPanel" class="card mt-3 d-none">
            <div class="card-header">پنل مدیر کل</div>
            <div class="card-body">
              <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                  <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#adminUsers" type="button" role="tab">کاربران</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" data-bs-toggle="tab" data-bs-target="#adminItems" type="button" role="tab">داده‌ها</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" data-bs-toggle="tab" data-bs-target="#adminLogs" type="button" role="tab">گزارش</button>
                </li>
              </ul>
              <div class="tab-content">
                <div class="tab-pane fade show active" id="adminUsers" role="tabpanel">
                  <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text">جستجو</span>
                    <input id="adminUsersQuery" type="text" class="form-control" placeholder="ایمیل یا نام" />
                    <button id="btnAdminUsersRefresh" class="btn btn-outline-secondary" type="button">تازه‌سازی</button>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle">
                      <thead>
                        <tr>
                          <th>ایمیل</th>
                          <th>نقش</th>
                          <th>فعال</th>
                          <th>عملیات</th>
                        </tr>
                      </thead>
                      <tbody id="adminUsersTbody"></tbody>
                    </table>
                  </div>
                </div>
                <div class="tab-pane fade" id="adminItems" role="tabpanel">
                  <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text">جستجو</span>
                    <input id="adminItemsQuery" type="text" class="form-control" placeholder="ایمیل/عنوان/توضیح" />
                    <button id="btnAdminItemsRefresh" class="btn btn-outline-secondary" type="button">تازه‌سازی</button>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle">
                      <thead>
                        <tr>
                          <th>مالک</th>
                          <th>عنوان</th>
                          <th>تاریخ</th>
                          <th class="text-end">عملیات</th>
                        </tr>
                      </thead>
                      <tbody id="adminItemsTbody"></tbody>
                    </table>
                  </div>
                </div>
                <div class="tab-pane fade" id="adminLogs" role="tabpanel">
                  <button id="btnAdminLogsRefresh" class="btn btn-outline-secondary btn-sm mb-2" type="button">تازه‌سازی</button>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle">
                      <thead>
                        <tr>
                          <th>زمان</th>
                          <th>Actor</th>
                          <th>Action</th>
                          <th>Entity</th>
                        </tr>
                      </thead>
                      <tbody id="adminLogsTbody"></tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-8">
          <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
              <div>مدیریت داده‌ها</div>
              <button id="btnItemsRefresh" type="button" class="btn btn-outline-secondary btn-sm">تازه‌سازی</button>
            </div>
            <div class="card-body">
              <form id="formItem" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="id" value="" />
                <div class="col-12 col-md-5">
                  <label class="form-label">عنوان</label>
                  <input name="title" type="text" class="form-control" required />
                </div>
                <div class="col-12 col-md-5">
                  <label class="form-label">توضیح</label>
                  <input name="content" type="text" class="form-control" />
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                  <button id="btnItemSubmit" class="btn btn-primary w-100" type="submit">ثبت</button>
                  <button id="btnItemCancel" class="btn btn-outline-secondary w-100 d-none" type="button">لغو</button>
                </div>
              </form>

              <div class="input-group input-group-sm mb-2">
                <span class="input-group-text">جستجو</span>
                <input id="itemsQuery" type="text" class="form-control" placeholder="عنوان یا توضیح" />
                <button id="btnItemsSearch" class="btn btn-outline-secondary" type="button">اعمال</button>
              </div>

              <div class="table-responsive">
                <table class="table table-hover align-middle">
                  <thead>
                    <tr>
                      <th>عنوان</th>
                      <th>تاریخ</th>
                      <th class="text-end">عملیات</th>
                    </tr>
                  </thead>
                  <tbody id="itemsTbody"></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="assets/vendor/jquery/jquery.min.js"></script>
  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/app.js"></script>
</body>
</html>
