<?php
declare(strict_types=1);
require __DIR__ . '/../config/air-quality.php';
$t=['warning_start_ppm'=>300.0,'danger_start_ppm'=>700.0];
$cases=[
    [null,'Belum Terkalibrasi'],
    [0.0,'Normal'],
    [299.99,'Normal'],
    [300.0,'Waspada'],
    [699.99,'Waspada'],
    [700.0,'Bahaya'],
    [1200.0,'Bahaya'],
];
foreach ($cases as [$ppm,$expected]) {
    $got=airCategory($ppm,$t);
    if ($got!==$expected) throw new RuntimeException("{$ppm}: {$got} != {$expected}");
    echo 'LULUS: '.($ppm===null?'NULL':$ppm).' => '.$got.PHP_EOL;
}
foreach ([NAN,INF,-1,1000001,'tidak angka'] as $bad) {
    try { parsePpm($bad); throw new RuntimeException('Nilai tidak valid diterima!'); }
    catch (InvalidArgumentException) { echo "LULUS: Input ilegal ditolak\n"; }
}
if (parsePpm(null)!==null) throw new RuntimeException('Null harus dipertahankan.');
echo "SEMUA UJI LOGIKA BERHASIL\n";
