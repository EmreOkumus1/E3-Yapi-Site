<?php
/**
 * E3 Yapı — iletişim formu alıcısı.
 *
 * Formdan gelen mesajı doğrular ve firmanın e-posta adreslerine yollar.
 * Harici bir servise (Supabase, Resend vb.) ihtiyaç duymaz.
 *
 * NEDEN mail() KULLANMIYOR:
 * Hosting, spam önlemi olarak PHP'nin mail() fonksiyonunu disable_functions ile
 * kapatmış. Ancak sunucunun kendi Exim servisi localhost:25'te açık ve alıcılar
 * aynı sunucudaki yerel kutular olduğu için kimlik doğrulama istemiyor.
 * Bu yüzden SMTP konuşmasını doğrudan burada yapıyoruz — şifre saklamaya gerek yok.
 *
 * Doğrulama kuralları src/lib/contact.ts ile birebir aynıdır — istemci
 * kontrolü atlanabileceği için asıl kontrol burada yapılır.
 *
 * PHP 7.4 ve 8.x ile uyumludur. mbstring eklentisi yoksa da çalışır —
 * sunucu 8.2'ye yükseltildiğinde bu eklenti kapalı geldiği için yedek
 * uygulamalar eklendi (bkz. metinUzunlugu / kucukHarf).
 */

declare(strict_types=1);

// Hatalar asla ziyaretçiye gösterilmesin — sunucu yolunu ve kod yapısını sızdırır.
// Sadece sunucu günlüğüne yazılır.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Beklenmeyen ölümcül hatada bile HTML değil, düzgün JSON dönsün.
register_shutdown_function(function (): void {
    $son = error_get_last();
    if ($son !== null && in_array($son['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'server_error']);
    }
});

// ---- Ayarlar ---------------------------------------------------------

/**
 * Mesajların gideceği adres(ler).
 * info@ kurumsal arşiv, emre.okumus@ fiilen takip edilen kutu.
 * Hepsi @e3yapi.com.tr olmalı — yerel teslimat şifresiz çalışır.
 */
const ALICILAR = ['info@e3yapi.com.tr', 'emre.okumus@e3yapi.com.tr'];

/** Gönderen adres — kendi alan adınıza ait olmalı, yoksa mailler spam'e düşer. */
const GONDEREN_ADRES = 'info@e3yapi.com.tr';
const GONDEREN_ISIM  = 'E3 Yapi Web Sitesi';

/** Sunucunun kendi mail servisi. */
const SMTP_SUNUCU = 'localhost';
const SMTP_PORT   = 25;
const SMTP_ZAMAN_ASIMI = 12;

/** Aynı IP'den bu süre içinde yalnızca bir mesaj kabul edilir (saniye). */
const BEKLEME_SANIYE = 60;

/** Aynı IP'den 1 saat içindeki üst sınır. */
const SAATLIK_LIMIT = 5;

// ---- Yardımcılar -----------------------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function cevap(int $kod, array $govde)
{
    http_response_code($kod);
    echo json_encode($govde, JSON_UNESCAPED_UNICODE);
    exit;
}

function hata(string $mesaj, int $kod = 400)
{
    cevap($kod, ['ok' => false, 'error' => $mesaj]);
}

/** RFC 2047 — Türkçe karakterli başlık düzgün görünsün. */
function basligiKodla(string $metin): string
{
    return '=?UTF-8?B?' . base64_encode($metin) . '?=';
}

/**
 * mbstring her sunucuda açık değil (PHP 8.2'ye geçişte kapalı geldi).
 * Türkçe karakterler UTF-8'de 2 bayt tuttuğu için strlen() yanlış sayar;
 * eklenti yoksa regex ile karakter sayıyoruz.
 */
function metinUzunlugu(string $metin): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($metin, 'UTF-8');
    }
    $sayi = preg_match_all('/./us', $metin);
    return $sayi === false ? strlen($metin) : $sayi;
}

/** E-posta adresleri ASCII olduğu için strtolower yedek olarak yeterli. */
function kucukHarf(string $metin): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($metin, 'UTF-8') : strtolower($metin);
}

