# Fiyatavı

Mehmet Yağız Altunsoy — 254309058 — Tokat Gaziosmanpaşa Üniversitesi, Bilgisayar Teknolojileri

Türk oyuncular için PC oyun fiyat karşılaştırma sitesi. Steam, Epic Games Store, GOG, Microsoft Store ve diğer yetkili mağazalardaki fiyatları TL olarak karşılaştırır; bir oyunun nerede en ucuz olduğunu gösterir, istek listesindeki oyunların fiyatı düşünce kullanıcıya mail atar. Fiyatlar Türkiye mağazalarından TL olarak gösterilir; arayüz Türkçe açılır, İngilizce de seçilebilir.

Projenin **iki ayrı sunucu tarafı** vardır, ikisi de bu depoda bulunur ve aynı ön yüzü (tasarım, sayfalar, animasyonlar) kullanır:

- **`/` (kök dizin) — Node.js / Express sürümü:** Sitenin asıl, sürekli geliştirilen sürümü.
- **`/php` — PHP 8 sürümü:** Aynı sitenin PHP ile yazılmış, aynı veri tabanı şemasını kullanan karşılığı (paylaşımlı/ücretsiz PHP barındırma gibi Node çalıştıramayan ortamlarda kullanılmak üzere).

## Özellikler

- Türkiye mağazalarının gerçek fiyatları (TL)
- Steam'deki neredeyse tüm oyunlar + en popüler 1000 oyun; listede olmayan oyunlar aramayla eklenir
- Popüler oyunlarda indirimler ve indirimin bitiş tarihi, "Çok satanlar" vitrini ve fragmanlı "Günün oyunu" bandı
- Kategoriler (aksiyon, macera, bulmaca…), mağaza filtresi, sadece indirimdekiler
- Steam oyuncu puanları; çok satan, en çok favorilenen ve en yüksek puanlı sıralamaları
- Her oyunun paylaşılabilir adresi (ör. `/oyun/elden-ring`) ve "Linki kopyala" düğmesi
- "Şu an ücretsiz" oyunlar, benzer oyun önerileri, güncel kurla yaklaşık fiyat karşılıkları
- Fiyat geçmişi grafiği (3 ay / 6 ay / 1 yıl / tümü), dönemin ve tüm zamanların en düşük fiyatı
- "Şimdi al mı, bekle mi?" önerisi: fiyat geçmişine bakarak almak için iyi zaman olup olmadığını söyler
- En fazla 3 oyunu yan yana karşılaştırma (fiyat, en düşük fiyat, puan, öneri)
- Ana sayfada "Bu hafta biten indirimler" (geri sayımlı)
- Arama, A–Z filtresi, sıralama (popüler, ucuzdan pahalıya, en yüksek indirim, en yeni…)
- Üyelik sistemi (e-posta doğrulama, şifremi unuttum), istek listesi, istek listesine eklemede ve fiyat düşüşünde mail bildirimi
- Tasarımlı HTML mailler ve haftalık özet maili (istek listesindeki oyunların haftalık fiyat durumu)
- Yönetim sekmesi: kullanıcı, oyun, mail ve sistem istatistikleri (sadece yöneticiler görür)
- Hedef fiyat alarmı ("bu oyun X TL'ye inince haber ver") ve "tüm zamanların en düşüğü" rozeti
- Oyun sayfasında Steam'den sistem gereksinimleri
- KVKK aydınlatma metni, gizlilik politikası ve çerez bilgilendirmesi (taslak; yayına almadan önce hukukçuya kontrol ettirilmeli)
- Açılmayan (erişim engelli) mağazaları otomatik tespit edip gizleme
- Türkçe / İngilizce, koyu / açık mod, mobil uyumlu tasarım
- Ayarlar ve Yardım sayfaları, ilk girişte karşılama ekranı
- Sinematik tam sayfa oyun ekranı, fırsat rafları (100 TL altı, tarihi en düşük, 7 günde ucuzlayanlar), Ctrl+K hızlı arama, telefonda alt menü ve ana ekrana eklenebilir uygulama (PWA)

Oyun adları hiçbir dilde çevrilmez, her zaman orijinal hâliyle gösterilir.

## Kullanılan teknolojiler

