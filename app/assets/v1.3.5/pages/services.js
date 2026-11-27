import { call } from '../api.js?v=0.0.53';
import { escapeHtml, skeletonList, fmtNumber, fmtBytes, serviceStatusBadge } from '../utils.js?v=0.0.52';
import { icon } from '../icons.js?v=0.0.52';

export async function services(view) {
    let page = 1;
    const limit = 10;
    let query = '';
    let totalPages = 1;
    let totalItems = 0;
    let sort = 'recent';
    let direction = 'desc';
    let searchAll = false;
    let statusFilter = 'all';
    let currentItems = [];
    let requestSeq = 0;
    let disposed = false;

    view.innerHTML = `
        <article class="services-page">
            <div class="services-content">
                <div class="services-heading">
                    <div><p class="services-eyebrow">اتصال‌های شما، یک‌جا</p><h1>اشتراک‌های من</h1></div>
                    <a href="#/buy" class="services-add">${icon('plus')}<span>اشتراک جدید</span></a>
                </div>

                <label class="search-field services-search">
                    ${icon('search', 'class="ico search-field-ico"')}
                    <input id="services-search" type="search" aria-label="جستجوی اشتراک" placeholder="جستجو در اشتراک‌ها" maxlength="2048" dir="auto" spellcheck="false" inputmode="search" autocomplete="off" />
                    <button type="button" class="search-field-clear hidden" id="services-search-clear" aria-label="پاک کردن جستجو">
                        ${icon('close', 'class="ico"')}
                    </button>
                </label>

                <div class="subscription-tools">
                    <details><summary>روش‌های جستجو</summary><p>نام اشتراک، شناسهٔ عددی یا لینک کامل را وارد کنید.</p></details>
                    <label id="subscriptions-scope" hidden><input type="checkbox" id="subscriptions-all" /> جستجو در همهٔ اشتراک‌ها</label>
                    <p id="subscriptions-cached" class="muted" hidden>حجم، زمان و شناسه بر اساس آخرین اطلاعات ثبت‌شده مرتب می‌شوند.</p>
                    <div id="subscriptions-sort" class="subscription-sort">
                        <label for="subscriptions-sort-field">مرتب‌سازی</label>
                        <select id="subscriptions-sort-field">
                            <option value="recent">جدیدترین</option><option value="username">نام</option>
                            <option value="panel_user_id">شناسه</option><option value="remaining_bytes">حجم مانده</option>
                            <option value="days_remaining">روز مانده</option><option value="expires_at">انقضا</option>
                        </select>
                        <button type="button" class="btn btn-ghost" id="subscriptions-direction" aria-label="مرتب‌سازی صعودی" aria-pressed="false" disabled>↓</button>
                    </div>
                </div>
                <div class="services-filter-bar">
                    <span class="services-filter-label">وضعیت در این صفحه</span>
                    <div class="services-filters" role="group" aria-label="فیلتر وضعیت سرویس‌های این صفحه">
                        <button type="button" data-service-filter="all" aria-pressed="true">همه</button>
                        <button type="button" data-service-filter="active" aria-pressed="false">فعال</button>
                        <button type="button" data-service-filter="attention" aria-pressed="false">نیاز به بررسی</button>
                    </div>
                </div>
                <div class="services-results-head"><span id="services-results-label">در حال دریافت سرویس‌ها…</span><span id="services-count" class="hidden"></span></div>
                <div id="services-list" class="services-list" aria-busy="true">
                    ${skeletonList(4)}
                </div>

                <div class="pager services-pager hidden" id="services-pager">
                    <button class="btn btn-ghost" id="services-prev">${icon('chevronRight', 'class="ico ico-leading"')}قبلی</button>
                    <span class="pager-info" id="services-pager-info"></span>
                    <button class="btn btn-ghost" id="services-next">بعدی${icon('chevronLeft', 'class="ico ico-trailing"')}</button>
                </div>
            </div>
        </article>
    `;

    const $list = view.querySelector('#services-list');
    const $pager = view.querySelector('#services-pager');
    const $info = view.querySelector('#services-pager-info');
    const $prev = view.querySelector('#services-prev');
    const $next = view.querySelector('#services-next');
    const $search = view.querySelector('#services-search');
    const $searchClear = view.querySelector('#services-search-clear');
    const $count = view.querySelector('#services-count');
    const $resultsLabel = view.querySelector('#services-results-label');
    const $filters = [...view.querySelectorAll('[data-service-filter]')];

    const $cached = view.querySelector('#subscriptions-cached');
    const $scope = view.querySelector('#subscriptions-scope');
    const $all = view.querySelector('#subscriptions-all');
    const $sort = view.querySelector('#subscriptions-sort');
    const $sortField = view.querySelector('#subscriptions-sort-field');
    const $direction = view.querySelector('#subscriptions-direction');
    $all.addEventListener('change', () => {
        searchAll = $all.checked;
        $sort.hidden = searchAll;
        $cached.hidden = true;
        page = 1;
        statusFilter = 'all';
        $filters.forEach(btn => btn.setAttribute('aria-pressed', String(btn.dataset.serviceFilter === 'all')));
        load();
    });
    function updateDirection() {
        const ascending = direction === 'asc';
        $direction.textContent = ascending ? '↑' : '↓';
        $direction.setAttribute('aria-pressed', String(ascending));
        $direction.disabled = sort === 'recent';
    }
    $sortField.addEventListener('change', () => {
        sort = $sortField.value;
        direction = sort === 'username' ? 'asc' : 'desc';
        updateDirection();
        page = 1;
        load();
    });
    $direction.addEventListener('click', () => {
        direction = direction === 'asc' ? 'desc' : 'asc';
        updateDirection();
        page = 1;
        load();
    });

    function renderItems() {
        const items = currentItems.filter(it => {
            const active = serviceStatusBadge(it.status || it.Status || '').badge === 'is-active';
            return statusFilter === 'all' || (statusFilter === 'active' ? active : !active);
        });
        $resultsLabel.textContent = `${fmtNumber(items.length)} اشتراک در این صفحه`;
        if (!items.length) {
            const filtered = statusFilter !== 'all' && currentItems.length > 0;
            $list.innerHTML = `<div class="services-empty">
                <span class="services-empty-icon">${icon(query || filtered ? 'search' : 'box')}</span>
                <h2>${filtered ? 'در این صفحه سرویسی با این وضعیت نیست' : query ? 'نتیجه‌ای پیدا نشد' : 'اولین اتصال را بساز'}</h2>
                <p>${filtered ? 'فیلتر را عوض کن یا صفحهٔ بعد را ببین.' : query ? 'شناسه را بررسی کن یا جست‌وجو را پاک کن.' : 'با انتخاب یک پلن، سرویس تو همین‌جا نمایش داده می‌شود.'}</p>
                ${filtered ? '<button type="button" class="btn btn-ghost" id="services-reset-filter">نمایش همه</button>' : query ? '<button type="button" class="btn btn-ghost" id="services-reset-search">پاک کردن جست‌وجو</button>' : `<a href="#/buy" class="btn btn-primary">${icon('plus')} خرید اولین سرویس</a>`}
            </div>`;
            $list.querySelector('#services-reset-filter')?.addEventListener('click', () => setFilter('all'));
            $list.querySelector('#services-reset-search')?.addEventListener('click', () => $searchClear.click());
        } else {
            $list.innerHTML = items.map(renderItem).join('');
        }
    }

    function setFilter(value) {
        statusFilter = value;
        $filters.forEach(btn => btn.setAttribute('aria-pressed', String(btn.dataset.serviceFilter === value)));
        renderItems();
    }
    $filters.forEach(btn => btn.addEventListener('click', () => setFilter(btn.dataset.serviceFilter)));

    async function load() {
        const seq = ++requestSeq;
        if (searchAll && !query) {
            currentItems = [];
            $count.classList.add('hidden');
            $pager.classList.add('hidden');
            $list.setAttribute('aria-busy', 'false');
            $filters.forEach(btn => { btn.disabled = true; });
            $resultsLabel.textContent = 'جستجوی همهٔ اشتراک‌ها';
            $list.innerHTML = '<p class="muted">عبارت جستجو را وارد کنید.</p>';
            return;
        }
        $list.innerHTML = skeletonList(4);
        $list.setAttribute('aria-busy', 'true');
        $filters.forEach(btn => { btn.disabled = true; });
        $resultsLabel.textContent = 'در حال دریافت سرویس‌ها…';
        $pager.classList.add('hidden');

        try {
            const params = { page, limit, sort, direction };
            const res = query
                ? await call('invoices', { method: 'POST', body: { ...params, q: query, scope: searchAll ? 'all' : 'mine' } })
                : await call('invoices', { params });
            if (disposed || seq !== requestSeq) return;

            $scope.hidden = res?.obj?.can_search_all !== true;
            $cached.hidden = searchAll || res?.obj?.cached_usage !== true;
            page = Number(res?.obj?.page || page);
            const items = res?.obj?.items || [];
            totalPages = Number(res?.obj?.total_pages) || 1;
            totalItems = Number(res?.obj?.total) || items.length;

            $count.classList.toggle('hidden', totalItems === 0);
            $count.textContent = `${fmtNumber(totalItems)} سرویس در مجموع`;
            currentItems = items;

            renderItems();

            if (totalPages > 1) {
                $pager.classList.remove('hidden');
                $info.textContent = `صفحه ${fmtNumber(page)} از ${fmtNumber(totalPages)} (${fmtNumber(totalItems)} سرویس)`;
                $prev.disabled = page <= 1;
                $next.disabled = page >= totalPages;
            }
        } catch (err) {
            if (disposed || seq !== requestSeq) return;
            $count.classList.add('hidden');
            currentItems = [];
            $resultsLabel.textContent = 'دریافت سرویس‌ها انجام نشد';
            $list.innerHTML = `
                <div class="empty">
                    ${icon('alert', 'class="ico ico-xxl ico-warn"')}
                    <h3>خطا در دریافت لیست</h3>
                    <p class="muted">${escapeHtml(err.message || '')}</p>
                    <button type="button" class="btn btn-ghost" id="services-retry">تلاش مجدد</button>
                </div>
            `;
            $list.querySelector('#services-retry')?.addEventListener('click', load);
        } finally {
            if (!disposed && seq === requestSeq) {
                $list.setAttribute('aria-busy', 'false');
                $filters.forEach(btn => { btn.disabled = currentItems.length === 0; });
            }
        }
    }

    function renderItem(it) {
        const st = serviceStatusBadge(it.status || it.Status || '');
        const badge = st.badge;
        const badgeText = st.text;
        const badgeIcon = st.icon;

        const username = it.username || '—';
        const displayUsername = it.display_username || username;
        const productName = it.plan_label || it.name_product || '';
        const location = it.Service_location || '';
        const href = `#/services/${encodeURIComponent(username)}/${encodeURIComponent(it.id_invoice)}`;
        const days = metric(it.days_remaining, value => value === 0 ? 'منقضی' : fmtNumber(value));
        const traffic = metric(it.remaining_bytes, value => value === 0 ? 'تمام' : fmtBytes(value));
        const expiry = it.expires_at == null ? '—' : Number(it.expires_at) === 0 ? '∞'
            : new Intl.DateTimeFormat('fa-IR', { dateStyle: 'short', timeZone: 'Asia/Tehran' }).format(it.expires_at * 1000);
        const tag = searchAll ? 'article' : 'a';
        return `
            <${tag} ${searchAll ? '' : `href="${href}"`} class="service-preview ${badge}">
                <div class="service-preview-head">
                    <span class="service-preview-icon">${icon('globe')}</span>
                    <h2><bdi>${escapeHtml(displayUsername)}</bdi></h2>
                    <span class="service-state">${icon(badgeIcon)} ${escapeHtml(badgeText)}</span>
                </div>
                <div class="service-preview-identity"><span>شناسهٔ اشتراک</span><bdi>${escapeHtml(it.panel_user_id || '—')}</bdi></div>
                ${searchAll ? `<div class="service-preview-identity"><span>شماره سفارش</span><bdi>${escapeHtml(it.id_invoice)}</bdi></div>` : `
                    <dl class="subscription-metrics">
                        <div><dt>روز مانده</dt><dd>${escapeHtml(days)}</dd></div>
                        <div><dt>حجم مانده</dt><dd>${escapeHtml(traffic)}</dd></div>
                        <div><dt>انقضا</dt><dd>${escapeHtml(expiry)}</dd></div>
                    </dl>`}
                <div class="service-preview-footer">
                    <div class="service-preview-tags">
                        ${location ? `<span>${icon('globe')} ${escapeHtml(location)}</span>` : ''}
                        ${productName ? `<span>${escapeHtml(productName)}</span>` : ''}
                        ${it.has_queued_renewal ? `<span class="service-queued">${icon('clock')} رزرو اشتراک</span>` : ''}
                    </div>
                    ${searchAll ? '' : `<span class="service-details-link">جزئیات ${icon('arrowLeft')}</span>`}
                </div>
            </${tag}>`;
    }

    function metric(value, format) {
        return value == null ? '—' : Number(value) === -1 ? '∞' : format(Number(value));
    }

    let searchTimer = null;
    function applySearch() {
        const next = ($search.value || '').trim();
        $searchClear.classList.toggle('hidden', next === '');
        if (next === query) return;
        query = next;
        page = 1;
        load();
    }
    $search.addEventListener('input', () => {
        $searchClear.classList.toggle('hidden', ($search.value || '').trim() === '');
        clearTimeout(searchTimer);
        searchTimer = setTimeout(applySearch, 400);
    });
    $search.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(searchTimer);
            applySearch();
        }
    });
    $searchClear.addEventListener('click', () => {
        $search.value = '';
        clearTimeout(searchTimer);
        applySearch();
        $search.focus();
    });
    $prev.addEventListener('click', () => { if (page > 1) { page--; load(); } });
    $next.addEventListener('click', () => { if (page < totalPages) { page++; load(); } });

    load();
    return () => { disposed = true; ++requestSeq; clearTimeout(searchTimer); };
}
