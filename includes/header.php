<?php
// Halaman pemanggil harus sudah require bootstrap.php dan require_login() sebelum file ini.
$u = current_user();
$currentPage = basename($_SERVER['SCRIPT_NAME']);

// Peta halaman -> label, untuk breadcrumb otomatis di topbar.
$pageLabels = ['index.php' => 'Dashboard'];
foreach (MODULES as $key => $label) {
    $file = $key === 'barang_masuk_keluar' ? 'transaksi.php' : ($key === 'kelola_permintaan' ? 'kelola_permintaan.php' : $key . '.php');
    $pageLabels[$file] = $label;
}
$pageLabels['kelola_admin.php'] = 'Kelola Admin';
$pageLabels['aktivitas.php'] = 'Riwayat Aktivitas';
$pageLabels['kelola_bidang.php'] = 'Kelola Bidang';
$pageLabels['analitik.php'] = 'Dashboard Analitik';
$pageLabels['katalog.php'] = 'Katalog Barang';
$pageLabels['permintaan.php'] = 'Permintaan ATK';
$pageLabels['progres.php'] = 'Progres ATK';
$pageLabels['input_bast.php'] = 'Input BAST';
$pageLabels['riwayat_ambil.php'] = 'Riwayat Pengambilan';
$breadcrumbLabel = $pageLabels[$currentPage] ?? null;

// Jumlah kiriman (oper) baru dari admin untuk akun bidang -> lencana merah di menu.
require_once __DIR__ . '/riwayat_helper.php';
$pendingReq = 0;
if (!is_bidang() && has_permission('kelola_permintaan')) {
    try { $pendingReq = (int)($GLOBALS['pdo']->query("SELECT COUNT(*) FROM permintaan WHERE status = 'menunggu'")->fetchColumn()); } catch (Throwable $e) { $pendingReq = 0; }
}
$operBaru = is_bidang() ? oper_belum_dibaca($GLOBALS['pdo'] ?? null, (int)($u['id'] ?? 0)) : 0;

/** Buang akhiran .php dari URL internal, biar link tampil bersih (clean URL via .htaccess). */
function clean_url($file) {
    return preg_replace('/\.php(\?|$)/', '$1', $file);
}

