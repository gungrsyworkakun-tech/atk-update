<?php
/**
 * export_ai_hasil.php
 *
 * Endpoint untuk meng-export hasil jawaban TERAKHIR dari Asisten AI
 * ($_SESSION['ai_last_result'], diisi oleh asisten_ai.php) ke Excel (.xlsx),
 * CSV, atau PDF — dipanggil lewat 2 jalur:
 *   1. Tombol umum "Export ke Excel" (link biasa, tanpa ?format) -> tampil
 *      halaman pilih format dulu.
 *   2. Command chat langsung ("export ke excel/csv/pdf" — dideteksi lewat
 *      keyword sederhana di asisten_ai.php, TIDAK lewat AI/Groq sama sekali
 *      supaya cepat & gratis) -> asisten_ai.php sudah menyertakan
 *      ?format=xlsx/csv/pdf, langsung diarahkan ke sini dan auto-download.
 *
 * Tidak pakai library eksternal (PhpSpreadsheet/FPDF dll) sama sekali —
 * XLSX & PDF dibangun manual dari struktur file mentahnya, supaya jalan di
 * shared hosting tanpa Composer/vendor folder. Sudah divalidasi bisa dibuka
 * ulang dengan benar oleh library pembaca independen (openpyxl utk xlsx,
 * pypdf utk pdf) sebelum dipakai di sini.
 */
require __DIR__ . '/includes/bootstrap.php';
require_permission('asisten_ai');
$u = current_user();

$format = strtolower($_GET['format'] ?? '');
$allowedFormats = ['xlsx', 'csv', 'pdf'];

$blocks = $_SESSION['ai_last_result']['blocks'] ?? [];
$exportableBlocks = array_values(array_filter($blocks, function ($b) { return !empty($b['rows']); }));

if (!$exportableBlocks) {
    http_response_code(400);
    echo '<!doctype html><meta charset="utf-8"><title>Tidak ada data</title>'
        . '<body style="font-family:sans-serif;padding:60px;text-align:center;color:#333;">'
        . '<h2>Tidak ada data untuk di-export</h2>'
        . '<p>Tanyakan sesuatu ke Asisten AI dulu (misal cek stok, rekap bidang, dll), baru hasilnya bisa di-export.</p>'
        . '<a href="asisten_ai.php">&larr; Kembali ke Asisten AI</a>'
        . '</body>';
    exit;
}

if (!in_array($format, $allowedFormats, true)) {
    // Halaman pilih format — dipakai tombol umum "Export Data" yang belum
    // menentukan format spesifik.
    $pertanyaanTerakhir = htmlspecialchars($_SESSION['ai_last_result']['question'] ?? '', ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><meta charset="utf-8"><title>Pilih Format Export</title>'
        . '<body style="font-family:sans-serif;padding:60px;text-align:center;color:#333;">'
        . '<h2>Pilih format export</h2>'
        . '<p style="color:#666;">Hasil jawaban terakhir: &ldquo;' . $pertanyaanTerakhir . '&rdquo;</p>'
        . '<div style="display:flex;gap:14px;justify-content:center;margin-top:24px;">'
        . '<a href="?format=xlsx" style="padding:12px 24px;background:#16a34a;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">Excel (.xlsx)</a>'
        . '<a href="?format=csv" style="padding:12px 24px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">CSV</a>'
        . '<a href="?format=pdf" style="padding:12px 24px;background:#dc2626;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">PDF</a>'
        . '</div>'
        . '<p style="margin-top:32px;"><a href="asisten_ai.php" style="color:#666;">&larr; Kembali ke Asisten AI</a></p>'
        . '</body>';
    exit;
}

$filenameBase = 'asisten-ai-export-' . date('Y-m-d_His');

function ai_export_headers_from_row(array $row) {
    return array_map(function ($k) { return ucwords(str_replace('_', ' ', $k)); }, array_keys($row));
}

// ============================================================
// CSV
// ============================================================
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM supaya Excel baca UTF-8 dengan benar
    $out = fopen('php://output', 'w');
    foreach ($exportableBlocks as $b) {
        fputcsv($out, [$b['title'] ?? 'Data']);
        fputcsv($out, ai_export_headers_from_row($b['rows'][0]));
        foreach ($b['rows'] as $row) {
            fputcsv($out, array_map(function ($v) {
                return is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
            }, array_values($row)));
        }
        fputcsv($out, []); // baris kosong pemisah antar blok
    }
    fclose($out);
    exit;
}