function istemciIp(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $anahtar) {
        if (!empty($_SERVER[$anahtar])) {
            $ip = trim(explode(',', (string) $_SERVER[$anahtar])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/** Kayıt dosyaları public_html dışında tutulur, web'den erişilemez. */
function veriKlasoru(): string
{
    $tercih = dirname(__DIR__) . '/e3-iletisim';
    if (is_dir($tercih) || @mkdir($tercih, 0700, true)) {
        return $tercih;
    }
    return sys_get_temp_dir();
}

// ---- Basit SMTP istemcisi -------------------------------------------

/**
 * Sunucudan yanıt okur. Çok satırlı yanıtlarda (250-...) son satırı bekler.
 * Beklenen kodla eşleşmezse false döner.
 */
function smtpBekle($baglanti, int $beklenenKod, string &$sonYanit = null): bool
{
    $sonYanit = '';
    while (($satir = fgets($baglanti, 1024)) !== false) {
        $sonYanit .= $satir;
        // "250 " (boşluklu) son satır, "250-" devamı var demek.
        if (strlen($satir) >= 4 && $satir[3] === ' ') {
            break;
        }
    }
    return (int) substr(ltrim($sonYanit), 0, 3) === $beklenenKod;
}

function smtpYaz($baglanti, string $komut): void
{
    fwrite($baglanti, $komut . "\r\n");
}

/**
 * Mesajı yerel SMTP sunucusuna teslim eder.
 * Başarısızlıkta hatanın hangi adımda olduğunu $neden'e yazar.
 */
function smtpGonder(array $alicilar, string $konu, string $govdeMetni, string $yanitAdresi, string &$neden = null): bool
{
    $baglanti = @fsockopen(SMTP_SUNUCU, SMTP_PORT, $errno, $errstr, SMTP_ZAMAN_ASIMI);
    if (!$baglanti) {
        $neden = "baglanti: $errno $errstr";
        return false;
    }
    stream_set_timeout($baglanti, SMTP_ZAMAN_ASIMI);

    $kapat = function () use ($baglanti) {
        @smtpYaz($baglanti, 'QUIT');
        @fclose($baglanti);
    };

    if (!smtpBekle($baglanti, 220, $yanit)) { $neden = 'karsilama: ' . trim((string) $yanit); $kapat(); return false; }

    smtpYaz($baglanti, 'EHLO e3yapi.com.tr');
    if (!smtpBekle($baglanti, 250, $yanit)) {
        // Bazı sunucular yalnızca HELO kabul eder.
        smtpYaz($baglanti, 'HELO e3yapi.com.tr');
        if (!smtpBekle($baglanti, 250, $yanit)) { $neden = 'ehlo: ' . trim((string) $yanit); $kapat(); return false; }
    }

    smtpYaz($baglanti, 'MAIL FROM:<' . GONDEREN_ADRES . '>');
    if (!smtpBekle($baglanti, 250, $yanit)) { $neden = 'mail from: ' . trim((string) $yanit); $kapat(); return false; }

    $kabulEdilen = 0;
    foreach ($alicilar as $alici) {
        smtpYaz($baglanti, 'RCPT TO:<' . $alici . '>');
        if (smtpBekle($baglanti, 250, $yanit)) {
            $kabulEdilen++;
        } else {
            error_log('[e3yapi] alici reddedildi: ' . $alici . ' - ' . trim((string) $yanit));
        }
    }
    if ($kabulEdilen === 0) { $neden = 'rcpt to: ' . trim((string) $yanit); $kapat(); return false; }

    smtpYaz($baglanti, 'DATA');
    if (!smtpBekle($baglanti, 354, $yanit)) { $neden = 'data: ' . trim((string) $yanit); $kapat(); return false; }

    // Gövdeyi base64 ile yolluyoruz: satır uzunluğu, nokta kaçışı ve UTF-8
    // sorunlarının tamamı böylece ortadan kalkıyor.
    $basliklar = [
        'Date: ' . date('r'),
        'From: ' . basligiKodla(GONDEREN_ISIM) . ' <' . GONDEREN_ADRES . '>',
        'To: ' . implode(', ', $alicilar),
        'Reply-To: ' . $yanitAdresi,
        'Subject: ' . basligiKodla($konu),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@e3yapi.com.tr>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: e3yapi-form',
    ];

    fwrite($baglanti, implode("\r\n", $basliklar) . "\r\n\r\n");
    fwrite($baglanti, chunk_split(base64_encode($govdeMetni), 76, "\r\n"));
    fwrite($baglanti, "\r\n.\r\n");

    if (!smtpBekle($baglanti, 250, $yanit)) { $neden = 'teslim: ' . trim((string) $yanit); $kapat(); return false; }

    $kapat();
    return true;
}

// ---- İstek kontrolü --------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    hata('method_not_allowed', 405);
}

$ham = file_get_contents('php://input');
$veri = json_decode((string) $ham, true);
if (!is_array($veri)) {
    $veri = $_POST;
}

$al = function (string $anahtar) use ($veri): string {
    return trim((string) ($veri[$anahtar] ?? ''));
};

$ad      = $al('name');
$sirket  = $al('company');
$eposta  = kucukHarf($al('email'));
$telefon = $al('phone');
$mesaj   = $al('message');
$balKupu = $al('website');

// Bal küpü doluysa bot demektir. Başarılı gibi cevap ver, hiçbir şey yapma.
if ($balKupu !== '') {
    cevap(200, ['ok' => true]);
}

// ---- Doğrulama (src/lib/contact.ts ile aynı kurallar) ----------------

$adUzunluk = metinUzunlugu($ad);
if ($adUzunluk < 3 || $adUzunluk > 120) {
    hata('name');
}
if (metinUzunlugu($sirket) > 160) {
    hata('company');
}
if (metinUzunlugu($eposta) > 254 || !filter_var($eposta, FILTER_VALIDATE_EMAIL)) {
    hata('email');
}
if ($telefon !== '' && !preg_match('/^[0-9+()\s.\-]{7,32}$/u', $telefon)) {
    hata('phone');
}

$mesajUzunluk = metinUzunlugu($mesaj);
if ($mesajUzunluk < 20) {
    hata('message');
}
if ($mesajUzunluk > 4000) {
    hata('too_long');
}

// Başlık enjeksiyonu: satır sonu içeren alanlar başlıklara girmemeli.
if (preg_match('/[\r\n]/', $ad . $eposta . $sirket . $telefon)) {
    hata('invalid');
}

// ---- Hız sınırı ------------------------------------------------------

$ip = istemciIp();
$kayitDosyasi = veriKlasoru() . '/hiz-' . md5($ip) . '.txt';
$simdi = time();
$gecmis = [];

if (is_readable($kayitDosyasi)) {
    $gecmis = array_values(array_filter(
        array_map('intval', explode(',', (string) file_get_contents($kayitDosyasi))),
        function (int $zaman) use ($simdi): bool {
            return $zaman > $simdi - 3600;
        }
    ));
}

if ($gecmis !== [] && $simdi - max($gecmis) < BEKLEME_SANIYE) {
    hata('throttle', 429);
}
if (count($gecmis) >= SAATLIK_LIMIT) {
    hata('throttle', 429);
}

$gecmis[] = $simdi;
@file_put_contents($kayitDosyasi, implode(',', $gecmis), LOCK_EX);

// ---- Mesajı oluştur --------------------------------------------------

$tarih = date('d.m.Y H:i');

$govde = "Web sitesinden yeni iletisim formu mesaji\n"
    . str_repeat('=', 44) . "\n\n"
    . "Ad Soyad : {$ad}\n"
    . ($sirket !== '' ? "Sirket   : {$sirket}\n" : '')
    . "E-posta  : {$eposta}\n"
    . ($telefon !== '' ? "Telefon  : {$telefon}\n" : '')
    . "Tarih    : {$tarih}\n"
    . "IP       : {$ip}\n\n"
    . str_repeat('-', 44) . "\n"
    . "MESAJ\n"
    . str_repeat('-', 44) . "\n\n"
    . $mesaj . "\n\n"
    . str_repeat('=', 44) . "\n"
    . "Bu mesaji yanitlarsaniz dogrudan gonderene ulasir.\n";

$konu = 'E3 Yapi - ' . $ad . ($sirket !== '' ? " ({$sirket})" : '') . ' form mesaji';

$neden = '';
$gonderildi = smtpGonder(ALICILAR, $konu, $govde, $eposta, $neden);

// mail() açıksa (hosting ayarı değişirse) yedek yol olarak dursun.
if (!$gonderildi && function_exists('mail')) {
    $yedekBasliklar = "From: " . basligiKodla(GONDEREN_ISIM) . " <" . GONDEREN_ADRES . ">\r\n"
        . "Reply-To: {$eposta}\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8";
    $gonderildi = @mail(implode(',', ALICILAR), basligiKodla($konu), $govde, $yedekBasliklar);
}

// Mail gitmese bile talep kaybolmasın — sunucuda bir kopya tutuluyor.
@file_put_contents(
    veriKlasoru() . '/mesajlar.jsonl',
    json_encode([
        'tarih' => $tarih,
        'ad' => $ad,
        'sirket' => $sirket,
        'eposta' => $eposta,
        'telefon' => $telefon,
        'mesaj' => $mesaj,
        'ip' => $ip,
        'mail_gonderildi' => $gonderildi,
        'hata' => $gonderildi ? null : $neden,
    ], JSON_UNESCAPED_UNICODE) . "\n",
    FILE_APPEND | LOCK_EX
);

if (!$gonderildi) {
    error_log('[e3yapi] SMTP teslim edilemedi: ' . $neden);
    hata('mail_failed', 500);
}

cevap(200, ['ok' => true]);
