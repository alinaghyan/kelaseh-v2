@ -1,885 +1,134 @@
let csrfToken = '';
let currentUser = null;
let adminCitiesLoaded = false;
let headerClockTimer = null;

function showToast(message, type) {
  const id = `t_${Date.now()}`;
  const bg = type === 'success' ? 'text-bg-success' : type === 'error' ? 'text-bg-danger' : 'text-bg-secondary';
  const html = `
    <div id="${id}" class="toast ${bg}" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body">${$('<div/>').text(message).html()}</div>
        <button type="button" class="btn-close btn-close-white me-auto ms-2" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  `;
  $('#toastHost').append(html);
  const el = document.getElementById(id);
  const t = new bootstrap.Toast(el, { delay: 2500 });
  t.show();
  el.addEventListener('hidden.bs.toast', () => el.remove());
}

function api(action, data) {
  return $.ajax({
    url: 'core.php',
    method: 'POST',
    data: { ...data, action },
    headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {},
    dataType: 'json',
  });
}

function loadAdminCities() {
  if (adminCitiesLoaded) {
    return $.Deferred().resolve().promise();
  }
  return api('admin.cities.list', {})
    .done((res) => {
      const cities = (res.data && res.data.cities) || [];
      const opts = ['<option value="">انتخاب کنید…</option>']
        .concat(
          cities.map((c) => {
            const code = $('<div/>').text(c.code || '').html();
            const name = $('<div/>').text(c.name || '').html();
            return `<option value="${code}">${name}</option>`;
          })
        )
        .join('');
      $('#adminCitySelect').html(opts);
      adminCitiesLoaded = true;
    })
    .fail(() => {
      showToast('خطا در دریافت لیست شهرها.', 'error');
    });
}

function refreshAdminCities() {
  return api('admin.cities.list', {})
    .done((res) => {
      const cities = (res.data && res.data.cities) || [];
      const rows = cities
        .map((c) => {
          const code = $('<div/>').text(c.code || '').html();
          const name = $('<div/>').text(c.name || '').html();
          return `
            <tr data-code="${code}">
              <td class="text-secondary">${code}</td>
              <td>
                <input class="form-control form-control-sm city-name" type="text" value="${name}" />
              </td>
              <td class="text-end">
                <div class="btn-group btn-group-sm" role="group">
                  <button class="btn btn-outline-primary btn-city-save" type="button">ذخیره</button>
                  <button class="btn btn-outline-danger btn-city-del" type="button">حذف</button>
                </div>
              </td>
            </tr>
          `;
        })
        .join('');
      $('#adminCitiesTbody').html(rows || `<tr><td colspan="3" class="text-center text-secondary py-3">شهری ثبت نشده است.</td></tr>`);
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'خطا در دریافت شهرها.';
      showToast(msg, 'error');
    });
}

function setView(mode) {
  $('#viewLoading').toggleClass('d-none', mode !== 'loading');
  $('#viewAuth').toggleClass('d-none', mode !== 'auth');
  $('#viewApp').toggleClass('d-none', mode !== 'app');
}

function renderUser() {
  const loggedIn = !!currentUser;
  $('#btnLogout').toggleClass('d-none', !loggedIn);
  $('#currentRole').toggleClass('d-none', !loggedIn);
  $('#headerNav').toggleClass('d-none', !loggedIn);
  $('#headerDateTime').toggleClass('d-none', !loggedIn);
  $('#kelasehOffice').toggleClass('d-none', !loggedIn);
  if (!loggedIn) {
    if (headerClockTimer) {
      clearInterval(headerClockTimer);
      headerClockTimer = null;
    }
    return;
  }

  const roleFa = currentUser.role === 'admin' ? 'مدیر کل' : 'کاربر';
  $('#currentRole').text(roleFa);
  $('#profileEmail').text(currentUser.username || currentUser.email || '');
  $('#profileName').text(currentUser.display_name || '');
  $('#profileRole').text(roleFa);

  const cityName = currentUser.city_name || '';
  $('#kelasehOffice').text(cityName ? `اداره ${cityName}` : '').toggleClass('d-none', !cityName);
/* ADMIN USER EDIT & DELETE LOGIC */

  $('#adminPanel').toggleClass('d-none', currentUser.role !== 'admin');
}

function refreshHeaderDateTime() {
  if (!csrfToken) {
    return;
  }
  api('time.now', {})
    .done((res) => {
      const s = (res.data && res.data.now_jalali) || '';
      $('#headerDateTime').text(s);
    })
    .fail(() => {});
}

function startHeaderClock() {
  if (headerClockTimer) {
    clearInterval(headerClockTimer);
  }
  refreshHeaderDateTime();
  headerClockTimer = setInterval(refreshHeaderDateTime, 60000);
}

