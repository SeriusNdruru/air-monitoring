'use strict';

let airQualityChart = null;

document.addEventListener('DOMContentLoaded', () => {
    console.log('Dashboard JavaScript mulai dijalankan.');

    /*
     * Data dashboard tetap dimuat meskipun Chart.js
     * gagal dimuat dari CDN.
     */
    loadDashboard();

    if (typeof window.Chart !== 'undefined') {
        initializeChart();
    } else {
        console.warn(
            'Chart.js tidak tersedia. Data dashboard tetap dimuat tanpa grafik.'
        );
    }

    // Memperbarui data terbaru setiap 5 detik.
    setInterval(loadLatestData, 5000);

    // Memperbarui grafik dan tabel setiap 15 detik.
    setInterval(loadHistoryData, 15000);
});

async function loadDashboard() {
    await Promise.allSettled([
        loadLatestData(),
        loadHistoryData()
    ]);
}

async function fetchJson(url) {
    /*
     * Parameter waktu digunakan untuk mencegah browser
     * atau server menggunakan respons lama dari cache.
     */
    const separator = url.includes('?') ? '&' : '?';
    const requestUrl = `${url}${separator}t=${Date.now()}`;

    console.log('Mengambil data:', requestUrl);

    const response = await fetch(requestUrl, {
        method: 'GET',
        cache: 'no-store',
        headers: {
            Accept: 'application/json'
        }
    });

    if (!response.ok) {
        throw new Error(
            `Permintaan ${url} gagal dengan HTTP ${response.status}.`
        );
    }

    const contentType = response.headers.get('content-type') || '';

    if (!contentType.includes('application/json')) {
        const responseText = await response.text();

        console.error('Respons bukan JSON:', responseText);

        throw new Error(
            `${url} tidak memberikan respons JSON.`
        );
    }

    return response.json();
}

async function loadLatestData() {
    try {
        const result = await fetchJson('/api/latest.php');

        console.log('Data terbaru:', result);

        if (!result.success) {
            throw new Error(
                result.message || 'Data terbaru gagal dimuat.'
            );
        }

        if (!result.data) {
            displayEmptyLatestData();
            return;
        }

        renderLatestData(result.data);
        hideError();
    } catch (error) {
        console.error('Kesalahan data terbaru:', error);
        updateLiveIndicator('offline');
        showError(error.message);
    }
}

async function loadHistoryData() {
    try {
        const result = await fetchJson(
            '/api/history.php?limit=30'
        );

        console.log('Data riwayat:', result);

        if (!result.success) {
            throw new Error(
                result.message || 'Riwayat data gagal dimuat.'
            );
        }

        const historyData = Array.isArray(result.data)
            ? result.data
            : [];

        renderHistoryTable(historyData);
        updateChart(historyData);
    } catch (error) {
        console.error('Kesalahan riwayat data:', error);
        showError(error.message);
    }
}

function renderLatestData(data) {
    setText(
        'mq135Value',
        formatPpm(data.mq135_ppm)
    );

    setText(
        'deviceCode',
        data.device_code ?? '-'
    );

    setText(
        'deviceName',
        data.device_name ?? '-'
    );

    setText(
        'deviceLocation',
        data.location ?? '-'
    );

    setText(
        'deviceStatus',
        formatDeviceStatus(data.device_status)
    );

    updateLiveIndicator(data.device_status);

    setText(
        'lastUpdate',
        formatDateTime(data.recorded_at)
    );

    updateAirStatus(data.air_status);
}

function displayEmptyLatestData() {
    setText('mq135Value', '-');
    setText('deviceCode', '-');
    setText('deviceName', '-');
    setText('deviceLocation', '-');
    setText('deviceStatus', 'Offline');
    setText('lastUpdate', '-');

    updateLiveIndicator('offline');
    updateAirStatus('Belum ada data');
}

function renderHistoryTable(data) {
    const tableBody = document.getElementById('historyBody');

    if (!tableBody) {
        return;
    }

    tableBody.replaceChildren();

    if (!Array.isArray(data) || data.length === 0) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');

        cell.colSpan = 5;
        cell.className = 'empty-cell';
        cell.textContent =
            'Belum terdapat riwayat data sensor.';

        row.appendChild(cell);
        tableBody.appendChild(row);

        return;
    }

    /*
     * Hanya menampilkan 10 data terbaru pada tabel.
     */
    const tableData = data.slice(0, 10);

    tableData.forEach((item, index) => {
        const row = document.createElement('tr');

        appendCell(row, String(index + 1));
        appendCell(
            row,
            formatDateTime(item.recorded_at)
        );
        appendCell(
            row,
            item.device_code ?? '-'
        );
        appendCell(
            row,
            formatPpm(item.mq135_ppm)
        );

        const statusCell = document.createElement('td');
        const statusBadge = document.createElement('span');

        statusBadge.className =
            `status-badge ${getStatusClass(item.air_status)}`;

        statusBadge.textContent =
            item.air_status ?? 'Tidak diketahui';

        statusCell.appendChild(statusBadge);
        row.appendChild(statusCell);
        tableBody.appendChild(row);
    });
}
function initializeChart() {
    const canvas = document.getElementById(
        'airQualityChart'
    );

    if (!canvas) {
        console.error(
            'Elemen airQualityChart tidak ditemukan.'
        );

        return;
    }

    if (typeof window.Chart === 'undefined') {
        console.warn(
            'Chart.js tidak tersedia. Grafik tidak dibuat.'
        );

        return;
    }

    const ChartConstructor = window.Chart;

    airQualityChart = new ChartConstructor(canvas, {
        type: 'line',

        data: {
            labels: [],

            datasets: [
                {
                    label: 'Estimasi CO2 ekuivalen (PPM)',
                    data: [],
                    borderWidth: 2,
                    tension: 0.25,
                    pointRadius: 3
                }
            ]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,

            interaction: {
                intersect: false,
                mode: 'index'
            },

            scales: {
                y: {
                    beginAtZero: true,

                    title: {
                        display: true,
                        text: 'Estimasi konsentrasi (PPM)'
                    }
                },

                x: {
                    title: {
                        display: true,
                        text: 'Waktu'
                    }
                }
            },

            plugins: {
                legend: {
                    display: true
                }
            }
        }
    });

    console.log('Grafik berhasil dibuat.');
}

