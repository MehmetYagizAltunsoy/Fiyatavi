# Fiyatavı — PHP sürümü

Bu klasör, sitenin Node.js sürümünün **PHP 8** karşılığıdır. Ön yüz (tasarım, animasyonlar, bütün sayfalar) aynıdır: `public/` içindeki dosyalar buraya kopyalanır. Değişen tek şey sunucu tarafıdır. Adresler (`/api/...`), JSON cevapları ve veri tabanı şeması Node sürümüyle aynıdır, yani iki sürüm aynı `ganimet.db` dosyasını okuyabilir.

## Klasör yapısı

| Dosya / klasör | Ne işe yarar |
|---|---|
| `index.php` | Ön denetleyici: bütün istekler buraya gelir, `inc/api.php` doğru işlevi çağırır |
| `inc/config.php` | `.env` okuma, sabitler |
| `inc/db.php` | SQLite bağlantısı (PDO), tablolar ve sürüm geçişleri |
| `inc/auth.php` | Şifre özeti, oturum, e-posta bağlantıları, deneme sınırı |
| `inc/api.php` | Bütün API adresleri ve sayfa (paylaşım önizlemesi, sitemap, robots) |
| `inc/itad.php`, `steam*.php`, `rates.php`, `shops.php`, `sync.php` | Fiyat ve oyun bilgisi çekme (IsThereAnyDeal, Steam, SteamSpy, kur) |
| `inc/mailer.php`, `mailtemplate.php`, `accountmail.php`, `notify.php` | SMTP ile mail gönderme, şablonlar, fiyat düşüşü / hedef fiyat / haftalık özet |
| `inc/tick.php`, `cron.php` | Arka plan işleri (PHP'de sürekli çalışan süreç olmadığı için cron ile) |
| `app.html` | Sitenin ön yüzü (`../public/index.html`'in kopyası) |
| `data/` | Veri tabanı, günlük yedekler, kayıtlar (web'den erişime kapalı) |
| `test/` | Testler: `php test/run.php` |
| `bin/` | Komut satırı araçları: `php bin/mailtest.php`, `php bin/kontrol.php` |

## Yerelde çalıştırma

PHP 8.1 veya üstü gerekir (`pdo_sqlite`, `curl`, `mbstring`, `openssl` eklentileri açık olmalı; çoğu kurulumda hazırdır).

```
cd php
cp .env.example .env        # içini doldur (ITAD_API_KEY en önemlisi)
php -S localhost:8000 router.php
```

Tarayıcıda `http://localhost:8000`. İlk açılışta oyun listesi boştur; arka plan işini bir kez elle çalıştır:

```
php cron.php
```

Fiyatlar birkaç turda dolar (her tur en fazla `TICK_BUDGET` saniye çalışır, kaldığı yerden devam eder). Testler: `php test/run.php`.

Node sürümünün ön yüzünü değiştirdiysen: `bash sync-assets.sh` (public/ → php/ kopyalar).

## Arka plan işleri nasıl çalışır?

Node sürümü sürekli açık kalıp saatte bir fiyatları günceller. PHP istek bitince kapanır, bu yüzden iş **zamanlanır**:

1. **cron (önerilen):** sunucuda `*/10 * * * * php /yol/php/cron.php` ya da cron-job.org gibi bir servisle `https://siteadi/cron.php?key=CRON_KEY` adresini 10 dakikada bir çağır. `.env` içinde `CRON_KEY` uzun bir şifre olmalı.
2. **Cron yoksa:** `LAZY_TICK=1` (varsayılan) iken site, ziyaretçi geldikçe cevabı gönderdikten sonra kendisi bir tur çalıştırır (`TICK_MINUTES` aralıkla). Ziyaretçi az ise güncelleme seyrek olur.

Her tur zaman bütçelidir (`TICK_BUDGET`, varsayılan: web 25 sn, komut satırı 240 sn): süre dolunca bırakır, sonraki tur devam eder. Barındırmanın süre sınırı daha kısaysa bu değeri düşür.

## Yayınlama

**A) Oracle Cloud sunucusu (en sağlam yol, ücretsiz):** `YAYINLAMA.md`'deki 1–3. adımları yap (hesap, sunucu, DuckDNS). Sonra sunucuda:

```
curl -fsSLO https://raw.githubusercontent.com/MehmetYagizAltunsoy/mehmet-yagiz-altunsoy/main/deploy/kurulum-php.sh
bash kurulum-php.sh fiyatavi.duckdns.org
sudo nano /opt/fiyatavi/php/.env      # anahtarları doldur
```

Kurulum PHP-FPM + Caddy (otomatik HTTPS) kurar ve 10 dakikada bir `cron.php` çalıştıran bir cron ekler. Güncelleme: `bash /opt/fiyatavi/deploy/guncelle-php.sh`.

**B) Ortak (cPanel / ücretsiz) PHP barındırma:** `php/` klasörünün **içindekileri** (klasörün kendisini değil) sitenin kök klasörüne (`htdocs` / `public_html`) yükle, `.env.example` dosyasını `.env` yapıp doldur. `.htaccess` Apache içindir (mod_rewrite açık olmalı). Önce `https://siteadi/api/health` adresini aç; `{"ok":true...}` görmelisin. Dikkat:

- Bazı ücretsiz barındırmalar dışarıya bağlantıyı (ITAD, Steam, SMTP) kısıtlar ya da robot isteklerini engeller; böyle bir yerde fiyatlar çekilemez. Önce `php bin/kontrol.php` mantığıyla deneyip yoksa A yolunu seç.
- Bu tür yerlerde `cron.php?key=...` isteği engellenebilir; o zaman `LAZY_TICK=1` ile kendi kendine çalışır.
- Veri tabanı `data/` içindedir; `.htaccess` dışarıdan erişimi kapatır. Nginx kullanıyorsan `inc/`, `data/`, `test/`, `bin/` ve `.env` için `deny all` kuralı eklemeyi unutma.

## Node sürümünden taşıma

`ganimet.db` dosyasını `php/data/` içine kopyala (site kapalıyken). Hesaplar, istek listeleri ve fiyat geçmişi olduğu gibi çalışır. **Eski şifreler de geçerlidir:** Node'un şifre biçimi (scrypt) PHP'de doğrulanır ve kullanıcı giriş yapınca otomatik olarak yeni biçime çevrilir. (Eski biçimle ilk giriş birkaç saniye sürebilir.)

## Node sürümüyle farklar

- Sürekli çalışan süreç yok: arka plan işleri cron ya da tembel tur ile çalışır (yukarıda).
- Bellek içi önbellekler ve deneme sayacı veri tabanına taşındı (`cache`, `attempts`, `queue` tabloları).
- Mail: Nodemailer yerine saf PHP SMTP istemcisi (`inc/mailer.php`). SMTP ayarı yoksa mailler `data/mail.log` dosyasına yazılır.
- Yönetim sayfasında "Node" alanı PHP sürümünü gösterir.
