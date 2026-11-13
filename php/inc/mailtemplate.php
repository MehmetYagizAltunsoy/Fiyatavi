<?php
declare(strict_types=1);
/* HTML e-posta şablonu.
   Mail programları (Gmail, Outlook, telefon uygulamaları) modern CSS'i desteklemediği için
   düzen tablolarla, stiller de her etiketin içine (inline) yazılarak kurulur. */

const MAIL_C = [
    'bg' => '#F3F2FA', 'card' => '#FFFFFF', 'text' => '#1B1D36', 'muted' => '#5E6082', 'line' => '#E4E2F0',
    'accent' => '#FFC93C', 'accentText' => '#1B1D36', 'good' => '#0F8A8F', 'goodBg' => '#E3F5F5',
];
const MAIL_FONT = "'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

/** HTML için kaçış (JS'teki esc) */
function mailEsc($s): string { return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** JS'teki "doğru" (truthy) mantığı: null, '', 0, '0' yanlış sayılır */
function mailTruthy($v): bool { return !($v === null || $v === false || $v === '' || $v === 0 || $v === 0.0 || $v === '0'); }

// Oyun görseli: mailde yatay Steam başlık görseli en iyi durur; yoksa resmi kapak
function gameImage($g): ?string {
    if (!empty($g['appid'])) return 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/' . $g['appid'] . '/header.jpg';
    return !empty($g['boxart']) ? (string) $g['boxart'] : null;
}

function mailButton(string $href, string $label): string {
    $C = MAIL_C; $F = MAIL_FONT; $h = mailEsc($href); $l = mailEsc($label);
    return <<<HTML

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 4px">
    <tr><td style="border-radius:10px;background:{$C['accent']}">
      <a href="$h" style="display:inline-block;padding:13px 26px;font:600 16px $F;color:{$C['accentText']};text-decoration:none;border-radius:10px">$l</a>
    </td></tr>
  </table>
HTML;
}

// Fiyat kutusu: şimdiki fiyat, (varsa) eski fiyatın üstü çizili, indirim etiketi ve mağaza
function mailPriceBox(array $p): string {
    $C = MAIL_C; $F = MAIL_FONT;
    $old = mailTruthy($p['old'] ?? null) ? '<span style="font-size:15px;color:' . $C['muted'] . ';text-decoration:line-through;margin-left:8px">' . mailEsc($p['old']) . '</span>' : '';
    $cut = mailTruthy($p['cut'] ?? null) ? '<span style="display:inline-block;margin-left:8px;padding:3px 8px;border-radius:6px;background:' . $C['accent'] . ';color:' . $C['accentText'] . ';font-size:13px;font-weight:700">-%' . mailEsc($p['cut']) . '</span>' : '';
    $shop = mailTruthy($p['shop'] ?? null) ? '<div style="font-size:14px;color:' . $C['muted'] . ';margin-top:6px">' . mailEsc($p['shop']) . '</div>' : '';
    $extra = mailTruthy($p['extra'] ?? null) ? '<div style="font-size:14px;color:' . $C['good'] . ';background:' . $C['goodBg'] . ';border-radius:8px;padding:8px 10px;margin-top:10px">' . mailEsc($p['extra']) . '</div>' : '';
    $label = mailEsc($p['label'] ?? ''); $now = mailEsc($p['now'] ?? '');
    return <<<HTML

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{$C['bg']};border-radius:12px;margin:18px 0">
    <tr><td style="padding:16px 18px;font-family:$F">
      <div style="font-size:13px;color:{$C['muted']};margin-bottom:4px">$label</div>
      <div>
        <span style="font-size:28px;font-weight:700;color:{$C['text']}">$now</span>
        $old
        $cut
      </div>
      $shop
      $extra
    </td></tr>
  </table>
HTML;
}

// Haftalık özet: istek listesindeki her oyun için bir satır (küçük görsel, ad, fiyat, geçen haftaya göre değişim)
function gameRows(array $items): string {
    $C = MAIL_C; $F = MAIL_FONT;
    $rows = '';
    foreach ($items as $it) {
        $url = mailEsc($it['url'] ?? '');
        $img = mailTruthy($it['image'] ?? null)
            ? '<a href="' . $url . '"><img src="' . mailEsc($it['image']) . '" alt="" width="96" style="display:block;width:96px;height:auto;border-radius:6px;border:0"></a>'
            : '<div style="width:96px;height:45px;border-radius:6px;background:' . $C['bg'] . '"></div>';
        $price = mailTruthy($it['price'] ?? null) ? '<b>' . mailEsc($it['price']) . '</b>' : '<span style="color:' . $C['muted'] . '">' . mailEsc($it['noPrice'] ?? '') . '</span>';
        $cut = mailTruthy($it['cut'] ?? null) ? '<span style="display:inline-block;margin-left:6px;padding:1px 6px;border-radius:5px;background:' . $C['accent'] . ';font-size:12px;font-weight:700">-%' . mailEsc($it['cut']) . '</span>' : '';
        $atl = mailTruthy($it['atl'] ?? null) ? '<span style="display:inline-block;margin-left:6px;padding:1px 6px;border-radius:5px;background:' . $C['goodBg'] . ';color:' . $C['good'] . ';font-size:12px;font-weight:700">' . mailEsc($it['atl']) . '</span>' : '';
        $change = '';
        if (mailTruthy($it['change'] ?? null)) {
            $col = ($it['changeColor'] ?? '') === 'down' ? $C['good'] : (($it['changeColor'] ?? '') === 'up' ? '#C8324F' : $C['muted']);
            $change = '<div style="margin-top:3px;font-size:13px;color:' . $col . '">' . mailEsc($it['change']) . '</div>';
        }
        $title = mailEsc($it['title'] ?? '');
        $rows .= <<<HTML
<tr><td style="padding:10px 0;border-top:1px solid {$C['line']}">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
      <td width="96" style="padding-right:12px;vertical-align:top">$img</td>
      <td style="font-family:$F;vertical-align:top">
        <a href="$url" style="font-size:15px;font-weight:600;color:{$C['text']};text-decoration:none">$title</a>
        <div style="margin-top:4px;font-size:15px;color:{$C['text']}">
          $price
          $cut
          $atl
        </div>
        $change
      </td></tr></table>
  </td></tr>
HTML;
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:14px 0 18px">' . "\n  " . $rows . "\n  </table>";
}

/**
 * Ortak çerçeve.
 * $o: site, siteUrl (site adı ve adresi), preheader (gelen kutusunda konunun yanındaki önizleme), image, imageAlt, imageHref,
 *     kicker, title, paragraphs (dizi), price (mailPriceBox parametreleri), blocks (hazır HTML), cta ['href','label'],
 *     ctaHint, footer (dizi), lang
 */
function layout(array $o): string {
    $C = MAIL_C; $F = MAIL_FONT;
    $lang = mailEsc(mailTruthy($o['lang'] ?? null) ? $o['lang'] : 'tr');
    $title = mailEsc($o['title'] ?? '');
    $pre = mailEsc($o['preheader'] ?? '');
    $siteUrl = mailEsc($o['siteUrl'] ?? '');
    $site = mailEsc($o['site'] ?? '');
    $cta = $o['cta'] ?? null;

    $imageHtml = '';
    if (mailTruthy($o['image'] ?? null)) {
        // görsel bir bağlantı içinde: tıklanınca oyun açılır, Gmail de üstüne "indir" düğmesi koymaz
        $img = '<img src="' . mailEsc($o['image']) . '" alt="' . mailEsc($o['imageAlt'] ?? '') . '" width="560" style="display:block;width:100%;max-width:560px;height:auto;border:0">';
        $href = $o['imageHref'] ?? ($cta['href'] ?? null);
        $imageHtml = mailTruthy($href) ? '<a href="' . mailEsc($href) . '" style="display:block;text-decoration:none">' . $img . '</a>' : $img;
    }
    $kicker = mailTruthy($o['kicker'] ?? null) ? '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:' . $C['good'] . ';margin-bottom:6px">' . mailEsc($o['kicker']) . '</div>' : '';
    $paras = '';
    foreach (($o['paragraphs'] ?? []) as $p) $paras .= '<p style="margin:0 0 12px;font-size:15px;line-height:1.55;color:' . $C['text'] . '">' . mailEsc($p) . '</p>';
    $price = !empty($o['price']) ? mailPriceBox($o['price']) : '';
    $blocks = (string) ($o['blocks'] ?? '');
    $button = $cta ? mailButton((string) $cta['href'], (string) $cta['label']) : '';
    $hint = $cta ? '<p style="margin:14px 0 0;font-size:12px;color:' . $C['muted'] . ';word-break:break-all">' . mailEsc($o['ctaHint'] ?? '') . ' <a href="' . mailEsc($cta['href']) . '" style="color:' . $C['muted'] . '">' . mailEsc($cta['href']) . '</a></p>' : '';
    $footer = implode('<br>', array_map('mailEsc', $o['footer'] ?? []));

    return <<<HTML
<!doctype html>
<html lang="$lang"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title>$title</title></head>
<body style="margin:0;padding:0;background:{$C['bg']}">
<div style="display:none;max-height:0;overflow:hidden;opacity:0">$pre</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{$C['bg']}">
 <tr><td align="center" style="padding:24px 12px">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px">
   <tr><td style="padding:0 4px 14px;font:700 20px $F;color:{$C['text']}">
     <a href="$siteUrl" style="color:{$C['text']};text-decoration:none">
       <span style="display:inline-block;width:26px;height:26px;border-radius:7px;background:{$C['accent']};vertical-align:-6px;margin-right:8px;text-align:center;font-size:15px;line-height:26px">◎</span>$site
     </a>
   </td></tr>
   <tr><td style="background:{$C['card']};border-radius:16px;overflow:hidden;border:1px solid {$C['line']}">
     $imageHtml
     <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr><td style="padding:24px 26px 26px;font-family:$F;color:{$C['text']}">
        $kicker
        <h1 style="margin:0 0 10px;font-size:24px;line-height:1.25;font-weight:700;color:{$C['text']}">$title</h1>
        $paras
        $price
        $blocks
        $button
        $hint
      </td></tr>
     </table>
   </td></tr>
   <tr><td style="padding:16px 8px 0;font:12px/1.5 $F;color:{$C['muted']};text-align:center">
     $footer
   </td></tr>
  </table>
 </td></tr>
</table>
</body></html>
HTML;
}
