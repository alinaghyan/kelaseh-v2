let csrfToken = '';
let currentUser = null;

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

function setView(mode) {
  $('#viewLoading').toggleClass('d-none', mode !== 'loading');
  $('#viewAuth').toggleClass('d-none', mode !== 'auth');
  $('#viewApp').toggleClass('d-none', mode !== 'app');
}

function renderUser() {
  const loggedIn = !!currentUser;
  $('#btnLogout').toggleClass('d-none', !loggedIn);
  $('#currentRole').toggleClass('d-none', !loggedIn);
  if (!loggedIn) {
    return;
  }

  const roleFa = currentUser.role === 'admin' ? 'مدیر کل' : 'کاربر';
  $('#currentRole').text(roleFa);
  $('#profileEmail').text(currentUser.email);
  $('#profileName').text(currentUser.display_name || '');
  $('#profileRole').text(roleFa);

  $('#adminPanel').toggleClass('d-none', currentUser.role !== 'admin');
}

function resetItemForm() {
  $('#formItem [name=id]').val('');
  $('#formItem [name=title]').val('');
  $('#formItem [name=content]').val('');
  $('#btnItemSubmit').text('ثبت').removeClass('btn-success').addClass('btn-primary');
  $('#btnItemCancel').addClass('d-none');
}

function renderItems(items) {
  const rows = items.map((it) => {
    const title = $('<div/>').text(it.title).html();
    const date = $('<div/>').text(it.updated_at_jalali || it.updated_at || '').html();
    const content = $('<div/>').text(it.content || '').html();
    return `
      <tr data-id="${it.id}" data-title="${title}" data-content="${content}">
        <td>
          <div class="fw-semibold">${title}</div>
          ${content ? `<div class="text-secondary small">${content}</div>` : ''}
        </td>
        <td class="text-secondary">${date}</td>
        <td class="text-end">
          <div class="btn-group btn-group-sm" role="group">
            <button class="btn btn-outline-primary btn-edit" type="button">ویرایش</button>
            <button class="btn btn-outline-danger btn-del" type="button">حذف</button>
          </div>
        </td>
      </tr>
    `;
  });
  $('#itemsTbody').html(rows.join('') || `<tr><td colspan="3" class="text-center text-secondary py-4">چیزی برای نمایش نیست.</td></tr>`);
}

function refreshItems() {
  const q = $('#itemsQuery').val() || '';
  return api('items.list', { q })
    .done((res) => {
      renderItems((res.data && res.data.items) || []);
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'خطا در دریافت داده‌ها.';
      showToast(msg, 'error');
    });
}

function refreshAdminUsers() {
  const q = $('#adminUsersQuery').val() || '';
  return api('admin.users.list', { q })
    .done((res) => {
      const users = (res.data && res.data.users) || [];
      const rows = users.map((u) => {
        const email = $('<div/>').text(u.email).html();
        const name = $('<div/>').text(u.display_name || '').html();
        const role = u.role === 'admin' ? 'admin' : 'user';
        const isActive = Number(u.is_active) === 1;
        return `
          <tr data-id="${u.id}">
            <td>
              <div class="fw-semibold">${email}</div>
              ${name ? `<div class="text-secondary small">${name}</div>` : ''}
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
      const rows = logs.map((l) => {
        const dt = $('<div/>').text(l.created_at_jalali || l.created_at || '').html();
        const actor = $('<div/>').text(l.actor_id || '').html();
        const act = $('<div/>').text(l.action || '').html();
        const ent = $('<div/>').text(l.entity || '').html();
        return `<tr><td class="text-secondary">${dt}</td><td>${actor}</td><td>${act}</td><td>${ent}</td></tr>`;
      });
      $('#adminLogsTbody').html(rows.join('') || `<tr><td colspan="4" class="text-center text-secondary py-3">لاگی یافت نشد.</td></tr>`);
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'خطا در دریافت گزارش.';
      showToast(msg, 'error');
    });
}

function refreshAdminItems() {
  const q = $('#adminItemsQuery').val() || '';
  return api('admin.items.list', { q })
    .done((res) => {
      const items = (res.data && res.data.items) || [];
      const rows = items.map((it) => {
        const owner = $('<div/>').text(it.owner_email || '').html();
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
        return;
      }
      renderUser();
      setView('app');
      refreshItems();
      if (currentUser.role === 'admin') {
        refreshAdminUsers();
        refreshAdminItems();
        refreshAdminLogs();
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
      refreshItems();
      if (currentUser && currentUser.role === 'admin') {
        refreshAdminUsers();
        refreshAdminItems();
        refreshAdminLogs();
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
      setView('auth');
    })
    .fail(() => {
      showToast('خروج ناموفق بود.', 'error');
    });
});

$(document).on('click', '#btnItemsRefresh', function () {
  refreshItems();
});

$(document).on('click', '#btnItemsSearch', function () {
  refreshItems();
});

$(document).on('click', '#itemsTbody .btn-edit', function () {
  const tr = $(this).closest('tr');
  const id = tr.data('id');
  const title = tr.data('title');
  const content = tr.data('content');
  $('#formItem [name=id]').val(String(id));
  $('#formItem [name=title]').val(title);
  $('#formItem [name=content]').val(content);
  $('#btnItemSubmit').text('به‌روزرسانی').removeClass('btn-primary').addClass('btn-success');
  $('#btnItemCancel').removeClass('d-none');
});

$(document).on('click', '#btnItemCancel', function () {
  resetItemForm();
});

$(document).on('click', '#itemsTbody .btn-del', function () {
  const tr = $(this).closest('tr');
  const id = tr.data('id');
  const title = tr.data('title');
  if (!confirm(`حذف شود؟\n${title}`)) {
    return;
  }
  api('items.delete', { id })
    .done((res) => {
      showToast(res.message || 'حذف شد.', 'success');
      resetItemForm();
      refreshItems();
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'حذف ناموفق بود.';
      showToast(msg, 'error');
    });
});

$(document).on('submit', '#formItem', function (e) {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(this));
  const isUpdate = !!data.id;
  api(isUpdate ? 'items.update' : 'items.create', data)
    .done((res) => {
      showToast(res.message || (isUpdate ? 'به‌روزرسانی شد.' : 'ثبت شد.'), 'success');
      resetItemForm();
      refreshItems();
    })
    .fail((xhr) => {
      const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'عملیات ناموفق بود.';
      showToast(msg, 'error');
    });
});

$(document).on('click', '#btnAdminUsersRefresh', function () {
  refreshAdminUsers();
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

$(boot);
