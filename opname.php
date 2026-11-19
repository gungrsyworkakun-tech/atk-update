<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('opname');

// Bahan Opname: daftar barang + saldo sistem, kolom "Hasil Opsik" dikosongkan untuk diisi saat hitung fisik.
$kodeUakpb = trim((string)($_GET['kode_uakpb'] ?? '137.03.2200.692909.000.KD'));
$uakpb     = trim((string)($_GET['uakpb'] ?? 'KANTOR IMIGRASI KELAS I KHUSUS TPI NGURAH RAI'));
$nomor     = trim((string)($_GET['nomor'] ?? ''));
$tglOpname = trim((string)($_GET['tgl_opname'] ?? ''));
$perHal    = max(10, min(60, (int)($_GET['per_hal'] ?? 30)));
$sembunyi0 = !empty($_GET['sembunyi0']);

$bulan = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$tglOpnameTampil = '';
if ($tglOpname !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglOpname)) {
    [$y, $m, $d] = explode('-', $tglOpname);
    $tglOpnameTampil = (int)$d . ' ' . $bulan[(int)$m - 1] . ' ' . $y;
}

$sql = "SELECT kode, nama, COALESCE(NULLIF(satuan,''), 'pcs') AS satuan, stok FROM items";
if ($sembunyi0) $sql .= ' WHERE stok > 0';
$sql .= ' ORDER BY kode ASC';
$items = $pdo->query($sql)->fetchAll();

