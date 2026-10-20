-- Jalankan SEKALI setelah backup lengkap dan saat maintenance.
-- Jangan isi nilai PPM historis dengan konversi sintetis.
ALTER TABLE sensor_readings
    ADD COLUMN mq135_ppm DECIMAL(12,2) NULL AFTER mq135_raw;

INSERT INTO system_settings (setting_key,setting_value,description)
VALUES
 ('mq135_warning_start_ppm','300','Ambang contoh INTERNAL: awal Waspada (ppm CO2 ekuivalen); bukan ISPU'),
 ('mq135_danger_start_ppm','700','Ambang contoh INTERNAL: awal Bahaya (ppm CO2 ekuivalen); bukan ISPU')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),description=VALUES(description);
-- setting_key wajib UNIQUE (cek dump asal). Nilai lama ADC tetap disimpan untuk audit.
