<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AACS - CSV Data Export Hub</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen antialiased flex flex-col">

    <!-- Top Navigation / Header -->
    <header class="border-b border-slate-800 bg-slate-900/80 backdrop-blur-md sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-tr from-sky-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-sky-500/20 text-white font-extrabold text-xl">
                    A
                </div>
                <div>
                    <div class="flex items-center space-x-2">
                        <h1 class="text-xl font-bold tracking-tight text-white">AACS Data Export Hub</h1>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                            <span class="w-1.5 h-1.5 mr-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Live System
                        </span>
                    </div>
                    <p class="text-xs text-slate-400">Unduh data CSV telemetri operasional & metrik riset ESP-NOW</p>
                </div>
            </div>

            <!-- Tab Navigation & Room Selector -->
            <div class="flex items-center space-x-3">
                <!-- Nav Tabs -->
                <div class="flex bg-slate-800 p-1 rounded-xl border border-slate-700 text-xs font-semibold">
                    <a href="/expo?room_id={{ $currentRoom?->id ?? 119 }}" class="px-3 py-1.5 rounded-lg text-slate-400 hover:text-white transition flex items-center space-x-1">
                        <span>🚀</span>
                        <span>Expo Demo</span>
                    </a>
                    <a href="/?room_id={{ $currentRoom?->id ?? 119 }}" class="px-3 py-1.5 rounded-lg bg-sky-600 text-white shadow-sm flex items-center space-x-1">
                        <span>📥</span>
                        <span>CSV Export</span>
                    </a>
                </div>

                <div class="relative hidden sm:block">
                    <select id="roomSelect" onchange="switchRoom(this.value)" class="bg-slate-800 border border-slate-700 text-slate-100 text-xs font-semibold rounded-lg focus:ring-2 focus:ring-sky-500 block px-3 py-2 shadow-sm">
                        @foreach($rooms as $room)
                            <option value="{{ $room->id }}" {{ ($currentRoom && $currentRoom->id == $room->id) ? 'selected' : '' }}>
                                Room {{ $room->id }}: {{ $room->name }} ({{ $room->ac_brand }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-grow max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

        @if(!$currentRoom)
            <div class="bg-amber-500/10 border border-amber-500/20 rounded-xl p-6 text-amber-300">
                Belum ada ruangan yang terdaftar di sistem.
            </div>
        @else

        <!-- Room Overview & Quick KPI Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <!-- Metric 1 -->
            <div class="bg-slate-800/60 border border-slate-700/60 rounded-xl p-4 shadow-sm hover:border-slate-600 transition">
                <div class="flex items-center justify-between text-slate-400 text-xs font-medium mb-1">
                    <span>Suhu & Kelembaban</span>
                    <span class="text-sky-400">🌡️ SHT31</span>
                </div>
                <div class="text-2xl font-extrabold text-white">
                    {{ number_format($stats['sensor_today']) }}
                    <span class="text-xs font-normal text-slate-400">hari ini</span>
                </div>
                <div class="text-xs text-slate-500 mt-1">Total riwayat: {{ number_format($stats['sensor_total']) }} baris (retensi 3 hari)</div>
            </div>

            <!-- Metric 2 -->
            <div class="bg-slate-800/60 border border-slate-700/60 rounded-xl p-4 shadow-sm hover:border-slate-600 transition">
                <div class="flex items-center justify-between text-slate-400 text-xs font-medium mb-1">
                    <span>Okupansi (Orang)</span>
                    <span class="text-emerald-400">👥 YOLOv8n</span>
                </div>
                <div class="text-2xl font-extrabold text-white">
                    {{ number_format($stats['occupancy_today']) }}
                    <span class="text-xs font-normal text-slate-400">hari ini</span>
                </div>
                <div class="text-xs text-slate-500 mt-1">Total riwayat: {{ number_format($stats['occupancy_total']) }} baris (retensi 3 hari)</div>
            </div>

            <!-- Metric 3 -->
            <div class="bg-slate-800/60 border border-slate-700/60 rounded-xl p-4 shadow-sm hover:border-slate-600 transition">
                <div class="flex items-center justify-between text-slate-400 text-xs font-medium mb-1">
                    <span>Riwayat Kontrol AC</span>
                    <span class="text-indigo-400">❄️ {{ $currentRoom->ac_brand }}</span>
                </div>
                <div class="text-2xl font-extrabold text-white">
                    {{ number_format($stats['ac_logs_today']) }}
                    <span class="text-xs font-normal text-slate-400">hari ini</span>
                </div>
                <div class="text-xs text-slate-500 mt-1">Total riwayat: {{ number_format($stats['ac_logs_total']) }} baris (retensi 3 hari)</div>
            </div>

            <!-- Metric 4 -->
            <div class="bg-slate-800/60 border border-slate-700/60 rounded-xl p-4 shadow-sm hover:border-slate-600 transition">
                <div class="flex items-center justify-between text-slate-400 text-xs font-medium mb-1">
                    <span>Sesi Riset Link</span>
                    <span class="text-purple-400">📡 ESP-NOW</span>
                </div>
                <div class="text-2xl font-extrabold text-white">
                    {{ count($sessions) }}
                    <span class="text-xs font-normal text-slate-400">sesi</span>
                </div>
                <div class="text-xs text-slate-500 mt-1">Total sampel riset link metrics (permanen / tanpa batas)</div>
            </div>
        </div>

        <!-- Section 1: Data Operasional & Telemetri Ruangan -->
        <div>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-lg font-bold text-white flex items-center space-x-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-sky-500"></span>
                        <span>1. Ekspor Data Telemetri & Log Operasional</span>
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Rentang unduh disesuaikan dengan retensi database: Sensor, AC & Okupansi (3 hari), Baterai (7 hari).
                    </p>
                </div>
                <span class="text-xs text-slate-400 hidden sm:block">Format: RFC 4180 CSV (BOM UTF-8, Timestamp UTC & WIB)</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">

                <!-- CARD 1: SENSOR SUHU & KELEMBABAN -->
                <div class="bg-slate-800/40 border border-slate-800 rounded-2xl p-6 flex flex-col justify-between hover:border-slate-700 transition">
                    <div>
                        <div class="w-12 h-12 rounded-xl bg-sky-500/10 text-sky-400 border border-sky-500/20 flex items-center justify-center text-2xl mb-4">
                            🌡️
                        </div>
                        <h3 class="text-base font-bold text-white mb-1">Sensor Log (SHT31)</h3>
                        <p class="text-xs text-slate-400 leading-relaxed mb-4">
                            Data pembacaan suhu (°C), kelembaban relatif (%RH), ID ruangan, dan timestamp.
                        </p>

                        <div class="space-y-3 mb-6">
                            <label class="text-xs font-semibold text-slate-300 block">Rentang Waktu:</label>
                            <select id="sensorRange" onchange="toggleDateInput('sensor')" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-xs font-medium text-slate-200">
                                <option value="today">Hari Ini Saja (Default)</option>
                                <option value="days_2">2 Hari Terakhir</option>
                                <option value="days_3">3 Hari Terakhir (Semua Data Tersedia)</option>
                                <option value="custom">Pilih Tanggal (Maks 3 Hari Lalu)</option>
                            </select>
                            <div id="sensorDateContainer" class="hidden space-y-1">
                                <input type="date" id="sensorDate" value="{{ date('Y-m-d') }}" min="{{ date('Y-m-d', strtotime('-3 days')) }}" max="{{ date('Y-m-d') }}" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-200">
                                <div class="text-[10px] text-slate-400">Tersedia: {{ date('d M', strtotime('-3 days')) }} s/d Hari Ini</div>
                            </div>
                            <div class="text-[11px] text-sky-400/90 pt-1 flex items-center space-x-1">
                                <span>ℹ️</span>
                                <span>Retensi database: 3 hari (data >3 hari otomatis terhapus)</span>
                            </div>
                        </div>
                    </div>

                    <button onclick="downloadCsv('sensor')" class="w-full py-2.5 px-4 rounded-xl bg-sky-600 hover:bg-sky-500 active:bg-sky-700 text-white font-semibold text-xs transition flex items-center justify-center space-x-2 shadow-lg shadow-sky-600/20">
                        <span>📥</span>
                        <span>Unduh CSV Suhu</span>
                    </button>
                </div>

                <!-- CARD 2: RIWAYAT KONTROL AC -->
                <div class="bg-slate-800/40 border border-slate-800 rounded-2xl p-6 flex flex-col justify-between hover:border-slate-700 transition">
                    <div>
                        <div class="w-12 h-12 rounded-xl bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 flex items-center justify-center text-2xl mb-4">
                            ❄️
                        </div>
                        <h3 class="text-base font-bold text-white mb-1">Riwayat Kontrol AC</h3>
                        <p class="text-xs text-slate-400 leading-relaxed mb-4">
                            Riwayat status power AC, setpoint suhu, mode, fan speed, swing, dan sumber kontrol.
                        </p>

                        <div class="space-y-3 mb-6">
                            <label class="text-xs font-semibold text-slate-300 block">Rentang Waktu:</label>
                            <select id="acRange" onchange="toggleDateInput('ac')" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-xs font-medium text-slate-200">
                                <option value="today">Hari Ini Saja (Default)</option>
                                <option value="days_2">2 Hari Terakhir</option>
                                <option value="days_3">3 Hari Terakhir (Semua Data Tersedia)</option>
                                <option value="custom">Pilih Tanggal (Maks 3 Hari Lalu)</option>
                            </select>
                            <div id="acDateContainer" class="hidden space-y-1">
                                <input type="date" id="acDate" value="{{ date('Y-m-d') }}" min="{{ date('Y-m-d', strtotime('-3 days')) }}" max="{{ date('Y-m-d') }}" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-200">
                                <div class="text-[10px] text-slate-400">Tersedia: {{ date('d M', strtotime('-3 days')) }} s/d Hari Ini</div>
                            </div>
                            <div class="text-[11px] text-indigo-400/90 pt-1 flex items-center space-x-1">
                                <span>ℹ️</span>
                                <span>Retensi database: 3 hari (data >3 hari otomatis terhapus)</span>
                            </div>
                        </div>
                    </div>

                    <button onclick="downloadCsv('ac')" class="w-full py-2.5 px-4 rounded-xl bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 text-white font-semibold text-xs transition flex items-center justify-center space-x-2 shadow-lg shadow-indigo-600/20">
                        <span>📥</span>
                        <span>Unduh CSV Status AC</span>
                    </button>
                </div>

                <!-- CARD 3: OKUPANSI RUANGAN -->
                <div class="bg-slate-800/40 border border-slate-800 rounded-2xl p-6 flex flex-col justify-between hover:border-slate-700 transition">
                    <div>
                        <div class="w-12 h-12 rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 flex items-center justify-center text-2xl mb-4">
                            👥
                        </div>
                        <h3 class="text-base font-bold text-white mb-1">Okupansi Ruangan</h3>
                        <p class="text-xs text-slate-400 leading-relaxed mb-4">
                            Data estimasi jumlah orang/kepala di ruangan hasil inferensi YOLOv8n setiap 2 menit.
                        </p>

                        <div class="space-y-3 mb-6">
                            <label class="text-xs font-semibold text-slate-300 block">Rentang Waktu:</label>
                            <select id="occupancyRange" onchange="toggleDateInput('occupancy')" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-xs font-medium text-slate-200">
                                <option value="today">Hari Ini Saja (Default)</option>
                                <option value="days_2">2 Hari Terakhir</option>
                                <option value="days_3">3 Hari Terakhir (Semua Data Tersedia)</option>
                                <option value="custom">Pilih Tanggal (Maks 3 Hari Lalu)</option>
                            </select>
                            <div id="occupancyDateContainer" class="hidden space-y-1">
                                <input type="date" id="occupancyDate" value="{{ date('Y-m-d') }}" min="{{ date('Y-m-d', strtotime('-3 days')) }}" max="{{ date('Y-m-d') }}" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-200">
                                <div class="text-[10px] text-slate-400">Tersedia: {{ date('d M', strtotime('-3 days')) }} s/d Hari Ini</div>
                            </div>
                            <div class="text-[11px] text-emerald-400/90 pt-1 flex items-center space-x-1">
                                <span>ℹ️</span>
                                <span>Retensi database: 3 hari (data >3 hari otomatis terhapus)</span>
                            </div>
                        </div>
                    </div>

                    <button onclick="downloadCsv('occupancy')" class="w-full py-2.5 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white font-semibold text-xs transition flex items-center justify-center space-x-2 shadow-lg shadow-emerald-600/20">
                        <span>📥</span>
                        <span>Unduh CSV Okupansi</span>
                    </button>
                </div>

                <!-- CARD 4: BATERAI NODE SENSOR -->
                <div class="bg-slate-800/40 border border-slate-800 rounded-2xl p-6 flex flex-col justify-between hover:border-slate-700 transition">
                    <div>
                        <div class="w-12 h-12 rounded-xl bg-amber-500/10 text-amber-400 border border-amber-500/20 flex items-center justify-center text-2xl mb-4">
                            🔋
                        </div>
                        <h3 class="text-base font-bold text-white mb-1">Baterai Node Sensor</h3>
                        <p class="text-xs text-slate-400 leading-relaxed mb-4">
                            Log telemetri berkala tegangan baterai (mV), level persentase (%), dan validitas.
                        </p>

                        <div class="space-y-3 mb-6">
                            <label class="text-xs font-semibold text-slate-300 block">Rentang Retensi:</label>
                            <select id="batteryDays" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-xs font-medium text-slate-200">
                                <option value="1">Hari Ini Saja (1 Hari)</option>
                                <option value="3">3 Hari Terakhir</option>
                                <option value="7" selected>7 Hari Terakhir (Semua Data Tersedia)</option>
                            </select>
                            <div class="text-[11px] text-amber-400/90 pt-1 flex items-center space-x-1">
                                <span>ℹ️</span>
                                <span>Retensi database: 7 hari (data >7 hari otomatis terhapus)</span>
                            </div>
                        </div>
                    </div>

                    <button onclick="downloadBatteryCsv()" class="w-full py-2.5 px-4 rounded-xl bg-amber-600 hover:bg-amber-500 active:bg-amber-700 text-white font-semibold text-xs transition flex items-center justify-center space-x-2 shadow-lg shadow-amber-600/20">
                        <span>📥</span>
                        <span>Unduh CSV Baterai</span>
                    </button>
                </div>

            </div>
        </div>

        <!-- Section: Ekspor Telemetri Sensor Independen (Remote & Node) -->
        <div class="mt-12">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-lg font-bold text-white flex items-center space-x-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-cyan-500"></span>
                        <span>2. Ekspor Telemetri Sensor Independen (Remote & Node)</span>
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Log mentah pembacaan sensor SHT31 dari Remote dan Node secara terpisah serta perbandingan kedua sumber (retensi 30 hari).
                    </p>
                </div>
                <span class="text-xs text-slate-400 hidden sm:block">Retensi: 30 Hari (RFC 4180 CSV, BOM UTF-8)</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                <!-- CARD: TELEMETRI REMOTE -->
                <div class="bg-slate-800/40 border border-slate-800 rounded-2xl p-6 flex flex-col justify-between hover:border-slate-700 transition">
                    <div>
                        <div class="w-12 h-12 rounded-xl bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 flex items-center justify-center text-2xl mb-4">
                            📡
                        </div>
                        <h3 class="text-base font-bold text-white mb-1">Telemetri Remote</h3>
                        <p class="text-xs text-slate-400 leading-relaxed mb-4">
                            Riwayat suhu dan kelembapan SHT31 pada Remote, tetap direkam ketika Node aktif.
                        </p>

                        <div class="space-y-3 mb-6">
                            <label class="text-xs font-semibold text-slate-300 block">Rentang Waktu:</label>
                            <select id="remoteRange" onchange="toggleDateInput('remote')" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-xs font-medium text-slate-200">
                                <option value="today">Hari Ini Saja (Default)</option>
                                <option value="days_3">3 Hari Terakhir</option>
                                <option value="days_7">7 Hari Terakhir</option>
                                <option value="days_30">30 Hari Terakhir (Semua Data Tersedia)</option>
                                <option value="custom">Pilih Tanggal Tertentu</option>
                            </select>
                            <div id="remoteDateContainer" class="hidden space-y-1">
                                <input type="date" id="remoteDate" value="{{ date('Y-m-d') }}" min="{{ date('Y-m-d', strtotime('-30 days')) }}" max="{{ date('Y-m-d') }}" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-200">
                                <div class="text-[10px] text-slate-400">Tersedia: {{ date('d M', strtotime('-30 days')) }} s/d Hari Ini</div>
                            </div>
                            <div class="text-[11px] text-cyan-400/90 pt-1 flex items-center space-x-1">
                                <span>ℹ️</span>
                                <span>Retensi database: 30 hari</span>
                            </div>
                        </div>
                    </div>

                    <button onclick="downloadIndependentCsv('remote')" class="w-full py-2.5 px-4 rounded-xl bg-cyan-600 hover:bg-cyan-500 active:bg-cyan-700 text-white font-semibold text-xs transition flex items-center justify-center space-x-2 shadow-lg shadow-cyan-600/20">
                        <span>📥</span>
                        <span>Unduh CSV Remote</span>
                    </button>
                </div>

                <!-- CARD: TELEMETRI NODE -->
                <div class="bg-slate-800/40 border border-slate-800 rounded-2xl p-6 flex flex-col justify-between hover:border-slate-700 transition">
                    <div>
                        <div class="w-12 h-12 rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 flex items-center justify-center text-2xl mb-4">
                            🌱
                        </div>
                        <h3 class="text-base font-bold text-white mb-1">Telemetri Node</h3>
                        <p class="text-xs text-slate-400 leading-relaxed mb-4">
                            Riwayat suhu dan kelembapan SHT31 Node.
                        </p>

                        <div class="space-y-3 mb-6">
                            <label class="text-xs font-semibold text-slate-300 block">Rentang Waktu:</label>
                            <select id="nodeRange" onchange="toggleDateInput('node')" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-xs font-medium text-slate-200">
                                <option value="today">Hari Ini Saja (Default)</option>
                                <option value="days_3">3 Hari Terakhir</option>
                                <option value="days_7">7 Hari Terakhir</option>
                                <option value="days_30">30 Hari Terakhir (Semua Data Tersedia)</option>
                                <option value="custom">Pilih Tanggal Tertentu</option>
                            </select>
                            <div id="nodeDateContainer" class="hidden space-y-1">
                                <input type="date" id="nodeDate" value="{{ date('Y-m-d') }}" min="{{ date('Y-m-d', strtotime('-30 days')) }}" max="{{ date('Y-m-d') }}" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-200">
                                <div class="text-[10px] text-slate-400">Tersedia: {{ date('d M', strtotime('-30 days')) }} s/d Hari Ini</div>
                            </div>
                            <div class="text-[11px] text-emerald-400/90 pt-1 flex items-center space-x-1">
                                <span>ℹ️</span>
                                <span>Retensi database: 30 hari</span>
                            </div>
                        </div>
                    </div>

                    <button onclick="downloadIndependentCsv('node')" class="w-full py-2.5 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white font-semibold text-xs transition flex items-center justify-center space-x-2 shadow-lg shadow-emerald-600/20">
                        <span>📥</span>
                        <span>Unduh CSV Node</span>
                    </button>
                </div>

                <!-- CARD: PERBANDINGAN REMOTE & NODE -->
                <div class="bg-slate-800/40 border border-slate-800 rounded-2xl p-6 flex flex-col justify-between hover:border-slate-700 transition">
                    <div>
                        <div class="w-12 h-12 rounded-xl bg-violet-500/10 text-violet-400 border border-violet-500/20 flex items-center justify-center text-2xl mb-4">
                            ⚖️
                        </div>
                        <h3 class="text-base font-bold text-white mb-1">Perbandingan Remote & Node</h3>
                        <p class="text-xs text-slate-400 leading-relaxed mb-4">
                            Data mentah kedua sensor dalam format long untuk analisis.
                        </p>

                        <div class="space-y-3 mb-6">
                            <label class="text-xs font-semibold text-slate-300 block">Rentang Waktu:</label>
                            <select id="compareRange" onchange="toggleDateInput('compare')" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-xs font-medium text-slate-200">
                                <option value="today">Hari Ini Saja (Default)</option>
                                <option value="days_3">3 Hari Terakhir</option>
                                <option value="days_7">7 Hari Terakhir</option>
                                <option value="days_30">30 Hari Terakhir (Semua Data Tersedia)</option>
                                <option value="custom">Pilih Tanggal Tertentu</option>
                            </select>
                            <div id="compareDateContainer" class="hidden space-y-1">
                                <input type="date" id="compareDate" value="{{ date('Y-m-d') }}" min="{{ date('Y-m-d', strtotime('-30 days')) }}" max="{{ date('Y-m-d') }}" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-200">
                                <div class="text-[10px] text-slate-400">Tersedia: {{ date('d M', strtotime('-30 days')) }} s/d Hari Ini</div>
                            </div>
                            <div class="text-[11px] text-violet-400/90 pt-1 flex items-center space-x-1">
                                <span>ℹ️</span>
                                <span>Format long terurut waktu (received_at ASC)</span>
                            </div>
                        </div>
                    </div>

                    <button onclick="downloadIndependentCsv('compare')" class="w-full py-2.5 px-4 rounded-xl bg-violet-600 hover:bg-violet-500 active:bg-violet-700 text-white font-semibold text-xs transition flex items-center justify-center space-x-2 shadow-lg shadow-violet-600/20">
                        <span>📥</span>
                        <span>Unduh CSV Perbandingan</span>
                    </button>
                </div>

            </div>
        </div>

        <!-- Section 3: Data Riset Link Metrics ESP-NOW -->
        <div class="mt-12">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-lg font-bold text-white flex items-center space-x-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-purple-500"></span>
                        <span>Telemetri Node & Link Metrics ESP-NOW</span>
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Data evaluasi transmisi ESP-NOW Node ke Remote. PDR dihitung ulang oleh backend dari counter kumulatif.
                    </p>
                </div>
                <span class="text-xs font-semibold px-2.5 py-1 bg-purple-500/10 text-purple-300 border border-purple-500/20 rounded-lg">
                    Tabel: node_link_samples (Tanpa Auto-Pruning)
                </span>
            </div>

            @if(empty($sessions))
                <div class="bg-slate-800/40 border border-slate-800 rounded-2xl p-8 text-center text-slate-400 text-sm">
                    Belum ada sesi link metrics yang tercatat untuk ruangan ini.
                </div>
            @else
                <div class="space-y-3">
                    @foreach($sessions as $s)
                        <div class="bg-slate-800/50 border border-slate-800 hover:border-slate-700 rounded-xl p-5 flex flex-col md:flex-row md:items-center justify-between gap-4 transition">
                            <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                                <div class="w-12 h-12 rounded-xl bg-purple-500/10 border border-purple-500/20 text-purple-400 flex items-center justify-center font-bold text-sm shrink-0">
                                    #{{ substr($s['session_id'], -4) }}
                                </div>
                                <div>
                                    <div class="flex items-center space-x-3 mb-1">
                                        <span class="font-bold text-white text-base">Session ID: {{ $s['session_id'] }}</span>
                                        @if($s['is_complete'])
                                            <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                                                ✓ SELESAI (≤ 20%)
                                            </span>
                                        @else
                                            <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-sky-500/20 text-sky-400 border border-sky-500/30">
                                                ● BERJALAN / AKTIF
                                            </span>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-300">
                                        <span>📊 <strong>PDR:</strong> <span class="text-emerald-400 font-semibold">{{ $s['pdr'] }}%</span></span>
                                        <span>📦 <strong>Sent/Ack:</strong> {{ $s['sent_total'] }} / {{ $s['acked_total'] }} ({{ $s['failed_total'] }} gagal)</span>
                                        <span>🔄 <strong>Retries:</strong> {{ $s['retries_total'] }}</span>
                                        <span>🔋 <strong>Baterai:</strong> {{ $s['battery_start'] ?? '-' }}% &rarr; {{ $s['battery_end'] ?? '-' }}% ({{ $s['battery_mv_end'] ?? '-' }}mV)</span>
                                        <span>🕒 <strong>Sampel:</strong> {{ $s['samples_count'] }} baris</span>
                                    </div>
                                    <div class="text-[11px] text-slate-500 mt-1">
                                        Rentang Waktu: {{ $s['first_at'] }} &mdash; {{ $s['last_at'] }} WIB
                                    </div>
                                </div>
                            </div>

                            <div class="shrink-0">
                                <a href="/api/v1/rooms/{{ $currentRoom->id }}/node-link-sessions/{{ $s['session_id'] }}/export.csv" 
                                   download="node_link_metrics_room_{{ $currentRoom->id }}_session_{{ $s['session_id'] }}.csv"
                                   class="inline-flex items-center justify-center space-x-2 px-4 py-2.5 bg-purple-600 hover:bg-purple-500 active:bg-purple-700 text-white text-xs font-semibold rounded-xl shadow-lg shadow-purple-600/20 transition">
                                    <span>📥</span>
                                    <span>Unduh CSV Lengkap Sesi {{ $s['session_id'] }}</span>
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Section 3: REST API Reference (Untuk Script / Python / Curl) -->
        <div class="mt-12 bg-slate-800/30 border border-slate-800/60 rounded-2xl p-6">
            <details class="group">
                <summary class="flex items-center justify-between cursor-pointer list-none text-sm font-semibold text-slate-300 hover:text-white">
                    <span class="flex items-center space-x-2">
                        <span>💡</span>
                        <span>Panduan Endpoint Langsung untuk Python / Pandas / Skrip Otomatis</span>
                    </span>
                    <span class="transition group-open:rotate-180 text-xs text-slate-500">▼</span>
                </summary>
                <div class="mt-4 pt-4 border-t border-slate-800/80 text-xs text-slate-300 space-y-3 font-mono leading-relaxed">
                    <p class="text-slate-400 font-sans">
                        Temanmu dapat langsung membaca CSV ke dalam dataframe Python pandas tanpa otentikasi login:
                    </p>
                    <div class="bg-slate-950 p-4 rounded-xl border border-slate-800 overflow-x-auto text-emerald-400">
                        <pre><code>import pandas as pd

url = "https://{{ request()->getHttpHost() }}/api/v1/rooms/{{ $currentRoom->id }}/node-link-sessions/{{ $sessions[0]['session_id'] ?? 55881234 }}/export.csv"
df = pd.read_csv(url, storage_options={"User-Agent": "Mozilla/5.0"})

samples = df[df["Record_Type"] == "SAMPLE"].copy()
summaries = df[df["Record_Type"] == "SUMMARY"].copy()

if len(summaries) != 1:
    raise ValueError(f"CSV harus memiliki tepat satu baris SUMMARY, ditemukan {len(summaries)}")

summary = summaries.iloc[0]

print("=== HASIL ANALISIS LINK METRICS ===")
print("Session ID:", summary["Session_ID"])
print("Status sesi:", summary["Session_State"])
print("Baris sampel yang tersimpan:", len(samples))
print("Total pengiriman Node:", int(summary["Sent_Total"]))
print("Total berhasil (ACK):", int(summary["Acked_Total"]))
print("Total gagal:", int(summary["Failed_Total"]))
print("PDR (%):", summary["PDR_Percent"])
print("Jumlah RSSI valid:", int(summary["RSSI_Valid_Count"]))
print("RSSI rata-rata (dBm):", summary["RSSI_Average_dBm"])
print("RSSI minimum/lemah (dBm):", summary["RSSI_Minimum_dBm"])
print("RSSI maksimum/kuat (dBm):", summary["RSSI_Maximum_dBm"])
print("RSSI valid terbaru (dBm):", summary["RSSI_Latest_dBm"])</code></pre>
                    </div>
                </div>
            </details>
        </div>

        @endif

    </main>

    <footer class="border-t border-slate-800 bg-slate-950/60 py-6 mt-12 text-center text-xs text-slate-500">
        <p>AACS Backend &copy; 2026 &bull; Automatic Air Conditioner System &bull; Laboratorium IoT</p>
    </footer>

    <!-- Client-side script for dynamic URL handling -->
    <script>
        const roomId = {{ $currentRoom ? $currentRoom->id : 119 }};

        function switchRoom(id) {
            window.location.href = '/?room_id=' + id;
        }

        function toggleDateInput(type) {
            const range = document.getElementById(type + 'Range').value;
            const container = document.getElementById(type + 'DateContainer');
            if (range === 'custom') {
                container.classList.remove('hidden');
            } else {
                container.classList.add('hidden');
            }
        }

        function downloadCsv(type) {
            const range = document.getElementById(type + 'Range').value;
            let url = `/api/v1/rooms/${roomId}/export-csv?type=${type}`;

            if (range === 'today') {
                url += '&date=today';
            } else if (range === 'days_1') {
                url += '&days=1';
            } else if (range === 'days_2') {
                url += '&days=2';
            } else if (range === 'days_3') {
                url += '&days=3';
            } else if (range === 'custom') {
                const dateVal = document.getElementById(type + 'Date').value;
                if (!dateVal) {
                    alert('Silakan pilih tanggal terlebih dahulu.');
                    return;
                }
                url += `&date=${dateVal}`;
            }

            window.location.href = url;
        }

        function downloadBatteryCsv() {
            const days = document.getElementById('batteryDays').value;
            const url = `/api/v1/rooms/${roomId}/battery/export-csv?days=${days}`;
            window.location.href = url;
        }

        function downloadIndependentCsv(target) {
            const range = document.getElementById(target + 'Range').value;
            let endpoint = '';
            if (target === 'remote') {
                endpoint = `/api/v1/rooms/${roomId}/remote-telemetry/export.csv`;
            } else if (target === 'node') {
                endpoint = `/api/v1/rooms/${roomId}/node-telemetry/export.csv`;
            } else if (target === 'compare') {
                endpoint = `/api/v1/rooms/${roomId}/sensor-sources/export.csv`;
            }

            let params = [];
            if (range === 'today') {
                params.push('date=today');
            } else if (range === 'days_3') {
                params.push('days=3');
            } else if (range === 'days_7') {
                params.push('days=7');
            } else if (range === 'days_30') {
                params.push('days=30');
            } else if (range === 'custom') {
                const dateVal = document.getElementById(target + 'Date').value;
                if (!dateVal) {
                    alert('Silakan pilih tanggal terlebih dahulu.');
                    return;
                }
                params.push(`date=${dateVal}`);
            }

            const url = endpoint + (params.length ? '?' + params.join('&') : '');
            window.location.href = url;
        }
    </script>
</body>
</html>
