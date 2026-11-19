// Pindai QR barang dari halaman login (hanya untuk melihat info barang).
(function () {
  var btn        = document.getElementById('cek-btn-scan');
  var readerDiv  = document.getElementById('cek-reader');
  var resultDiv  = document.getElementById('cek-status');
  var card       = document.getElementById('cek-card');
  var fileInput  = document.getElementById('cek-file');
  var fileLabel  = document.getElementById('cek-file-label');
  if (!btn || !readerDiv) return;

  var qr = null;
  var scanning = false;
  var cfg = { fps: 10, qrbox: 220 };

  function setStatus(msg, isError) {
    resultDiv.textContent = msg || '';
    resultDiv.className = 'cek-status' + (isError ? ' is-error' : '');
  }

  function hideCard() { card.style.display = 'none'; }

  function fmt(n, satuan) { return Number(n).toLocaleString('id-ID') + ' ' + satuan; }
  function fmtTgl(t) {
    if (!t) return '';
    var p = String(t).substring(0, 10).split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : t;
  }
  function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function line(left, sub, right, rightCls) {
    var row = el('div', 'cek-line');
    var l = el('div', 'l');
    l.appendChild(el('b', '', left));
    if (sub) l.appendChild(el('small', '', sub));
    var r = el('div', 'r' + (rightCls ? ' ' + rightCls : ''), right);
    row.appendChild(l);
    row.appendChild(r);
    return { row: row, right: r };
  }

  // Tombol Lihat/Sembunyikan BAST: berkas baru diminta ke server saat dibuka.
  function addBastToggle(r, wrapAfter, d, t) {
    var view = null;
    var btn = el('button', 'cek-bast-btn', 'Lihat BAST');
    btn.type = 'button';
    btn.addEventListener('click', function () {
      if (view) {                       // sedang terbuka -> sembunyikan
        view.parentNode.removeChild(view);
        view = null;
        btn.textContent = 'Lihat BAST';
        return;
      }
      var url = 'serve_bast_publik.php?id=' + encodeURIComponent(t.id) + '&kode=' + encodeURIComponent(d.kode);
      view = el('div', 'cek-bast-view');
      if (t.bast_pdf) {
        var f = document.createElement('iframe');
        f.src = url;
        f.title = 'BAST';
        view.appendChild(f);
      } else {
        var img = document.createElement('img');
        img.src = url;
        img.alt = 'BAST';
        view.appendChild(img);
      }
      var a = el('a', '', 'Buka di tab baru');
      a.href = url; a.target = '_blank'; a.rel = 'noopener';
      view.appendChild(a);
      wrapAfter.parentNode.insertBefore(view, wrapAfter.nextSibling);
      btn.textContent = 'Sembunyikan BAST';
    });
    r.appendChild(btn);
  }

  function showCard(d) {
    document.getElementById('cek-nama').textContent  = d.nama;
    document.getElementById('cek-kode').textContent  = d.kode;
    document.getElementById('cek-jenis').textContent = d.jenis || '—';
    document.getElementById('cek-tahun').textContent = d.tahun_masuk;
    document.getElementById('cek-masuk').textContent  = fmt(d.total_masuk, d.satuan);
    document.getElementById('cek-keluar').textContent = fmt(d.total_keluar, d.satuan);
    document.getElementById('cek-stok').textContent   = fmt(d.stok, d.satuan);

    var bd = document.getElementById('cek-bidang');
    clear(bd);
    if (!d.bidang || !d.bidang.length) {
      bd.appendChild(el('div', 'cek-empty', 'Belum ada bidang yang mengambil barang ini.'));
    } else {
      d.bidang.forEach(function (b) {
        var info = b.kali + 'x ambil, terakhir ' + fmtTgl(b.terakhir);
        bd.appendChild(line(b.bidang, info, fmt(b.total, d.satuan), 'keluar').row);
      });
    }

    var rw = document.getElementById('cek-riwayat');
    clear(rw);
    if (!d.riwayat || !d.riwayat.length) {
      rw.appendChild(el('div', 'cek-empty', 'Belum ada transaksi.'));
    } else {
      d.riwayat.forEach(function (t) {
        var masuk = t.tipe === 'masuk';
        var label = masuk ? 'Barang masuk' : ('Keluar ke ' + (t.bidang || '(tanpa bidang)'));
        var sign  = (masuk ? '+' : '−') + Number(t.jumlah).toLocaleString('id-ID') + ' ' + d.satuan;
        var ln = line(label, fmtTgl(t.tanggal), sign, masuk ? 'masuk' : 'keluar');
        rw.appendChild(ln.row);
        if (t.bast) addBastToggle(ln.right, ln.row, d, t);
      });
    }

    card.style.display = 'block';
  }

  function lookup(kode) {
    hideCard();
    setStatus('Kode terbaca. Mencari barang…');
    fetch('cari_barang_publik.php?kode=' + encodeURIComponent(kode), { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, data: j }; }); })
      .then(function (res) {
        if (!res.ok) { setStatus(res.data.error || 'Barang tidak ditemukan.', true); return; }
        setStatus('');
        showCard(res.data);
      })
      .catch(function () { setStatus('Gagal menghubungi server.', true); });
  }

  function resetButton() {
    scanning = false;
    readerDiv.style.display = 'none';
    btn.textContent = 'Mulai Kamera';
  }

  function stopScan(cb) {
    if (qr && scanning) {
      qr.stop().then(function () { qr.clear(); resetButton(); if (cb) cb(); })
        .catch(function () { resetButton(); if (cb) cb(); });
    } else if (cb) { cb(); }
  }

  function onScanSuccess(text) { stopScan(function () { lookup(text); }); }

  function onStarted() {
    scanning = true;
    btn.textContent = 'Hentikan Kamera';
    setStatus('Kamera aktif, arahkan ke QR pada label barang…');
  }

  function failed(err) {
    var msg = (err && err.name === 'NotReadableError')
      ? 'Kamera sedang dipakai aplikasi/tab lain atau tidak bisa dibuka.'
      : 'Kamera tidak bisa diakses.';
    readerDiv.style.display = 'none';
    setStatus(msg + ' Gunakan tombol "Ambil/Unggah Foto QR".', true);
  }

  function tryList(devices, i, lastErr) {
    if (i >= devices.length) { failed(lastErr); return; }
    qr.start(devices[i].id, cfg, onScanSuccess, function () {})
      .then(onStarted)
      .catch(function (e) { tryList(devices, i + 1, e); });
  }

  btn.addEventListener('click', function () {
    if (scanning) { stopScan(); return; }
    hideCard();

    if (typeof Html5Qrcode === 'undefined') {
      setStatus('Pustaka pemindai gagal dimuat.', true);
      return;
    }
    if (!window.isSecureContext) {
      setStatus('Kamera hanya bisa dibuka lewat HTTPS. Gunakan "Ambil/Unggah Foto QR".', true);
      return;
    }

    setStatus('Membuka kamera…');
    readerDiv.style.display = 'block';
    qr = new Html5Qrcode('cek-reader');

    qr.start({ facingMode: 'environment' }, cfg, onScanSuccess, function () {})
      .then(onStarted)
      .catch(function (firstErr) {
        Html5Qrcode.getCameras().then(function (devices) {
          if (!devices || !devices.length) {
            readerDiv.style.display = 'none';
            setStatus('Tidak ada kamera terdeteksi. Gunakan "Ambil/Unggah Foto QR".', true);
            return;
          }
          tryList(devices, 0, firstErr);
        }).catch(function () { failed(firstErr); });
      });
  });

  if (fileInput) {
    fileInput.addEventListener('change', function (ev) {
      var file = ev.target.files && ev.target.files[0];
      if (!file) return;
      if (typeof Html5Qrcode === 'undefined') {
        setStatus('Pustaka pemindai gagal dimuat.', true);
        return;
      }
      hideCard();
      setStatus('Membaca QR dari foto…');

      stopScan(function () {
        var tmp = document.getElementById('cek-file-temp');
        if (!tmp) {
          tmp = document.createElement('div');
          tmp.id = 'cek-file-temp';
          tmp.style.display = 'none';
          document.body.appendChild(tmp);
        }
        var scanner = new Html5Qrcode('cek-file-temp');
        scanner.scanFile(file, true)
          .then(function (text) { scanner.clear(); lookup(text); })
          .catch(function () {
            scanner.clear();
            setStatus('Tidak ada QR yang terbaca di foto ini. Coba foto yang lebih jelas.', true);
          })
          .finally(function () { fileInput.value = ''; });
      });
    });
  }
})();