function getPageFromHash() {
  const raw = (window.location.hash || '').replace('#', '').trim();
  if (raw === 'profile' || raw === 'create' || raw === 'dashboard') {
    return raw;
  }
  return 'dashboard';
}

function renderPage(page) {
  const $links = $('#headerNav a');
  $links.removeClass('active');
  $links.filter(`[data-page="${page}"]`).addClass('active');

  const isAdmin = currentUser && currentUser.role === 'admin';

  if (page === 'profile') {
    $('#cardProfile').removeClass('d-none');
    $('#adminPanel').addClass('d-none');
    $('#colLeft').removeClass('d-none').addClass('col-12').removeClass('col-lg-4');
    $('#colRight').addClass('d-none');
    return;
  }

  if (page === 'create') {
    $('#colLeft').addClass('d-none');
    $('#colRight').removeClass('d-none').removeClass('col-lg-8').addClass('col-12');

    $('#kelasehCreateSection').removeClass('d-none');
    $('#kelasehListSection').addClass('d-none');
    $('#btnKelasehRefresh').addClass('d-none');
    $('#kelasehCardTitle').text('ایجاد شماره کلاسه');
    return;
  }
function updateAdminEditUserFields() {
  const role = $('#adminEditRoleSelect').val();
  const branchWrap = $('#adminEditBranchWrap');
  const officeWrap = $('#adminEditOfficeWrap');

  $('#cardProfile').addClass('d-none');
  $('#kelasehCreateSection').addClass('d-none');
  $('#kelasehListSection').removeClass('d-none');
  $('#btnKelasehRefresh').removeClass('d-none');
  $('#kelasehCardTitle').text('پنل کاربری');
  branchWrap.addClass('d-none');
  officeWrap.addClass('d-none');

  if (isAdmin) {
    $('#colLeft').removeClass('d-none').addClass('col-lg-4');
    $('#adminPanel').removeClass('d-none');
    $('#colRight').removeClass('d-none').addClass('col-lg-8');
  } else {
    $('#colLeft').addClass('d-none');
    $('#colRight').removeClass('d-none').removeClass('col-lg-8').addClass('col-12');
  if (role === 'branch_admin') {
    branchWrap.removeClass('d-none');
  } else if (role === 'office_admin') {
    officeWrap.removeClass('d-none');
  }
}

function renderKelaseh(rows) {
  const trs = rows.map((r, idx) => {
    const rowNo = idx + 1;
    const code = $('<div/>').text(r.code || '').html();
    const branchNo = String(r.branch_no || '').padStart(2, '0');
    const plaintiff = $('<div/>').text(r.plaintiff_name || '').html();
    const defendant = $('<div/>').text(r.defendant_name || '').html();
    const date = $('<div/>').text(r.created_at_jalali || r.created_at || '').html();
    const status = r.status === 'voided' ? 'ابطال' : r.status === 'inactive' ? 'غیرفعال' : 'فعال';
    const statusHtml = $('<div/>').text(status).html();
    const statusClass = r.status === 'voided' ? 'text-danger' : r.status === 'inactive' ? 'text-secondary' : 'text-success';
    const json = encodeURIComponent(
      JSON.stringify({
        code: r.code || '',
        status: r.status || 'active',
        plaintiff_name: r.plaintiff_name || '',
        defendant_name: r.defendant_name || '',
        plaintiff_national_code: r.plaintiff_national_code || '',
        defendant_national_code: r.defendant_national_code || '',
        plaintiff_mobile: r.plaintiff_mobile || '',
        defendant_mobile: r.defendant_mobile || '',
      })
    );
    return `
      <tr data-json="${json}">
        <td><input class="form-check-input kelaseh-label-check" type="checkbox" /></td>
        <td class="text-secondary">${rowNo}</td>
        <td><div class="fw-semibold">${code}</div></td>
        <td class="text-secondary">${branchNo}</td>
        <td>${plaintiff}</td>
        <td>${defendant}</td>
        <td class="text-secondary">${date}</td>
        <td class="${statusClass}">${statusHtml}</td>
        <td class="text-end">
          <div class="btn-group btn-group-sm" role="group">
            <button class="btn btn-outline-dark btn-kelaseh-print" type="button">چاپ</button>
            <button class="btn btn-outline-secondary btn-kelaseh-label" type="button">چاپ لیبل پوشه</button>
            <button class="btn btn-outline-primary btn-kelaseh-edit" type="button">ویرایش</button>
            <button class="btn btn-outline-warning btn-kelaseh-toggle" type="button">فعال/غیرفعال</button>
            <button class="btn btn-outline-danger btn-kelaseh-void" type="button">ابطال</button>
          </div>
          <div class="d-flex justify-content-end gap-2 align-items-center mt-1">
            <div class="form-check form-check-inline m-0">
              <input class="form-check-input kelaseh-sms-plaintiff" type="checkbox" checked />
              <label class="form-check-label small">خواهان</label>
            </div>
            <div class="form-check form-check-inline m-0">
              <input class="form-check-input kelaseh-sms-defendant" type="checkbox" />
              <label class="form-check-label small">خوانده</label>
            </div>
            <button class="btn btn-outline-success btn-sm btn-kelaseh-sms" type="button">ارسال پیامک</button>
          </div>
        </td>
      </tr>
    `;
  });
  $('#kelasehTbody').html(trs.join('') || `<tr><td colspan="9" class="text-center text-secondary py-4">چیزی برای نمایش نیست.</td></tr>`);
}

function refreshKelaseh() {
  const national_code = $('#kelasehNational').val() || '';
  const from = $('#kelasehFrom').val() || '';
  const to = $('#kelasehTo').val() || '';
  return api('kelaseh.list', { national_code, from, to })
    .done((res) => {
      renderKelaseh((res.data && res.data.kelaseh) || []);
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'خطا در دریافت کلاسه‌ها.';
      showToast(msg, 'error');
    });
}

function refreshAdminUsers() {
  const q = $('#adminUsersQuery').val() || '';
  return api('admin.users.list', { q })
    .done((res) => {
      const users = (res.data && res.data.users) || [];
      const rows = users.map((u) => {
        const username = $('<div/>').text(u.username || '').html();
        const email = $('<div/>').text(u.email || '').html();
        const name = $('<div/>').text(u.display_name || `${u.first_name || ''} ${u.last_name || ''}`.trim()).html();
        const mobile = $('<div/>').text(u.mobile || '').html();
        const city = $('<div/>').text(u.city_name || '').html();
        const branches = $('<div/>').text((u.branch_start_no && u.branch_count) ? `شعبه: ${String(u.branch_start_no).padStart(2, '0')} تا ${String(u.branch_start_no + u.branch_count - 1).padStart(2, '0')}` : (u.branch_count ? `شعبه: ${u.branch_count}` : '')).html();
        const role = u.role === 'admin' ? 'admin' : 'user';
        const isActive = Number(u.is_active) === 1;
        return `
          <tr data-id="${u.id}">
            <td>
              <div class="fw-semibold">${username || email}</div>
              ${name ? `<div class="text-secondary small">${name}</div>` : ''}
              ${(mobile || city || branches) ? `<div class="text-secondary small">${[mobile, city, branches].filter(Boolean).join(' | ')}</div>` : ''}
            </td>
            <td>
              <select class="form-select form-select-sm user-role">
                <option value="user" ${role === 'user' ? 'selected' : ''}>کاربر</option>
                <option value="admin" ${role === 'admin' ? 'selected' : ''}>مدیر کل</option>
              </select>
            </td>
            <td>
              <div class="form-check form-switch">
                <input class="form-check-input user-active" type="checkbox" ${isActive ? 'checked' : ''} />
              </div>
            </td>
            <td>
              <button class="btn btn-outline-secondary btn-sm btn-save-user" type="button">ذخیره</button>
            </td>
          </tr>
        `;
      });
      $('#adminUsersTbody').html(rows.join('') || `<tr><td colspan="4" class="text-center text-secondary py-3">کاربری یافت نشد.</td></tr>`);
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'خطا در دریافت کاربران.';
      showToast(msg, 'error');
    });
}

function refreshAdminLogs() {
  return api('admin.audit.list', {})
    .done((res) => {
      const logs = (res.data && res.data.logs) || [];

      const actionMap = {
        register: 'ثبت‌نام',
        login: 'ورود',
        logout: 'خروج',
        create: 'ایجاد',
        update: 'ویرایش',
        delete: 'حذف',
        kelaseh_create: 'ایجاد کلاسه',
        kelaseh_update: 'ویرایش کلاسه',
        kelaseh_set_status: 'تغییر وضعیت کلاسه',
        admin_city_create: 'افزودن شهر',
        admin_city_update: 'ویرایش شهر',
        admin_city_delete: 'حذف شهر',
        admin_create: 'ایجاد کاربر',
        set_role: 'تغییر نقش',
        activate: 'فعال‌سازی',
        deactivate: 'غیرفعال‌سازی',
        admin_delete: 'حذف (مدیر)',
        sms_settings_update: 'ویرایش تنظیمات پیامک',
        sms_send: 'ارسال پیامک',
      };
      const entityMap = {
        user: 'کاربر',
        item: 'داده',
        kelaseh_number: 'پرونده',
        isfahan_city: 'شهر',
        app_settings: 'تنظیمات',
      };

      const rows = logs.map((l) => {
        const dt = $('<div/>').text(l.created_at_jalali || l.created_at || '').html();
        const actor = $('<div/>').text(l.actor_key || l.actor_id || '').html();
        const act = $('<div/>').text(actionMap[l.action] || l.action || '').html();
        const ent = $('<div/>').text(entityMap[l.entity] || l.entity || '').html();
        return `<tr><td class="text-secondary">${dt}</td><td>${actor}</td><td>${act}</td><td>${ent}</td></tr>`;
      });
      $('#adminLogsTbody').html(rows.join('') || `<tr><td colspan="4" class="text-center text-secondary py-3">لاگی یافت نشد.</td></tr>`);
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'خطا در دریافت گزارش.';
      showToast(msg, 'error');
    });
}

function refreshAdminStats() {
  const from = $('#adminStatsFrom').val() || '';
  const to = $('#adminStatsTo').val() || '';
  return api('admin.kelaseh.stats', { from, to })
    .done((res) => {
      const totals = (res.data && res.data.totals) || {};
      $('#adminStatsTotal').text(totals.total || 0);
      $('#adminStatsActive').text(totals.active || 0);
      $('#adminStatsInactive').text(totals.inactive || 0);
      $('#adminStatsVoided').text(totals.voided || 0);

      const cities = (res.data && res.data.cities) || [];
      const cityRows = cities.map((c) => {
        const name = $('<div/>').text(c.city_name || c.city_code || '').html();
        return `<tr><td>${name}</td><td>${c.total || 0}</td><td>${c.active || 0}</td><td>${c.inactive || 0}</td><td>${c.voided || 0}</td></tr>`;
      });
      $('#adminStatsCitiesTbody').html(cityRows.join('') || `<tr><td colspan="5" class="text-center text-secondary py-3">داده‌ای یافت نشد.</td></tr>`);

      const users = (res.data && res.data.users) || [];
      const userRows = users.map((u) => {
        const uname = $('<div/>').text(u.display_name || u.username || '').html();
        const city = $('<div/>').text(u.city_name || u.city_code || '').html();
        return `<tr><td>${uname}</td><td class="text-secondary">${city}</td><td>${u.total || 0}</td><td>${u.active || 0}</td><td>${u.inactive || 0}</td><td>${u.voided || 0}</td></tr>`;
      });
      $('#adminStatsUsersTbody').html(userRows.join('') || `<tr><td colspan="6" class="text-center text-secondary py-3">داده‌ای یافت نشد.</td></tr>`);
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'خطا در دریافت آمار.';
      showToast(msg, 'error');
    });
}

function refreshAdminSmsSettings() {
  return api('admin.sms.settings.get', {})
    .done((res) => {
      const s = (res.data && res.data.settings) || {};
      $('#adminSmsEnabled').prop('checked', Number(s.enabled) === 1);
      $('#adminSmsSender').val(s.sender || '');
      $('#adminSmsTplPlaintiff').val(s.tpl_plaintiff || '');
      $('#adminSmsTplDefendant').val(s.tpl_defendant || '');

      const hasKey = Number(s.api_key_present) === 1;
      $('#adminSmsApiKey').val('');
      $('#adminSmsApiKeyHint').text(hasKey ? 'کلید API ذخیره شده است. برای تغییر، مقدار جدید وارد کنید.' : 'کلید API تنظیم نشده است.');
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'خطا در دریافت تنظیمات پیامک.';
      showToast(msg, 'error');
    });
}

function refreshAdminItems() {
  const q = $('#adminItemsQuery').val() || '';
  return api('admin.items.list', { q })
    .done((res) => {
      const items = (res.data && res.data.items) || [];
      const rows = items.map((it) => {
        const owner = $('<div/>').text(it.owner_key || '').html();
        const title = $('<div/>').text(it.title || '').html();
        const date = $('<div/>').text(it.updated_at_jalali || it.updated_at || '').html();
        return `
          <tr data-id="${it.id}">
            <td class="text-secondary">${owner}</td>
            <td>
              <div class="fw-semibold">${title}</div>
              ${it.content ? `<div class="text-secondary small">${$('<div/>').text(it.content).html()}</div>` : ''}
            </td>
            <td class="text-secondary">${date}</td>
            <td class="text-end">
              <button class="btn btn-outline-danger btn-sm btn-admin-item-del" type="button">حذف</button>
            </td>
          </tr>
        `;
      });
      $('#adminItemsTbody').html(rows.join('') || `<tr><td colspan="4" class="text-center text-secondary py-3">داده‌ای یافت نشد.</td></tr>`);
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'خطا در دریافت داده‌ها.';
      showToast(msg, 'error');
    });
}
$(document).on('change', '#adminEditRoleSelect', updateAdminEditUserFields);

function boot() {
  setView('loading');
  $.ajax({
    url: 'core.php',
    method: 'POST',
    data: { action: 'session' },
    dataType: 'json',
  })
    .done((res) => {
      csrfToken = (res.data && res.data.csrf_token) || '';
      currentUser = (res.data && res.data.user) || null;

      if (!currentUser) {
        setView('auth');
$(document).on('click', '.btn-admin-edit-user', function () {
    const tr = $(this).closest('tr');
    const raw = tr.attr('data-json');
    if (!raw) return;
    
    let u;
    try {
        u = JSON.parse(decodeURIComponent(raw));
    } catch(e) {
        console.error(e);
        return;
      }
      renderUser();
      setView('app');
      if (!window.location.hash) {
        window.location.hash = '#dashboard';
      }
      renderPage(getPageFromHash());
      startHeaderClock();
      refreshKelaseh();
      if (currentUser.role === 'admin') {
        loadAdminCities();
        refreshAdminUsers();
        refreshAdminCities();
        refreshAdminItems();
        refreshAdminLogs();
        refreshAdminStats();
        refreshAdminSmsSettings();
      }
    })
    .fail((xhr) => {
      const msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) || 'خطا در اتصال به سرور.';
      showToast(msg, 'error');
      setView('auth');
    });
}

$(document).on('submit', '#formLogin', function (e) {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(this));
  api('login', data)
    .done((res) => {
      csrfToken = (res.data && res.data.csrf_token) || csrfToken;
      currentUser = (res.data && res.data.user) || null;
      showToast(res.message || 'ورود انجام شد.', 'success');
      renderUser();
      setView('app');
      if (!window.location.hash) {
        window.location.hash = '#dashboard';
      }
      renderPage(getPageFromHash());
      startHeaderClock();
      refreshKelaseh();
      if (currentUser && currentUser.role === 'admin') {
        loadAdminCities();
        refreshAdminUsers();
        refreshAdminCities();
        refreshAdminItems();
        refreshAdminLogs();
        refreshAdminStats();
        refreshAdminSmsSettings();
      }
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'ورود ناموفق بود.';
      showToast(msg, 'error');
    });
});

$(document).on('click', '#btnLogout', function () {
  api('logout', {})
    .done((res) => {
      csrfToken = (res.data && res.data.csrf_token) || '';
      currentUser = null;
      showToast(res.message || 'خارج شدید.', 'success');
      renderUser();
      setView('auth');
    })
    .fail(() => {
      showToast('خروج ناموفق بود.', 'error');
    });
});

$(window).on('hashchange', function () {
  if (!currentUser) {
    return;
  }
  renderPage(getPageFromHash());
});

$(document).on('click', '#btnKelasehRefresh', function () {
  refreshKelaseh();
});

$(document).on('click', '#btnKelasehSearch', function () {
  refreshKelaseh();
});

$(document).on('submit', '#formKelasehCreate', function (e) {
  e.preventDefault();
  const submitter = (e.originalEvent && e.originalEvent.submitter) || null;
  const shouldSendSms = submitter && submitter.id === 'btnKelasehCreateAndSms';
  const to_plaintiff = $('#kelasehSmsPlaintiff').is(':checked') ? 1 : 0;
  const to_defendant = $('#kelasehSmsDefendant').is(':checked') ? 1 : 0;
  const data = Object.fromEntries(new FormData(this));
  api('kelaseh.create', data)
    .done((res) => {
      const code = (res.data && res.data.code) || '';
      showToast(code ? `شناسه پرونده ایجاد شد: ${code}` : (res.message || 'ثبت شد.'), 'success');
      this.reset();
      refreshKelaseh();
      if (code) {
        window.open(`core.php?action=kelaseh.print&code=${encodeURIComponent(code)}`, '_blank');
      }

      if (shouldSendSms && code) {
        if (!to_plaintiff && !to_defendant) {
          showToast('برای ارسال پیامک، خواهان یا خوانده را انتخاب کنید.', 'error');
          return;
        }
        api('kelaseh.sms.send', { code, to_plaintiff, to_defendant })
          .done((r2) => {
            showToast(r2.message || 'پیامک ارسال شد.', 'success');
          })
          .fail((xhr) => {
            const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'ارسال پیامک ناموفق بود.';
            showToast(msg, 'error');
          });
      }
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'ثبت پرونده ناموفق بود.';
      showToast(msg, 'error');
    });
});

$(document).on('click', '#btnKelasehExportCsv', function () {
  const national_code = $('#kelasehNational').val() || '';
  const from = $('#kelasehFrom').val() || '';
  const to = $('#kelasehTo').val() || '';
  const qs = new URLSearchParams({ action: 'kelaseh.export.csv', csrf_token: csrfToken, national_code, from, to });
  window.location.href = `core.php?${qs.toString()}`;
});

$(document).on('click', '#btnKelasehExportPdf', function () {
  const national_code = $('#kelasehNational').val() || '';
  const from = $('#kelasehFrom').val() || '';
  const to = $('#kelasehTo').val() || '';
  const qs = new URLSearchParams({ action: 'kelaseh.export.print', csrf_token: csrfToken, national_code, from, to });
  window.open(`core.php?${qs.toString()}`, '_blank');
});

$(document).on('click', '#kelasehTbody .btn-kelaseh-print', function () {
  const tr = $(this).closest('tr');
  const raw = tr.attr('data-json');
  if (!raw) {
    return;
  }
  const payload = JSON.parse(decodeURIComponent(raw));
  const code = payload.code;
  window.open(`core.php?action=kelaseh.print&code=${encodeURIComponent(code)}`, '_blank');
});

$(document).on('click', '#kelasehTbody .btn-kelaseh-label', function () {
  const tr = $(this).closest('tr');
  const raw = tr.attr('data-json');
  if (!raw) {
    return;
  }
  const payload = JSON.parse(decodeURIComponent(raw));
  const code = payload.code;
  window.open(`core.php?action=kelaseh.label&code=${encodeURIComponent(code)}`, '_blank');
});

$(document).on('change', '#kelasehTbody .kelaseh-label-check', function () {
  if (!this.checked) {
    return;
  }
  const tr = $(this).closest('tr');
  const raw = tr.attr('data-json');
  if (!raw) {
    return;
  }
  const payload = JSON.parse(decodeURIComponent(raw));
  const code = payload.code;
  window.open(`core.php?action=kelaseh.label&code=${encodeURIComponent(code)}`, '_blank');
  this.checked = false;
});

$(document).on('click', '#kelasehTbody .btn-kelaseh-sms', function () {
  const tr = $(this).closest('tr');
  const raw = tr.attr('data-json');
  if (!raw) {
    return;
  }
  const payload = JSON.parse(decodeURIComponent(raw));
  const code = payload.code;
  const to_plaintiff = tr.find('.kelaseh-sms-plaintiff').is(':checked') ? 1 : 0;
  const to_defendant = tr.find('.kelaseh-sms-defendant').is(':checked') ? 1 : 0;
  if (!to_plaintiff && !to_defendant) {
    showToast('حداقل یک گیرنده را انتخاب کنید.', 'error');
    return;
  }
  api('kelaseh.sms.send', { code, to_plaintiff, to_defendant })
    .done((res) => {
      showToast(res.message || 'ارسال انجام شد.', 'success');
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'ارسال پیامک ناموفق بود.';
      showToast(msg, 'error');
    });
});

$(document).on('click', '#kelasehTbody .btn-kelaseh-edit', function () {
  const tr = $(this).closest('tr');
  const raw = tr.attr('data-json');
  if (!raw) {
    return;
  }
  const payload = JSON.parse(decodeURIComponent(raw));
  $('#formKelasehEdit [name=code]').val(payload.code);
  $('#formKelasehEdit [name=plaintiff_name]').val(payload.plaintiff_name);
  $('#formKelasehEdit [name=defendant_name]').val(payload.defendant_name);
  $('#formKelasehEdit [name=plaintiff_national_code]').val(payload.plaintiff_national_code);
  $('#formKelasehEdit [name=defendant_national_code]').val(payload.defendant_national_code);
  $('#formKelasehEdit [name=plaintiff_mobile]').val(payload.plaintiff_mobile);
  $('#formKelasehEdit [name=defendant_mobile]').val(payload.defendant_mobile);
  const modal = new bootstrap.Modal(document.getElementById('modalKelasehEdit'));
  modal.show();
});

$(document).on('submit', '#formKelasehEdit', function (e) {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(this));
  api('kelaseh.update', data)
    .done((res) => {
      showToast(res.message || 'ویرایش شد.', 'success');
      const modalEl = document.getElementById('modalKelasehEdit');
      const modal = bootstrap.Modal.getInstance(modalEl);
      if (modal) {
        modal.hide();
      }
      refreshKelaseh();
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'ویرایش ناموفق بود.';
      showToast(msg, 'error');
    });
});

$(document).on('click', '#kelasehTbody .btn-kelaseh-toggle', function () {
  const tr = $(this).closest('tr');
  const raw = tr.attr('data-json');
  if (!raw) {
    return;
  }
  const payload = JSON.parse(decodeURIComponent(raw));
  const code = payload.code;
  const status = payload.status;
  if (status === 'voided') {
    showToast('پرونده ابطال شده است.', 'error');
    return;
  }
  const next = status === 'inactive' ? 'active' : 'inactive';
  api('kelaseh.set_status', { code, status: next })
    .done((res) => {
      showToast(res.message || 'وضعیت به‌روزرسانی شد.', 'success');
      refreshKelaseh();
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'عملیات ناموفق بود.';
      showToast(msg, 'error');
    });
});

$(document).on('click', '#kelasehTbody .btn-kelaseh-void', function () {
  const tr = $(this).closest('tr');
  const raw = tr.attr('data-json');
  if (!raw) {
    return;
  }
  const payload = JSON.parse(decodeURIComponent(raw));
  const code = payload.code;
  if (!confirm('این پرونده ابطال شود؟')) {
    return;
  }
  api('kelaseh.set_status', { code, status: 'voided' })
    .done((res) => {
      showToast(res.message || 'ابطال شد.', 'success');
      refreshKelaseh();
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'ابطال ناموفق بود.';
      showToast(msg, 'error');
    });
});

$(document).on('submit', '#formAdminCreateUser', function (e) {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(this));
  api('admin.users.create', data)
    .done((res) => {
      showToast(res.message || 'کاربر ایجاد شد.', 'success');
      this.reset();
      refreshAdminUsers();
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'ایجاد کاربر ناموفق بود.';
      showToast(msg, 'error');
    });
});

$(document).on('click', '#btnAdminUsersRefresh', function () {
  refreshAdminUsers();
});

$(document).on('click', '#btnAdminCitiesRefresh', function () {
  refreshAdminCities();
});

$(document).on('submit', '#formAdminCityCreate', function (e) {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(this));
  api('admin.cities.create', data)
    .done((res) => {
      showToast(res.message || 'شهر ایجاد شد.', 'success');
      this.reset();
      adminCitiesLoaded = false;
      $.when(loadAdminCities(), refreshAdminCities()).done(() => {});
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'افزودن شهر ناموفق بود.';
      showToast(msg, 'error');
    });
});

