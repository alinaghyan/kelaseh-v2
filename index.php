<?php
/**
 * نقطه ورود اصلی برنامه و رابط کاربری (Frontend)
 * این فایل ساختار HTML صفحات را می‌سازد و کدهای JS/CSS را بارگذاری می‌کند.
 */

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
$cfg = is_file($configPath) ? require $configPath : [];
$appName = is_array($cfg) ? (string)($cfg['app']['name'] ?? 'کلاسه') : 'کلاسه';

?><!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.rtl.min.css?v=5.3" />
  <link rel="stylesheet" href="https://unpkg.com/persian-datepicker@1.2.0/dist/css/persian-datepicker.min.css" />
  <link rel="stylesheet" href="assets/css/app.css?v=<?php echo file_exists(__DIR__ . '/assets/css/app.css') ? filemtime(__DIR__ . '/assets/css/app.css') : '1'; ?>" />
</head>
<body>
  <!-- Modal Office Create User -->
  <div class="modal fade" id="modalOfficeCreateUser" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title fs-6">ایجاد مدیر شعبه</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="formOfficeCreateUser">
            <input type="hidden" name="role" value="branch_admin">
            <div class="row g-2">
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">نام</label>
                <input type="text" class="form-control form-control-sm" name="first_name" required>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">نام خانوادگی</label>
                <input type="text" class="form-control form-control-sm" name="last_name" required>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">ایمیل/نام کاربری</label>
                <input type="text" class="form-control form-control-sm" name="username" required>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">شماره موبایل</label>
                <input type="text" class="form-control form-control-sm" name="mobile" required maxlength="11">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">رمز عبور</label>
                <input type="password" class="form-control form-control-sm" name="password" required minlength="6">
              </div>
              <div class="col-12">
                 <div class="d-flex align-items-center justify-content-between">
                   <label class="form-label form-label-sm mb-1">شعبه‌های مجاز</label>
                   <div class="form-check form-switch">
                     <input id="officeCreateManualBranches" class="form-check-input" type="checkbox" checked>
                     <label class="form-check-label small" for="officeCreateManualBranches">انتخاب دستی شعب</label>
                   </div>
                 </div>

                 <div id="officeCreateBranchListWrap" class="card p-2 bg-light">
                    <div id="officeCreateBranchList" class="row g-2"></div>
                 </div>

                 <div id="officeCreateBranchRangeWrap" class="card p-2 bg-light d-none">
                   <div class="row g-2">
                     <div class="col-12 col-md-6">
                       <label class="form-label form-label-sm">شروع از شعبه</label>
                       <input name="branch_start_no" type="number" class="form-control form-control-sm" min="1" max="99" value="1" />
                     </div>
                     <div class="col-12 col-md-6">
                       <label class="form-label form-label-sm">تعداد شعب</label>
                       <input name="branch_count" type="number" class="form-control form-control-sm" min="1" max="99" value="1" />
                     </div>
                   </div>
                 </div>
              </div>
            </div>
            <div class="mt-3 text-end">
              <button type="submit" class="btn btn-primary btn-sm">ایجاد</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div id="viewLoading" class="card position-fixed top-50 start-50 translate-middle shadow-sm d-none" style="z-index: 1060; min-width: 250px;">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2">
        <div class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></div>
        <div>در حال آماده‌سازی…</div>
      </div>
    </div>
  </div>

  <div id="viewAuth" class="auth-container d-none">
    <div class="auth-card">
      <div class="card-body p-4 text-center">
        <div class="auth-logo-container">
          <img src="assets/img/logo.png" alt="Logo" class="auth-logo" onerror="this.src='https://img.icons8.com/ios-filled/100/ffffff/camera.png'">
        </div>
        <h2 class="auth-title h4"><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="auth-subtitle">خوش آمدید، لطفا وارد حساب خود شوید</p>

        <form id="formLogin" class="vstack gap-2 text-start">
          <div class="auth-input-group form-floating">
            <span class="auth-input-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                <path d="M3 14s-1 0-1-1 1-4 6-4 6 3 6 4-1 1-1 1zm5-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6"/>
              </svg>
            </span>
            <input name="login" type="text" class="form-control" id="loginInput" placeholder="نام کاربری" autocomplete="username" required />
            <label for="loginInput">نام کاربری یا ایمیل</label>
          </div>

          <div class="auth-input-group form-floating">
            <span class="auth-input-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2m3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2"/>
              </svg>
            </span>
            <input name="password" type="password" class="form-control" id="passwordInput" placeholder="رمز عبور" autocomplete="current-password" required />
            <label for="passwordInput">رمز عبور</label>
          </div>

          <div id="loginOtpSection" class="d-none">
            <div class="auth-input-group form-floating">
              <span class="auth-input-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                  <path d="M8 16a8 8 0 1 0 0-16 8 8 0 0 0 0 16m.93-9.412-1 4.705c-.07.34.029.533.308.533.19 0 .452-.084.626-.182.028-.016.041-.01.041.012a.1.1 0 0 1-.03.07 1.9 1.9 0 0 1-.765.499c-.481.187-.999-.087-1.143-.592l-1.203-5.497c-.144-.527.215-.922.819-.922.51 0 .88.167 1.034.525.02.047.025.077.011.077a.17.17 0 0 0-.039-.01c-.16-.032-.392-.063-.518-.063-.279 0-.471.199-.308.53l.93 4.35Z"/>
                </svg>
              </span>
              <input id="loginOtpInput" name="otp" type="text" class="form-control" placeholder="کد تایید" inputmode="numeric" maxlength="8" />
              <label for="loginOtpInput">کد تایید</label>
            </div>
            <div id="loginOtpHint" class="form-text text-center small text-white-50 mb-3"></div>
          </div>

          <button class="btn btn-primary auth-btn w-100" type="submit" id="btnLoginSubmit">
            <span class="btn-text">ورود به سیستم</span>
            <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
          </button>
          
          <button id="btnLoginOtpVerify" class="btn btn-outline-light auth-btn w-100 d-none" type="button">تأیید کد و ورود</button>
        </form>

        <div class="auth-footer">
          &copy; <?php echo date('Y'); ?> تمامی حقوق محفوظ است
        </div>
      </div>
    </div>
  </div>

  <div id="mainHeader" class="container-fluid py-3 d-none" style="max-width: 1400px; margin: 0 auto;">
    <div class="d-flex flex-column gap-2 mb-3">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="d-flex align-items-center gap-3">
          <img src="assets/img/logo.png" alt="Logo" style="height: 50px;" onerror="this.style.display='none'">
          <div class="d-flex flex-column">
            <h1 class="h5 m-0"><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></h1>
            <div id="kelasehOffice" class="small text-secondary d-none"></div>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <div id="headerDateTime" class="small text-secondary d-none"></div>
          <span id="currentRole" class="badge text-bg-light d-none"></span>
          <button id="btnLogout" type="button" class="btn btn-outline-danger btn-sm d-none">خروج</button>
        </div>
      </div>

      <ul id="headerNav" class="nav nav-pills d-none">
        <li class="nav-item"><a class="nav-link" href="#create" data-page="create">ایجاد شماره کلاسه</a></li>
        <li class="nav-item"><a class="nav-link" href="#dashboard" data-page="dashboard">پنل کاربری</a></li>
        <li class="nav-item"><a class="nav-link" href="#profile" data-page="profile">پروفایل</a></li>
        <li class="nav-item" id="navItemAdmin"><a class="nav-link" href="#admin" data-page="admin">پنل مدیر کل</a></li>
        <li class="nav-item" id="navItemAdminKelasehSearch"><a class="nav-link" href="#admin-kelaseh-search" data-page="admin-kelaseh-search">جستجوی پرونده</a></li>
      </ul>
    </div>
  </div>

  <div class="container-fluid" style="max-width: 1400px; margin: 0 auto;">
    <div id="toastHost" class="toast-container position-fixed top-0 start-0 p-3"></div>

    <div id="viewApp" class="d-none">
      <div class="row g-3" id="appRow">
        <div class="col-12" id="colRight">
          <div class="card" id="cardProfile">
            <div class="card-header">پروفایل</div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-3 mb-2">
                  <div class="text-secondary small">نام کاربری</div>
                  <div id="profileEmail" class="fw-semibold"></div>
                </div>
                <div class="col-md-3 mb-2">
                  <div class="text-secondary small">نام نمایشی</div>
                  <div id="profileName" class="fw-semibold"></div>
                </div>
                <div class="col-md-3 mb-2">
                  <div class="text-secondary small">نقش</div>
                  <div id="profileRole" class="fw-semibold"></div>
                </div>
                <div class="col-md-3 mb-2">
                   <div class="text-secondary small">تقویم نمایش</div>
                   <div>شمسی (تهران)</div>
                </div>
              </div>
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
                  <button class="nav-link" data-bs-toggle="tab" data-bs-target="#adminCities" type="button" role="tab">اداره‌ها</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" data-bs-toggle="tab" data-bs-target="#adminItems" type="button" role="tab">داده‌ها</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" data-bs-toggle="tab" data-bs-target="#adminLogs" type="button" role="tab">گزارش</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" data-bs-toggle="tab" data-bs-target="#adminStats" type="button" role="tab">آمار</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" data-bs-toggle="tab" data-bs-target="#adminSms" type="button" role="tab">پیامک</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" data-bs-toggle="tab" data-bs-target="#adminDetailedStats" type="button" role="tab">گزارش تفکیکی</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" data-bs-toggle="tab" data-bs-target="#adminKelasehSearch" type="button" role="tab">جستجوی پرونده</button>
                </li>
              </ul>
              <div class="tab-content">
                <div class="tab-pane fade show active" id="adminUsers" role="tabpanel">
                  <form id="formAdminCreateUser" class="border rounded p-2 mb-2">
                    <div class="row g-2">
                      <div class="col-12 col-md-6">
                        <label class="form-label form-label-sm">نام</label>
                        <input name="first_name" type="text" class="form-control form-control-sm" required />
                      </div>
                      <div class="col-12 col-md-6">
                        <label class="form-label form-label-sm">نام خانوادگی</label>
                        <input name="last_name" type="text" class="form-control form-control-sm" required />
                      </div>
                      <div class="col-12 col-md-6">
                        <label class="form-label form-label-sm">نام کاربری</label>
                        <input name="username" type="text" class="form-control form-control-sm" placeholder="مثلاً کاربر123" required />
                      </div>
                      <div class="col-12 col-md-6">
                        <label class="form-label form-label-sm">شماره تماس</label>
                        <input name="mobile" type="text" class="form-control form-control-sm" placeholder="09123456789" required />
                      </div>
                      <div class="col-12 col-md-6">
                        <label class="form-label form-label-sm">رمز عبور</label>
                        <input name="password" type="password" class="form-control form-control-sm" autocomplete="new-password" required />
                      </div>
                      <div class="col-12 col-md-6">
                        <label class="form-label form-label-sm">اداره (استان اصفهان)</label>
                        <select name="city_code" id="adminCitySelect" class="form-select form-select-sm" required>
                          <option value="">انتخاب کنید…</option>
                        </select>
                      </div>
                      <div class="col-12 col-md-3">
                        <label class="form-label form-label-sm">نقش</label>
                        <select name="role" id="adminRoleSelect" class="form-select form-select-sm">
                          <option value="admin">مدیر کل</option>
                          <option value="office_admin" selected>مدیر اداره</option>
                          <option value="branch_admin">مدیر شعبه</option>
                        </select>
                      </div>
                      <div class="col-12 col-md-3" id="adminOfficeBranchWrap">
                        <label class="form-label form-label-sm">شعبه مدیر اداره</label>
                        <select name="branch_no" class="form-select form-select-sm">
                          <option value="">انتخاب کنید…</option>
                          <option value="1">01 (شعبه یک)</option>
                          <option value="2">02 (شعبه دو)</option>
                          <option value="3">03 (شعبه سه)</option>
                          <option value="4">04 (شعبه چهار)</option>
                          <option value="5">05 (شعبه پنج)</option>
                          <option value="6">06 (شعبه شش)</option>
                          <option value="7">07 (شعبه هفت)</option>
                          <option value="8">08 (شعبه هشت)</option>
                          <option value="9">09 (شعبه نه)</option>
                        </select>
                      </div>
                      <div class="col-12 col-md-6 d-none" id="adminBranchMultiWrap">
                        <label class="form-label form-label-sm">شعبه‌های مدیر شعبه</label>
                        <div class="card p-2 bg-light">
                           <div id="adminCreateBranchList" class="row g-2"></div>
                        </div>
                      </div>
                      <div class="col-12 col-md-6 d-none" id="adminUserBranchesWrap">
                        <label class="form-label form-label-sm">محدوده شعبه (برای کاربر)</label>
                        <div class="row g-2">
                          <div class="col-6">
                            <select name="branch_count" class="form-select form-select-sm">
                              <option value="">تعداد…</option>
                              <option value="1" selected>1</option>
                              <option value="2">2</option>
                              <option value="3">3</option>
                              <option value="4">4</option>
                              <option value="5">5</option>
                              <option value="6">6</option>
                              <option value="7">7</option>
                              <option value="8">8</option>
                              <option value="9">9</option>
                            </select>
                          </div>
                          <div class="col-6">
                            <select name="branch_start_no" class="form-select form-select-sm">
                              <option value="1" selected>01</option>
                              <option value="2">02</option>
                              <option value="3">03</option>
                              <option value="4">04</option>
                              <option value="5">05</option>
                              <option value="6">06</option>
                              <option value="7">07</option>
                              <option value="8">08</option>
                              <option value="9">09</option>
                            </select>
                          </div>
                        </div>
                      </div>
                      <div class="col-12 col-md-3 d-flex align-items-end">
                        <button class="btn btn-primary btn-sm w-100" type="submit">ایجاد کاربر</button>
                      </div>
                    </div>
                  </form>
                  <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text">جستجو</span>
                    <input id="adminUsersQuery" type="text" class="form-control" placeholder="نام کاربری/نام/موبایل" />
                    <button id="btnAdminUsersRefresh" class="btn btn-outline-secondary" type="button">تازه‌سازی</button>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle">
                      <thead>
                        <tr>
                          <th>کاربر</th>
                          <th style="width: 140px;">اداره</th>
                          <th>نقش</th>
                          <th>فعال</th>
                          <th style="width: 140px;">شعبه</th>
                          <th>آخرین ورود</th>
                          <th>عملیات</th>
                        </tr>
                      </thead>
                      <tbody id="adminUsersTbody"></tbody>
                    </table>
                  </div>
                </div>

                <div class="tab-pane fade" id="adminCities" role="tabpanel">
                  <form id="formAdminCityCreate" class="border rounded p-2 mb-2">
                    <div class="row g-2 align-items-end">
                      <div class="col-12 col-md-3">
                        <label class="form-label form-label-sm">کد</label>
                        <input name="code" type="text" class="form-control form-control-sm" placeholder="مثلاً ۰۰۱۰" maxlength="4" required />
                      </div>
                      <div class="col-12 col-md-6">
                        <label class="form-label form-label-sm">نام اداره</label>
                        <input name="name" type="text" class="form-control form-control-sm" required />
                      </div>
                      <div class="col-12 col-md-3">
                        <button class="btn btn-primary btn-sm w-100" type="submit">افزودن</button>
                      </div>
                    </div>
                  </form>

                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="text-secondary small">برای ویرایش، نام را تغییر دهید و «ذخیره» را بزنید.</div>
                    <button id="btnAdminCitiesRefresh" class="btn btn-outline-secondary btn-sm" type="button">تازه‌سازی</button>
                  </div>

                  <div class="table-responsive">
                    <table class="table table-sm align-middle">
                      <thead>
                        <tr>
                          <th style="width: 90px;">کد</th>
                          <th>نام</th>
                          <th class="text-end" style="width: 220px;">عملیات</th>
                        </tr>
                      </thead>
                      <tbody id="adminCitiesTbody"></tbody>
                    </table>
                  </div>
                </div>
                <div class="tab-pane fade" id="adminItems" role="tabpanel">
                  <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text">جستجو</span>
                    <input id="adminItemsQuery" type="text" class="form-control" placeholder="کاربر/عنوان/توضیح" />
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
                          <th>کاربر</th>
                          <th>عملیات</th>
                          <th>بخش</th>
                        </tr>
                      </thead>
                      <tbody id="adminLogsTbody"></tbody>
                    </table>
                  </div>
                </div>

                <div class="tab-pane fade" id="adminStats" role="tabpanel">
                  <div class="row g-2 align-items-end mb-2">
                    <div class="col-6 col-md-4">
                      <label class="form-label form-label-sm">از تاریخ</label>
                      <input id="adminStatsFrom" type="text" class="form-control form-control-sm" placeholder="1404/11/12" />
                    </div>
                    <div class="col-6 col-md-4">
                      <label class="form-label form-label-sm">تا تاریخ</label>
                      <input id="adminStatsTo" type="text" class="form-control form-control-sm" placeholder="1404/11/12" />
                    </div>
                    <div class="col-12 col-md-4 d-grid">
                      <button id="btnAdminStatsRefresh" class="btn btn-outline-secondary btn-sm" type="button">دریافت آمار</button>
                    </div>
                  </div>

                  <div class="small text-secondary mb-2">
                    مجموع: <span id="adminStatsTotal">0</span> | فعال: <span id="adminStatsActive">0</span> | غیرفعال: <span id="adminStatsInactive">0</span> | ابطال: <span id="adminStatsVoided">0</span>
                  </div>

                  <div class="row g-3">
                    <div class="col-12 col-lg-6">
                      <div class="fw-semibold mb-1">به تفکیک اداره</div>
                      <div class="table-responsive">
                        <table class="table table-sm align-middle">
                          <thead>
                            <tr>
                              <th>اداره</th>
                              <th>کل</th>
                              <th>فعال</th>
                              <th>غیرفعال</th>
                              <th>ابطال</th>
                            </tr>
                          </thead>
                          <tbody id="adminStatsCitiesTbody"></tbody>
                        </table>
                      </div>
                    </div>
                    <div class="col-12 col-lg-6">
                      <div class="fw-semibold mb-1">به تفکیک کاربر</div>
                      <div class="table-responsive">
                        <table class="table table-sm align-middle">
                          <thead>
                            <tr>
                              <th>کاربر</th>
                              <th>اداره</th>
                              <th>کل</th>
                              <th>فعال</th>
                              <th>غیرفعال</th>
                              <th>ابطال</th>
                            </tr>
                          </thead>
                          <tbody id="adminStatsUsersTbody"></tbody>
                        </table>
                      </div>
                    </div>
                  </div>

                  <div class="card mt-3">
                    <div class="card-header py-2">تست سلامت</div>
                    <div class="card-body p-2">
                      <div class="d-flex gap-2 flex-wrap align-items-center">
                        <button id="btnAdminRunBranchAdminTest" class="btn btn-outline-primary btn-sm" type="button">اجرای تست مدیر شعبه (۳۰ ثبت)</button>
                        <a id="adminTestDownloadLink" class="btn btn-outline-success btn-sm d-none" target="_blank" rel="noopener">دانلود خروجی اکسل</a>
                      </div>
                      <div id="adminTestResult" class="small text-secondary mt-2"></div>
                    </div>
                  </div>
                </div>

                <div class="tab-pane fade" id="adminSms" role="tabpanel">
                  <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item">
                      <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#adminSmsConfig" type="button">تنظیمات</button>
                    </li>
                    <li class="nav-item">
                      <button class="nav-link" data-bs-toggle="tab" data-bs-target="#adminSmsReport" type="button">گزارش و اعتبار</button>
                    </li>
                  </ul>
                  
                  <div class="tab-content">
                    <div class="tab-pane fade show active" id="adminSmsConfig">
                      <form id="formAdminSmsSettings" class="border rounded p-2">
                        <div class="row g-2">
                          <div class="col-12">
                            <div class="form-check">
                              <input id="adminSmsEnabled" name="enabled" class="form-check-input" type="checkbox" />
                              <label class="form-check-label" for="adminSmsEnabled">ارسال پیامک فعال باشد</label>
                            </div>
                          </div>
                          <div class="col-12">
                            <div class="form-check">
                              <input id="adminSmsOtpEnabled" name="otp_enabled" class="form-check-input" type="checkbox" />
                              <label class="form-check-label" for="adminSmsOtpEnabled">ارسال کد تأیید ورود برای مدیران فعال باشد</label>
                              <div class="form-text">در صورت فعال بودن، هنگام ورود نقش‌های مدیر کل/مدیر اداره/مدیر شعبه نیاز به کد تأیید دارند.</div>
                            </div>
                          </div>

                          <div id="adminSmsOtpSettings" class="col-12 d-none">
                            <div class="card p-2 bg-light">
                              <div class="row g-2">
                                <div class="col-12 col-lg-6">
                                  <label class="form-label form-label-sm">متن پیامک کد تأیید</label>
                                  <textarea id="adminSmsTplOtp" name="tpl_otp" class="form-control form-control-sm" rows="3"></textarea>
                                  <div class="form-text">متغیرها: {otp} (کد تأیید)، {app_name} (نام سامانه)</div>
                                </div>
                                <div class="col-12 col-md-4 col-lg-2">
                                  <label class="form-label form-label-sm">طول کد تأیید</label>
                                  <input id="adminSmsOtpLen" name="otp_len" type="number" class="form-control form-control-sm" min="4" max="8" value="6" />
                                </div>
                                <div class="col-12 col-md-4 col-lg-2">
                                  <label class="form-label form-label-sm">اعتبار (دقیقه)</label>
                                  <input id="adminSmsOtpTtl" name="otp_ttl" type="number" class="form-control form-control-sm" min="1" max="30" value="5" />
                                </div>
                                <div class="col-12 col-md-4 col-lg-2">
                                  <label class="form-label form-label-sm">حداکثر تلاش</label>
                                  <input id="adminSmsOtpMaxTries" name="otp_max_tries" type="number" class="form-control form-control-sm" min="1" max="10" value="5" />
                                </div>
                              </div>
                            </div>
                          </div>
                          <div class="col-12 col-md-6">
                            <label class="form-label form-label-sm">کلید API کاوه‌نگار</label>
                            <input id="adminSmsApiKey" name="api_key" type="password" class="form-control form-control-sm" autocomplete="off" placeholder="برای تغییر وارد کنید" />
                            <div id="adminSmsApiKeyHint" class="form-text">اگر خالی بماند، مقدار قبلی حفظ می‌شود.</div>
                          </div>
                          <div class="col-12 col-md-6">
                            <label class="form-label form-label-sm">شماره/نام فرستنده (اختیاری)</label>
                            <input id="adminSmsSender" name="sender" type="text" class="form-control form-control-sm" placeholder="مثلاً 1000xxx" />
                          </div>
                          <div class="col-12 col-lg-6">
                            <label class="form-label form-label-sm">متن پیامک خواهان</label>
                            <textarea id="adminSmsTplPlaintiff" name="tpl_plaintiff" class="form-control form-control-sm" rows="4"></textarea>
                          </div>
                          <div class="col-12 col-lg-6">
                            <label class="form-label form-label-sm">متن پیامک خوانده</label>
                            <textarea id="adminSmsTplDefendant" name="tpl_defendant" class="form-control form-control-sm" rows="4"></textarea>
                          </div>
                          <div class="col-12">
                            <div class="small text-secondary">
                              متغیرها:
                              {code} (کد کلاسه)،
                              {full_code} (کد کامل اداره-کلاسه)،
                              {city_name} (نام اداره)،
                              {branch_no} (شماره شعبه)،
                              {date} (تاریخ ثبت)،
                              {plaintiff_name} (نام خواهان)،
                              {plaintiff_national_code} (کد ملی خواهان)،
                              {plaintiff_mobile} (موبایل خواهان)،
                              {defendant_name} (نام خوانده)،
                              {defendant_national_code} (کد ملی خوانده)،
                              {defendant_mobile} (موبایل خوانده)
                            </div>
                          </div>
                          <div class="col-12 col-md-4 d-grid">
                            <button class="btn btn-primary btn-sm" type="submit">ذخیره تنظیمات پیامک</button>
                          </div>
                        </div>
                      </form>
                    </div>
                    
                    <div class="tab-pane fade" id="adminSmsReport">
                      <div class="card mb-3">
                        <div class="card-body py-2">
                          <div class="d-flex justify-content-between align-items-center">
                             <div>
                               <span class="fw-bold">اعتبار باقی‌مانده:</span>
                               <span id="smsPanelCredit" class="ms-2 badge bg-success fs-6">...</span>
                               <span class="small text-muted ms-1">ریال</span>
                             </div>
                             <button id="btnAdminSmsReportRefresh" class="btn btn-sm btn-outline-secondary">بروزرسانی</button>
                          </div>
                        </div>
                      </div>
                      
                      <div class="table-responsive">
                        <table class="table table-sm table-bordered table-striped">
                          <thead>
                            <tr>
                              <th>شناسه</th>
                              <th>تاریخ</th>
                              <th>گیرنده</th>
                              <th>نوع</th>
                              <th>وضعیت</th>
                              <th>متن</th>
                            </tr>
                          </thead>
                          <tbody id="adminSmsLogsTbody"></tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="tab-pane fade" id="adminDetailedStats" role="tabpanel">
                  <div class="d-flex justify-content-end mb-2">
                    <button id="btnAdminDetailedStatsRefresh" class="btn btn-outline-secondary btn-sm" type="button">دریافت گزارش</button>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle table-bordered">
                      <thead>
                        <tr>
                          <th>اداره</th>
                          <th>نقش</th>
                          <th>نام کاربر</th>
                          <th>تعداد ثبت</th>
                        </tr>
                      </thead>
                      <tbody id="adminDetailedStatsTbody"></tbody>
                    </table>
                  </div>
                </div>

                <div class="tab-pane fade" id="adminKelasehSearch" role="tabpanel">
                  <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text">جستجو</span>
                    <input id="adminKelasehSearchQuery" type="text" class="form-control" placeholder="کلاسه/کد ملی/نام" />
                    <select id="adminKelasehCityFilter" class="form-select" style="max-width: 220px;">
                      <option value="">همه اداره‌ها</option>
                    </select>
                    <button id="btnAdminKelasehSearch" class="btn btn-outline-secondary" type="button">جستجو</button>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle">
                      <thead>
                        <tr>
                          <th>کلاسه</th>
                          <th>اداره/کاربر</th>
                          <th>خواهان</th>
                          <th>کد ملی خواهان</th>
                          <th>خوانده</th>
                          <th>تاریخ</th>
                          <th>چاپ</th>
                        </tr>
                      </thead>
                      <tbody id="adminKelasehSearchTbody"></tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div id="officePanel" class="card mt-3 d-none">
            <div class="card-header">پنل مدیر اداره</div>
            <div class="card-body">
              <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                  <button class="nav-link" data-bs-toggle="tab" data-bs-target="#officeUsers" type="button" role="tab">مدیریت کاربران</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" data-bs-toggle="tab" data-bs-target="#officeStats" type="button" role="tab">آمار</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" data-bs-toggle="tab" data-bs-target="#officeKelaseh" type="button" role="tab">پرونده‌ها</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" data-bs-toggle="tab" data-bs-target="#officeCapacities" type="button" role="tab">ظرفیت شعب</button>
                </li>
              </ul>
              <div class="tab-content">
                <div class="tab-pane fade show active" id="officeUsers" role="tabpanel">
                  <div class="mb-2">
                     <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalOfficeCreateUser">ایجاد مدیر شعبه جدید</button>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle table-bordered">
                      <thead>
                        <tr>
                          <th>نام کاربری</th>
                          <th>نام</th>
                          <th>نقش</th>
                          <th>شعب</th>
                          <th>تنظیمات</th>
                          <th></th>
                        </tr>
                      </thead>
                      <tbody id="officeUsersTbody"></tbody>
                    </table>
                  </div>
                </div>
                <div class="tab-pane fade" id="officeStats" role="tabpanel">
                  <div class="d-flex justify-content-end mb-2">
                    <button id="btnOfficeStatsRefresh" class="btn btn-outline-secondary btn-sm" type="button">بروزرسانی</button>
                  </div>
                  <div class="row g-2" id="officeStatsContainer"></div>
                </div>
                <div class="tab-pane fade" id="officeKelaseh" role="tabpanel">
                   <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text">جستجو</span>
                    <input id="officeKelasehSearchQuery" type="text" class="form-control" placeholder="کلاسه/کد ملی/نام" />
                    <button id="btnOfficeKelasehSearch" class="btn btn-outline-secondary" type="button">جستجو</button>
                    <button id="btnOfficeKelasehPrintAll" class="btn btn-outline-dark" type="button">چاپ لیست کامل</button>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle table-bordered">
                      <thead>
                        <tr>
                          <th>کلاسه</th>
                          <th>کاربر</th>
                          <th>خواهان</th>
                          <th>کد ملی خواهان</th>
                          <th>خوانده</th>
                          <th>تاریخ</th>
                          <th>چاپ</th>
                          <th>عملیات</th>
                        </tr>
                      </thead>
                      <tbody id="officeKelasehSearchTbody"></tbody>
                    </table>
                  </div>
                </div>
                <div class="tab-pane fade" id="officeCapacities" role="tabpanel">
                   <div class="d-flex justify-content-between align-items-center mb-2">
                      <div class="text-secondary small">ظرفیت روزانه شعب را مدیریت کنید.</div>
                      <button id="btnOfficeCapacitiesRefresh" class="btn btn-outline-secondary btn-sm">تازه‌سازی</button>
                   </div>
                   <div class="table-responsive">
                      <table class="table table-sm table-bordered align-middle">
                         <thead>
                            <tr>
                               <th>شعبه</th>
                               <th>ظرفیت فعلی</th>
                               <th>تنظیمات</th>
                            </tr>
                         </thead>
                         <tbody id="officeCapacitiesTbody"></tbody>
                      </table>
                   </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <!-- colRight removed as we merged it -->
        <div class="card mt-3" id="cardKelaseh">
            <div class="card-header d-flex align-items-center justify-content-between">
              <div id="kelasehCardTitle" class="fw-semibold">پنل کاربری</div>
              <button id="btnKelasehRefresh" type="button" class="btn btn-outline-secondary btn-sm">تازه‌سازی</button>
            </div>
            <div class="card-body">
              <div id="kelasehCreateSection" class="d-none">
                <div class="card">
                  <div class="card-body">
                    <form id="formKelasehCreate">
                      <div class="row g-3">
                        <div id="kelasehBranchSelectWrap" class="col-12 col-md-6 d-none">
                          <label class="form-label form-label-sm">انتخاب شعبه (اختیاری)</label>
                          <select id="kelasehBranchNoSelect" name="branch_no" class="form-select form-select-sm">
                            <option value="">انتخاب خودکار</option>
                          </select>
                        </div>
                        <div class="col-12 col-md-6">
                          <label class="form-label form-label-sm">تاریخ ثبت کلاسه (دستی)</label>
                          <input id="kelasehManualDate" type="text" class="form-control form-control-sm" placeholder="انتخاب تاریخ..." readonly />
                          <input type="hidden" name="manual_year" id="manual_year" />
                          <input type="hidden" name="manual_month" id="manual_month" />
                          <input type="hidden" name="manual_day" id="manual_day" />
                          <div class="form-text small">در صورت خالی بودن، تاریخ امروز درج می‌شود.</div>
                        </div>
                        <div class="col-12 col-md-6">
                          <label class="form-label form-label-sm">کد ملی خواهان (الزامی)</label>
                          <input name="plaintiff_national_code" type="text" class="form-control form-control-sm national-check" required maxlength="10" placeholder="۱۰ رقم" />
                          <div class="form-text text-danger d-none nc-error">کد ملی نامعتبر است.</div>
                        </div>
                        <div class="col-12 col-md-6">
                          <label class="form-label form-label-sm">شماره موبایل خواهان (الزامی)</label>
                          <input name="plaintiff_mobile" type="text" class="form-control form-control-sm" required maxlength="11" placeholder="09xxxxxxxxx" />
                        </div>
                        <div class="col-12 col-md-6">
                          <label class="form-label form-label-sm">نام و نام خانوادگی خواهان (اختیاری)</label>
                          <input name="plaintiff_name" type="text" class="form-control form-control-sm" />
                        </div>
                        <div class="col-12 col-md-6">
                          <label class="form-label form-label-sm">کد ملی خوانده (اختیاری)</label>
                          <input name="defendant_national_code" type="text" class="form-control form-control-sm national-check" maxlength="10" placeholder="اختیاری" />
                          <div class="form-text text-danger d-none nc-error">کد ملی نامعتبر است.</div>
                        </div>
                        <div class="col-12 col-md-6">
                          <label class="form-label form-label-sm">شماره موبایل خوانده (اختیاری)</label>
                          <input name="defendant_mobile" type="text" class="form-control form-control-sm" maxlength="11" placeholder="اختیاری" />
                        </div>
                        <div class="col-12 col-md-6">
                          <label class="form-label form-label-sm">نام و نام خانوادگی خوانده (اختیاری)</label>
                          <input name="defendant_name" type="text" class="form-control form-control-sm" placeholder="اختیاری" />
                        </div>
                        <div class="col-12 text-end">
                          <button class="btn btn-primary" type="submit">ثبت و ایجاد شماره کلاسه</button>
                        </div>
                      </div>
                    </form>
                  </div>
                </div>

                <div id="historyCheckSection" class="mt-3 d-none">
                  <div class="row g-3">
                    <div class="col-12 col-lg-6">
                      <div class="card border-info h-100">
                        <div class="card-header bg-info text-white small py-1">سوابق خواهان</div>
                        <div class="card-body p-0">
                          <table class="table table-sm table-striped mb-0 small">
                            <thead><tr><th>کلاسه</th><th>اداره</th><th>تاریخ</th><th>طرف مقابل</th></tr></thead>
                            <tbody id="historyPlaintiffTbody"></tbody>
                          </table>
                        </div>
                      </div>
                    </div>
                    <div class="col-12 col-lg-6">
                      <div class="card border-warning h-100">
                        <div class="card-header bg-warning text-dark small py-1">سوابق خوانده</div>
                        <div class="card-body p-0">
                          <table class="table table-sm table-striped mb-0 small">
                            <thead><tr><th>کلاسه</th><th>اداره</th><th>تاریخ</th><th>طرف مقابل</th></tr></thead>
                            <tbody id="historyDefendantTbody"></tbody>
                          </table>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="card mt-3">
                  <div class="card-header small py-1 bg-light d-flex justify-content-between align-items-center">
                    <span>ثبت‌های امروز شما</span>
                    <div class="btn-group btn-group-sm">
                      <button id="btnKelasehTodaySelectAll" class="btn btn-outline-primary" type="button">انتخاب همه</button>
                      <button id="btnKelasehTodayPrintAllLabels" class="btn btn-outline-secondary" type="button">چاپ لیبل کامل</button>
                    </div>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                      <thead class="table-light">
                        <tr>
                          <th style="width: 50px;"></th>
                          <th style="width: 50px;">ردیف</th>
                          <th>کلاسه</th>
                          <th style="width: 60px;">شعبه</th>
                          <th style="width: 140px;">اداره</th>
                          <th>خواهان</th>
                          <th>کد ملی خواهان</th>
                          <th>خوانده</th>
                          <th>تاریخ</th>
                          <th>وضعیت</th>
                          <th class="text-end">عملیات</th>
                        </tr>
                      </thead>
                      <tbody id="kelasehTodayTbody"></tbody>
                    </table>
                  </div>
                </div>
              </div>

              <div id="kelasehListSection">

              <div class="row g-2 mb-2">
                <div class="col-12 col-md-2">
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">جستجو</span>
                    <input id="kelasehNational" type="text" class="form-control" placeholder="کد ملی/نام/کلاسه/موبایل..." />
                  </div>
                </div>
                <div id="kelasehCityFilterWrap" class="col-12 col-md-2 d-none">
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">اداره</span>
                    <select id="adminKelasehCityFilterMain" class="form-select">
                      <option value="">همه اداره‌ها</option>
                    </select>
                  </div>
                </div>
                <div id="kelasehOwnerFilterWrap" class="col-12 col-md-2 d-none">
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">مدیر شعبه</span>
                    <select id="kelasehOwnerFilter" class="form-select">
                      <option value="0">همه مدیران</option>
                    </select>
                  </div>
                </div>
                <div class="col-6 col-md-2">
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">از</span>
                    <input id="kelasehFrom" type="text" class="form-control" placeholder="1404/11/12" />
                  </div>
                </div>
                <div class="col-6 col-md-2">
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">تا</span>
                    <input id="kelasehTo" type="text" class="form-control" placeholder="1404/11/12" />
                  </div>
                </div>
                <div class="col-12 col-md-2 d-grid">
                  <button id="btnKelasehSearch" class="btn btn-outline-secondary btn-sm" type="button">جستجو</button>
                </div>
              </div>

              <div class="d-flex flex-wrap gap-2 mb-2 align-items-center">
                <button id="btnKelasehExportCsv" class="btn btn-outline-success btn-sm" type="button">خروجی اکسل</button>
                <button id="btnKelasehExportPdf" class="btn btn-outline-dark btn-sm" type="button">خروجی پی‌دی‌اف</button>
                <button id="btnKelasehPrintLabels" class="btn btn-outline-secondary btn-sm" type="button">چاپ کامل لیبل</button>
                <button id="btnKelasehSelectAll" class="btn btn-outline-primary btn-sm" type="button">انتخاب همه</button>
              </div>

              <div class="table-responsive">
                <table class="table table-hover align-middle">
                  <thead>
                    <tr>
                      <th style="width: 50px;"></th>
                      <th style="width: 70px;">ردیف</th>
                      <th>کلاسه</th>
                      <th style="width: 90px;">شعبه</th>
                      <th style="width: 140px;">اداره</th>
                      <th>کاربر</th>
                      <th>خواهان</th>
                      <th>خوانده</th>
                      <th>تاریخ</th>
                      <th>چاپ</th>
                      <th>وضعیت</th>
                      <th class="text-end">عملیات</th>
                    </tr>
                  </thead>
                  <tbody id="kelasehTbody"></tbody>
                </table>
              </div>

              <div class="d-flex flex-wrap gap-2 mt-2 mb-3 align-items-center">
                <button id="btnKelasehPrintLabelsBottom" class="btn btn-outline-secondary btn-sm" type="button">چاپ لیبل‌های انتخاب شده</button>
                <button id="btnKelasehSelectAllBottom" class="btn btn-outline-primary btn-sm" type="button">انتخاب همه</button>
              </div>
              
              <div class="d-flex justify-content-between align-items-center mt-3">
                <div class="text-secondary small" id="kelasehPaginationInfo"></div>
                <nav aria-label="Pagination">
                  <ul class="pagination pagination-sm mb-0" id="kelasehPagination">
                  </ul>
                </nav>
              </div>

              </div>
          </div>
        </div>
      </div>
    </div>

  <div class="modal fade" id="modalKelasehEdit" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">ویرایش پرونده</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form id="formKelasehEdit">
          <div class="modal-body">
            <input type="hidden" name="code" value="" />
            <div class="row g-2">
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">نام و نام خانوادگی خواهان</label>
                <input name="plaintiff_name" type="text" class="form-control form-control-sm" required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">نام و نام خانوادگی خوانده</label>
                <input name="defendant_name" type="text" class="form-control form-control-sm" required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">کد ملی/ شناسه ملی خواهان</label>
                <input name="plaintiff_national_code" type="text" class="form-control form-control-sm" required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">کد ملی/ شناسه ملی خوانده</label>
                <input name="defendant_national_code" type="text" class="form-control form-control-sm" required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">شماره تماس خواهان</label>
                <input name="plaintiff_mobile" type="text" class="form-control form-control-sm" required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">شماره تماس خوانده</label>
                <input name="defendant_mobile" type="text" class="form-control form-control-sm" required />
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">بستن</button>
            <button type="submit" class="btn btn-primary">ذخیره</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="modalAdminEditUser" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">ویرایش کاربر</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form id="formAdminEditUser">
          <div class="modal-body">
            <input type="hidden" name="id" value="" />
            <div class="row g-3">
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">نام</label>
                <input name="first_name" type="text" class="form-control form-control-sm" />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">نام خانوادگی</label>
                <input name="last_name" type="text" class="form-control form-control-sm" />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">موبایل</label>
                <input name="mobile" type="text" class="form-control form-control-sm" maxlength="11" />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">ایمیل</label>
                <input name="email" type="email" class="form-control form-control-sm" />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">رمز عبور (خالی بگذارید تا تغییر نکند)</label>
                <input name="password" type="password" class="form-control form-control-sm" />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">نقش کاربری</label>
                <select name="role" id="adminEditRoleSelect" class="form-select form-select-sm">
                  <option value="admin">مدیر کل</option>
                  <option value="branch_admin">مدیر شعبه</option>
                  <option value="office_admin">مدیر اداره</option>
                </select>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">وضعیت</label>
                <select name="is_active" class="form-select form-select-sm">
                  <option value="1">فعال</option>
                  <option value="0">غیرفعال</option>
                </select>
              </div>
              
              <!-- Branch Admin Fields -->
              <div id="adminEditBranchWrap" class="col-12 d-none">
                 <div class="card p-2 bg-light">
                    <label class="form-label form-label-sm fw-bold">انتخاب شعب و ظرفیت (مدیر شعبه)</label>
                    <div id="adminEditBranchList" class="row g-2">
                       <!-- Checkboxes injected by JS -->
                    </div>
                 </div>
              </div>

              <!-- Office Admin Fields -->
              <div id="adminEditOfficeWrap" class="col-12 d-none">
                 <div class="card p-2 bg-light">
                    <label class="form-label form-label-sm fw-bold">تنظیمات مدیر اداره</label>
                    <div class="row g-2">
                       <div class="col-12 col-md-6">
                          <label class="form-label form-label-sm">تعداد شعب</label>
                          <input name="branch_count" type="number" class="form-control form-control-sm" min="1" max="99" />
                       </div>
                    </div>
                 </div>
              </div>

            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">بستن</button>
            <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="assets/vendor/jquery/jquery.min.js?v=3.7"></script>
  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=5.3"></script>
  <script src="https://unpkg.com/persian-date@1.1.0/dist/persian-date.min.js"></script>
  <script src="https://unpkg.com/persian-datepicker@1.2.0/dist/js/persian-datepicker.min.js"></script>
  <script src="assets/js/app.js?v=<?php echo file_exists(__DIR__ . '/assets/js/app.js') ? filemtime(__DIR__ . '/assets/js/app.js') : '1'; ?>"></script>
</body>
</html>
