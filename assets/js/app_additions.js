
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
  if (!q.trim()) {
    showToast('لطفاً عبارتی برای جستجو وارد کنید.', 'error');
    return;
  }
  api('admin.kelaseh.search', { q })
    .done((res) => {
      const rows = (res.data && res.data.results) || [];
      const html = rows.map((r) => {
        // Use full_code if available
        const rawCode = r.full_code || r.code || '';
        const code = $('<div/>').text(toPersianDigits(rawCode)).html();
        const owner = $('<div/>').text(toPersianDigits((r.city_name ? r.city_name + ' / ' : '') + (r.owner_name || ''))).html();
        const plaintiff = $('<div/>').text(toPersianDigits(r.plaintiff_name || '')).html();
        const defendant = $('<div/>').text(toPersianDigits(r.defendant_name || '')).html();
        const date = $('<div/>').text(toPersianDigits(r.created_at_jalali || '')).html();
        
        return `<tr><td dir="ltr" class="text-end fw-bold">${code}</td><td>${owner}</td><td>${plaintiff}</td><td>${defendant}</td><td>${date}</td></tr>`;
      }).join('');
      $('#adminKelasehSearchTbody').html(html || `<tr><td colspan="5" class="text-center text-secondary py-3">موردی یافت نشد.</td></tr>`);
    })
    .fail((xhr) => {
       const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'خطا در جستجو.';
       showToast(msg, 'error');
    });
}

$(document).on('click', '#btnAdminKelasehSearch', function () {
  refreshAdminKelasehSearch();
});

// Fix for Admin Panel Visibility and Redirect
const originalRenderPage = renderPage;
renderPage = function(page) {
    const isAdmin = currentUser && currentUser.role === 'admin';
    
    // Redirect non-admins trying to access admin
    if (page === 'admin' && !isAdmin) {
        window.location.hash = '#dashboard';
        return;
    }
    
    // Call original logic
    // We need to reimplement/patch because we can't easily call original inside replaced variable if it was function declaration
    // Actually, I can just append the handlers and fix renderPage in place using SearchReplace.
    // The code above is just for thought process.
};