$(document).on('click', '#adminCitiesTbody .btn-city-save', function () {
  const tr = $(this).closest('tr');
  const code = tr.data('code');
  const name = tr.find('.city-name').val();
  api('admin.cities.update', { code, name })
    .done((res) => {
      showToast(res.message || 'ذخیره شد.', 'success');
      adminCitiesLoaded = false;
      loadAdminCities();
      refreshAdminCities();
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'ذخیره ناموفق بود.';
      showToast(msg, 'error');
    });
});

$(document).on('click', '#adminCitiesTbody .btn-city-del', function () {
  const tr = $(this).closest('tr');
  const code = tr.data('code');
  if (!confirm('این شهر حذف شود؟')) {
    return;
  }
  api('admin.cities.delete', { code })
    .done((res) => {
      showToast(res.message || 'حذف شد.', 'success');
      adminCitiesLoaded = false;
      loadAdminCities();
      refreshAdminCities();
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'حذف ناموفق بود.';
      showToast(msg, 'error');
    });
});

$(document).on('click', '#btnAdminItemsRefresh', function () {
  refreshAdminItems();
});

$(document).on('click', '#adminItemsTbody .btn-admin-item-del', function () {
  const tr = $(this).closest('tr');
  const id = tr.data('id');
  if (!confirm('این آیتم حذف شود؟')) {
    return;
  }
  api('admin.items.delete', { id })
    .done((res) => {
      showToast(res.message || 'حذف شد.', 'success');
      refreshAdminItems();
      refreshAdminLogs();
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'حذف ناموفق بود.';
      showToast(msg, 'error');
    });
});

