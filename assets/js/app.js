
/**
 * کدهای جاوااسکریپت اصلی برنامه (Frontend Logic)
 * مدیریت رویدادها، ارسال درخواست‌های AJAX به سرور، و به‌روزرسانی رابط کاربری (UI)
 */

let csrfToken = '';
let currentUser = null;
let adminCitiesLoaded = false;
let headerClockTimer = null;

function initDatePickers() {
  const commonOptions = {
    format: 'YYYY/MM/DD',
    autoClose: true,
    initialValue: false,
    persianDigit: true,
    calendar: {
      persian: {
        showHint: true,
        leapThreshold: 12
      }
    }
  };

  if ($.fn.pDatepicker) {
    $('#kelasehFrom, #kelasehTo, #adminStatsFrom, #adminStatsTo').pDatepicker(commonOptions);
    
    $('#kelasehManualDate').pDatepicker({
      ...commonOptions,
      onSelect: function(unix) {
        const date = new persianDate(unix);
        $('#manual_year').val(date.year());
        $('#manual_month').val(date.month());
        $('#manual_day').val(date.date());
      }
    });
  }
}

function toPersianDigits(str) {
  if (str === null || str === undefined) return '';
  return String(str).replace(/[0-9]/g, function (w) {
    const persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return persian[w];
  });
}

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

function loadBranchManagers() {
  const isAdmin = currentUser && currentUser.role === 'admin';
  const isOfficeAdmin = currentUser && currentUser.role === 'office_admin';
  
  if (!isAdmin && !isOfficeAdmin) {
    $('#kelasehOwnerFilterWrap').addClass('d-none');
    return $.Deferred().resolve().promise();
  }

  const city_code = isAdmin ? $('#adminKelasehCityFilterMain').val() : null;
  
  // For admin, if no city is selected, hide the manager filter
  if (isAdmin && !city_code) {
    $('#kelasehOwnerFilterWrap').addClass('d-none');
    $('#kelasehOwnerFilter').val('0'); // Reset manager filter
    return $.Deferred().resolve().promise();
  }

  return api('admin.users.list', { q: '', city_code })
    .done((res) => {
      const users = (res.data && res.data.users) || [];
      const branchAdmins = users.filter((u) => u.role === 'branch_admin' || u.role === 'user');
      const opts = ['<option value="0">همه مدیران</option>']
        .concat(
          branchAdmins.map((u) => {
            const name = toPersianDigits(u.display_name || `${u.first_name || ''} ${u.last_name || ''}`.trim() || u.username || '');
            return `<option value="${u.id}">${name}</option>`;
          })
        )
        .join('');
      $('#kelasehOwnerFilter').html(opts);
      $('#kelasehOwnerFilterWrap').removeClass('d-none');
    })
    .fail(() => {
      showToast('خطا در دریافت لیست مدیران شعبه.', 'error');
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
      const opts2 = ['<option value="">همه اداره‌ها</option>']
        .concat(
          cities.map((c) => {
            const code = $('<div/>').text(c.code || '').html();
            const name = $('<div/>').text(c.name || '').html();
            return `<option value="${code}">${name}</option>`;
          })
        )
        .join('');
      $('#adminKelasehCityFilter').html(opts2);
      $('#adminKelasehCityFilterMain').html(opts2);
      adminCitiesLoaded = true;
    })
    .fail(() => {
      showToast('خطا در دریافت لیست اداره‌ها.', 'error');
    });
}