// ============================================================
// XLSX — dibangun manual dari struktur OOXML (zip + xml), satu sheet
// per blok data. Pakai cell tipe inlineStr supaya tidak perlu
// sharedStrings.xml (lebih sederhana & tetap valid).
// ============================================================
function ai_export_xml_escape($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function ai_export_col_letter($n) {
    $letter = '';
    while ($n > 0) {
        $mod = ($n - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $n = intdiv($n - 1, 26);
    }
    return $letter;
}

function ai_export_build_sheet_xml(array $headers, array $rows) {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

    $rowIndex = 1;
    $xml .= '<row r="' . $rowIndex . '">';
    foreach ($headers as $ci => $h) {
        $ref = ai_export_col_letter($ci + 1) . $rowIndex;
        $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . ai_export_xml_escape($h) . '</t></is></c>';
    }
    $xml .= '</row>';
    $rowIndex++;

    foreach ($rows as $row) {
        $xml .= '<row r="' . $rowIndex . '">';
        $ci = 0;
        foreach (array_values($row) as $val) {
            $ref = ai_export_col_letter($ci + 1) . $rowIndex;
            $valStr = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string)$val;
            $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . ai_export_xml_escape($valStr) . '</t></is></c>';
            $ci++;
        }
        $xml .= '</row>';
        $rowIndex++;
    }
    $xml .= '</sheetData></worksheet>';
    return $xml;
}

function ai_export_write_xlsx(array $sheets, $filePath) {
    $zip = new ZipArchive();
    if ($zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }

    $overrides = '';
    foreach ($sheets as $i => $s) {
        $num = $i + 1;
        $overrides .= '<Override PartName="/xl/worksheets/sheet' . $num . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        $overrides .
        '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>');

    $sheetEntries = '';
    $relEntries = '';
    foreach ($sheets as $i => $s) {
        $num = $i + 1;
        $sheetName = ai_export_xml_escape(mb_substr(preg_replace('/[\[\]\*\/\\\\\?:]/', ' ', $s['name']), 0, 31));
        $sheetEntries .= '<sheet name="' . $sheetName . '" sheetId="' . $num . '" r:id="rId' . $num . '"/>';
        $relEntries .= '<Relationship Id="rId' . $num . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $num . '.xml"/>';
    }

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets>' . $sheetEntries . '</sheets></workbook>');

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relEntries . '</Relationships>');

    foreach ($sheets as $i => $s) {
        $num = $i + 1;
        $zip->addFromString('xl/worksheets/sheet' . $num . '.xml', ai_export_build_sheet_xml($s['headers'], $s['rows']));
    }

    $zip->close();
    return true;
}