function updateChart(data) {
    if (
        !airQualityChart ||
        !Array.isArray(data)
    ) {
        return;
    }

    /*
     * Grafik sebaiknya bergerak dari data lama
     * menuju data terbaru.
     */
    const chronologicalData = [...data].reverse();

    airQualityChart.data.labels =
        chronologicalData.map((item) => {
            return formatTime(item.recorded_at);
        });

    airQualityChart.data.datasets[0].data =
        chronologicalData.map((item) => {
            const value = item.mq135_ppm === null ? NaN : Number(item.mq135_ppm);

            return Number.isNaN(value)
                ? null
                : value;
        });

    airQualityChart.update();
}

function updateAirStatus(status) {
    const element = document.getElementById(
        'airStatus'
    );

    if (!element) {
        console.error(
            'Elemen airStatus tidak ditemukan.'
        );

        return;
    }

    element.textContent =
        status || 'Tidak diketahui';

    element.className =
        `status-badge ${getStatusClass(status)}`;
}

function getStatusClass(status) {
    const normalizedStatus =
        String(status || '').toLowerCase();

    if (
        normalizedStatus === 'normal' ||
        normalizedStatus === 'baik'
    ) {
        return 'status-normal';
    }

    if (
        normalizedStatus === 'waspada' ||
        normalizedStatus === 'sedang'
    ) {
        return 'status-warning';
    }

    if (
        normalizedStatus === 'bahaya' ||
        normalizedStatus === 'buruk'
    ) {
        return 'status-danger';
    }

    return 'status-unknown';
}

function formatDateTime(value) {
    if (!value) {
        return '-';
    }

    return String(value);
}

function formatTime(value) {
    if (!value) {
        return '-';
    }

    const valueParts =
        String(value).split(' ');

    return valueParts.length > 1
        ? valueParts[1]
        : String(value);
}

function formatDeviceStatus(status) {
    if (!status) {
        return '-';
    }

    const normalizedStatus =
        String(status).toLowerCase();

    const labels = {
        online: 'Online',
        offline: 'Offline',
        maintenance: 'Pemeliharaan'
    };

    return labels[normalizedStatus] ?? status;
}

function updateLiveIndicator(status) {
    const indicator = document.querySelector(
        '.live-indicator'
    );

    if (!indicator) {
        return;
    }

    const dot = indicator.querySelector(
        '.live-dot'
    );

    const normalizedStatus =
        String(status || '').toLowerCase();

    const isOnline = normalizedStatus === 'online';

    let label = indicator.querySelector(
        '.live-label'
    );

    if (!label) {
        Array.from(indicator.childNodes)
            .filter((node) => {
                return node.nodeType === Node.TEXT_NODE;
            })
            .forEach((node) => node.remove());

        label = document.createElement('span');
        label.className = 'live-label';
        indicator.appendChild(label);
    }

    label.textContent = isOnline
        ? 'Pembaruan otomatis'
        : 'Perangkat offline';

    indicator.style.color = isOnline
        ? '#64748b'
        : '#dc2626';

    indicator.style.fontWeight = isOnline
        ? '400'
        : '600';

    if (dot) {
        dot.style.backgroundColor = isOnline
            ? '#16a34a'
            : '#dc2626';

        dot.style.boxShadow = isOnline
            ? '0 0 0 4px rgba(22, 163, 74, 0.12)'
            : '0 0 0 4px rgba(220, 38, 38, 0.12)';
    }

    indicator.setAttribute(
        'aria-label',
        isOnline
            ? 'Perangkat online dan pembaruan otomatis aktif'
            : 'Perangkat offline'
    );
}

function appendCell(row, value) {
    const cell = document.createElement('td');

    cell.textContent =
        value === null || value === undefined
            ? '-'
            : String(value);

    row.appendChild(cell);
}

function setText(elementId, value) {
    const element = document.getElementById(
        elementId
    );

    if (!element) {
        console.error(
            `Elemen ${elementId} tidak ditemukan.`
        );

        return;
    }

    element.textContent =
        value === null || value === undefined
            ? '-'
            : String(value);
}

function showError(message) {
    const errorBox = document.getElementById(
        'errorBox'
    );

    if (!errorBox) {
        console.error(message);
        return;
    }

    errorBox.textContent = message;
    errorBox.classList.remove('hidden');
}

function hideError() {
    const errorBox = document.getElementById(
        'errorBox'
    );

    if (!errorBox) {
        return;
    }

    errorBox.textContent = '';
    errorBox.classList.add('hidden');
}
// Tidak memalsukan histori ADC menjadi PPM.
function formatPpm(value) {
    if (value === null || value === undefined || value === '') return 'Belum dikalibrasi';
    const n = Number(value);
    return Number.isFinite(n) ? `${n.toFixed(2)} ppm` : 'Belum dikalibrasi';
}