$(document).on('click', '#adminUsersTbody .btn-save-user', function () {
  const tr = $(this).closest('tr');
  const id = tr.data('id');
  const role = tr.find('.user-role').val();
  const isActive = tr.find('.user-active').is(':checked') ? 1 : 0;

  $.when(api('admin.users.set_role', { id, role }), api('admin.users.set_active', { id, is_active: isActive }))
    .done(() => {
      showToast('ذخیره شد.', 'success');
      refreshAdminUsers();
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'ذخیره ناموفق بود.';
      showToast(msg, 'error');
    });
});

$(document).on('click', '#btnAdminLogsRefresh', function () {
  refreshAdminLogs();
});

$(document).on('click', '#btnAdminStatsRefresh', function () {
  refreshAdminStats();
});

$(document).on('submit', '#formAdminSmsSettings', function (e) {
  e.preventDefault();
  const enabled = $('#adminSmsEnabled').is(':checked') ? 1 : 0;
  const api_key = $('#adminSmsApiKey').val() || '';
  const sender = $('#adminSmsSender').val() || '';
  const tpl_plaintiff = $('#adminSmsTplPlaintiff').val() || '';
  const tpl_defendant = $('#adminSmsTplDefendant').val() || '';
    }

  api('admin.sms.settings.set', { enabled, api_key, sender, tpl_plaintiff, tpl_defendant })
    .done((res) => {
      showToast(res.message || 'ذخیره شد.', 'success');
      refreshAdminSmsSettings();
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'ذخیره تنظیمات پیامک ناموفق بود.';
      showToast(msg, 'error');
    });
    const form = $('#formAdminEditUser');
    form.find('[name="id"]').val(u.id);
    form.find('[name="first_name"]').val(u.first_name || '');
    form.find('[name="last_name"]').val(u.last_name || '');
    form.find('[name="mobile"]').val(u.mobile || '');
    form.find('[name="email"]').val(u.email || '');
    form.find('[name="password"]').val('');
    form.find('[name="role"]').val(u.role || 'user');
    form.find('[name="is_active"]').val(Number(u.is_active) === 1 ? '1' : '0');
    form.find('[name="branch_count"]').val(u.branch_count || 1);

    // Branch checkboxes
    let html = '';
    const userBranches = (u.branches || []);
    const globalCap = u.branch_capacity || 15;
    
    for (let i = 1; i <= 99; i++) {
        const isChecked = userBranches.includes(i) ? 'checked' : '';
        // Note: per-branch capacity is not currently returned by list API, so we default to global
        // If we want to support per-branch capacity edit, we need backend support in list or fetch details
        // For now we use global cap or empty.
        const cap = globalCap;
        
        html += `
        <div class="col-6 col-md-4 col-lg-3">
            <div class="border rounded p-2 h-100">
                <div class="form-check mb-1">
                    <input class="form-check-input branch-check" type="checkbox" name="branches_check[]" value="${i}" id="edit_br_${i}" ${isChecked}>
                    <label class="form-check-label small" for="edit_br_${i}">شعبه ${i}</label>
                </div>
                <input type="number" class="form-control form-control-sm branch-capacity" data-branch="${i}" value="${cap}" min="1" max="999" placeholder="ظرفیت" ${isChecked ? '' : 'disabled'}>
            </div>
        </div>`;
    }
    $('#adminEditBranchList').html(html);

    updateAdminEditUserFields();
    new bootstrap.Modal('#modalAdminEditUser').show();
});

