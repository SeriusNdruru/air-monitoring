# Revisi sistem monitoring kualitas udara: ADC → estimasi PPM

## Status rilis
**BETA untuk integrasi dan pengujian, bukan bukti akurasi sensor maupun nilai ISPU.** Seluruh kode sumber telah diedit dan dites sintaks (PHP + JS) serta logika batas PPM (PHP). Belum ada akses server hidup, flash ESP32, alat kalibrasi, atau pengujian end-to-end. Foto modul tidak cukup untuk memastikan RL atau R0.

## Yang berubah
- `firmware/uji_kualitas_udara_PPM.ino`: pembacaan ADC dan mV pada GPIO34, koreksi 10 kΩ + 10 kΩ (AO sensor ≈ 2× input pin), rumus Rs/R0 dan kurva power CO2 ekuivalen, kategori serta JSON dengan 3 field `mq135_raw`, `mq135_ppm`, `status`; LCD menampilkan PPM atau placeholder saat kalibrasi belum siap. **Firmware tidak mengarang R0, RL, A, B.**
- `api/receive-data.php`: endpoint nyata firmware; `api/receive-reading.php` diarahkan ke implementasi sama; `api/thresholds.php` menyediakan nilai konfigurasi untuk firmware.
- `config/air-quality.php`: aturan tunggal, `Normal < warning`, `Waspada warning ≤ ppm < danger`, `Bahaya ≥ danger`; input null = `Belum Terkalibrasi`.
- `migrations/001_adc_to_ppm.sql`: kolom baru `sensor_readings.mq135_ppm DECIMAL(12,2) NULL` dan setting ambang internal 300/700 ppm, **tidak mengubah riwayat ADC**.
- `api/latest.php`, `api/history.php`, `assets/js/dashboard.js`, `index.php`: nilai dan grafik PPM; data historis tanpa PPM ditandai tidak dikalibrasi. `admin/ambang-batas.php` kini memasukkan batas PPM, bukan ADC; sebagian besar tabel/diagram admin sudah membaca PPM.
- Notifikasi Telegram: status dihitung server dan dikirim bila status PPM yang telah terisi berubah; gunakan subscriber aktif pada `telegram_users` dan log `notification_logs`.
- Rahasia yang sebelumnya tertanam pada firmware dan aplikasi **telah disingkirkan dari paket ini**. Rotasi API key, kata sandi DB, kata sandi Wi-Fi, dan token bot yang pernah diekspor/dibagikan.

## Instalasi produksi (urutannya penting)
1. **Backup** database dan situs aktif. Nonaktifkan sementara ingestion pada server. Jangan menimpa data historis.
2. Pada database yang sudah ada, jalankan *sekali* `migrations/001_adc_to_ppm.sql`. Pastikan `system_settings.setting_key` unik sesuai dump awal. Jangan jalankan migrasi dua kali karena `ALTER TABLE` bukan idempotent.
3. Upload direktori `api/`, `config/`, `admin/`, `assets/`, dan `index.php`. Huruf kecil penting pada hosting Linux. Pastikan base URL di firmware sesuai nama direktori produksi.
4. Atur environmental variables via Plesk/hosting: `AQM_DB_HOST`, `AQM_DB_PORT`, `AQM_DB_NAME`, `AQM_DB_USER`, `AQM_DB_PASSWORD`, `AQM_API_KEY`, `AQM_TELEGRAM_BOT_TOKEN`. Jika memakai single-chat helper lama, atur pula `AQM_TELEGRAM_CHAT_ID`. Jangan masukkan nilainya ke repository, HTML, atau PDF.
5. Pasang library `ArduinoJson` versi 7 dan `LiquidCrystal_I2C` yang sesuai, buat file lokal `firmware/secrets.h` dari `firmware/secrets.example.h` dan isi URL/kredensial baru. File `secrets.h` jangan dibagikan.
6. **HTTPS membutuhkan CA sertifikat server** (`AQM_ROOT_CA` dalam `secrets.h`); firmware sengaja menolak HTTPS tanpa CA daripada menonaktifkan pemeriksaan sertifikat. Untuk pengujian lokal non-internet, URL HTTP LAN dapat digunakan sementara.
7. Verifikasi pengkabelan: MQ-135 VCC/GND, AO → R1 10kΩ → GPIO34 → R2 10kΩ → GND; tegangan pin ESP32 **tidak boleh melampaui rating papan**. Dua resistor ini **bukan** RL modul.
8. Sebelum kalibrasi: `CALIBRATION_READY=false` dan parameter RL/R0/A/B `NAN`. Firmware mengirim `mq135_ppm:null` dan status `Belum Terkalibrasi`; jangan paksa hasil angka.
9. Setelah mendapat identifikasi RL, konsentrasi gas referensi, nilai R0, dan kurva A/B dengan rujukan yang dapat dipertanggungjawabkan, isi semua konstanta sesuai datasheet/model **yang benar-benar digunakan**, lalu set `CALIBRATION_READY=true`. Lakukan perbandingan dengan alat referensi, catat tanggal, suhu, RH, dan kesalahan ukur. Ini menghasilkan **estimasi gas ekuivalen**, bukan CO2 spesifik.
10. Endpoint check: `GET /api/thresholds.php` menggunakan `X-API-KEY`; `POST /api/receive-data.php` juga menggunakan `X-API-KEY`. Endpoint akan memberikan HTTP 201 jika data disimpan.