if ($format === 'xlsx') {
    $sheets = array_map(function ($b) {
        return [
            'name' => $b['title'] ?? 'Data',
            'headers' => ai_export_headers_from_row($b['rows'][0]),
            'rows' => $b['rows'],
        ];
    }, $exportableBlocks);

    $tmpFile = tempnam(sys_get_temp_dir(), 'ai_xlsx_');
    $ok = ai_export_write_xlsx($sheets, $tmpFile);

    if (!$ok) {
        http_response_code(500);
        echo 'Gagal membuat file Excel. Coba lagi atau hubungi admin.';
        exit;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.xlsx"');
    header('Content-Length: ' . filesize($tmpFile));
    readfile($tmpFile);
    @unlink($tmpFile);
    exit;
}

// ============================================================
// PDF — dibangun manual dari struktur PDF mentah (tanpa library),
// font Courier bawaan (monospace, tidak perlu di-embed), auto pagination
// per blok data. Cocok untuk tabel data sederhana.
// ============================================================
function ai_export_pdf_escape_text($s) {
    $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', (string)$s);
    if ($converted === false) $converted = preg_replace('/[^\x20-\x7E]/', '?', (string)$s);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
}

function ai_export_pdf_format_row(array $cols, $colWidthChars) {
    $parts = [];
    foreach ($cols as $c) {
        $c = (string)$c;
        if (mb_strlen($c) > $colWidthChars) $c = mb_substr($c, 0, max(0, $colWidthChars - 1)) . '…';
        $parts[] = str_pad($c, $colWidthChars);
    }
    return implode(' ', $parts);
}

function ai_export_render_pdf(array $blocks) {
    $pageWidth = 841.89;  // A4 landscape (pt) — kolom lebih lega dari portrait
    $pageHeight = 595.28;
    $marginX = 30;
    $marginY = 30;
    $fontSize = 7;
    $lineHeight = 10;
    $charWidth = $fontSize * 0.6;

    $usableWidth = $pageWidth - 2 * $marginX;
    $maxCharsPerLine = (int)floor($usableWidth / $charWidth);

    $lines = [];
    foreach ($blocks as $b) {
        if (empty($b['rows'])) continue;
        $headers = ai_export_headers_from_row($b['rows'][0]);
        $colCount = count($headers) ?: 1;
        $colWidthChars = max(4, (int)floor($maxCharsPerLine / $colCount));

        $lines[] = $b['title'] ?? 'Data';
        $lines[] = str_repeat('-', min($maxCharsPerLine, 160));
        $lines[] = ai_export_pdf_format_row($headers, $colWidthChars);
        $lines[] = str_repeat('-', min($maxCharsPerLine, 160));
        foreach ($b['rows'] as $row) {
            $vals = array_map(function ($v) {
                return is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v;
            }, array_values($row));
            $lines[] = ai_export_pdf_format_row($vals, $colWidthChars);
        }
        $lines[] = '';
        $lines[] = '';
    }
    if (!$lines) $lines = ['Tidak ada data.'];

    $linesPerPage = max(5, (int)floor(($pageHeight - 2 * $marginY) / $lineHeight));
    $pagesLines = array_chunk($lines, $linesPerPage);

    $catalogId = 1;
    $pagesId = 2;
    $fontId = 3;
    $nextId = 4;
    $pageIds = [];
    $contentIds = [];
    foreach ($pagesLines as $pl) {
        $pageIds[] = $nextId++;
        $contentIds[] = $nextId++;
    }

    $contents = [];
    foreach ($pagesLines as $pl) {
        $startY = $pageHeight - $marginY;
        $stream = "BT\n/F1 {$fontSize} Tf\n{$lineHeight} TL\n{$marginX} {$startY} Td\n";
        foreach ($pl as $i => $lineText) {
            $text = ai_export_pdf_escape_text($lineText);
            $stream .= ($i === 0 ? '' : "T*\n") . "({$text}) Tj\n";
        }
        $stream .= 'ET';
        $contents[] = $stream;
    }

    $objs = [];
    $objs[$catalogId] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";
    $kids = implode(' ', array_map(function ($id) { return "{$id} 0 R"; }, $pageIds));
    $objs[$pagesId] = "<< /Type /Pages /Kids [{$kids}] /Count " . count($pageIds) . ' >>';
    $objs[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';

    foreach ($pageIds as $i => $pid) {
        $cid = $contentIds[$i];
        $objs[$pid] = "<< /Type /Page /Parent {$pagesId} 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Resources << /Font << /F1 {$fontId} 0 R >> >> /Contents {$cid} 0 R >>";
        $len = strlen($contents[$i]);
        $objs[$cid] = "<< /Length {$len} >>\nstream\n{$contents[$i]}\nendstream";
    }

    ksort($objs);
    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];
    foreach ($objs as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
    }
    $xrefStart = strlen($pdf);
    $count = count($objs) + 1;
    $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
    for ($i = 1; $i < $count; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size {$count} /Root {$catalogId} 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";
    return $pdf;
}

if ($format === 'pdf') {
    $pdfContent = ai_export_render_pdf($exportableBlocks);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.pdf"');
    header('Content-Length: ' . strlen($pdfContent));
    echo $pdfContent;
    exit;
}