$(document).on('change', '#adminEditBranchList .branch-check', function() {
    const capInput = $(this).closest('div.border').find('.branch-capacity');
    capInput.prop('disabled', !this.checked);
});

$(document).on('submit', '#formAdminEditUser', function(e) {
    e.preventDefault();
    const form = $(this);
    const data = {
        id: form.find('[name="id"]').val(),
        first_name: form.find('[name="first_name"]').val(),
        last_name: form.find('[name="last_name"]').val(),
        mobile: form.find('[name="mobile"]').val(),
        email: form.find('[name="email"]').val(),
        password: form.find('[name="password"]').val(),
        role: form.find('[name="role"]').val(),
        is_active: form.find('[name="is_active"]').val(),
        branch_count: form.find('[name="branch_count"]').val(),
        branch_capacity: 15 // default or from logic?
    };
    
    // Determine branch capacity for non-branch-admin users (global)
    // Actually API handles it. If role is branch_admin, we send branches array.
    
    if (data.role === 'branch_admin') {
        const branches = [];
        $('#adminEditBranchList .branch-check:checked').each(function() {
            const b = $(this).val();
            const cap = $(this).closest('div.border').find('.branch-capacity').val();
            branches.push({ branch: b, capacity: cap });
        });
        data.branches = branches;
    }
    
    api('admin.users.update', data)
        .done(res => {
            showToast(res.message, 'success');
            bootstrap.Modal.getInstance(document.getElementById('modalAdminEditUser')).hide();
            if (currentUser && currentUser.role === 'admin') refreshAdminUsers();
            else refreshOfficeUsers();
        })
        .fail(xhr => {
             showToast((xhr.responseJSON && xhr.responseJSON.message) || 'خطا در ذخیره.', 'error');
        });
});

$(document).on('click', '.btn-admin-delete-user', function() {
    if (!confirm('آیا از حذف این کاربر مطمئن هستید؟')) return;
    const tr = $(this).closest('tr');
    const id = tr.data('id');
    
    api('admin.users.delete', { id })
        .done(res => {
            showToast(res.message, 'success');
            if (currentUser && currentUser.role === 'admin') refreshAdminUsers();
            else refreshOfficeUsers();
        })
        .fail(xhr => {
             showToast((xhr.responseJSON && xhr.responseJSON.message) || 'خطا در حذف.', 'error');
        });
});

$(boot);