function nav_item($file, $label, $iconName, $badge = 0) {
    global $currentPage;
    $active = $currentPage === $file ? 'active' : '';
    echo '<a class="nav-item ' . $active . '" href="' . e(clean_url($file)) . '">';
    echo '<span class="nav-ic">' . icon($iconName, 17) . '</span>';
    echo '<span class="lbl">' . e($label) . '</span>';
    if ($badge > 0) echo '<span class="nav-badge" data-live-badge style="margin-left:auto;background:#DC2626;color:#fff;border-radius:20px;font-size:10.5px;font-weight:700;padding:1px 7px;">' . (int)$badge . '</span>';
    echo '</a>';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pendataan ATK</title>
<script>
// Terapkan tema tersimpan sebelum halaman digambar (mencegah kedipan putih saat tema gelap).
(function(){try{var t=localStorage.getItem('atk-theme');if(t==='dark'){document.documentElement.setAttribute('data-theme','dark');}}catch(e){}})();
</script>
<link rel="icon" type="image/png" href="assets/1.png?v=<?= @filemtime(dirname(__DIR__).'/assets/1.png') ?: time() ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(dirname(__DIR__) . '/assets/style.css') ?: time() ?>">
</head>
<body class="<?= is_bidang() ? 'is-bidang' : '' ?>">
<div class="app">
  <div class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-row">
        <img src="assets/1.png?v=<?= @filemtime(dirname(__DIR__).'/assets/1.png') ?: time() ?>" alt="Logo" class="brand-mark">
        <div>
          <h2>Pendataan ATK</h2>
          <div class="eyebrow">Sistem Inventaris</div>
        </div>
      </div>
    </div>

    <div class="nav">
      <?php if (is_bidang()): ?>
        <div class="nav-group">
          <div class="nav-group-label">Menu Bidang</div>
          <?php nav_item('katalog.php', 'Katalog Barang', 'box'); ?>
          <?php nav_item('permintaan.php', 'Permintaan ATK', 'clipboard'); ?>
          <?php nav_item('progres.php', 'Progres ATK', 'gauge'); ?>
          <?php nav_item('riwayat_ambil.php', 'Riwayat Pengambilan', 'clock', $operBaru); ?>
          <?php nav_item('input_bast.php', 'Input BAST', 'file'); ?>
        </div>
      <?php else: ?>
        <div class="nav-group">
          <div class="nav-group-label">Menu Utama</div>
          <?php nav_item('index.php', 'Dashboard', 'grid'); ?>
          <?php nav_item('analitik.php', 'Dashboard Analitik', 'gauge'); ?>
        </div>

        <?php if (has_permission('barang_masuk_keluar') || has_permission('barcode') || has_permission('data_barang') || has_permission('bast') || has_permission('realisasi') || has_permission('harga') || has_permission('bidang') || has_permission('kelola_permintaan') || has_permission('penitipan') || has_permission('kirim_notifikasi') || has_permission('opname')): ?>
        <div class="nav-group">
          <div class="nav-group-label">Data ATK</div>
          <?php if (has_permission('barang_masuk_keluar')): nav_item('transaksi.php', MODULES['barang_masuk_keluar'], MODULE_META['barang_masuk_keluar']['icon']); endif; ?>
          <?php if (has_permission('barcode')): nav_item('barcode.php', MODULES['barcode'], MODULE_META['barcode']['icon']); endif; ?>
          <?php if (has_permission('data_barang')): nav_item('data_barang.php', MODULES['data_barang'], MODULE_META['data_barang']['icon']); endif; ?>
          <?php if (has_permission('bast')): nav_item('bast.php', MODULES['bast'], MODULE_META['bast']['icon']); endif; ?>
          <?php if (has_permission('realisasi')): nav_item('realisasi.php', MODULES['realisasi'], MODULE_META['realisasi']['icon']); endif; ?>
          <?php if (has_permission('harga')): nav_item('harga.php', MODULES['harga'], MODULE_META['harga']['icon']); endif; ?>
          <?php if (has_permission('bidang')): nav_item('bidang.php', MODULES['bidang'], MODULE_META['bidang']['icon']); endif; ?>
          <?php if (has_permission('kelola_permintaan')): nav_item('kelola_permintaan.php', MODULES['kelola_permintaan'], MODULE_META['kelola_permintaan']['icon'], $pendingReq); endif; ?>
          <?php if (has_permission('penitipan')): nav_item('penitipan.php', MODULES['penitipan'], MODULE_META['penitipan']['icon']); endif; ?>
          <?php if (has_permission('kirim_notifikasi')): nav_item('kirim_notifikasi.php', MODULES['kirim_notifikasi'], MODULE_META['kirim_notifikasi']['icon']); endif; ?>
          <?php if (has_permission('opname')): nav_item('opname.php', MODULES['opname'], MODULE_META['opname']['icon']); endif; ?>
        </div>
        <?php endif; ?>

        <?php if (is_super()): ?>
        <div class="nav-group">
          <div class="nav-group-label">Administrasi</div>
          <?php nav_item('aktivitas.php', 'Riwayat Aktivitas', 'clock'); ?>
          <?php nav_item('kelola_admin.php', 'Kelola Admin', ADMIN_META['kelola_admin']['icon']); ?>
          <?php nav_item('kelola_bidang.php', 'Kelola Bidang', ADMIN_META['kelola_bidang']['icon']); ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

  </div>

  <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

  <div class="content-col">
    <div class="topbar">
      <button class="icon-btn-ghost" id="sidebar-toggle" type="button" title="Lipat/lebarkan sidebar"><?= icon('sidebar', 18) ?></button>
      <h1 class="page-title"><?= e($breadcrumbLabel ?: 'Pendataan ATK') ?></h1>
      <div class="topbar-right">
        <button class="icon-btn-ghost theme-btn" id="theme-btn" type="button" title="Ganti tema terang/gelap" aria-label="Ganti tema terang/gelap">
          <span class="ic-sun"><?= icon('sun', 18) ?></span><span class="ic-moon"><?= icon('moon', 18) ?></span>
        </button>
      <?php if (!is_bidang()): ?>
        <div class="notif-wrap" id="notif-wrap">
          <button class="icon-btn-ghost notif-bell" id="notif-bell" type="button" title="Notifikasi">
            <?= icon('bell', 18) ?>
            <span class="notif-dot" id="notif-dot" hidden></span>
          </button>
          <div class="notif-panel" id="notif-panel">
            <div class="notif-panel-head">
              <span>Notifikasi</span>
              <button type="button" id="notif-mark-all">Tandai semua dibaca</button>
            </div>
            <div class="notif-list" id="notif-list">
              <div class="notif-empty">Memuat…</div>
            </div>
          </div>
        </div>
        <style>
          .notif-wrap{position:relative;}
          .notif-bell{position:relative;}
          .notif-dot{position:absolute;top:6px;right:6px;width:8px;height:8px;border-radius:50%;background:#f43f5e;box-shadow:0 0 0 2px var(--paper-card);}
          .notif-panel{display:none;position:absolute;top:calc(100% + 6px);right:0;width:340px;max-height:400px;overflow-y:auto;background:var(--paper-card);border:1px solid var(--line);border-radius:12px;box-shadow:0 12px 28px rgba(16,24,40,.16);z-index:50;}
          .notif-panel.show{display:block;}
          .notif-panel-head{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid var(--line);font-size:12.5px;font-weight:600;}
          .notif-panel-head button{background:none;border:none;color:var(--accent);font-size:11.5px;cursor:pointer;padding:0;}
          .notif-panel-head button:hover{text-decoration:underline;}
          .notif-item{display:block;padding:10px 12px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit;cursor:pointer;}
          .notif-item:last-child{border-bottom:none;}
          .notif-item:hover{background:var(--paper-sunk);}
          .notif-item.unread{background:rgba(63,79,168,.06);}
          .notif-item .ni-title{font-weight:600;font-size:13px;display:flex;align-items:center;gap:6px;}
          .notif-item .ni-title .ni-dot{width:6px;height:6px;border-radius:50%;background:#f43f5e;flex-shrink:0;}
          .notif-item .ni-msg{font-size:12px;color:var(--text-dim);margin-top:3px;line-height:1.4;}
          .notif-item .ni-time{font-size:10.5px;color:var(--text-dim);margin-top:4px;}
          .notif-empty{padding:20px 12px;text-align:center;font-size:12.5px;color:var(--text-dim);}
        </style>
        <div class="search-pill" id="global-search-wrap">
          <?= icon('search', 15) ?>
          <input type="text" id="global-search" placeholder="Cari barang, kode, atau bidang…" autocomplete="off">
          <div id="global-search-results" class="gs-results"></div>
        </div>
        <style>
          #global-search-wrap{position:relative;}
          .gs-results{display:none;position:absolute;top:calc(100% + 6px);right:0;width:320px;max-height:360px;overflow-y:auto;background:var(--paper-card);border:1px solid var(--line);border-radius:12px;box-shadow:0 12px 28px rgba(16,24,40,.16);z-index:50;padding:6px;}
          .gs-results.show{display:block;}
          .gs-group-label{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--text-dim);padding:6px 8px 2px;}
          .gs-item{display:flex;flex-direction:column;gap:2px;padding:8px;border-radius:6px;text-decoration:none;color:inherit;}
          .gs-item:hover{background:var(--paper-sunk);}
          .gs-item .gs-title{font-weight:600;font-size:13.5px;}
          .gs-item .gs-sub{font-size:12px;color:var(--text-dim);}
          .gs-empty{padding:10px 8px;font-size:12.5px;color:var(--text-dim);}
        </style>
      <?php endif; ?>
        <details class="userchip userchip-top" id="userchip-top">
          <summary title="<?= e($u['username']) ?>"><span class="avatar"><?= e(mb_strtoupper(mb_substr($u['username'], 0, 1))) ?></span></summary>
          <div class="uc-panel">
            <div class="uc-who">
              <b><?= e($u['username']) ?></b>
              <span><?= is_super() ? 'Super Admin' : (is_bidang() ? 'Admin Bidang' : 'Admin Biasa') ?></span>
            </div>
            <a class="uc-link danger" href="<?= clean_url('logout.php') ?>"><?= icon('logout', 15) ?> Keluar</a>
          </div>
        </details>
      </div>
    </div>

    <input type="hidden" id="globalCsrfToken" value="<?= e(csrf_token()) ?>">
    <script>
      (function(){
        var wrap = document.getElementById('notif-wrap');
        if (!wrap) return; // is_bidang() -> bel tidak dirender

        var bell = document.getElementById('notif-bell');
        var dot = document.getElementById('notif-dot');
        var panel = document.getElementById('notif-panel');
        var list = document.getElementById('notif-list');
        var markAllBtn = document.getElementById('notif-mark-all');
        var csrf = document.getElementById('globalCsrfToken').value;
        var loaded = false;

        function waktuRelatif(iso) {
          var detik = Math.floor((Date.now() - new Date(iso.replace(' ', 'T'))) / 1000);
          if (detik < 60) return 'baru saja';
          if (detik < 3600) return Math.floor(detik / 60) + ' menit lalu';
          if (detik < 86400) return Math.floor(detik / 3600) + ' jam lalu';
          return Math.floor(detik / 86400) + ' hari lalu';
        }

        function renderList(items) {
          if (!items.length) { list.innerHTML = '<div class="notif-empty">Belum ada notifikasi.</div>'; return; }
          list.innerHTML = items.map(function (n) {
            return '<div class="notif-item ' + (n.dibaca ? '' : 'unread') + '" data-id="' + n.id + '">' +
              '<div class="ni-title">' + (n.dibaca ? '' : '<span class="ni-dot"></span>') + escapeHtml(n.judul) + '</div>' +
              '<div class="ni-msg">' + escapeHtml(n.pesan) + '</div>' +
              '<div class="ni-time">' + waktuRelatif(n.created_at) + '</div>' +
            '</div>';
          }).join('');
        }

        function escapeHtml(s) {
          var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML;
        }

        function updateDot(unreadCount) {
          dot.hidden = unreadCount <= 0;
        }

        function muatNotifikasi() {
          fetch('notifikasi?do=list').then(function (r) { return r.json(); }).then(function (data) {
            renderList(data.items || []);
            updateDot(data.unread || 0);
            loaded = true;
          }).catch(function () {
            list.innerHTML = '<div class="notif-empty">Gagal memuat notifikasi.</div>';
          });
        }

        function cekJumlahSaja() {
          fetch('notifikasi?do=count').then(function (r) { return r.json(); }).then(function (data) {
            updateDot(data.unread || 0);
          }).catch(function () {});
        }

        bell.addEventListener('click', function (e) {
          e.stopPropagation();
          var show = !panel.classList.contains('show');
          panel.classList.toggle('show', show);
          if (show && !loaded) muatNotifikasi();
        });

        list.addEventListener('click', function (e) {
          var item = e.target.closest('.notif-item');
          if (!item || !item.classList.contains('unread')) return;
          var id = item.dataset.id;
          item.classList.remove('unread');
          var dotEl = item.querySelector('.ni-dot'); if (dotEl) dotEl.remove();
          var fd = new FormData(); fd.append('csrf', csrf); fd.append('id', id);
          fetch('notifikasi?do=mark_read', { method: 'POST', body: fd }).then(function () { cekJumlahSaja(); });
        });

        markAllBtn.addEventListener('click', function (e) {
          e.stopPropagation();
          document.querySelectorAll('.notif-item.unread').forEach(function (el) {
            el.classList.remove('unread');
            var dotEl = el.querySelector('.ni-dot'); if (dotEl) dotEl.remove();
          });
          var fd = new FormData(); fd.append('csrf', csrf);
          fetch('notifikasi?do=mark_all_read', { method: 'POST', body: fd }).then(function () { updateDot(0); });
        });

        document.addEventListener('click', function (e) {
          if (!wrap.contains(e.target)) panel.classList.remove('show');
        });

        cekJumlahSaja();
        setInterval(cekJumlahSaja, 60000); // cek jumlah belum-dibaca tiap 1 menit
      })();
    </script>


    <?php if (!is_bidang() && has_permission('kelola_permintaan')): ?>
    <div id="live-toasts" aria-live="polite"></div>
    <script>
    // Notifikasi waktu-nyata: permintaan ATK baru dari bidang muncul sebagai pop-up tanpa perlu muat ulang.
    (function(){
      var KEY = 'atk-live-last', judulAsli = document.title, pending = <?= (int)$pendingReq ?>;
      var navLink = document.querySelector('.nav-item[href*="kelola_permintaan"]');
      function esc(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
      function bunyi(){
        try {
          var C = window.AudioContext || window.webkitAudioContext; if (!C) return;
          var c = new C(), o = c.createOscillator(), g = c.createGain();
          o.type = 'sine'; o.frequency.setValueAtTime(880, c.currentTime); o.frequency.setValueAtTime(1175, c.currentTime + .12);
          g.gain.setValueAtTime(.0001, c.currentTime); g.gain.exponentialRampToValueAtTime(.15, c.currentTime + .02);
          g.gain.exponentialRampToValueAtTime(.0001, c.currentTime + .35);
          o.connect(g); g.connect(c.destination); o.start(); o.stop(c.currentTime + .4);
        } catch (e) {}
      }
      function setBadge(n){
        pending = n;
        if (navLink) {
          var b = navLink.querySelector('[data-live-badge]');
          if (n > 0) {
            if (!b) { b = document.createElement('span'); b.setAttribute('data-live-badge',''); b.className = 'nav-badge';
              b.style.cssText = 'margin-left:auto;background:#DC2626;color:#fff;border-radius:20px;font-size:10.5px;font-weight:700;padding:1px 7px;'; navLink.appendChild(b); }
            b.textContent = n;
          } else if (b) { b.remove(); }
        }
        document.title = (n > 0 ? '(' + n + ') ' : '') + judulAsli;
        var dot = document.getElementById('notif-dot'); if (dot && n > 0) dot.hidden = false;
      }
      function toast(r){
        var box = document.getElementById('live-toasts'), el = document.createElement('a');
        el.className = 'live-toast'; el.href = 'kelola_permintaan';
        el.innerHTML = '<span class="lt-ic">&#128230;</span><span class="lt-body"><b>Permintaan ATK baru</b><span>' + esc(r.detail) + '</span><em>baru saja &middot; ' + esc(r.username || '') + '</em></span><button type="button" aria-label="Tutup">&times;</button>';
        el.querySelector('button').addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); el.remove(); });
        box.appendChild(el);
        setTimeout(function(){ el.classList.add('out'); setTimeout(function(){ el.remove(); }, 350); }, 12000);
      }
      function cek(){
        if (document.hidden) return;
        var after = sessionStorage.getItem(KEY); if (after === null) after = '-1';
        fetch('notifikasi?do=live&after=' + encodeURIComponent(after), { cache: 'no-store' })
          .then(function(r){ return r.json(); })
          .then(function(d){
            if (typeof d.last === 'number') sessionStorage.setItem(KEY, String(d.last));
            (d.baru || []).forEach(toast);
            if ((d.baru || []).length) bunyi();
            if (typeof d.pending === 'number') setBadge(d.pending);
          }).catch(function(){});
      }
      setBadge(pending); cek();
      setInterval(cek, 5000);
      document.addEventListener('visibilitychange', function(){ if (!document.hidden) cek(); });
    })();
    </script>
    <?php endif; ?>

    <div class="main">
      <?php $flash = flash_get(); if ($flash): ?>
        <div class="flash <?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
      <?php endif; ?>
      <?php if (is_bidang() && $operBaru > 0 && $currentPage !== 'riwayat_ambil.php'): ?>
        <div class="flash ok">
          Ada <b><?= (int)$operBaru ?></b> kiriman barang baru dari admin ATK.
          <a href="<?= clean_url('riwayat_ambil.php') ?>" style="font-weight:700;text-decoration:underline;margin-left:6px;">Lihat &amp; buat BAST</a>
        </div>
      <?php endif; ?>