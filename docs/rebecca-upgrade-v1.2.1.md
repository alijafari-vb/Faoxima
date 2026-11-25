# ارتقای فاکسیما روی ربکا به ۱.۲.۱

این راهنما برای نصب فعال فاکسیما ۱.۲.۰ در بخش External Apps ربکا روی لینوکس است. نشانی پوشه، کاربر PHP-FPM، مسیر PHP CLI و نام دیتابیس را از همان نصب بردارید. اگر مسیر برنامه یک symlink یا bind mount است، تعویض پوشه را از طریق سازوکار همان نصب انجام دهید. یک ربات آزمایشی با توکن و دیتابیس جدا برای آزمون مسیر پراکسی مناسب است.

## آماده‌سازی و پشتیبان

آرشیو برچسب [v1.2.1 فورک فاکسیما](https://github.com/alijafari-vb/Faoxima/archive/refs/tags/v1.2.1.tar.gz) را از یک اتصال دارای دسترسی به GitHub بگیرید و به سرور منتقل کنید. برای این چیدمان، مراحل را در یک نشست root و در بازهٔ کم‌ترافیک انجام دهید. نام‌ها و مسیرهای نمونه را با مقادیر نصب خود جایگزین کنید.

```bash
FX_APP=/var/lib/rebecca/external-apps/apps/APP_ID
FX_USER=rbphp_APP_ID
FX_GROUP=$(id -gn "$FX_USER")
FX_PHP=/usr/bin/php
FX_DB=APP_DATABASE
FX_ARCHIVE=/root/Faoxima-1.2.1.tar.gz
FX_BACKUP=$(mktemp -d /root/faoxima-1.2.1-backup.XXXXXX)
FX_STAGE=$(mktemp -d "$(dirname "$FX_APP")/.faoxima-1.2.1-stage.XXXXXX")
FX_SOURCE=$(mktemp -d /root/faoxima-1.2.1-source.XXXXXX)
fx() { runuser -u "$FX_USER" -- "$FX_PHP" "$FX_APP/scripts/telegram-polling.php" "$@"; }

test "$(cat "$FX_APP/version")" = 1.2.0
test -d "$FX_APP" && test ! -L "$FX_APP" && ! mountpoint -q "$FX_APP"
runuser -u "$FX_USER" -- "$FX_PHP" -r 'exit(extension_loaded("curl") && extension_loaded("pdo_mysql") && function_exists("proc_open") ? 0 : 1);'
tar -xzf "$FX_ARCHIVE" --strip-components=1 -C "$FX_SOURCE"
test "$(cat "$FX_SOURCE/version")" = 1.2.1

systemctl is-enabled fx-relay.service > "$FX_BACKUP/fx-relay.enabled" || true
systemctl is-active fx-relay.service > "$FX_BACKUP/fx-relay.active" || true
cp -a /opt/fx-relay.php /etc/fx-relay.env "$FX_BACKUP/"
systemctl cat fx-relay.service > "$FX_BACKUP/fx-relay.unit"
```

در صورت خطا، همان مرحله را بررسی کنید. فایل پشتیبان شامل تنظیمات حساس است. برای پشتیبان دیتابیس از حساب دارای دسترسی به همان دیتابیس استفاده کنید. در میزبان‌هایی که نام ابزار `mysqldump` است، دستور متناسب را اجرا کنید.

```bash
set -o pipefail
mariadb-dump --single-transaction --quick --routines --triggers --databases "$FX_DB" \
  | gzip -c > "$FX_BACKUP/database.sql.gz"
gzip -t "$FX_BACKUP/database.sql.gz"
```

پشتیبان دیتابیس برای بازیابی اضطراری است. در برگشت عادی، دیتابیس زنده را حفظ کنید تا سفارش‌ها و پرداخت‌های ثبت‌شده بعد از ارتقا از بین نروند. این ارتقا از ۱.۲.۰ برای صف تلگرام دو جدول جداگانه می‌سازد و برای نصب فعال به اجرای دوبارهٔ `table.php` احتیاج ندارد.

## تعویض کد و آزمون اولیه

۱. قبل از تغییر، `/start`، یک دکمه و یک عملیات فقط‌خواندنی وابسته به ربکا را با ریلهٔ قدیمی امتحان کنید. زمان و خطاهای فعلی را برای مقایسه یادداشت کنید.

۲. برای تعویض فایل‌ها، ثبت سفارش و پرداخت جدید و وظایف زمان‌بندی‌شدهٔ همان برنامه را در پنجرهٔ کوتاه نگهداری متوقف کنید. صبر کنید عملیات در حال اجرا تمام شود. ریلهٔ قدیمی را با `systemctl disable --now fx-relay.service` متوقف کنید تا در آزمایش یا پس از ریبوت دوباره هم‌زمان شروع نشود. سرویس‌های `fx-redsocks`، `fx-tg-redirect` و ورودی Xray را برای این آزمون فعال نگه دارید.

۳. ابتدا از پوشهٔ فعلی یک نسخهٔ کامل و سپس از آرشیو روی پوشهٔ مرحله‌بندی کپی کنید. دستور `rsync` زیر نسخهٔ نصبی `config.php`، تنظیمات محیطی و داده‌های زمان اجرا را حفظ می‌کند و فایل‌های موجود را از داخل پوشهٔ مرحله‌بندی حذف نمی‌کند.

```bash
cp -a "$FX_APP/." "$FX_STAGE/"
rsync -a --dry-run --itemize-changes --chown="$FX_USER:$FX_GROUP" \
  --exclude='/config.php' --exclude='/.env' --exclude='/.git' \
  --exclude='/installer/' --exclude='/storage/' --exclude='/logs/' \
  "$FX_SOURCE/" "$FX_STAGE/"
rsync -a --chown="$FX_USER:$FX_GROUP" \
  --exclude='/config.php' --exclude='/.env' --exclude='/.git' \
  --exclude='/installer/' --exclude='/storage/' --exclude='/logs/' \
  "$FX_SOURCE/" "$FX_STAGE/"
cmp "$FX_APP/config.php" "$FX_STAGE/config.php"
runuser -u "$FX_USER" -- "$FX_PHP" -l "$FX_STAGE/index.php"

FX_OLD="${FX_STAGE}.previous"
test ! -e "$FX_OLD" && mv -- "$FX_APP" "$FX_OLD" || exit 1
if ! mv -- "$FX_STAGE" "$FX_APP"; then
  mv -- "$FX_OLD" "$FX_APP"
  exit 1
fi
test "$(cat "$FX_APP/version")" = 1.2.1 || exit 1
fx init
```

مسیر `FX_OLD` را ثبت کنید. مسیر پیشین خارج از مسیر درخواست‌های برنامه نگه داشته می‌شود. برای نصب‌هایی که OPcache تغییر فایل را بررسی نمی‌کند، PHP-FPM مربوط را با روال معمول ربکا reload کنید. در پنل وب فاکسیما با ادمین اصلی وارد شوید و وضعیت صفحهٔ «اتصال تلگرام» را بررسی کنید.

## سوییچ از ریله به پراکسی داخلی فاکسیما

۱. در «تنظیمات متفرقه ← اتصال تلگرام» پراکسی فعلی را وارد کنید. برای SOCKS محلی نصب تو، اگر پورت Xray همچنان همان است، نمونه `socks5h://127.0.0.1:1070` است. «آزمون پراکسی ذخیره‌شده» باید موفق باشد. سپس گزینهٔ لانگ‌پولینگ را ذخیره کنید.

۲. با کاربر برنامه وب‌هوک را جدا کنید. پاک کردن آپدیت‌های منتظر در تلگرام در این دستور خاموش است.

```bash
systemctl is-active fx-relay.service || true
fx detach-webhook
```


۳. فایل [نمونهٔ systemd](faoxima-telegram@.service) را به `/etc/systemd/system/faoxima-telegram@.service` منتقل کنید. مقدار `User`، `WorkingDirectory` و مسیر PHP در `ExecStart` را مطابق مقادیر بالا قرار دهید. واحدها را در دورهٔ آزمون با `start` راه‌اندازی کنید.

```bash
systemctl daemon-reload
systemctl start faoxima-telegram@poll.service faoxima-telegram@work.service
fx status
journalctl -u faoxima-telegram@poll.service -u faoxima-telegram@work.service -n 30 --no-pager
```


۴. روی ربات، `/start`، کلیدهای «اشتراک‌های من»، جست‌وجوی اشتراک، یک عملیات فقط‌خواندنی ربکا و دریافت فایل را آزمایش کنید. بعد از موفقیت، برنامه و وظایف همان نصب را از وضعیت نگهداری خارج کنید. وضعیت پرداخت و سفارش معمول را با دادهٔ واقعی تحت نظر بگیرید. پس از پذیرش نسخه، هر دو واحد جدید را `enable` کنید. فایل‌های ریلهٔ قدیمی و پشتیبان را تا پایان دورهٔ آزمون نگه دارید. در صورت اطمینان از بی‌استفاده بودن `redsocks` و قوانین ریدایرکت توسط سایر برنامه‌ها، جداگانه دربارهٔ بازنشستگی آن‌ها تصمیم بگیرید.

## برگشت به ریلهٔ قدیمی

۱. قبل از سوییچ، ارسال سفارش جدید را در بازهٔ نگهداری محدود کنید. وضعیت صف را با `fx status` ببینید. ابتدا دریافت‌کنندهٔ جدید را متوقف کنید. پس از پایان عملیات در حال اجرا، پردازشگر جدید را نیز متوقف کنید. گزینهٔ لانگ‌پولینگ در پنل در این مرحله باید فعال بماند.

۲. اگر صف باقی‌مانده دارد، با `drain` آن را تخلیه کنید. این فرمان بعد از توقف هر دو سرویس کار می‌کند و در صورت قطع پراکسی یا وجود ردیف نیازمند بررسی، پیام می‌دهد و صف را نگه می‌دارد. ردیف‌های نیازمند بررسی را با توجه به سفارش و پیام واقعی پیگیری کنید. پس از بررسی می‌توانید از `retry ROW` یا `resolve ROW` استفاده کنید. قبل از تکمیل بررسی، ردیف معلق را پاک نکنید.

```bash
systemctl stop faoxima-telegram@poll.service
fx status
systemctl stop faoxima-telegram@work.service
fx drain
fx status
```


۳. بعد از خالی شدن صف، لانگ‌پولینگ را از پنل خاموش کنید. اگر می‌خواهید مسیر خروجی قدیمی را هم دقیقاً بازسازی کنید، پراکسی جدید را از پنل حذف کنید تا اتصال خروجی دوباره از مسیر قبلی نصب عبور کند. ریلهٔ قدیمی هم از `getUpdates` استفاده می‌کند، بنابراین برای برگشت به آن وب‌هوک ثبت نکنید.

```bash
systemctl disable faoxima-telegram@poll.service faoxima-telegram@work.service
systemctl start fx-relay.service
# اگر در فایل fx-relay.enabled وضعیت قبلی enabled است:
systemctl enable fx-relay.service
```


۴. اگر ایراد از کد ۱.۲.۱ است، بعد از تخلیهٔ صف و در پنجرهٔ نگهداری، ریله را متوقف کنید، پوشهٔ ۱.۲.۱ را نگه دارید و `FX_OLD` را به مسیر `FX_APP` بازگردانید. فایل‌های زمان اجرای تازه مثل رسید یا رسانهٔ تازه‌بارگذاری‌شده را جداگانه از پوشهٔ جدید حفظ کنید. سپس با دیتابیس زنده PHP-FPM را طبق نیاز reload و ریله را دوباره شروع کنید.

اگر پراکسی قطع است یا صف قابل تخلیه نیست، داده‌های صف را در همان دیتابیس نگه دارید. حتی اگر لازم شود برای از سرگیری دریافت پیام‌های تازه به ریلهٔ قدیمی برگردید، آپدیت‌هایی که قبلاً در صف جدید ثبت و به تلگرام تأیید شده‌اند برای پردازش بعدی در همان صف محلی باقی می‌مانند. ریلهٔ قدیمی آن ردیف‌ها را از تلگرام دوباره دریافت نمی‌کند. قبل از راه‌اندازی دوبارهٔ پردازشگر جدید، ترتیب و اثر عملیات معلق را بررسی کنید. [مستندات Telegram Bot API](https://core.telegram.org/bots/api#getting-updates)