## Payload
Data belum dikalibrasi:
```json
{"device_code":"AQM-01","mq135_raw":2190,"mq135_ppm":null,"status":"Belum Terkalibrasi"}
```
Contoh **DATA SIMULASI, BUKAN HASIL SENSOR** untuk tes API kategori:
```json
{"device_code":"AQM-01","mq135_raw":2190,"mq135_ppm":699.99,"status":"Waspada"}
```
Backend menghitung ulang status, jadi client yang mengirim label berbeda akan mendapat `status_mismatch=true` tanpa membiarkan label salah tersimpan. Pengaturan admin default `300` dan `700` adalah **ambang INTERNAL untuk uji software**; tidak berasal dari PermenLHK No. 14/2020 / ISPU maupun Permenkes 1077/2011.

## Pengujian lokal yang tersedia
```bash
php tests/test_air_quality.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/js/dashboard.js
```
Driver PDO MySQL, database produksi, ESP32 fisik, dan akses internet tidak tersedia pada lingkungan penyusunan, sehingga integrasi nyata, kalibrasi, notifikasi Telegram sungguhan, pengujian performa, serta klaim "siap sidang" belum dapat diverifikasi. `docs/` mencantumkan matriks verifikasi lapangan dan perubahan naskah.

## Dasar ilmiah dan batasan
- MQ-135 adalah sensor semikonduktor sensitif terhadap beragam gas. Bentuk model C=A(Rs/R0)^B perlu koefisien yang dikutip dan divalidasi terhadap referensi gas.
- PermenLHK 14/2020 mengatur ISPU berdasarkan pencemar tertentu; estimasi CO2 ekuivalen MQ-135 **bukan ISPU**.
- Permenkes 1077/2011 merupakan rujukan penyehatan udara dalam ruang, bukan pembenaran otomatis batas 300/700 ppm untuk MQ-135.
- Rujukan paper relevan dalam daftar pustaka skripsi lama: Khan dkk. [1], Humphrey dkk. [2], Guerrero-Ulloa dkk. [4], Sitanggang dkk. [18]. Kecocokan metodologi/angka perlu dibaca ulang dari naskah artikel aslinya.

## Hal yang wajib diperiksa sebelum deploy publik
- Pastikan direktori admin hanya bisa diakses pengguna terautentikasi dan API key diganti.
- Rilis ini bukan hardening keamanan menyeluruh. Lakukan audit login, HTTPS, CSRF, rate limiting, dan backup terpisah.
- Semua tangkapan layar, tabel uji, dan hasil pengukuran BAB IV harus diperbarui **setelah** aplikasi dan perangkat benar-benar diuji. Jangan gunakan data simulasi sebagai data lapangan.