$halaman = array_chunk($items, $perHal);
if (!$halaman) $halaman = [[]];
$totalHal = count($halaman);
$tglCetak = date('d/m/Y');
require __DIR__ . '/includes/header.php';
?>
<style>
  .op-stats { display:flex; gap:10px; flex-wrap:wrap; margin:0 0 14px; }
  .op-chip { background:var(--card, rgba(255,255,255,.04)); border:1px solid var(--border, rgba(255,255,255,.1)); border-radius:12px; padding:10px 16px; min-width:130px; }
  .op-chip b { display:block; font-size:20px; line-height:1.2; }
  .op-chip span { font-size:11.5px; color:var(--text-dim); }
  .op-form { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px 14px; align-items:end; }
  .op-form .field { margin:0; }
  .op-form .field label { font-size:11px; }
  .op-form .wide { grid-column:span 2; }
  .op-check { display:flex; align-items:center; gap:8px; font-size:13px; padding-bottom:10px; cursor:pointer; }
  .op-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:14px; }
  .op-stage { background:rgba(0,0,0,.18); border:1px solid var(--border, rgba(255,255,255,.08)); border-radius:14px; padding:20px 10px; overflow-x:auto; }
  .op-sheet { width:210mm; min-height:297mm; margin:0 auto 20px; background:#fff; color:#000; padding:12mm 12mm 10mm; border-radius:3px; box-shadow:0 6px 24px rgba(0,0,0,.35); font-family:Arial,Helvetica,sans-serif; }
  .op-sheet h1 { text-align:center; font-size:16px; margin:0; letter-spacing:1px; color:#000; }
  .op-no { text-align:center; font-size:12px; margin:3px 0 10px; }
  .op-meta { display:flex; justify-content:space-between; font-size:11px; line-height:1.7; margin-bottom:10px; }
  .op-meta .l { max-width:62%; } .op-meta .r { min-width:38%; }
  .op-meta .row { display:flex; } .op-meta .r .k { width:95px; } .op-meta .l .k { width:90px; display:inline-block; }
  .op-sheet table { width:100%; border-collapse:collapse; font-size:11px; background:#fff; color:#000; }
  .op-sheet th, .op-sheet td { border:1px solid #000; padding:4px 5px; color:#000; background:#fff; }
  .op-sheet th { background:#f0f0f0; text-align:center; }
  .op-sheet td.c { text-align:center; } .op-sheet td.n { text-align:right; }
  .op-sheet tr { height:21px; }
  .op-ttd { display:flex; justify-content:space-between; margin-top:26px; font-size:11px; text-align:center; }
  .op-ttd { gap:10px; } .op-ttd > div { width:32%; } .op-ttd .sp { height:60px; } .op-ttd .jab { min-height:30px; } .op-ttd .nm { font-weight:bold; text-decoration:underline; } .op-ttd .ed { padding:1px 2px; }
  .op-sheet table tbody tr, .op-sheet table tbody tr:hover, .op-sheet table tbody tr:nth-child(odd), .op-sheet table tbody tr:nth-child(even) { background:#fff !important; }
  .op-sheet table tbody tr:hover td, .op-sheet table tbody tr td { background:#fff !important; color:#000 !important; border-color:#000 !important; }
  .op-sheet table tbody tr td.hasil, .op-sheet table tbody tr:hover td.hasil { background:#fffdf0 !important; }
  .op-sheet table thead th { background:#f0f0f0 !important; color:#000 !important; border-color:#000 !important; text-transform:none; letter-spacing:0; font-size:11px; }
  .op-sheet table td { height:auto; vertical-align:middle; font-size:11px; }
  .op-sheet .ed:focus { outline:2px solid #1f6feb; outline-offset:-2px; background:#eef4ff; }
  .op-sheet h1.ed:focus, .op-sheet .op-ttd .ed:focus { outline-offset:2px; border-radius:3px; }
  .op-sheet td.ed:focus { outline:2px solid #1f6feb; outline-offset:-2px; background:#eef4ff; }
  .op-sheet td.hasil { background:#fffdf0; }
  .op-tip { font-size:12px; color:var(--text-dim); margin:0 0 10px; }
  @media (max-width:760px) { .op-form .wide { grid-column:span 1; } }
  @media print {
    @page { size:A4; margin:0; }
    html, body { height:auto !important; overflow:visible !important; background:#fff !important; }
    body * { visibility:hidden !important; }
    .op-stage, .op-stage * { visibility:visible !important; }
    .op-stage { position:absolute; left:0; top:0; width:100%; padding:0; border:0; background:#fff; overflow:visible; }
    .op-sheet { margin:0; box-shadow:none; border-radius:0; page-break-after:always; }
    .op-sheet:last-child { page-break-after:auto; }
  }
</style>

<div class="topline">
  <div><h1>Bahan Opname</h1><div class="sub">Daftar barang beserta saldo di sistem untuk opname fisik. Kolom Hasil Opsik dikosongkan untuk ditulis tangan saat menghitung barang.</div></div>
</div>

<div class="op-stats">
  <div class="op-chip"><b><?= count($items) ?></b><span>Barang dalam daftar</span></div>
  <div class="op-chip"><b><?= $totalHal ?></b><span>Halaman cetak</span></div>
  <div class="op-chip"><b><?= $perHal ?></b><span>Baris per halaman</span></div>
</div>

<form method="get" class="card">
  <div class="op-form">
    <div class="field"><label>No. Bahan Opname</label><input type="text" name="nomor" value="<?= e($nomor) ?>" placeholder="Boleh dikosongkan"></div>
    <div class="field"><label>Tanggal Opname</label><input type="date" name="tgl_opname" value="<?= e($tglOpname) ?>"></div>
    <div class="field"><label>Baris per halaman</label><input type="number" name="per_hal" value="<?= (int)$perHal ?>" min="10" max="60"></div>
    <div class="field wide"><label>Kode UAKPB</label><input type="text" name="kode_uakpb" value="<?= e($kodeUakpb) ?>"></div>
    <div class="field wide"><label>UAKPB</label><input type="text" name="uakpb" value="<?= e($uakpb) ?>"></div>
    <label class="op-check"><input type="checkbox" name="sembunyi0" value="1" <?= $sembunyi0 ? 'checked' : '' ?>> Sembunyikan barang stok 0</label>
  </div>
  <div class="op-actions">
    <button class="btn btn-primary" type="submit" style="padding:9px 18px;">Terapkan</button>
    <button class="btn btn-ghost" type="button" onclick="window.print()" style="padding:9px 18px;">Cetak / Simpan PDF</button>
    <button class="btn btn-ghost" type="button" id="opCsv" style="padding:9px 18px;">Unduh Excel (CSV)</button>
    <button class="btn btn-ghost" type="button" id="opReset" style="padding:9px 18px;color:#f87171;">Kosongkan isian</button>
  </div>
</form>

<p class="op-tip">Klik judul, kolom Nama, Satuan, Saldo, Hasil Opsik, atau nama dan jabatan penandatangan lalu ketik langsung. Isian tersimpan otomatis di browser ini. Perubahan di sini hanya untuk lembar opname, stok di database tidak berubah.</p>
<div class="op-stage">
<?php $no = 0; foreach ($halaman as $h => $baris): ?>
  <div class="op-sheet">
    <h1 class="ed" contenteditable="true" data-k="_judul" data-c="judul">BAHAN OPNAME</h1>
    <div class="op-no">NO : <?= $nomor !== '' ? e($nomor) : '...................................................' ?></div>
    <div class="op-meta">
      <div class="l">
        <div><span class="k">KODE UAKPB</span>: <?= e($kodeUakpb) ?></div>
        <div style="margin-top:6px"><span class="k">UAKPB</span>: <?= e($uakpb) ?></div>
      </div>
      <div class="r">
        <div class="row"><span class="k">Tanggal Cetak</span><span>: <?= e($tglCetak) ?></span></div>
        <div class="row"><span class="k">Tanggal Opnam</span><span>: <?= $tglOpnameTampil !== '' ? e($tglOpnameTampil) : '.......................................' ?></span></div>
        <div class="row"><span class="k">Halaman</span><span>: <?= $h + 1 ?> dari <?= $totalHal ?></span></div>
      </div>
    </div>
    <table>
      <thead>
        <tr><th style="width:34px">No.</th><th style="width:130px">Kode Barang</th><th>Nama Barang</th><th style="width:60px">Satuan</th><th style="width:80px">Saldo di Sistem</th><th style="width:90px">Hasil Opsik</th></tr>
      </thead>
      <tbody>
        <?php foreach ($baris as $it): $no++; ?>
        <tr>
          <td class="c"><?= $no ?></td>
          <td class="c"><?= e($it['kode']) ?></td>
          <td class="ed" contenteditable="true" data-k="<?= e($it['kode']) ?>" data-c="nama"><?= e(mb_strtoupper($it['nama'])) ?></td>
          <td class="c ed" contenteditable="true" data-k="<?= e($it['kode']) ?>" data-c="satuan"><?= e($it['satuan']) ?></td>
          <td class="n ed" contenteditable="true" data-k="<?= e($it['kode']) ?>" data-c="saldo"><?= (int)$it['stok'] ?></td>
          <td class="c ed hasil" contenteditable="true" data-k="<?= e($it['kode']) ?>" data-c="hasil"></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$baris): ?><tr><td colspan="6" class="c">Belum ada data barang.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php if ($h === $totalHal - 1): ?>
    <div class="op-ttd">
      <?php foreach ([['Mengetahui,<br>Kepala Subbagian Umum', 1], ['Pengelola Barang', 2], ['Petugas Opname', 3]] as [$jab, $n]): ?>
      <div>
        <div class="ed jab" contenteditable="true" data-k="_ttd<?= $n ?>" data-c="jabatan"><?= $jab ?></div>
        <div class="sp"></div>
        <div class="ed nm" contenteditable="true" data-k="_ttd<?= $n ?>" data-c="nama">( ........................................ )</div>
        <div class="ed nip" contenteditable="true" data-k="_ttd<?= $n ?>" data-c="nip">NIP. ............................</div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<script>
(function(){
  var KEY='opname_edit_v1', data={};
  try { data=JSON.parse(localStorage.getItem(KEY)||'{}')||{}; } catch(e){ data={}; }
  var cells=document.querySelectorAll('.ed');
  var asli={};
  cells.forEach(function(td){
    var id=td.dataset.k+'|'+td.dataset.c; asli[id]=td.textContent;
    if(data[id]!==undefined) td.textContent=data[id];
  });
  function simpan(){ try{ localStorage.setItem(KEY,JSON.stringify(data)); }catch(e){} }
  cells.forEach(function(td){
    td.addEventListener('input',function(){
      var id=td.dataset.k+'|'+td.dataset.c, v=td.textContent.trim();
      if(v===asli[id].trim()) delete data[id]; else data[id]=td.textContent;
      simpan();
    });
    td.addEventListener('keydown',function(ev){
      if(ev.key==='Enter' && td.tagName!=='TD'){ ev.preventDefault(); td.blur(); return; }
      if(ev.key==='Enter'){ ev.preventDefault();
        var all=Array.prototype.slice.call(document.querySelectorAll('td.ed[data-c="'+td.dataset.c+'"]'));
        var n=all[all.indexOf(td)+1]; if(n) n.focus(); }
    });
    td.addEventListener('paste',function(ev){ ev.preventDefault(); var t=(ev.clipboardData||window.clipboardData).getData('text').replace(/\s+/g,' '); document.execCommand('insertText',false,t); });
  });
  document.querySelectorAll('h1.ed').forEach(function(h){
    h.addEventListener('input',function(){ document.querySelectorAll('h1.ed').forEach(function(o){ if(o!==h) o.textContent=h.textContent; }); });
  });
  document.getElementById('opReset').addEventListener('click',function(){
    if(!confirm('Kosongkan semua isian dan kembalikan ke data sistem?')) return;
    data={}; simpan(); location.reload();
  });
  document.getElementById('opCsv').addEventListener('click',function(){
    var rows=[['No','Kode Barang','Nama Barang','Satuan','Saldo di Sistem','Hasil Opsik']];
    document.querySelectorAll('.op-sheet tbody tr').forEach(function(tr){
      var t=tr.querySelectorAll('td'); if(t.length<6) return;
      rows.push(Array.prototype.map.call(t,function(c){return c.textContent.trim();}));
    });
    var csv='\ufeff'+rows.map(function(r){return r.map(function(v){return '"'+v.replace(/"/g,'""')+'"';}).join(';');}).join('\r\n');
    var a=document.createElement('a'); a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));
    a.download='bahan_opname.csv'; document.body.appendChild(a); a.click(); a.remove();
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>