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
    <div class="d-flex flex-column gap-2 mb-3">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="d-flex flex-column">
          <h1 class="h5 m-0"><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></h1>
          <div id="kelasehOffice" class="small text-secondary d-none"></div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <div id="headerDateTime" class="small text-secondary d-none"></div>
          <span id="currentRole" class="badge text-bg-light d-none"></span>
          <button id="btnLogout" type="button" class="btn btn-outline-danger btn-sm d-none">خروج</button>
        </div>
      </div>

      <ul id="headerNav" class="nav nav-pills d-none">
        <li class="nav-item"><a class="nav-link" href="#dashboard" data-page="dashboard">پنل کاربری</a></li>
        <li class="nav-item"><a class="nav-link" href="#profile" data-page="profile">پروفایل</a></li>
        <li class="nav-item"><a class="nav-link" href="#create" data-page="create">ایجاد شماره کلاسه</a></li>
      </ul>
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
                <label class="form-label">نام کاربری یا ایمیل</label>
                <input name="login" type="text" class="form-control" autocomplete="username" required />
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
      <div class="row g-3" id="appRow">
        <div class="col-12 col-lg-4" id="colLeft">
          <div class="card" id="cardProfile">
            <div class="card-header">پروفایل</div>
            <div class="card-body">
              <div class="mb-2">
                <div class="text-secondary small">نام کاربری</div>
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
                  <button class="nav-link" data-bs-toggle="tab" data-bs-target="#adminCities" type="button" role="tab">شهرها</button>
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
                        <label class="form-label form-label-sm">شهر (استان اصفهان)</label>
                        <select name="city_code" id="adminCitySelect" class="form-select form-select-sm" required>
                          <option value="">انتخاب کنید…</option>
                        </select>
                      </div>
                      <div class="col-12 col-md-6">
                        <label class="form-label form-label-sm">تعداد شعبه</label>
                        <select name="branch_count" class="form-select form-select-sm" required>
                          <option value="">انتخاب کنید…</option>
                          <option value="1">1</option>
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
                      <div class="col-12 col-md-3">
                        <label class="form-label form-label-sm">شناسه شعبه (شروع)</label>
                        <select name="branch_start_no" class="form-select form-select-sm" required>
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
                      <div class="col-12 col-md-3">
                        <label class="form-label form-label-sm">نقش</label>
                        <select name="role" class="form-select form-select-sm">
                          <option value="user" selected>کاربر</option>
                          <option value="admin">ادمین</option>
                        </select>
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
                          <th>نقش</th>
                          <th>فعال</th>
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
                        <input name="code" type="text" class="form-control form-control-sm" placeholder="مثلاً 01" maxlength="2" required />
                      </div>
                      <div class="col-12 col-md-6">
                        <label class="form-label form-label-sm">نام شهر</label>
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
                      <div class="fw-semibold mb-1">به تفکیک شهر</div>
                      <div class="table-responsive">
                        <table class="table table-sm align-middle">
                          <thead>
                            <tr>
                              <th>شهر</th>
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
                              <th>شهر</th>
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
                </div>

                <div class="tab-pane fade" id="adminSms" role="tabpanel">
                  <form id="formAdminSmsSettings" class="border rounded p-2">
                    <div class="row g-2">
                      <div class="col-12">
                        <div class="form-check">
                          <input id="adminSmsEnabled" name="enabled" class="form-check-input" type="checkbox" />
                          <label class="form-check-label" for="adminSmsEnabled">ارسال پیامک فعال باشد</label>
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
                        <div class="small text-secondary">متغیرها: {code} ، {plaintiff_name} ، {defendant_name}</div>
                      </div>
                      <div class="col-12 col-md-4 d-grid">
                        <button class="btn btn-primary btn-sm" type="submit">ذخیره تنظیمات پیامک</button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-8" id="colRight">
          <div class="card" id="cardKelaseh">
            <div class="card-header d-flex align-items-center justify-content-between">
              <div id="kelasehCardTitle" class="fw-semibold">پنل کاربری</div>
              <button id="btnKelasehRefresh" type="button" class="btn btn-outline-secondary btn-sm">تازه‌سازی</button>
            </div>
            <div class="card-body">
              <div id="kelasehCreateSection">
              <form id="formKelasehCreate" class="border rounded p-2 mb-3">
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
                    <label class="form-label form-label-sm">کدملی/ شناسه ملی خواهان</label>
                    <input name="plaintiff_national_code" type="text" class="form-control form-control-sm" placeholder="مثلاً 10 یا 11 رقم" required />
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label form-label-sm">کدملی/ شناسه ملی خوانده</label>
                    <input name="defendant_national_code" type="text" class="form-control form-control-sm" placeholder="مثلاً 10 یا 11 رقم" required />
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label form-label-sm">شماره تماس خواهان</label>
                    <input name="plaintiff_mobile" type="text" class="form-control form-control-sm" placeholder="09123456789" required />
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label form-label-sm">شماره تماس خوانده</label>
                    <input name="defendant_mobile" type="text" class="form-control form-control-sm" placeholder="09123456789" required />
                  </div>
                  <div class="col-12 d-flex gap-2 align-items-center">
                    <button id="btnKelasehCreate" class="btn btn-primary" type="submit">ثبت و ایجاد شناسه پرونده</button>
                    <div class="d-flex flex-wrap gap-3 align-items-center">
                      <div class="form-check m-0">
                        <input id="kelasehSmsPlaintiff" class="form-check-input" type="checkbox" checked />
                        <label class="form-check-label" for="kelasehSmsPlaintiff">ارسال پیامک به خواهان</label>
                      </div>
                      <div class="form-check m-0">
                        <input id="kelasehSmsDefendant" class="form-check-input" type="checkbox" />
                        <label class="form-check-label" for="kelasehSmsDefendant">ارسال پیامک به خوانده</label>
                      </div>
                      <button id="btnKelasehCreateAndSms" class="btn btn-outline-success" type="submit">ثبت و ارسال پیامک</button>
                    </div>
                  </div>
                </div>
              </form>

              </div>

              <div id="kelasehListSection">

              <div class="row g-2 mb-2">
                <div class="col-12 col-md-4">
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">کدملی/ شناسه ملی</span>
                    <input id="kelasehNational" type="text" class="form-control" placeholder="کدملی/شناسه ملی خواهان/خوانده" />
                  </div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">از</span>
                    <input id="kelasehFrom" type="text" class="form-control" placeholder="1404/11/12" />
                  </div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">تا</span>
                    <input id="kelasehTo" type="text" class="form-control" placeholder="1404/11/12" />
                  </div>
                </div>
                <div class="col-12 col-md-2 d-grid">
                  <button id="btnKelasehSearch" class="btn btn-outline-secondary btn-sm" type="button">جستجو</button>
                </div>
              </div>

              <div class="d-flex flex-wrap gap-2 mb-2">
                <button id="btnKelasehExportCsv" class="btn btn-outline-success btn-sm" type="button">خروجی اکسل</button>
                <button id="btnKelasehExportPdf" class="btn btn-outline-dark btn-sm" type="button">خروجی پی‌دی‌اف</button>
              </div>

              <div class="table-responsive">
                <table class="table table-hover align-middle">
                  <thead>
                    <tr>
                      <th style="width: 50px;"></th>
                      <th style="width: 70px;">ردیف</th>
                      <th>کلاسه</th>
                      <th style="width: 90px;">شعبه</th>
                      <th>خواهان</th>
                      <th>خوانده</th>
                      <th>تاریخ</th>
                      <th>وضعیت</th>
                      <th class="text-end">عملیات</th>
                    </tr>
                  </thead>
                  <tbody id="kelasehTbody"></tbody>
                </table>
              </div>

              </div>
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
                <label class="form-label form-label-sm">کدملی/ شناسه ملی خواهان</label>
                <input name="plaintiff_national_code" type="text" class="form-control form-control-sm" required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label form-label-sm">کدملی/ شناسه ملی خوانده</label>
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

  <script src="assets/vendor/jquery/jquery.min.js"></script>
  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/app.js"></script>
</body>
</html>
