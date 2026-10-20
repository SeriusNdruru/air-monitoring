<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Dashboard AQM</title>

    <link
        rel="icon"
        type="image/png"
        href="/assets/images/AQM.png?v=1"
    >

    <link
        rel="apple-touch-icon"
        href="/assets/images/AQM.png?v=1"
    >

    <link
        rel="stylesheet"
        href="/assets/css/style.css?v=5"
    >

    <script
        src="https://cdn.jsdelivr.net/npm/chart.js"
        defer
    ></script>

    <script
		src="/assets/js/dashboard.js?v=8"
		defer
	></script>
</head>
<body>
    <header class="topbar">
		<div class="header-brand">
			<img
				src="/assets/images/AQM.png?v=1"
				alt="Logo Dashboard AQM"
				class="header-logo"
			>

			<div class="header-title">
				<h1>Dashboard Monitoring Kualitas Udara</h1>

				<p>
					Sistem monitoring berbasis Internet of Things
				</p>
			</div>
		</div>

		<div class="live-indicator">
			<span class="live-dot"></span>
			Pembaruan otomatis
		</div>
	</header>

    <main class="container">
        <div
            id="errorBox"
            class="error-box hidden"
            role="alert"
        ></div>

        <section class="summary-grid">
            <article class="summary-card">
                <p class="card-label">Estimasi CO₂ ekuivalen</p>

                <h2 id="mq135Value">-</h2>

                <p class="card-description">
                    Satuan PPM (belum valid tanpa kalibrasi)
                </p>
            </article>

            <article class="summary-card">
                <p class="card-label">Status Udara</p>

                <h2>
                    <span
                        id="airStatus"
                        class="status-badge status-unknown"
                    >
                        Belum ada data
                    </span>
                </h2>

                <p class="card-description">
                    Berdasarkan ambang batas sistem
                </p>
            </article>
        </section>

        <section class="information-grid">
            <article class="panel">
                <div class="panel-header">
                    <div>
                        <h2>Informasi Perangkat</h2>
                        <p>Identitas dan kondisi perangkat monitoring</p>
                    </div>
                </div>

                <dl class="device-information">
                    <div>
                        <dt>Kode perangkat</dt>
                        <dd id="deviceCode">-</dd>
                    </div>

                    <div>
                        <dt>Nama perangkat</dt>
                        <dd id="deviceName">-</dd>
                    </div>

                    <div>
                        <dt>Lokasi</dt>
                        <dd id="deviceLocation">-</dd>
                    </div>

                    <div>
                        <dt>Status perangkat</dt>
                        <dd id="deviceStatus">-</dd>
                    </div>

                    <div>
                        <dt>Pembaruan terakhir</dt>
                        <dd id="lastUpdate">-</dd>
                    </div>
                </dl>
            </article>

            <article class="panel">
                <div class="panel-header">
                    <div>
                        <h2>Grafik Pembacaan MQ135</h2>

                        <p>
                            Riwayat estimasi PPM berdasarkan waktu, bukan indeks ISPU resmi
                        </p>
                    </div>
                </div>

                <div class="chart-container">
                    <canvas id="airQualityChart"></canvas>
                </div>
            </article>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Riwayat Data Sensor</h2>

                    <p>
                        Data terbaru yang diterima oleh sistem
                    </p>
                </div>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>No.</th>
                        <th>Waktu</th>
                        <th>Perangkat</th>
                        <th>Estimasi CO₂ ekuivalen</th>
                        <th>Status</th>
                    </tr>
                    </thead>

                    <tbody id="historyBody">
                        <tr>
                            <td colspan="5" class="empty-cell">
                                Memuat data...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <footer>
        Sistem Monitoring Kualitas Udara Berbasis IoT
    </footer>
</body>
</html>