function refreshAdminCities() {
  return api('admin.cities.list', {})
    .done((res) => {
      const cities = (res.data && res.data.cities) || [];
      const rows = cities
        .map((c) => {
          const code = toPersianDigits(c.code || '');
          const name = toPersianDigits(c.name || '');
          return `
            <tr data-code="${c.code}">
              <td>
                <input class="form-control form-control-sm city-code" type="text" value="${c.code}" maxlength="4" dir="ltr" />
              </td>
              <td>
                <input class="form-control form-control-sm city-name" type="text" value="${c.name}" />
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
      $('#adminCitiesTbody').html(rows || `<tr><td colspan="3" class="text-center text-secondary py-3">اداره‌ای ثبت نشده است.</td></tr>`);
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'خطا در دریافت اداره‌ها.';
      showToast(msg, 'error');
    });
}

function setView(mode) {
  $('#viewLoading').toggleClass('d-none', mode !== 'loading');
  $('#viewAuth').toggleClass('d-none', mode !== 'auth');
  $('#viewApp').toggleClass('d-none', mode !== 'app');
  $('#mainHeader').toggleClass('d-none', mode === 'auth' || mode === 'loading');
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

  const roleFa = currentUser.role === 'admin' ? 'مدیر کل' : currentUser.role === 'office_admin' ? 'مدیر اداره' : currentUser.role === 'branch_admin' ? 'مدیر شعبه' : 'کاربر';
  $('#currentRole').text(roleFa);
  $('#profileEmail').text(currentUser.username || currentUser.email || '');
  $('#profileName').text(currentUser.display_name || '');
  $('#profileRole').text(roleFa);

  const cityName = currentUser.city_name || '';
  $('#kelasehOffice').text(cityName ? `اداره ${cityName}` : '').toggleClass('d-none', !cityName);

  const isAdmin = currentUser.role === 'admin';
  const isOfficeAdmin = currentUser.role === 'office_admin';
  $('#adminPanel').toggleClass('d-none', !isAdmin);
  // Revert: We want admin panel link to be always visible for admin, 
  // but handled by click to switch view, not just toggle d-none on panel itself.
  // Actually, the nav item visibility is controlled here.
  $('#navItemAdmin').toggleClass('d-none', !isAdmin);
  $('#navItemAdminKelasehSearch').toggleClass('d-none', !isAdmin);
  $('#headerNav a[data-page="create"]').closest('li').toggleClass('d-none', isOfficeAdmin || isAdmin);
}

function updateKelasehBranchSelect() {
  const wrap = $('#kelasehBranchSelectWrap');
  const sel = $('#kelasehBranchNoSelect');
  if (!currentUser || currentUser.role !== 'branch_admin') {
    wrap.addClass('d-none');
    sel.html('<option value="">انتخاب خودکار</option>');
    return;
  }

  const raw = Array.isArray(currentUser.branches) ? currentUser.branches : [];
  let branches = raw.map((x) => Number(x)).filter((x) => Number.isFinite(x) && x > 0);
  if (!branches.length) {
    const start = Number(currentUser.branch_start_no || 1);
    const count = Number(currentUser.branch_count || 1);
    branches = [];
    for (let i = 0; i < count; i++) branches.push(start + i);
  }
  branches = Array.from(new Set(branches)).sort((a, b) => a - b);

  const opts = ['<option value="">انتخاب خودکار</option>'];
  branches.forEach((b) => {
    const v = String(b);
    opts.push(`<option value="${v}">شعبه ${toPersianDigits(String(b).padStart(2, '0'))}</option>`);
  });
  sel.html(opts.join(''));
  wrap.removeClass('d-none');
}

// Ensure the click handler is attached
$(document).on('click', '#navItemAdmin a', function(e) {
    e.preventDefault();
    window.location.hash = '#admin';
});

function refreshHeaderDateTime() {
  if (!csrfToken) {
    return;
  }
  api('time.now', {})
    .done((res) => {
      const s = (res.data && res.data.now_jalali) || '';
      $('#headerDateTime').text(toPersianDigits(s));
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
  if (raw === 'profile' || raw === 'create' || raw === 'dashboard' || raw === 'admin' || raw === 'admin-kelaseh-search') {
    return raw;
  }
  return 'dashboard';
}

function renderPage(page) {
  const $links = $('#headerNav a');
  $links.removeClass('active');
  $links.filter(`[data-page="${page}"]`).addClass('active');

  const isAdmin = currentUser && currentUser.role === 'admin';
  const isOfficeAdmin = currentUser && currentUser.role === 'office_admin';

  // Default: Hide all main sections
  $('#cardProfile').addClass('d-none');
  $('#adminPanel').addClass('d-none');
  $('#officePanel').addClass('d-none');
  $('#cardKelaseh').addClass('d-none');
  $('#kelasehCreateSection').addClass('d-none');
  $('#kelasehListSection').addClass('d-none');
  $('#btnKelasehRefresh').addClass('d-none');
  $('#colRight').removeClass('d-none');

  if (page === 'profile') {
    $('#cardProfile').removeClass('d-none');
    return;
  }

  if (page === 'admin') {
    if (isAdmin) {
      $('#adminPanel').removeClass('d-none');
      $('#colRight').removeClass('d-none'); // Show container for admin panel
      $('#kelasehCreateSection').addClass('d-none'); // Hide create section
      $('#kelasehListSection').addClass('d-none'); // Hide list section
      $('#cardKelaseh').addClass('d-none'); // Hide main card
      $('#officePanel').addClass('d-none');
    } else {
      window.location.hash = '#dashboard';
    }
    return;
  }

  if (page === 'admin-kelaseh-search') {
    if (isAdmin) {
      $('#adminPanel').removeClass('d-none');
      $('#colRight').removeClass('d-none');
      $('#kelasehCreateSection').addClass('d-none');
      $('#kelasehListSection').addClass('d-none');
      $('#cardKelaseh').addClass('d-none');
      $('#officePanel').addClass('d-none');
      $('button[data-bs-target="#adminKelasehSearch"]').trigger('click');
      $('#adminKelasehSearchQuery').trigger('focus');
    } else {
      window.location.hash = '#dashboard';
    }
    return;
  }

  if (page === 'create') {
    if (isOfficeAdmin) {
      window.location.hash = '#dashboard';
      return;
    }
    $('#cardKelaseh').removeClass('d-none');
    $('#kelasehCreateSection').removeClass('d-none');
    $('#kelasehCardTitle').text('ایجاد شماره کلاسه');
    updateKelasehBranchSelect();
    return;
  }

  // Dashboard (default)
  $('#cardKelaseh').removeClass('d-none');
  $('#kelasehListSection').removeClass('d-none');
  $('#btnKelasehRefresh').removeClass('d-none');
  $('#kelasehCardTitle').text('پنل کاربری');
  
  if (isAdmin) {
    $('#kelasehCityFilterWrap').removeClass('d-none');
    loadAdminCities().done(() => {
      loadBranchManagers();
    });
  } else {
    $('#kelasehCityFilterWrap').addClass('d-none');
    loadBranchManagers();
  }

  if (isOfficeAdmin) {
    $('#officePanel').removeClass('d-none');
  }

  if (currentUser.role === 'office_admin') {
      // Show all tabs for office admin
      $('button[data-bs-target="#officeUsers"]').closest('li').removeClass('d-none');
      $('button[data-bs-target="#officeStats"]').closest('li').removeClass('d-none');
      $('button[data-bs-target="#officeKelaseh"]').closest('li').removeClass('d-none');
      $('#officePanel .card-header').text('پنل مدیر اداره');
  }
}

function generateKelasehRows(rows, offset = 0) {
  if (!rows || !rows.length) return '';
  return rows.map((r, idx) => {
    const rowNo = toPersianDigits(offset + idx + 1);
    const rawCode = r.full_code || r.code || '';
    const code = $('<div/>').text(toPersianDigits(rawCode)).html();
    const branchNo = toPersianDigits(String(r.branch_no || '').padStart(2, '0'));
    const cityName = $('<div/>').text(toPersianDigits(r.city_name || '')).html();
    const ownerName = $('<div/>').text(toPersianDigits(r.owner_name || '')).html();
    const plaintiff = $('<div/>').text(toPersianDigits(r.plaintiff_name || '')).html();
    const defendant = $('<div/>').text(toPersianDigits(r.defendant_name || '')).html();
    const date = $('<div/>').text(toPersianDigits(r.created_at_jalali || r.created_at || '')).html();
    const status = r.status === 'voided' ? 'ابطال' : r.status === 'inactive' ? 'غیرفعال' : 'فعال';
    const statusHtml = $('<div/>').text(status).html();
    const statusClass = r.status === 'voided' ? 'text-danger' : r.status === 'inactive' ? 'text-secondary' : 'text-success';
    
    let manualBadge = '';
    const isManualDate = !!r.is_manual;
    const isManualBranch = !!r.is_manual_branch;

    if (isManualDate && isManualBranch) {
      manualBadge = '<span class="badge bg-warning text-dark ms-1" style="font-size: 0.6rem;">شعبه و تاریخ دستی</span>';
    } else if (isManualDate) {
      manualBadge = '<span class="badge bg-warning text-dark ms-1" style="font-size: 0.6rem;">تاریخ دستی</span>';
    } else if (isManualBranch) {
      manualBadge = '<span class="badge bg-warning text-dark ms-1" style="font-size: 0.6rem;">شعبه دستی</span>';
    }

    const json = encodeURIComponent(
      JSON.stringify({
        code: r.code || '',
        full_code: r.full_code || '',
        status: r.status || 'active',
        plaintiff_name: r.plaintiff_name || '',
        defendant_name: r.defendant_name || '',
        plaintiff_national_code: r.plaintiff_national_code || '',
        defendant_national_code: r.defendant_national_code || '',
        plaintiff_mobile: r.plaintiff_mobile || '',
        defendant_mobile: r.defendant_mobile || '',
        created_at_jalali: r.created_at_jalali || '',
        city_name: r.city_name || ''
      })
    );

    const actionButtons = `
        <div class="d-flex flex-column gap-1">
            <div class="btn-group btn-group-sm" role="group">
                <button class="btn btn-outline-secondary btn-kelaseh-label" type="button">چاپ لیبل</button>
                <button class="btn btn-outline-primary btn-kelaseh-edit" type="button">ویرایش</button>
                <button class="btn btn-outline-warning btn-kelaseh-toggle" type="button">وضعیت</button>
                <button class="btn btn-outline-danger btn-kelaseh-void" type="button">ابطال</button>
            </div>
            <div class="d-flex justify-content-end gap-2 align-items-center">
                <div class="form-check form-check-inline m-0">
                    <input class="form-check-input kelaseh-sms-plaintiff" type="checkbox" />
                    <label class="form-check-label small" style="font-size: 0.7rem;">خواهان</label>
                </div>
                <div class="form-check form-check-inline m-0">
                    <input class="form-check-input kelaseh-sms-defendant" type="checkbox" />
                    <label class="form-check-label small" style="font-size: 0.7rem;">خوانده</label>
                </div>
                <button class="btn btn-outline-success btn-sm py-0 btn-kelaseh-sms" style="font-size: 0.7rem;" type="button">پیامک</button>
            </div>
        </div>
    `;

    return `
      <tr data-json="${json}">
        <td>
           <div class="form-check d-flex justify-content-center m-0">
              <input class="form-check-input kelaseh-label-check" type="checkbox" id="chk_lbl_${idx}_${r.code}" />
           </div>
        </td>
        <td class="text-secondary">${rowNo}</td>
        <td><div class="fw-semibold" dir="ltr">${code}${manualBadge}</div></td>
        <td class="text-secondary">${branchNo}</td>
        <td class="text-secondary">${cityName}</td>
        <td class="text-secondary small">${ownerName}</td>
        <td>${plaintiff}</td>
        <td>${defendant}</td>
        <td class="text-secondary">${date}</td>
        <td class="${statusClass}">${statusHtml}</td>
        <td class="text-end">
          ${actionButtons}
        </td>
      </tr>
    `;
  }).join('');
}

let kelasehCurrentPage = 1;
const kelasehPageSize = 100;

function renderKelaseh(res) {
  const data = res.data || {};
  const rows = data.kelaseh || [];
  const total = data.total || 0;
  const page = data.page || 1;
  const limit = data.limit || 100;
  const offset = (page - 1) * limit;

  const html = generateKelasehRows(rows, offset);
  $('#kelasehTbody').html(html || `<tr><td colspan="11" class="text-center text-secondary py-4">چیزی برای نمایش نیست.</td></tr>`);
  
  // Pagination Info
  const start = offset + 1;
  const end = Math.min(offset + rows.length, total);
  if (total > 0) {
    $('#kelasehPaginationInfo').text(toPersianDigits(`نمایش ${start} تا ${end} از مجموع ${total} کلاسه`));
  } else {
    $('#kelasehPaginationInfo').text('');
  }

  // Pagination Controls
  const totalPages = Math.ceil(total / limit);
  let pagHtml = '';
  if (totalPages > 1) {
    const maxVisible = 5;
    let startPage = Math.max(1, page - 2);
    let endPage = Math.min(totalPages, startPage + maxVisible - 1);
    if (endPage - startPage + 1 < maxVisible) {
      startPage = Math.max(1, endPage - maxVisible + 1);
    }

    pagHtml += `<li class="page-item ${page === 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${page - 1}">قبلی</a></li>`;
    if (startPage > 1) {
      pagHtml += `<li class="page-item"><a class="page-link" href="#" data-page="1">۱</a></li>`;
      if (startPage > 2) pagHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
    }
    for (let i = startPage; i <= endPage; i++) {
      pagHtml += `<li class="page-item ${i === page ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${toPersianDigits(i)}</a></li>`;
    }
    if (endPage < totalPages) {
      if (endPage < totalPages - 1) pagHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
      pagHtml += `<li class="page-item"><a class="page-link" href="#" data-page="${totalPages}">${toPersianDigits(totalPages)}</a></li>`;
    }
    pagHtml += `<li class="page-item ${page === totalPages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${page + 1}">بعدی</a></li>`;
  }
  $('#kelasehPagination').html(pagHtml);
}

function refreshKelasehToday() {
  api('kelaseh.list.today', {})
    .done((res) => {
      const rows = (res.data && res.data.kelaseh) || [];
      const html = generateKelasehRows(rows);
      $('#kelasehTodayTbody').html(html || `<tr><td colspan="11" class="text-center text-secondary py-3">ثبتی برای امروز وجود ندارد.</td></tr>`);
    })
    .fail(() => {});
}

function refreshKelaseh(page = 1) {
  kelasehCurrentPage = page;
  const national_code = $('#kelasehNational').val() || '';
  const from = $('#kelasehFrom').val() || '';
  const to = $('#kelasehTo').val() || '';
  const owner_id = $('#kelasehOwnerFilter').val() || 0;
  const city_code = (currentUser && currentUser.role === 'admin') ? $('#adminKelasehCityFilterMain').val() : null;

  return api('kelaseh.list', { national_code, from, to, page, limit: kelasehPageSize, owner_id, city_code })
    .done((res) => {
      renderKelaseh(res);
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
      const admins = users.filter((u) => u.role === 'admin');
      const officeAdmins = users.filter((u) => u.role === 'office_admin');
      const branchAdmins = users.filter((u) => u.role === 'branch_admin');
      const others = users.filter((u) => u.role !== 'admin' && u.role !== 'office_admin' && u.role !== 'branch_admin');

      const renderUserRow = (u, opts) => {
        const username = $('<div/>').text(toPersianDigits(u.username || '')).html();
        const email = $('<div/>').text(toPersianDigits(u.email || '')).html();
        const name = $('<div/>').text(toPersianDigits(u.display_name || `${u.first_name || ''} ${u.last_name || ''}`.trim())).html();
        const mobile = $('<div/>').text(toPersianDigits(u.mobile || '')).html();
        const city = $('<div/>').text(toPersianDigits(u.city_name || u.city_code || '')).html();
        const branchesText = (u.branches || '').toString();
        const branchInfoText = branchesText
          ? `شعبه‌ها: ${toPersianDigits(branchesText.split(',').map((x) => String(x).padStart(2, '0')).join(', '))}`
          : ((u.branch_start_no && u.branch_count)
            ? `محدوده: ${toPersianDigits(String(u.branch_start_no).padStart(2, '0'))} تا ${toPersianDigits(String(u.branch_start_no + u.branch_count - 1).padStart(2, '0'))}`
            : (u.role === 'branch_admin' ? (toPersianDigits(u.branch_count || 1) + ' شعبه') : '-'));

        const role = u.role === 'admin' ? 'مدیر کل' : u.role === 'office_admin' ? 'مدیر اداره' : u.role === 'branch_admin' ? 'مدیر شعبه' : 'کاربر عادی';
        const isActive = Number(u.is_active) === 1 ? 'فعال' : 'غیرفعال';
        const activeClass = Number(u.is_active) === 1 ? 'text-success' : 'text-danger';
        const json = encodeURIComponent(JSON.stringify(u));

        const indent = opts && opts.indent ? 'ps-4' : '';
        const rowClass = opts && opts.rowClass ? opts.rowClass : '';
        const metaParts = [mobile].filter(Boolean);
        const meta = metaParts.length ? `<div class="text-secondary small">${metaParts.join(' | ')}</div>` : '';

        const createBranchBtn = u.role === 'office_admin' ? `<button class="btn btn-outline-success btn-sm btn-admin-create-branch-under-office" type="button">ایجاد مدیر شعبه</button>` : '';

        return `
          <tr data-id="${u.id}" data-json="${json}" class="${rowClass}">
            <td>
              <div class="${indent}">
                <div class="fw-semibold">${username || email}</div>
                ${name ? `<div class="text-secondary small">${name}</div>` : ''}
                ${meta}
              </div>
            </td>
            <td class="text-secondary">${u.role === 'admin' ? '-' : city}</td>
            <td>${role}</td>
            <td><span class="${activeClass}">${isActive}</span></td>
            <td class="text-secondary">${branchInfoText}</td>
            <td class="text-end">
              <button class="btn btn-outline-primary btn-sm btn-admin-edit-user" type="button">ویرایش</button>
              ${createBranchBtn}
              <button class="btn btn-outline-danger btn-sm btn-admin-delete-user" type="button">حذف</button>
            </td>
          </tr>
        `;
      };

      const rows = [];

      admins
        .slice()
        .sort((a, b) => Number(b.id || 0) - Number(a.id || 0))
        .forEach((a) => rows.push(renderUserRow(a, { rowClass: 'table-primary' })));

      const cityGroups = {};
      const getKey = (u) => String(u.city_code || '');
      officeAdmins.forEach((u) => {
        const key = getKey(u);
        if (!cityGroups[key]) cityGroups[key] = { city_code: key, city_name: u.city_name || '', office: [], branch: [] };
        cityGroups[key].office.push(u);
      });
      branchAdmins.forEach((u) => {
        const key = getKey(u);
        if (!cityGroups[key]) cityGroups[key] = { city_code: key, city_name: u.city_name || '', office: [], branch: [] };
        if (!cityGroups[key].city_name && u.city_name) cityGroups[key].city_name = u.city_name;
        cityGroups[key].branch.push(u);
      });

      Object.values(cityGroups)
        .sort((a, b) => {
          const an = String(a.city_name || '');
          const bn = String(b.city_name || '');
          if (an && bn) return an.localeCompare(bn);
          return String(a.city_code || '').localeCompare(String(b.city_code || ''));
        })
        .forEach((g) => {
          const cityLabel = $('<div/>').text(toPersianDigits(g.city_name || g.city_code || '')).html();
          rows.push(`<tr class="table-secondary"><td colspan="6" class="fw-semibold">اداره ${cityLabel || '—'}</td></tr>`);
          g.office
            .slice()
            .sort((a, b) => Number(b.id || 0) - Number(a.id || 0))
            .forEach((oa) => rows.push(renderUserRow(oa, { rowClass: 'table-light' })));
          g.branch
            .slice()
            .sort((a, b) => String(a.username || '').localeCompare(String(b.username || '')))
            .forEach((ba) => rows.push(renderUserRow(ba, { indent: true }))); 
        });

      others.forEach((u) => rows.push(renderUserRow(u, {})));

      $('#adminUsersTbody').html(rows.join('') || `<tr><td colspan="6" class="text-center text-secondary py-3">کاربری یافت نشد.</td></tr>`);
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
        admin_city_create: 'افزودن اداره',
        admin_city_update: 'ویرایش اداره',
        admin_city_delete: 'حذف اداره',
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
        isfahan_city: 'اداره',
        app_settings: 'تنظیمات',
      };

      const rows = logs.map((l) => {
        const dt = $('<div/>').text(toPersianDigits(l.created_at_jalali || l.created_at || '')).html();
        const actor = $('<div/>').text(toPersianDigits(l.actor_key || l.actor_id || '')).html();
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
      $('#adminStatsTotal').text(toPersianDigits(totals.total || 0));
      $('#adminStatsActive').text(toPersianDigits(totals.active || 0));
      $('#adminStatsInactive').text(toPersianDigits(totals.inactive || 0));
      $('#adminStatsVoided').text(toPersianDigits(totals.voided || 0));

      const cities = (res.data && res.data.cities) || [];
      const cityRows = cities.map((c) => {
        const name = $('<div/>').text(toPersianDigits(c.city_name || c.city_code || '')).html();
        return `<tr><td>${name}</td><td>${toPersianDigits(c.total || 0)}</td><td>${toPersianDigits(c.active || 0)}</td><td>${toPersianDigits(c.inactive || 0)}</td><td>${toPersianDigits(c.voided || 0)}</td></tr>`;
      });
      $('#adminStatsCitiesTbody').html(cityRows.join('') || `<tr><td colspan="5" class="text-center text-secondary py-3">داده‌ای یافت نشد.</td></tr>`);

      const users = (res.data && res.data.users) || [];
      const userRows = users.map((u) => {
        const uname = $('<div/>').text(toPersianDigits(u.display_name || u.username || '')).html();
        const city = $('<div/>').text(toPersianDigits(u.city_name || u.city_code || '')).html();
        return `<tr><td>${uname}</td><td class="text-secondary">${city}</td><td>${toPersianDigits(u.total || 0)}</td><td>${toPersianDigits(u.active || 0)}</td><td>${toPersianDigits(u.inactive || 0)}</td><td>${toPersianDigits(u.voided || 0)}</td></tr>`;
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
      $('#adminSmsOtpEnabled').prop('checked', Number(s.otp_enabled) === 1);
      $('#adminSmsOtpSettings').toggleClass('d-none', Number(s.otp_enabled) !== 1);
      $('#adminSmsTplOtp').val(s.tpl_otp || '');
      $('#adminSmsOtpLen').val(s.otp_len || 6);
      $('#adminSmsOtpTtl').val(s.otp_ttl || 5);
      $('#adminSmsOtpMaxTries').val(s.otp_max_tries || 5);
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
      } else {
        renderUser();
        setView('app');
        initDatePickers();
        if (!window.location.hash) {
          window.location.hash = '#dashboard';
        }
        renderPage(getPageFromHash());
        startHeaderClock();
        refreshKelaseh();
        refreshKelasehToday();
        if (currentUser.role === 'admin') {
          loadAdminCities();
          refreshAdminUsers();
          refreshAdminCities();
          refreshAdminItems();
          refreshAdminLogs();
          refreshAdminStats();
          refreshAdminSmsSettings();
        } else if (currentUser.role === 'office_admin') {
          refreshOfficeUsers();
          refreshOfficeKelasehSearch();
          refreshOfficeCapacities();
        }
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
  const formEl = this;
  const btn = $(formEl).find('#btnLoginSubmit');
  const data = Object.fromEntries(new FormData(formEl));

  btn.prop('disabled', true).find('.btn-text').addClass('d-none');
  btn.find('.spinner-border').removeClass('d-none');

  api('login', { login: data.login, password: data.password })
    .done((res) => {
      csrfToken = (res.data && res.data.csrf_token) || csrfToken;
      if (res.data && Number(res.data.otp_required) === 1) {
        $('#loginOtpSection').removeClass('d-none');
        $('#btnLoginOtpVerify').removeClass('d-none');
        $('#btnLoginSubmit').addClass('d-none');
        $('#loginOtpInput').val('').trigger('focus');
        const hint = res.data.mobile_hint ? `کد به ${toPersianDigits(res.data.mobile_hint)} ارسال شد.` : 'کد تأیید ارسال شد.';
        $('#loginOtpHint').text(hint);
        $(formEl).find('[name="login"]').prop('disabled', true);
        $(formEl).find('[name="password"]').prop('disabled', true);
        showToast(res.message || 'کد تأیید ارسال شد.', 'success');
        return;
      }

      currentUser = (res.data && res.data.user) || null;
      showToast(res.message || 'ورود انجام شد.', 'success');
      renderUser();
      setView('app');
      initDatePickers();
      if (!window.location.hash) {
        window.location.hash = '#dashboard';
      }
      renderPage(getPageFromHash());
      startHeaderClock();
      refreshKelaseh();
      refreshKelasehToday();
      if (currentUser && currentUser.role === 'admin') {
        loadAdminCities();
        refreshAdminUsers();
        refreshAdminCities();
        refreshAdminItems();
        refreshAdminLogs();
        refreshAdminStats();
        refreshAdminSmsSettings();
      } else if (currentUser && currentUser.role === 'office_admin') {
        refreshOfficeUsers();
        refreshOfficeKelasehSearch();
        refreshOfficeCapacities();
      }
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'ورود ناموفق بود.';
      showToast(msg, 'error');
    })
    .always(() => {
      btn.prop('disabled', false).find('.btn-text').removeClass('d-none');
      btn.find('.spinner-border').addClass('d-none');
    });
});

$(document).on('click', '#btnLoginOtpVerify', function () {
  const btn = $(this);
  const otp = $('#loginOtpInput').val() || '';

  btn.prop('disabled', true);
  
  api('login.otp.verify', { otp })
    .done((res) => {
      csrfToken = (res.data && res.data.csrf_token) || csrfToken;
      currentUser = (res.data && res.data.user) || null;
      $('#loginOtpSection').addClass('d-none');
      $('#btnLoginOtpVerify').addClass('d-none');
      $('#btnLoginSubmit').removeClass('d-none');
      const form = $('#formLogin');
      form.find('[name="login"]').prop('disabled', false);
      form.find('[name="password"]').prop('disabled', false);
      showToast(res.message || 'ورود انجام شد.', 'success');
      renderUser();
      setView('app');
      initDatePickers();
      if (!window.location.hash) {
        window.location.hash = '#dashboard';
      }
      renderPage(getPageFromHash());
      startHeaderClock();
      refreshKelaseh();
      refreshKelasehToday();
      if (currentUser && currentUser.role === 'admin') {
        loadAdminCities();
        refreshAdminUsers();
        refreshAdminCities();
        refreshAdminItems();
        refreshAdminLogs();
        refreshAdminStats();
        refreshAdminSmsSettings();
      } else if (currentUser && currentUser.role === 'office_admin') {
        refreshOfficeUsers();
        refreshOfficeKelasehSearch();
        refreshOfficeCapacities();
      }
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'تأیید کد ناموفق بود.';
      showToast(msg, 'error');
      btn.prop('disabled', false);
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

$(document).on('input', '#kelasehNational', function () {
  const from = $('#kelasehFrom').val() || '';
  const to = $('#kelasehTo').val() || '';
  // If date filters are empty, search immediately
  if (from === '' && to === '') {
    refreshKelaseh();
  }
});

$(document).on('change', '#officeCreateManualBranches', function () {
  const manual = $('#officeCreateManualBranches').is(':checked');
  $('#officeCreateBranchListWrap').toggleClass('d-none', !manual);
  $('#officeCreateBranchRangeWrap').toggleClass('d-none', manual);
});

function renderKelasehHistoryRows(items, lookupNC) {
  if (!items || !items.length) {
    return `<tr><td colspan="4" class="text-center text-muted">سابقه‌ای یافت نشد.</td></tr>`;
  }
  return items
    .map((item) => {
      const roleInRecord = (String(item.plaintiff_national_code) === String(lookupNC)) ? 'plaintiff' : 'defendant';
      const badgeClass = roleInRecord === 'plaintiff' ? 'bg-info' : 'bg-warning text-dark';
      const code = $('<div/>').text(toPersianDigits(item.code || '')).html();
      const city = $('<div/>').text(toPersianDigits(item.city_name || item.city_code || '')).html();
      const date = $('<div/>').text(toPersianDigits(item.created_at_jalali || '')).html();
      const oppositeRaw = roleInRecord === 'plaintiff' ? (item.defendant_name || '') : (item.plaintiff_name || '');
      const oppositeLabel = roleInRecord === 'plaintiff' ? 'خوانده' : 'خواهان';
      const opposite = $('<div/>').text(toPersianDigits(oppositeRaw ? `${oppositeRaw} (${oppositeLabel})` : '')).html();
      return `<tr><td><span class="badge ${badgeClass}" dir="ltr">${code}</span></td><td>${city}</td><td>${date}</td><td>${opposite}</td></tr>`;
    })
    .join('');
}

function refreshKelasehHistoryFor(nationalCode, role) {
  const val = String(nationalCode || '');
  const target = role === 'plaintiff' ? '#historyPlaintiffTbody' : '#historyDefendantTbody';

  if (!val || val.length < 10) {
    $(target).empty();
    toggleHistorySection();
    return;
  }

  api('kelaseh.history.check', { national_code: val })
    .done((res) => {
      const pList = (res.data && res.data.plaintiff) || [];
      const dList = (res.data && res.data.defendant) || [];
      const allCases = [...pList, ...dList];
      // Sort by ID descending (most recent first)
      allCases.sort((a, b) => (b.id || 0) - (a.id || 0));
      
      $(target).html(renderKelasehHistoryRows(allCases, val));
      toggleHistorySection();
    })
    .fail(() => {
      $(target).empty();
      toggleHistorySection();
    });
}

function toggleHistorySection() {
  const pHas = $('#historyPlaintiffTbody').children().length > 0;
  const dHas = $('#historyDefendantTbody').children().length > 0;
  
  if (pHas || dHas) {
    $('#historyCheckSection').removeClass('d-none');
  } else {
    $('#historyCheckSection').addClass('d-none');
  }
}

$(document).on('change', '.national-check', function() {
  const role = $(this).attr('name') === 'plaintiff_national_code' ? 'plaintiff' : 'defendant';
  refreshKelasehHistoryFor($(this).val(), role);
});

$(document).on('input', '.national-check', function() {
    const val = $(this).val();
    if (val && val.length === 10) {
        $(this).trigger('change');
    }
});

$(document).on('submit', '#formKelasehCreate', function (e) {
  e.preventDefault();
  const submitter = (e.originalEvent && e.originalEvent.submitter) || null;
  const shouldSendSms = submitter && submitter.id === 'btnKelasehCreateAndSms';
  const to_plaintiff = $('#kelasehSmsPlaintiff').is(':checked') ? 1 : 0;
  const to_defendant = $('#kelasehSmsDefendant').is(':checked') ? 1 : 0;
  const data = Object.fromEntries(new FormData(this));
  showToast('در حال ثبت پرونده…', 'info');
  api('kelaseh.create', data)
    .done((res) => {
      const code = (res.data && res.data.code) || '';
      const notices = (res.data && res.data.notices) || [];
      if (Array.isArray(notices) && notices.length) {
        notices.forEach((m) => showToast(m, 'success'));
      }
      showToast(code ? `شناسه پرونده ایجاد شد: ${code}` : (res.message || 'ثبت شد.'), 'success');
      this.reset();
      $('#historyPlaintiffTbody').empty();
      $('#historyDefendantTbody').empty();
      toggleHistorySection();
      refreshKelaseh();
      refreshKelasehToday();
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
  
  const $form = $('<form>', {
    action: 'core.php',
    method: 'POST',
    target: '_self'
  }).append(
    $('<input>', { type: 'hidden', name: 'action', value: 'kelaseh.export.csv' }),
    $('<input>', { type: 'hidden', name: 'csrf_token', value: csrfToken }),
    $('<input>', { type: 'hidden', name: 'national_code', value: national_code }),
    $('<input>', { type: 'hidden', name: 'from', value: from }),
    $('<input>', { type: 'hidden', name: 'to', value: to })
  );
  
  $('body').append($form);
  $form.submit().remove();
});

$(document).on('click', '#btnKelasehExportPdf', function () {
  const national_code = $('#kelasehNational').val() || '';
  const from = $('#kelasehFrom').val() || '';
  const to = $('#kelasehTo').val() || '';
  
  const $form = $('<form>', {
    action: 'core.php',
    method: 'POST',
    target: '_blank'
  }).append(
    $('<input>', { type: 'hidden', name: 'action', value: 'kelaseh.export.print' }),
    $('<input>', { type: 'hidden', name: 'csrf_token', value: csrfToken }),
    $('<input>', { type: 'hidden', name: 'national_code', value: national_code }),
    $('<input>', { type: 'hidden', name: 'from', value: from }),
    $('<input>', { type: 'hidden', name: 'to', value: to })
  );
  
  $('body').append($form);
  $form.submit().remove();
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

$(document).on('click', '.btn-kelaseh-label', function () {
  const tr = $(this).closest('tr');
  const raw = tr.attr('data-json');
  if (!raw) {
    return;
  }
  const payload = JSON.parse(decodeURIComponent(raw));
  const code = payload.code;
  window.open(`core.php?action=kelaseh.label&code=${encodeURIComponent(code)}`, '_blank');
});

$(document).on('click', '#kelasehTodayTbody .btn-kelaseh-edit', function () {
    const tr = $(this).closest('tr');
    const raw = tr.attr('data-json');
    if (!raw) return;
    const payload = JSON.parse(decodeURIComponent(raw));
    
    // Fill the edit form
    $('#formKelasehEdit [name=code]').val(payload.code);
    $('#formKelasehEdit [name=plaintiff_name]').val(payload.plaintiff_name);
    $('#formKelasehEdit [name=defendant_name]').val(payload.defendant_name);
    $('#formKelasehEdit [name=plaintiff_national_code]').val(payload.plaintiff_national_code);
    $('#formKelasehEdit [name=defendant_national_code]').val(payload.defendant_national_code);
    $('#formKelasehEdit [name=plaintiff_mobile]').val(payload.plaintiff_mobile);
    $('#formKelasehEdit [name=defendant_mobile]').val(payload.defendant_mobile);
    
    new bootstrap.Modal(document.getElementById('modalKelasehEdit')).show();
});

$(document).on('click', '#kelasehTodayTbody .btn-kelaseh-void', function () {
    const tr = $(this).closest('tr');
    const raw = tr.attr('data-json');
    if (!raw) return;
    const payload = JSON.parse(decodeURIComponent(raw));
    const code = payload.code;
    
    if (!confirm('این پرونده ابطال شود؟')) return;
    
    api('kelaseh.set_status', { code, status: 'voided' })
        .done((res) => {
            showToast(res.message || 'ابطال شد.', 'success');
            refreshKelasehToday();
            refreshKelaseh();
        })
        .fail((xhr) => {
            showToast((xhr.responseJSON && xhr.responseJSON.message) || 'ابطال ناموفق بود.', 'error');
        });
});

$(document).on('click', '#kelasehTodayTbody .btn-kelaseh-toggle', function () {
    const tr = $(this).closest('tr');
    const raw = tr.attr('data-json');
    if (!raw) return;
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
            refreshKelasehToday();
            refreshKelaseh();
        })
        .fail((xhr) => {
            showToast((xhr.responseJSON && xhr.responseJSON.message) || 'عملیات ناموفق بود.', 'error');
        });
});
$(document).on('click', '#kelasehPagination .page-link', function (e) {
    e.preventDefault();
    const page = $(this).data('page');
    if (page) refreshKelaseh(page);
});

$(document).on('click', '#btnKelasehSelectAll', function () {
    const checks = $('#kelasehTbody .kelaseh-label-check');
    const allChecked = checks.length > 0 && checks.length === checks.filter(':checked').length;
    checks.prop('checked', !allChecked);
});

$(document).on('change', '#adminKelasehCityFilter', function() {
    loadBranchManagers();
});

$(document).on('change', '#adminKelasehCityFilterMain', function() {
    loadBranchManagers();
    refreshKelaseh(1);
});

$(document).on('change', '#kelasehOwnerFilter', function() {
    refreshKelaseh(1);
});

$(document).on('change', '#kelasehTbody .kelaseh-label-check', function () {
    // Just toggle check, do nothing immediate
});

$(document).on('click', '#btnKelasehPrintLabels', function () {
  const codes = [];
  $('#kelasehTbody .kelaseh-label-check:checked').each(function () {
    const tr = $(this).closest('tr');
    const raw = tr.attr('data-json');
    if (raw) {
      const payload = JSON.parse(decodeURIComponent(raw));
      if (payload.code) codes.push(payload.code);
    }
  });

  if (codes.length === 0) {
    showToast('هیچ پرونده‌ای انتخاب نشده است.', 'error');
    return;
  }
  window.open(`core.php?action=kelaseh.label&codes=${codes.join(',')}`, '_blank');
});

$(document).on('click', '#btnKelasehTodaySelectAll', function () {
    const checks = $('#kelasehTodayTbody .kelaseh-label-check');
    const allChecked = checks.length > 0 && checks.length === checks.filter(':checked').length;
    checks.prop('checked', !allChecked);
});

$(document).on('click', '#btnKelasehTodayPrintAllLabels', function () {
  const codes = [];
  
  // First check if any checkbox is checked
  const checked = $('#kelasehTodayTbody .kelaseh-label-check:checked');
  if (checked.length > 0) {
    checked.each(function () {
        const tr = $(this).closest('tr');
        const raw = tr.attr('data-json');
        if (raw) {
          const payload = JSON.parse(decodeURIComponent(raw));
          if (payload.code) codes.push(payload.code);
        }
    });
  } else {
    // Fallback: collect all rows if nothing selected
    $('#kelasehTodayTbody tr').each(function () {
        const raw = $(this).attr('data-json');
        if (raw) {
          const payload = JSON.parse(decodeURIComponent(raw));
          if (payload.code) codes.push(payload.code);
        }
    });
  }

  if (codes.length === 0) {
    showToast('پرونده‌ای برای چاپ وجود ندارد.', 'error');
    return;
  }
  window.open(`core.php?action=kelaseh.label&codes=${codes.join(',')}`, '_blank');
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
      refreshKelasehToday();
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
      refreshKelasehToday();
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
      refreshKelasehToday();
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'ابطال ناموفق بود.';
      showToast(msg, 'error');
    });
});

function updateAdminCreateUserFields() {
  const role = $('#adminRoleSelect').val();
  const branchWrap = $('#adminBranchMultiWrap');
  const officeWrap = $('#adminOfficeBranchWrap');
  const userWrap = $('#adminUserBranchesWrap');
  const citySelect = $('#adminCitySelect');

  branchWrap.addClass('d-none');
  officeWrap.addClass('d-none');
  userWrap.addClass('d-none');
  
  // Admin doesn't need city, others do
  if (role === 'admin') {
      citySelect.prop('required', false).closest('div').addClass('d-none');
  } else {
      citySelect.prop('required', true).closest('div').removeClass('d-none');
  }

  if (role === 'branch_admin') {
    branchWrap.removeClass('d-none');
    if ($('#adminCreateBranchList').is(':empty')) {
      let html = '';
      for (let i = 1; i <= 15; i++) {
        html += `
        <div class="col-6 col-md-4 col-lg-3">
            <div class="border rounded p-2 h-100">
                <div class="form-check mb-1">
                    <input class="form-check-input branch-check" type="checkbox" name="branches_check[]" value="${i}" id="create_br_${i}">
                    <label class="form-check-label small" for="create_br_${i}">شعبه ${i}</label>
                </div>
                <input type="number" class="form-control form-control-sm branch-capacity" data-branch="${i}" value="15" min="1" max="999" placeholder="ظرفیت" disabled>
            </div>
        </div>`;
      }
      $('#adminCreateBranchList').html(html);
    }
  } else if (role === 'office_admin') {
    officeWrap.removeClass('d-none');
  } else if (role === 'user') {
    userWrap.removeClass('d-none');
  }
}

$(document).on('change', '#adminRoleSelect', updateAdminCreateUserFields);

$(document).on('change', '#adminCreateBranchList .branch-check', function () {
  const capInput = $(this).closest('div.border').find('.branch-capacity');
  capInput.prop('disabled', !this.checked);
});

$(document).on('submit', '#formAdminCreateUser', function (e) {
  e.preventDefault();
  const form = $(this);
  const data = {
    first_name: form.find('[name="first_name"]').val(),
    last_name: form.find('[name="last_name"]').val(),
    username: form.find('[name="username"]').val(),
    mobile: form.find('[name="mobile"]').val(),
    password: form.find('[name="password"]').val(),
    city_code: form.find('[name="city_code"]').val(),
    role: form.find('[name="role"]').val(),
    branch_count: form.find('[name="branch_count"]').val(),
    branch_start_no: form.find('[name="branch_start_no"]').val(),
    branch_no: form.find('[name="branch_no"]').val(),
  };

  if (data.role === 'branch_admin') {
    const branches = [];
    const branchCaps = {};
    $('#adminCreateBranchList .branch-check:checked').each(function () {
      const b = $(this).val();
      const cap = $(this).closest('div.border').find('.branch-capacity').val();
      branches.push(b);
      branchCaps[b] = cap;
    });
    data.branches = branches;
    data.branch_caps = branchCaps;
  }

  api('admin.users.create', data)
    .done((res) => {
      showToast(res.message || 'کاربر ایجاد شد.', 'success');
      form[0].reset();
      $('#adminCreateBranchList input[type="checkbox"]').prop('checked', false).trigger('change');
      refreshAdminUsers();
      updateAdminCreateUserFields();
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'ایجاد کاربر ناموفق بود.';
      showToast(msg, 'error');
    });
});

$(document).on('click', '#btnAdminUsersRefresh', function () {
  refreshAdminUsers();
});

$(document).on('click', '#adminUsersTbody .btn-admin-create-branch-under-office', function () {
  const raw = $(this).closest('tr').attr('data-json');
  if (!raw) return;
  const u = JSON.parse(decodeURIComponent(raw));
  const cityCode = u.city_code || '';
  if (!cityCode) {
    showToast('اداره مدیر اداره مشخص نیست.', 'error');
    return;
  }

  $('#adminRoleSelect').val('branch_admin').trigger('change');
  $('#adminCitySelect').val(String(cityCode)).trigger('change');
  document.getElementById('formAdminCreateUser')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  setTimeout(() => {
    $('#formAdminCreateUser [name="username"]').trigger('focus');
  }, 250);
});

$(document).on('click', '#btnAdminRunBranchAdminTest', function () {
  const ok = window.confirm('این تست یک کاربر و ۳۰ پرونده تستی ایجاد می‌کند و در پایان پاکسازی می‌کند. ادامه می‌دهید؟');
  if (!ok) return;

  $('#adminTestDownloadLink').addClass('d-none').attr('href', '#');
  $('#adminTestResult').text('در حال اجرای تست…');
  api('admin.test.branch_admin_flow.run', {})
    .done((res) => {
      const d = (res.data || {});
      const counts = d.branch_counts || {};
      const txt = `نتیجه: OK | شعبه ۱: ${toPersianDigits(counts[1] || 0)} | شعبه ۲: ${toPersianDigits(counts[2] || 0)} | شعبه ۳: ${toPersianDigits(counts[3] || 0)} | لینک دانلود تا ${toPersianDigits(d.expires_in || 0)} ثانیه معتبر است.`;
      $('#adminTestResult').text(txt);
      if (d.download_url) {
        $('#adminTestDownloadLink').removeClass('d-none').attr('href', d.download_url);
      }
      showToast(res.message || 'تست انجام شد.', 'success');
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'خطا در اجرای تست.';
      $('#adminTestResult').text(msg);
      showToast(msg, 'error');
    });
});

$(document).on('click', '#btnAdminCitiesRefresh', function () {
  refreshAdminCities();
});

$(document).on('submit', '#formAdminCityCreate', function (e) {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(this));
  api('admin.cities.create', data)
    .done((res) => {
      showToast(res.message || 'اداره ایجاد شد.', 'success');
      this.reset();
      adminCitiesLoaded = false;
      $.when(loadAdminCities(), refreshAdminCities()).done(() => {});
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'افزودن اداره ناموفق بود.';
      showToast(msg, 'error');
    });
});

$(document).on('click', '#adminCitiesTbody .btn-city-save', function () {
  const tr = $(this).closest('tr');
  const code = tr.data('code'); // Original code
  const newCode = tr.find('.city-code').val();
  const name = tr.find('.city-name').val();
  api('admin.cities.update', { code, new_code: newCode, name })
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
  if (!confirm('این اداره حذف شود؟')) {
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
  const otp_enabled = $('#adminSmsOtpEnabled').is(':checked') ? 1 : 0;
  const tpl_otp = $('#adminSmsTplOtp').val() || '';
  const otp_len = $('#adminSmsOtpLen').val() || '';
  const otp_ttl = $('#adminSmsOtpTtl').val() || '';
  const otp_max_tries = $('#adminSmsOtpMaxTries').val() || '';
  const api_key = $('#adminSmsApiKey').val() || '';
  const sender = $('#adminSmsSender').val() || '';
  const tpl_plaintiff = $('#adminSmsTplPlaintiff').val() || '';
  const tpl_defendant = $('#adminSmsTplDefendant').val() || '';

  api('admin.sms.settings.set', { enabled, otp_enabled, tpl_otp, otp_len, otp_ttl, otp_max_tries, api_key, sender, tpl_plaintiff, tpl_defendant })
    .done((res) => {
      showToast(res.message || 'ذخیره شد.', 'success');
      refreshAdminSmsSettings();
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'ذخیره تنظیمات پیامک ناموفق بود.';
      showToast(msg, 'error');
    });
});

$(document).on('change', '#adminSmsOtpEnabled', function () {
  const enabled = $('#adminSmsOtpEnabled').is(':checked');
  $('#adminSmsOtpSettings').toggleClass('d-none', !enabled);
});

/* ADMIN USER EDIT & DELETE LOGIC */

function updateAdminEditUserFields() {
  const role = $('#adminEditRoleSelect').val();
  const branchWrap = $('#adminEditBranchWrap');
  const officeWrap = $('#adminEditOfficeWrap');

  branchWrap.addClass('d-none');
  officeWrap.addClass('d-none');

  if (role === 'branch_admin') {
    branchWrap.removeClass('d-none');
  } else if (role === 'office_admin') {
    officeWrap.removeClass('d-none');
  }
}

$(document).on('change', '#adminEditRoleSelect', updateAdminEditUserFields);

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

    const form = $('#formAdminEditUser');
    form.find('[name="id"]').val(u.id);
    form.find('[name="first_name"]').val(u.first_name || '');
    form.find('[name="last_name"]').val(u.last_name || '');
    form.find('[name="mobile"]').val(u.mobile || '');
    form.find('[name="email"]').val(u.email || '');
    form.find('[name="password"]').val('');
    form.find('[name="role"]').val(u.role || 'user');
    if (currentUser && currentUser.role === 'office_admin') {
        form.find('[name="role"]').prop('disabled', true);
    } else {
        form.find('[name="role"]').prop('disabled', false);
    }
    form.find('[name="is_active"]').val(Number(u.is_active) === 1 ? '1' : '0');
    form.find('[name="branch_count"]').val(u.branch_count || 1);

    // Branch checkboxes
    let html = '';
    const userBranches = Array.isArray(u.branches)
      ? u.branches.map((x) => Number(x))
      : (typeof u.branches === 'string'
        ? u.branches.split(',').map((x) => Number(String(x).trim())).filter((n) => Number.isFinite(n) && n > 0)
        : []);
    
    const branchCaps = u.branch_capacities || {};
    
    for (let i = 1; i <= 15; i++) {
        const isChecked = userBranches.includes(i) ? 'checked' : '';
        const cap = branchCaps[i] || 15;
        
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
        branch_count: form.find('[name="branch_count"]').val()
    };
    
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
            else refreshOfficeUsers(); // assuming office user refresh function exists or fallback
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
            else refreshOfficeUsers(); // assuming office user refresh function exists
        })
        .fail(xhr => {
             showToast((xhr.responseJSON && xhr.responseJSON.message) || 'خطا در حذف.', 'error');
        });
});

function refreshAdminDetailedStats() {
  return api('admin.detailed.stats', {})
    .done((res) => {
      const stats = (res.data && res.data.stats) || [];
      const rows = stats.map((s) => {
        const city = $('<div/>').text(toPersianDigits(s.city_name || '')).html();
        const role = s.role === 'office_admin' ? 'مدیر اداره' : (s.role === 'branch_admin' ? 'مدیر شعبه' : s.role);
        const name = $('<div/>').text(toPersianDigits(s.display_name || s.username || '')).html();
        const total = toPersianDigits(s.total || 0);
        return `<tr><td>${city}</td><td>${role}</td><td>${name}</td><td>${total}</td></tr>`;
      });
      $('#adminDetailedStatsTbody').html(rows.join('') || `<tr><td colspan="4" class="text-center text-secondary py-3">داده‌ای یافت نشد.</td></tr>`);
    })
    .fail((xhr) => {
      showToast('خطا در دریافت آمار تفکیکی.', 'error');
    });
}

$(document).on('click', '#btnAdminDetailedStatsRefresh', function () {
  refreshAdminDetailedStats();
});

function refreshAdminKelasehSearch() {
  const q = $('#adminKelasehSearchQuery').val() || '';
  const city_code = $('#adminKelasehCityFilter').val() || '';
  if (!q.trim()) {
    showToast('لطفاً عبارتی برای جستجو وارد کنید.', 'error');
    return;
  }
  api('admin.kelaseh.search', { q, city_code })
    .done((res) => {
      const rows = (res.data && res.data.results) || [];
      const html = rows.map((r) => {
        // Use full_code if available
        const rawCode = r.full_code || r.code || '';
        const code = $('<div/>').text(toPersianDigits(rawCode)).html();
        const owner = $('<div/>').text(toPersianDigits((r.city_name ? r.city_name + ' / ' : '') + (r.owner_name || ''))).html();
        const plaintiff = $('<div/>').text(toPersianDigits(r.plaintiff_name || '')).html();
        const plaintiffNC = $('<div/>').text(toPersianDigits(r.plaintiff_national_code || '')).html();
        const defendant = $('<div/>').text(toPersianDigits(r.defendant_name || '')).html();
        const date = $('<div/>').text(toPersianDigits(r.created_at_jalali || '')).html();
        
        return `<tr><td dir="ltr" class="text-end fw-bold">${code}</td><td>${owner}</td><td>${plaintiff}</td><td>${plaintiffNC}</td><td>${defendant}</td><td>${date}</td></tr>`;
      }).join('');
      $('#adminKelasehSearchTbody').html(html || `<tr><td colspan="6" class="text-center text-secondary py-3">موردی یافت نشد.</td></tr>`);
    })
    .fail((xhr) => {
       const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'خطا در جستجو.';
       showToast(msg, 'error');
    });
}

$(document).on('click', '#btnAdminKelasehSearch', function () {
  refreshAdminKelasehSearch();
});

  const modalEl = document.getElementById('modalOfficeCreateUser');
  if (modalEl) {
    modalEl.addEventListener('shown.bs.modal', function () {
        $('#officeCreateManualBranches').prop('checked', true).trigger('change');
        if ($('#officeCreateBranchList').is(':empty')) {
          let html = '';
          for (let i = 1; i <= 15; i++) {
            html += `
            <div class="col-6 col-md-4 col-lg-3">
                <div class="border rounded p-2 h-100">
                    <div class="form-check mb-1">
                        <input class="form-check-input branch-check" type="checkbox" name="branches_check[]" value="${i}" id="off_create_br_${i}">
                        <label class="form-check-label small" for="off_create_br_${i}">شعبه ${i}</label>
                    </div>
                    <input type="number" class="form-control form-control-sm branch-capacity" data-branch="${i}" value="15" min="1" max="999" placeholder="ظرفیت" disabled>
                </div>
            </div>`;
          }
          $('#officeCreateBranchList').html(html);
        }
    });
  }

  $(document).on('change', '#officeCreateBranchList .branch-check', function () {
    const capInput = $(this).closest('div.border').find('.branch-capacity');
    capInput.prop('disabled', !this.checked);
  });

  $(document).on('submit', '#formOfficeCreateUser', function (e) {
    e.preventDefault();
    const form = $(this);
    const data = {
        role: 'branch_admin',
        first_name: form.find('[name="first_name"]').val(),
        last_name: form.find('[name="last_name"]').val(),
        username: form.find('[name="username"]').val(),
        mobile: form.find('[name="mobile"]').val(),
        password: form.find('[name="password"]').val(),
    };

    const manual = $('#officeCreateManualBranches').is(':checked');
    if (manual) {
      const branches = [];
      const branchCaps = {};
      $('#officeCreateBranchList .branch-check:checked').each(function () {
        const b = $(this).val();
        const cap = $(this).closest('div.border').find('.branch-capacity').val();
        branches.push(b);
        branchCaps[b] = cap;
      });
      if (!branches.length) {
        showToast('حداقل یک شعبه را انتخاب کنید.', 'error');
        return;
      }
      data.branches = branches;
      data.branch_caps = branchCaps;
    } else {
      data.branch_start_no = form.find('[name="branch_start_no"]').val();
      data.branch_count = form.find('[name="branch_count"]').val();
    }

    api('admin.users.create', data)
        .done(res => {
             showToast(res.message || 'مدیر شعبه ایجاد شد.', 'success');
             form[0].reset();
             $('#officeCreateBranchList input[type="checkbox"]').prop('checked', false).trigger('change');
             bootstrap.Modal.getInstance(document.getElementById('modalOfficeCreateUser')).hide();
             refreshOfficeUsers();
        })
        .fail(xhr => {
             showToast((xhr.responseJSON && xhr.responseJSON.message) || 'ایجاد ناموفق بود.', 'error');
        });
  });

  function refreshOfficeCapacities() {
      api('office.capacities.get', {})
        .done(res => {
            const list = res.data.capacities || [];
            const rows = list.map(item => {
                return `
                <tr>
                    <td>شعبه ${toPersianDigits(item.branch_no)}</td>
                    <td>
                        <input type="number" class="form-control form-control-sm office-cap-input" data-branch="${item.branch_no}" value="${item.capacity}" min="1" max="999">
                    </td>
                    <td>
                        <button class="btn btn-primary btn-sm btn-office-save-cap" data-branch="${item.branch_no}">ذخیره</button>
                    </td>
                </tr>`;
            }).join('');
            $('#officeCapacitiesTbody').html(rows);
        })
        .fail(() => showToast('خطا در دریافت ظرفیت‌ها', 'error'));
  }

  let officeSelectedOwnerId = 0;

  function refreshOfficeUsers() {
    return api('admin.users.list', { q: '' })
      .done((res) => {
        const users = (res.data && res.data.users) || [];
        const filtered = users.filter((u) => u.role === 'branch_admin');
        const rows = filtered.map((u) => {
          const username = $('<div/>').text(toPersianDigits(u.username || '')).html();
          const name = $('<div/>').text(toPersianDigits(u.display_name || `${u.first_name || ''} ${u.last_name || ''}`.trim())).html();
          const branchesText = (u.branches || '').toString();
          const branches = $('<div/>').text(branchesText ? toPersianDigits(branchesText.split(',').map((x) => String(x).padStart(2, '0')).join(', ')) : '').html();
          const lastLogin = $('<div/>').text(toPersianDigits(u.last_login_at_jalali || 'هرگز')).html();
          const json = encodeURIComponent(JSON.stringify(u));
          return `
            <tr data-id="${u.id}" data-json="${json}">
              <td class="fw-semibold">${username}</td>
              <td>${name}</td>
              <td>مدیر شعبه</td>
              <td class="text-secondary">${branches}</td>
              <td class="text-secondary small">${lastLogin}</td>
              <td class="text-end">
                <button class="btn btn-outline-primary btn-sm btn-admin-edit-user" type="button">ویرایش</button>
                <button class="btn btn-outline-danger btn-sm btn-admin-delete-user" type="button">حذف</button>
              </td>
              <td class="text-end">
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-secondary btn-office-filter-user" type="button" data-id="${u.id}">ثبت‌ها</button>
                  <button class="btn btn-outline-dark btn-office-clear-filter" type="button">همه</button>
                </div>
              </td>
            </tr>
          `;
        });
        $('#officeUsersTbody').html(rows.join('') || `<tr><td colspan="6" class="text-center text-secondary py-3">کاربری یافت نشد.</td></tr>`);
      })
      .fail((xhr) => {
        showToast((xhr.responseJSON && xhr.responseJSON.message) || 'خطا در دریافت کاربران.', 'error');
      });
  }

  function renderOfficeKelasehRows(rows) {
    const html = (rows || []).map((r) => {
      const rawCode = r.full_code || r.code || '';
      const code = $('<div/>').text(toPersianDigits(rawCode)).html();
      const owner = $('<div/>').text(toPersianDigits(r.owner_name || r.username || '')).html();
      const plaintiff = $('<div/>').text(toPersianDigits(r.plaintiff_name || '')).html();
      const plaintiffNC = $('<div/>').text(toPersianDigits(r.plaintiff_national_code || '')).html();
      const defendant = $('<div/>').text(toPersianDigits(r.defendant_name || '')).html();
      const date = $('<div/>').text(toPersianDigits(r.created_at_jalali || r.created_at || '')).html();
      const codeRaw = r.code || '';
      return `
        <tr>
          <td dir="ltr" class="text-end fw-bold">${code}</td>
          <td>${owner}</td>
          <td>${plaintiff}</td>
          <td dir="ltr" class="text-end text-secondary">${plaintiffNC}</td>
          <td>${defendant}</td>
          <td class="text-secondary">${date}</td>
          <td class="text-end">
            <div class="btn-group btn-group-sm" role="group">
              <button class="btn btn-outline-secondary btn-office-label" type="button" data-code="${codeRaw}">لیبل</button>
              <button class="btn btn-outline-dark btn-office-print" type="button" data-code="${codeRaw}">چاپ</button>
            </div>
          </td>
        </tr>
      `;
    }).join('');
    $('#officeKelasehSearchTbody').html(html || `<tr><td colspan="7" class="text-center text-secondary py-3">موردی یافت نشد.</td></tr>`);
  }

  function refreshOfficeKelasehSearch() {
    const q = $('#officeKelasehSearchQuery').val() || '';
    const payload = { q };
    if (officeSelectedOwnerId > 0) payload.owner_id = officeSelectedOwnerId;
    return api('kelaseh.list', payload)
      .done((res) => {
        const rows = (res.data && res.data.kelaseh) || [];
        renderOfficeKelasehRows(rows);
      })
      .fail((xhr) => {
        showToast((xhr.responseJSON && xhr.responseJSON.message) || 'خطا در جستجو.', 'error');
      });
  }

  function refreshOfficeStats() {
    return api('office.stats', {})
      .done((res) => {
        const totals = (res.data && res.data.totals) || {};
        const branches = (res.data && res.data.branches) || [];
        const users = (res.data && res.data.users) || [];

        const cards = [];
        cards.push(`<div class="col-12 col-md-3"><div class="card border-0 bg-light"><div class="card-body p-2"><div class="small text-secondary">کل ثبت‌ها</div><div class="fw-bold">${toPersianDigits(totals.total || 0)}</div></div></div></div>`);
        cards.push(`<div class="col-12 col-md-3"><div class="card border-0 bg-light"><div class="card-body p-2"><div class="small text-secondary">فعال</div><div class="fw-bold text-success">${toPersianDigits(totals.active || 0)}</div></div></div></div>`);
        cards.push(`<div class="col-12 col-md-3"><div class="card border-0 bg-light"><div class="card-body p-2"><div class="small text-secondary">غیرفعال</div><div class="fw-bold text-secondary">${toPersianDigits(totals.inactive || 0)}</div></div></div></div>`);
        cards.push(`<div class="col-12 col-md-3"><div class="card border-0 bg-light"><div class="card-body p-2"><div class="small text-secondary">ابطال</div><div class="fw-bold text-danger">${toPersianDigits(totals.voided || 0)}</div></div></div></div>`);

        const branchRows = branches.map((b) => {
          const bn = toPersianDigits(String(b.branch_no || '').padStart(2, '0'));
          return `<tr><td>شعبه ${bn}</td><td>${toPersianDigits(b.total || 0)}</td><td class="text-success">${toPersianDigits(b.active || 0)}</td><td class="text-secondary">${toPersianDigits(b.inactive || 0)}</td><td class="text-danger">${toPersianDigits(b.voided || 0)}</td></tr>`;
        }).join('');

        const userRows = users.map((u) => {
          const name = $('<div/>').text(toPersianDigits(u.display_name || u.username || '')).html();
          return `<tr><td>${name}</td><td>${toPersianDigits(u.total || 0)}</td></tr>`;
        }).join('');

        cards.push(`<div class="col-12"><div class="card"><div class="card-header py-2">آمار شعب</div><div class="card-body p-2"><div class="table-responsive"><table class="table table-sm table-bordered align-middle m-0"><thead><tr><th>شعبه</th><th>کل</th><th>فعال</th><th>غیرفعال</th><th>ابطال</th></tr></thead><tbody>${branchRows || `<tr><td colspan="5" class="text-center text-secondary py-3">داده‌ای یافت نشد.</td></tr>`}</tbody></table></div></div></div></div>`);
        cards.push(`<div class="col-12"><div class="card"><div class="card-header py-2">ثبت‌ها بر اساس مدیر شعبه</div><div class="card-body p-2"><div class="table-responsive"><table class="table table-sm table-bordered align-middle m-0"><thead><tr><th>مدیر شعبه</th><th>تعداد ثبت</th></tr></thead><tbody>${userRows || `<tr><td colspan="2" class="text-center text-secondary py-3">کاربری یافت نشد.</td></tr>`}</tbody></table></div></div></div></div>`);

        $('#officeStatsContainer').html(cards.join(''));
      })
      .fail((xhr) => {
        showToast((xhr.responseJSON && xhr.responseJSON.message) || 'خطا در دریافت آمار.', 'error');
      });
  }

  $(document).on('click', '#btnOfficeStatsRefresh', function () {
    refreshOfficeStats();
  });

  $('button[data-bs-target="#officeStats"]').on('shown.bs.tab', function () {
    refreshOfficeStats();
  });

  $(document).on('click', '#btnOfficeKelasehSearch', function () {
    refreshOfficeKelasehSearch();
  });

  $(document).on('click', '#btnOfficeKelasehPrintAll', function () {
    const q = $('#officeKelasehSearchQuery').val() || '';
    const qs = new URLSearchParams({ action: 'kelaseh.export.print', csrf_token: csrfToken, q });
    if (officeSelectedOwnerId > 0) qs.set('owner_id', String(officeSelectedOwnerId));
    window.open(`core.php?${qs.toString()}`, '_blank');
  });

  $(document).on('click', '#officeUsersTbody .btn-office-filter-user', function () {
    officeSelectedOwnerId = Number($(this).data('id') || 0);
    $('button[data-bs-target="#officeKelaseh"]').trigger('click');
    refreshOfficeKelasehSearch();
  });

  $(document).on('click', '#officeUsersTbody .btn-office-clear-filter', function () {
    officeSelectedOwnerId = 0;
    refreshOfficeKelasehSearch();
  });

  $(document).on('click', '#officeKelasehSearchTbody .btn-office-label', function () {
    const code = $(this).data('code');
    window.open(`core.php?action=kelaseh.label&code=${encodeURIComponent(code)}`, '_blank');
  });

  $(document).on('click', '#officeKelasehSearchTbody .btn-office-print', function () {
    const code = $(this).data('code');
    window.open(`core.php?action=kelaseh.print&code=${encodeURIComponent(code)}`, '_blank');
  });

  $(document).on('click', '#btnOfficeCapacitiesRefresh', refreshOfficeCapacities);
  
  // Refresh when tab shown
  $('button[data-bs-target="#officeCapacities"]').on('shown.bs.tab', function () {
      refreshOfficeCapacities();
  });

  $(document).on('click', '.btn-office-save-cap', function() {
      const btn = $(this);
      const branch = btn.data('branch');
      const input = btn.closest('tr').find('.office-cap-input');
      const cap = input.val();
      
      api('office.capacities.update', { branch_no: branch, capacity: cap })
        .done(res => showToast(res.message, 'success'))
        .fail(xhr => showToast((xhr.responseJSON && xhr.responseJSON.message) || 'خطا در ذخیره', 'error'));
  });

  $(boot);
