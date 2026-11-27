# نگهداری نسخهٔ شخصی فاکسیما

شاخهٔ `custom/v1.3.5` از تگ رسمی `v1.3.5` شروع می‌شود. شاخهٔ `feat/service-identifier-search` سابقهٔ نسخهٔ قبلی را نگه می‌دارد. تغییرات این شاخه به کامیت‌های جدا برای نرخ ارز، جستجو و جدول تلگرام، مینی‌اپ، و ثبت مالی تقسیم شده‌اند.

این نسخه برای نصب موجود ربکا به تغییر ساختار دیتابیس نیاز ندارد. شناسهٔ ربکا از API خوانده می‌شود و در همگام‌سازی عادی می‌تواند در JSON موجود `invoice.user_info` ثبت شود. مرتب‌سازی فهرست‌های بزرگ از اطلاعات ثبت‌شده استفاده می‌کند. نتیجهٔ سراسری مینی‌اپ فقط برای مدیر اصلی در دسترس است و فیلدهای لازم برای شناسایی نتیجه را برمی‌گرداند. لینک اشتراک در درخواست POST قرار می‌گیرد. برای سفارش‌های پنل مدیریت، `order:123` شمارهٔ سفارش فاکسیما را هدف می‌گیرد.

## آماده‌کردن شاخهٔ نسخهٔ بعد

در این مثال `vX.Y.Z` را با تگ رسمی بعدی جایگزین کنید. نشانی ریموت‌ها را با `git remote -v` بررسی کنید. `upstream` به `Mmd-Amir/Faoxima` و `fork` به فورک شما اشاره می‌کند.

```bash
git fetch upstream --tags
git fetch fork custom/v1.3.5
git switch -c custom/vX.Y.Z vX.Y.Z
git cherry-pick v1.3.5..fork/custom/v1.3.5
git range-diff v1.3.5..fork/custom/v1.3.5 vX.Y.Z..HEAD
```

وقت حل تعارض، مسیرهای فعلی نسخهٔ رسمی را مبنا بگیرید. جستجو و جدول در `src/Services/Subscriptions`، نرخ بازار در `src/Orders/Pricing/MarketRates.php` و ثبت نهایی تمدید در `src/Services/Renewal/WalletRenewal.php` قرار دارند. فایل‌های مینی‌اپ ممکن است در انتشار رسمی بعد به پوشهٔ نسخهٔ تازه منتقل شوند. اگر نسخهٔ رسمی قابلیتی معادل داشت، تستش را روی همان رفتار اجرا و کامیت معادل را در زمان انتقال رد کنید.

## تست نسخهٔ جدید

فرمان‌ها از ریشهٔ مخزن اجرا می‌شوند. `FAOXIMA_TEST_DSN` باید به دیتابیس آزمایشی اشاره کند. در صورت نیاز `FAOXIMA_TEST_DB_USER` و `FAOXIMA_TEST_DB_PASSWORD` را تنظیم کنید.

```bash
php -S 127.0.0.1:43497 scripts/tests/service-search.php
```

در ترمینال دوم اجرا کنید.

```bash
php scripts/tests/service-search.php
php scripts/tests/subscription-lifecycle.php
```

برای نرخ بازار، ابتدا `php -S 127.0.0.1:43500 scripts/tests/market-rates.php` را در یک ترمینال اجرا کنید. سپس در ترمینال دیگر بزنید.

```bash
for mode in primary partial swapwallet wallex unavailable; do
  php scripts/tests/market-rates.php "$mode" || exit 1
done
```

برای مینی‌اپ، Playwright و Chromium در محیط توسعه لازم‌اند. اگر از Chromium سیستم استفاده می‌کنید، مسیر آن را در `CHROMIUM_BIN` قرار دهید.

```bash
node scripts/tests/miniapp-subscriptions.cjs
```

قبل از نصب روی External App، بکاپ فایل و SQL بگیرید. از تگ رسمی‌ای که اکنون روی سرور نصب است تا کامیت شاخهٔ شخصی پچ بسازید. `git apply --check` باید روی پوشهٔ اپ موفق شود. بعد از نصب، Telegram، مینی‌اپ، Cron و نمایش مبلغ یک فاکتور تست را بررسی کنید. برای برگشت همین تغییر کد می‌توان همان پچ را پس از `git apply -R --check` به‌صورت معکوس اعمال کرد.