| Bölüm | Node.js sürümü (`/`) | PHP sürümü (`/php`) |
|---|---|---|
| Sunucu | [Node.js](https://nodejs.org) 22.13+ ve [Express](https://expressjs.com) | [PHP](https://www.php.net) 8.1+ (çerçeve yok, saf PHP) |
| Veri tabanı | SQLite (Node'un yerleşik `node:sqlite` modülü) | SQLite (PDO) — **aynı şema**, iki sürüm aynı veri tabanı dosyasını okuyabilir |
| Mail | [Nodemailer](https://nodemailer.com) (SMTP) | Saf PHP SMTP istemcisi (`inc/mailer.php`) |
| Ayarlar | [dotenv](https://github.com/motdotla/dotenv) | `.env` dosyasını kendi okuyucusu okur (`inc/config.php`) |
| Arka plan işleri (fiyat güncelleme, mail) | Sürekli çalışan süreç, zamanlayıcıyla kendisi tetikler | PHP istek bitince kapandığı için **cron** (`cron.php`) ya da "tembel tur" (ziyaretçi geldikçe) ile tetiklenir |
| Arayüz | Saf HTML, CSS ve JavaScript (çerçeve yok), tek dosya: `public/index.html` | Aynı arayüzün kopyası: `php/app.html` |
| Yazı tipleri | Chakra Petch ve Rubik (Google Fonts) | Aynı |
| Şifreleme | scrypt (Node `crypto`), HttpOnly oturum çerezi | Aynı özet biçimi okunur/doğrulanır (eski Node şifreleri de çalışır), HttpOnly oturum çerezi |
| Fiyat verisi | [IsThereAnyDeal API](https://docs.isthereanydeal.com/) | Aynı |
| Oyun listesi, kapaklar, puanlar, fragmanlar | [Steam Web API](https://steamcommunity.com/dev) ve Steam mağazası | Aynı |
| Kategoriler ve tahmini satışlar | [SteamSpy](https://steamspy.com/api.php) | Aynı |

İki sürüm de aynı API adreslerini (`/api/...`) ve aynı JSON cevaplarını kullanır; ön yüz hangi sürücüde çalıştığını bilmeden ikisiyle de konuşabilir.

## Kurulum — Node.js sürümü (`/`)

1. [Node.js](https://nodejs.org) (LTS sürümü) kur.
2. [IsThereAnyDeal](https://isthereanydeal.com/apps/my/) üzerinden ücretsiz bir API anahtarı al.
3. İsteğe bağlı: Steam'deki tüm oyunlar için [Steam Web API anahtarı](https://steamcommunity.com/dev/apikey) al.
4. Proje klasöründe:

```bash
npm install
npm run kurulum   # anahtarları ve site bilgilerini sorar, .env dosyasını oluşturur
npm start         # http://localhost:3000
```

### Komutlar (Node.js)

| Komut | Ne yapar |
|---|---|
| `npm start` | Siteyi başlatır, fiyatları düzenli olarak günceller |
| `npm run kurulum` | API anahtarlarını ve site ayarlarını girer / değiştirir |
| `npm run kontrol` | Kaç oyunun fiyatı olduğunu, mağazaları ve gizlenen mağazaları gösterir |
| `npm run sync` | Fiyatları elle bir kez günceller |
| `npm test` | Otomatik testleri çalıştırır (güvenlik, fiyat bildirimleri, haftalık özet, veri tabanı şeması) |
| `npm run mailtest` | Mail ayarlarını dener, kendine örnek bir mail gönderir |

Gizli bilgiler `.env` dosyasında durur ve GitHub'a yüklenmez (`.gitignore`). Örnek için kök dizindeki `.env.example` dosyasına bak.

## Kurulum — PHP sürümü (`/php`)

PHP 8.1 veya üstü gerekir (`pdo_sqlite`, `curl`, `mbstring`, `openssl` eklentileri açık olmalı).

```bash
cd php
cp .env.example .env        # içini doldur (ITAD_API_KEY en önemlisi)
php -S localhost:8000 router.php
```

Tarayıcıda `http://localhost:8000`. İlk açılışta oyun listesi boştur; arka plan işini bir kez elle çalıştır: `php cron.php`. Fiyatlar birkaç turda dolar. Testler: `php test/run.php`. Ayrıntılı kurulum, XAMPP/Apache ile çalıştırma, gerçek sunucuya (Oracle Cloud veya paylaşımlı PHP barındırma) yayınlama adımları için: [php/KURULUM.md](php/KURULUM.md).

Gizli bilgiler `php/.env` dosyasında durur ve GitHub'a yüklenmez. Örnek için `php/.env.example` dosyasına bak.

## Ayarlar (`.env`)

| Ayar | Açıklama |
|---|---|
| `ITAD_API_KEY` | IsThereAnyDeal API anahtarı (zorunlu) |
| `STEAM_API_KEY` | Steam Web API anahtarı (isteğe bağlı) |
| `ITAD_RATE_LIMIT` | IsThereAnyDeal'ın 5 dakikalık istek limiti |
| `CATALOG_SIZE` | Sitedeki popüler oyun sayısı (en fazla 1000) |
| `SYNC_MINUTES` / `TAIL_REFRESH_HOURS` | Popüler / diğer oyunların fiyat güncelleme sıklığı |
| `SITE_NAME`, `SITE_URL` | Site adı ve adresi (maillerde kullanılır) |
| `SUPPORT_EMAIL`, `SUPPORT_INSTAGRAM` | Yardım sayfasındaki iletişim bilgileri |
| `CONTROLLER_NAME` | KVKK metinlerindeki veri sorumlusu |
| `SMTP_*`, `MAIL_FROM` | Mail gönderimi (boşsa mailler terminale/kayıt dosyasına yazılır) |
| `SHOPS_HIDE` | Sitede gösterilmeyecek mağazalar (virgülle) |
| `ADMIN_EMAILS` | Yönetim sekmesini görebilecek e-postalar (virgülle; boşsa `SUPPORT_EMAIL`) |
| `CRON_KEY`, `LAZY_TICK`, `TICK_MINUTES`, `TICK_BUDGET` | **Sadece PHP sürümü:** arka plan işlerinin nasıl tetikleneceği |

## Proje yapısı

```
fiyatavi/
├── public/index.html   Node.js sürümünün arayüzü (HTML + CSS + JavaScript)
├── src/                Node.js sunucusu
│   ├── server.js       Sunucu ve API uç noktaları
│   ├── sync.js         Oyun, fiyat, geçmiş ve kapak güncellemeleri
│   ├── itad.js         IsThereAnyDeal API istemcisi (istek limiti dahil)
│   ├── steam.js        Steam oyun listesi
│   ├── shops.js        Mağaza erişim kontrolü
│   ├── steamspy.js     Kategoriler ve tahmini satışlar
│   ├── steamstore.js   Steam puanları ve fragmanlar
│   ├── rates.js        Güncel döviz kurları
│   ├── db.js           Veri tabanı şeması
│   ├── auth.js         Giriş sistemi ve oturumlar
│   ├── notify.js       İstek listesi ve fiyat düşüş bildirimleri
│   ├── mailer.js       Mail gönderimi
│   ├── accountmail.js  E-posta doğrulama ve şifre sıfırlama mailleri
│   ├── mailtemplate.js HTML mail şablonu
│   ├── mailtest.js     Mail ayarı denemesi
│   ├── setup.js        Kurulum sihirbazı
│   └── kontrol.js      Durum raporu
├── php/                 PHP 8 sürümü (bağımsız, Node sürümüyle aynı şemayı kullanır)
│   ├── index.php        Ön denetleyici: bütün istekler buraya gelir
│   ├── app.html          Arayüz (public/index.html'in kopyası)
│   ├── inc/              API uç noktaları, veri tabanı, giriş sistemi, mail, fiyat/oyun senkronizasyonu
│   ├── cron.php          Arka plan işini elle / zamanlanmış çalıştırma
│   ├── test/             Testler (`php test/run.php`)
│   ├── bin/               Komut satırı araçları (mailtest, kontrol)
│   ├── data/              Veri tabanı ve yedekler (web'den erişime kapalı, GitHub'a yüklenmez)
│   └── KURULUM.md         PHP sürümüne özel kurulum / yayınlama rehberi
├── test/               Node.js otomatik testleri (npm test)
├── veritabani/         ER diyagramına uygun SQL şeması (fiyatavi.sql) ve şema görseli
├── games.json          Sitede her zaman bulunacak oyunlar
├── .env.example        Node.js sürümü için örnek ayar dosyası
└── php/.env.example    PHP sürümü için örnek ayar dosyası
```

## Veri kaynakları

Fiyat, oyun bilgisi, kapak, puan ve fragman verileri [IsThereAnyDeal](https://isthereanydeal.com), Steam ve [SteamSpy](https://steamspy.com) üzerinden alınır. Oyun adları, kapak görselleri ve markalar sahiplerine aittir. Bu proje Valve, IsThereAnyDeal veya adı geçen mağazalarla bağlantılı değildir.

## Yayına alma

Siteyi ücretsiz bir sunucuda (Oracle Cloud Always Free) HTTPS ile yayınlamak için adım adım rehber: [YAYINLAMA.md](YAYINLAMA.md). Kurulum ve güncelleme betikleri `deploy/` klasöründe.
