<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AACS Expo - Live Demonstration Hub</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen antialiased flex flex-col">

    <!-- Top Navigation / Header -->
    <header class="border-b border-slate-800 bg-slate-900/90 backdrop-blur-md sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-tr from-sky-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-sky-500/20 text-white font-extrabold text-xl">
                    A
                </div>
                <div>
                    <div class="flex items-center space-x-2">
                        <h1 class="text-xl font-bold tracking-tight text-white">AACS Live Expo Showcase</h1>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                            <span class="w-1.5 h-1.5 mr-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Live System
                        </span>
                    </div>
                    <p class="text-xs text-slate-400">Monitoring real-time kamera YOLO, telemetri sensor & status Remote AC</p>
                </div>
            </div>

            <!-- Tab Navigation & Room Selector -->
            <div class="flex items-center space-x-3">
                <!-- Nav Tabs -->
                <div class="flex bg-slate-800 p-1 rounded-xl border border-slate-700 text-xs font-semibold">
                    <a href="/expo?room_id={{ $currentRoom?->id ?? 119 }}" class="px-3 py-1.5 rounded-lg bg-sky-600 text-white shadow-sm flex items-center space-x-1">
                        <span>🚀</span>
                        <span>Expo Demo</span>
                    </a>
                    <a href="/?room_id={{ $currentRoom?->id ?? 119 }}" class="px-3 py-1.5 rounded-lg text-slate-400 hover:text-white transition flex items-center space-x-1">
                        <span>📥</span>
                        <span>CSV Export</span>
                    </a>
                </div>

                <!-- Room Selector -->
                <div class="relative hidden sm:block">
                    <select id="roomSelect" onchange="switchRoom(this.value)" class="bg-slate-800 border border-slate-700 text-slate-100 text-xs font-semibold rounded-lg focus:ring-2 focus:ring-sky-500 block px-3 py-2 shadow-sm">
                        @foreach($rooms as $room)
                            <option value="{{ $room->id }}" {{ ($currentRoom && $currentRoom->id == $room->id) ? 'selected' : '' }}>
                                Room {{ $room->id }}: {{ $room->name }}
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

        <!-- ========================================================================= -->
        <!-- SEKSI 1: VISUALISASI KAMERA & DETEKSI YOLO (BERDAMPINGAN) -->
        <!-- ========================================================================= -->
        <div>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-lg font-bold text-white flex items-center space-x-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-sky-500"></span>
                        <span>1. Kamera Live Stream & Deteksi Okupansi (YOLOv8n)</span>
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Tekan tombol capture untuk mengambil snapshot, mendeteksi jumlah kepala orang, dan mengirimkan datanya ke ESP32 Remote via MQTT.
                    </p>
                </div>
                <div class="hidden sm:flex items-center space-x-2">
                    <span id="remoteOnlineBadge" class="text-xs font-semibold px-2.5 py-1 rounded-lg border {{ $deviceOnline ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border-rose-500/20' }}">
                        {{ $deviceOnline ? '🟢 Remote ESP32 Online' : '🔴 Remote Offline' }}
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <!-- KIRI: LIVE STREAM KAMERA -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 flex flex-col justify-between shadow-sm">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center space-x-2">
                            <span class="text-sky-400 text-sm">📹</span>
                            <h3 class="text-sm font-bold text-white">Live Stream Kamera (USB Webcam)</h3>
                        </div>
                        <span class="text-[11px] px-2 py-0.5 rounded-full bg-rose-500/20 text-rose-300 border border-rose-500/30 flex items-center space-x-1 font-semibold">
                            <span class="w-1.5 h-1.5 rounded-full bg-rose-400 animate-pulse"></span>
                            <span>LIVE (640x480)</span>
                        </span>
                    </div>

                    <div class="relative w-full aspect-[4/3] bg-black rounded-xl overflow-hidden border border-slate-800 flex items-center justify-center">
                        <img id="liveStreamImg" src="/camera/stream" alt="Live Camera Stream" class="w-full h-full object-cover" onerror="handleStreamError(this)">
                        <div id="streamFallback" class="hidden absolute inset-0 bg-slate-900 flex flex-col items-center justify-center text-center p-4">
                            <span class="text-3xl mb-2">📹</span>
                            <p class="text-xs text-slate-300 font-semibold">Stream Kamera Standby</p>
                            <p class="text-[11px] text-slate-500 mt-1">Gunakan tombol capture untuk mengambil snapshot terbaru.</p>
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-between text-xs text-slate-400">
                        <span>Fokus hardware: Terkunci (240)</span>
                        <span id="streamStatusText" class="text-emerald-400 font-medium">● Terhubung langsung ke Raspberry Pi</span>
                    </div>
                </div>

                <!-- KANAN: HASIL DETEKSI BOUNDING BOX & TOMBOL CAPTURE -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 flex flex-col justify-between shadow-sm">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center space-x-2">
                            <span class="text-indigo-400 text-sm">🎯</span>
                            <h3 class="text-sm font-bold text-white">Hasil Bounding Box Deteksi (YOLOv8n)</h3>
                        </div>
                        <span class="text-[11px] px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 font-semibold">
                            Conf: >= 0.60
                        </span>
                    </div>

                    <div class="relative w-full aspect-[4/3] bg-black rounded-xl overflow-hidden border border-slate-800 flex items-center justify-center">
                        <img id="latestDetectionImg" src="/captures/latest_detection.jpg?t={{ time() }}" alt="Hasil Bounding Box YOLO" class="w-full h-full object-cover" onerror="this.src='https://placehold.co/640x480/0f172a/94a3b8?text=Belum+Ada+Capture';">
                        
                        <!-- Overlay Tag Hasil -->
                        <div class="absolute top-3 left-3 bg-slate-900/80 backdrop-blur-md px-3 py-1 rounded-lg border border-slate-700 text-xs font-semibold text-white flex items-center space-x-2">
                            <span>👥 Okupansi:</span>
                            <span id="detectedCountBadge" class="text-emerald-400 text-sm font-bold">{{ $latestOccupancy ? $latestOccupancy->people_count : 0 }} Orang</span>
                        </div>
                    </div>

                    <!-- Tombol Aksi Capture & Quick Metrics -->
                    <div class="mt-4 space-y-3">
                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div class="bg-slate-800/60 p-2 rounded-xl border border-slate-800">
                                <div class="text-[10px] text-slate-400">Terdeteksi</div>
                                <div id="metricPeopleCount" class="text-base font-extrabold text-white">{{ $latestOccupancy ? $latestOccupancy->people_count : 0 }}</div>
                            </div>
                            <div class="bg-slate-800/60 p-2 rounded-xl border border-slate-800">
                                <div class="text-[10px] text-slate-400">Kecepatan Inferensi</div>
                                <div id="metricLatency" class="text-base font-extrabold text-sky-400">~330 ms</div>
                            </div>
                            <div class="bg-slate-800/60 p-2 rounded-xl border border-slate-800">
                                <div class="text-[10px] text-slate-400">MQTT Target</div>
                                <div class="text-xs font-bold text-amber-300 truncate">room/{{ $currentRoom->id }}/occupancy</div>
                            </div>
                        </div>

                        <!-- Action Button -->
                        <button id="btnCapture" onclick="triggerCapture()" class="w-full py-3.5 px-6 rounded-xl bg-gradient-to-r from-sky-500 via-indigo-600 to-purple-600 hover:from-sky-400 hover:via-indigo-500 hover:to-purple-500 font-bold text-sm text-white shadow-lg shadow-indigo-600/25 flex items-center justify-center space-x-2 transition-all transform active:scale-98">
                            <span id="btnIcon" class="text-base">📸</span>
                            <span id="btnText">Capture & Deteksi Okupansi Sekarang</span>
                        </button>

                        <!-- Status Alert Box -->
                        <div id="captureAlert" class="hidden p-3 rounded-xl text-xs font-medium border flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <span id="alertIcon">✅</span>
                                <span id="alertMessage">Data okupansi berhasil dikirim ke ESP32 Remote via MQTT!</span>
                            </div>
                            <span id="alertTime" class="text-[10px] text-slate-400"></span>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SEKSI 2: DUA GRAFIK TERPISAH (SUHU & KELEMBAPAN RUANGAN) -->
        <!-- ========================================================================= -->
        <div>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-4 gap-2">
                <div>
                    <h2 class="text-lg font-bold text-white flex items-center space-x-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                        <span>2. Visualisasi Telemetri Sensor: Suhu & Kelembaban (Terpisah)</span>
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Dua grafik mandiri memantau tren suhu (°C) dan kelembaban (% RH) dari sensor aktif ruangan (Remote / Node) yang dipilih via aplikasi.
                    </p>
                </div>

                <!-- Rentang Waktu Selector Pills -->
                <div class="flex items-center space-x-1 bg-slate-900 border border-slate-800 p-1 rounded-xl text-xs font-semibold">
                    <span class="text-slate-500 text-[11px] px-2 hidden sm:inline">Rentang:</span>
                    <button type="button" onclick="setChartRange('1h')" id="btnRange1h" class="px-3 py-1.5 rounded-lg bg-sky-600 text-white shadow-sm transition">
                        ⏱️ 1 Jam Terakhir
                    </button>
                    <button type="button" onclick="setChartRange('15m')" id="btnRange15m" class="px-3 py-1.5 rounded-lg text-slate-400 hover:text-white transition">
                        15 Menit
                    </button>
                    <button type="button" onclick="setChartRange('realtime')" id="btnRangeRealtime" class="px-3 py-1.5 rounded-lg text-slate-400 hover:text-white transition">
                        Live Tick
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <!-- GRAFIK 1: GRAFIK SUHU RUANGAN (°C) -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center space-x-2">
                                <div class="w-9 h-9 rounded-xl bg-orange-500/10 text-orange-400 border border-orange-500/20 flex items-center justify-center text-base">
                                    🌡️
                                </div>
                                <div>
                                    <h3 class="text-sm font-bold text-white">Grafik Suhu Ruangan (°C)</h3>
                                    <div class="text-[11px] text-slate-400 flex items-center space-x-2">
                                        <span>Sensor Aktif (Dipilih via App)</span>
                                        <span id="rangeBadgeTemp" class="text-[10px] font-semibold px-2 py-0.5 bg-slate-800 border border-slate-700 rounded-md text-orange-300">1 Jam Terakhir</span>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-xl font-extrabold text-orange-400" id="currentTemp">
                                    {{ $latestSensor ? number_format($latestSensor->temperature, 2) : '--' }} °C
                                </div>
                                <div class="text-[10px] text-slate-400">
                                    Suhu Saat Ini
                                </div>
                            </div>
                        </div>

                        <!-- Mini Quick Stats -->
                        <div class="grid grid-cols-3 gap-2 mb-3 text-center">
                            <div class="bg-slate-950/60 p-2 rounded-xl border border-slate-800/80">
                                <div class="text-[10px] text-slate-400">Minimum</div>
                                <div id="minTemp" class="text-xs font-bold text-slate-200">{{ $stats['temp_min'] ?? '--' }} °C</div>
                            </div>
                            <div class="bg-slate-950/60 p-2 rounded-xl border border-slate-800/80">
                                <div class="text-[10px] text-slate-400">Rata-rata</div>
                                <div id="avgTemp" class="text-xs font-bold text-orange-300">{{ $stats['temp_avg'] ?? '--' }} °C</div>
                            </div>
                            <div class="bg-slate-950/60 p-2 rounded-xl border border-slate-800/80">
                                <div class="text-[10px] text-slate-400">Maksimum</div>
                                <div id="maxTemp" class="text-xs font-bold text-slate-200">{{ $stats['temp_max'] ?? '--' }} °C</div>
                            </div>
                        </div>

                        <div class="h-64 w-full pt-1">
                            <canvas id="tempChart"></canvas>
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-between text-[11px] text-slate-400 pt-2 border-t border-slate-800/80">
                        <span>Topik: <code class="text-orange-300 font-mono">room/{{ $currentRoom->id }}/telemetry</code></span>
                        <span class="flex items-center space-x-1">
                            <span class="w-2.5 h-2.5 rounded-full bg-orange-400"></span>
                            <span class="text-slate-300 font-medium">Fluktuasi Suhu Ruangan (°C)</span>
                        </span>
                    </div>
                </div>

                <!-- GRAFIK 2: GRAFIK KELEMBABAN RUANGAN (% RH) -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center space-x-2">
                                <div class="w-9 h-9 rounded-xl bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 flex items-center justify-center text-base">
                                    💧
                                </div>
                                <div>
                                    <h3 class="text-sm font-bold text-white">Grafik Kelembaban Ruangan (% RH)</h3>
                                    <div class="text-[11px] text-slate-400 flex items-center space-x-2">
                                        <span>Sensor Aktif (Dipilih via App)</span>
                                        <span id="rangeBadgeHum" class="text-[10px] font-semibold px-2 py-0.5 bg-slate-800 border border-slate-700 rounded-md text-cyan-300">1 Jam Terakhir</span>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-xl font-extrabold text-cyan-400" id="currentHum">
                                    {{ $latestSensor ? number_format($latestSensor->humidity, 2) : '--' }} %
                                </div>
                                <div class="text-[10px] text-slate-400">
                                    Kelembaban Saat Ini
                                </div>
                            </div>
                        </div>

                        <!-- Mini Quick Stats -->
                        <div class="grid grid-cols-3 gap-2 mb-3 text-center">
                            <div class="bg-slate-950/60 p-2 rounded-xl border border-slate-800/80">
                                <div class="text-[10px] text-slate-400">Minimum</div>
                                <div id="minHum" class="text-xs font-bold text-slate-200">{{ $stats['hum_min'] ?? '--' }} %</div>
                            </div>
                            <div class="bg-slate-950/60 p-2 rounded-xl border border-slate-800/80">
                                <div class="text-[10px] text-slate-400">Rata-rata</div>
                                <div id="avgHum" class="text-xs font-bold text-cyan-300">{{ $stats['hum_avg'] ?? '--' }} %</div>
                            </div>
                            <div class="bg-slate-950/60 p-2 rounded-xl border border-slate-800/80">
                                <div class="text-[10px] text-slate-400">Maksimum</div>
                                <div id="maxHum" class="text-xs font-bold text-slate-200">{{ $stats['hum_max'] ?? '--' }} %</div>
                            </div>
                        </div>

                        <div class="h-64 w-full pt-1">
                            <canvas id="humChart"></canvas>
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-between text-[11px] text-slate-400 pt-2 border-t border-slate-800/80">
                        <span>Topik: <code class="text-cyan-300 font-mono">room/{{ $currentRoom->id }}/telemetry</code></span>
                        <span class="flex items-center space-x-1">
                            <span class="w-2.5 h-2.5 rounded-full bg-cyan-400"></span>
                            <span class="text-slate-300 font-medium">Relatif Humidity (% RH)</span>
                        </span>
                    </div>
                </div>

            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SEKSI 3: MONITOR STATUS & PERUBAHAN REMOTE AC -->
        <!-- ========================================================================= -->
        <div>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-lg font-bold text-white flex items-center space-x-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-purple-500"></span>
                        <span>3. Status Operasional & Perubahan Remote AC</span>
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Menampilkan apa yang diproses dan diperbarui oleh Remote ESP32 (Suhu setpoint, mode, dan log status).
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- KARTU STATUS AC TERKINI -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-2">
                            <span class="text-purple-400 text-sm">❄️</span>
                            <h3 class="text-sm font-bold text-white">Status Remote AC</h3>
                        </div>
                        <span id="acPowerBadge" class="text-xs font-bold px-3 py-1 rounded-full {{ ($latestAc && $latestAc->power === 'ON') ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : 'bg-slate-800 text-slate-400 border border-slate-700' }}">
                            {{ $latestAc ? $latestAc->power : 'OFF' }}
                        </span>
                    </div>

                    <!-- Target Suhu Display Besar -->
                    <div class="bg-slate-950/60 border border-slate-800/80 rounded-xl p-4 text-center">
                        <div class="text-xs text-slate-400 font-medium">Suhu Setpoint Target</div>
                        <div id="acSetpointVal" class="text-4xl font-extrabold text-white mt-1">
                            {{ $latestAc ? $latestAc->setpoint : '24' }}<span class="text-xl text-sky-400 font-normal">°C</span>
                        </div>
                        <div id="acUpdatedTime" class="text-[10px] text-slate-500 mt-1">
                            {{ $latestAc ? 'Terakhir diupdate: ' . \Carbon\Carbon::parse($latestAc->created_at)->timezone('Asia/Jakarta')->format('H:i:s') . ' WIB' : 'Belum ada data' }}
                        </div>
                    </div>

                    <!-- Detail Pengaturan Remote -->
                    <div class="space-y-2 text-xs">
                        <div class="flex justify-between py-1.5 border-b border-slate-800">
                            <span class="text-slate-400">Mode AC:</span>
                            <span id="acModeVal" class="font-semibold text-white">{{ $latestAc ? $latestAc->mode : 'COOL' }}</span>
                        </div>
                        <div class="flex justify-between py-1.5 border-b border-slate-800">
                            <span class="text-slate-400">Kecepatan Kipas:</span>
                            <span id="acFanVal" class="font-semibold text-white">{{ $latestAc ? $latestAc->fan_speed : 'AUTO' }}</span>
                        </div>
                        <div class="flex justify-between py-1.5 border-b border-slate-800">
                            <span class="text-slate-400">Swing AC:</span>
                            <span id="acSwingVal" class="font-semibold text-white">{{ $latestAc ? $latestAc->swing : 'AUTO' }}</span>
                        </div>
                        <div class="flex justify-between py-1.5 border-b border-slate-800">
                            <span class="text-slate-400">Sumber Kendali:</span>
                            <span id="acControlSourceVal" class="font-semibold text-sky-400">{{ $latestAc ? $latestAc->control_source : 'REMOTE' }}</span>
                        </div>
                        <div class="flex justify-between py-1.5">
                            <span class="text-slate-400">Baterai Node Sensor:</span>
                            <span id="nodeBatteryVal" class="font-semibold {{ ($batteryStatus && $batteryStatus->percent <= 20) ? 'text-rose-400' : 'text-emerald-400' }}">
                                {{ $batteryStatus && $batteryStatus->valid ? $batteryStatus->percent . '% (' . $batteryStatus->millivolts . ' mV)' : 'Tersambung (USB)' }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- DAFTAR RIWAYAT PERUBAHAN STATUS REMOTE (2 KOLOM) -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 lg:col-span-2 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-bold text-white flex items-center space-x-2">
                                <span>📋</span>
                                <span>Riwayat Perubahan Status Terkini dari Remote</span>
                            </h3>
                            <span class="text-[11px] text-slate-500">10 event terakhir</span>
                        </div>

                        <div class="overflow-x-auto">
                            <div class="space-y-2" id="acHistoryFeed">
                                @forelse($acHistory as $h)
                                    <div class="p-3 bg-slate-950/70 border border-slate-800 rounded-xl flex items-center justify-between text-xs">
                                        <div class="flex items-center space-x-3">
                                            <span class="font-mono text-[11px] text-slate-400">{{ \Carbon\Carbon::parse($h->created_at)->timezone('Asia/Jakarta')->format('H:i:s') }}</span>
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold {{ $h->power === 'ON' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-slate-800 text-slate-400' }}">{{ $h->power }}</span>
                                            <span class="text-white font-medium">Suhu: <strong class="text-sky-400">{{ $h->setpoint }}°C</strong></span>
                                            <span class="text-slate-400">Mode: {{ $h->mode }}</span>
                                            <span class="text-slate-400 hidden sm:inline">Fan: {{ $h->fan_speed }}</span>
                                        </div>
                                        <div class="text-[11px] font-semibold text-sky-400">
                                            {{ $h->control_source }}
                                        </div>
                                    </div>
                                @empty
                                    <div class="text-center py-8 text-xs text-slate-500">Belum ada log perubahan status AC.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-t border-slate-800 text-xs text-slate-500 flex items-center justify-between">
                        <span>Topik MQTT Status: <code class="text-slate-300 font-mono">room/{{ $currentRoom->id }}/ac/status</code></span>
                        <span>Auto-update realtime</span>
                    </div>
                </div>

            </div>
        </div>

        @endif

    </main>

    <footer class="border-t border-slate-800 bg-slate-950/80 py-6 mt-12 text-center text-xs text-slate-500">
        <p>AACS Backend &copy; 2026 &bull; Automatic Air Conditioner System &bull; Laboratorium IoT &bull; Expo Edition</p>
    </footer>

    <!-- Client-side Logic (Chart.js + AJAX Polling + Capture Trigger) -->
    <script>
        const roomId = {{ $currentRoom ? $currentRoom->id : 119 }};
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        function switchRoom(id) {
            window.location.href = '/expo?room_id=' + id;
        }

        function handleStreamError(img) {
            document.getElementById('streamFallback').classList.remove('hidden');
            document.getElementById('streamStatusText').innerHTML = '⚠️ Stream offline (Webcam standby)';
            document.getElementById('streamStatusText').className = 'text-amber-400 font-medium';
        }

        // =========================================================================
        // INISIALISASI DUA GRAFIK MANDIRI (SUHU & KELEMBABAN) MENGGUNAKAN CHART.JS
        // =========================================================================
        const tempCtx = document.getElementById('tempChart').getContext('2d');
        const humCtx  = document.getElementById('humChart').getContext('2d');

        // Data Awal dari Server (Blade Injection)
        const initialLabels = {!! json_encode($sensorLogs->map(fn($l) => \Carbon\Carbon::parse($l->created_at)->timezone('Asia/Jakarta')->format('H:i:s'))) !!};
        const initialTemps  = {!! json_encode($sensorLogs->map(fn($l) => (float)$l->temperature)) !!};
        const initialHums   = {!! json_encode($sensorLogs->map(fn($l) => (float)$l->humidity)) !!};

        // Generator Konfigurasi Grafik Tunggal Bersih & Responsif
        function createSingleChartConfig(label, color, unit, suggestedMin, suggestedMax) {
            return {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [
                        {
                            label: label,
                            data: [],
                            borderColor: color,
                            backgroundColor: color + '18',
                            borderWidth: 2.5,
                            tension: 0.35,
                            fill: true,
                            pointRadius: 2,
                            pointHoverRadius: 6,
                            pointBackgroundColor: color
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 400 },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            borderColor: '#334155',
                            borderWidth: 1,
                            callbacks: {
                                label: function(context) {
                                    return ` ${context.dataset.label}: ${context.parsed.y} ${unit}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: '#1e293b' },
                            ticks: { color: '#64748b', font: { size: 10 }, maxTicksLimit: 6 }
                        },
                        y: {
                            type: 'linear',
                            grid: { color: '#1e293b' },
                            suggestedMin: suggestedMin,
                            suggestedMax: suggestedMax,
                            ticks: {
                                color: color,
                                font: { size: 10 },
                                callback: function(v) { return v + ' ' + unit; }
                            }
                        }
                    }
                }
            };
        }

        // 1. Inisialisasi Grafik Suhu (Warna Oranye Hangat)
        const tempChart = new Chart(tempCtx, createSingleChartConfig('Suhu Ruangan', '#f97316', '°C', 22, 34));
        tempChart.data.labels = initialLabels;
        tempChart.data.datasets[0].data = initialTemps;
        tempChart.update();

        // 2. Inisialisasi Grafik Kelembaban (Warna Cyan/Biru Segar)
        const humChart = new Chart(humCtx, createSingleChartConfig('Kelembaban Ruangan', '#06b6d4', '%', 50, 90));
        humChart.data.labels = initialLabels;
        humChart.data.datasets[0].data = initialHums;
        humChart.update();


        // =========================================================================
        // PENGATURAN RENTANG WAKTU GRAFIK (1 JAM, 15 MENIT, LIVE TICK)
        // =========================================================================
        let activeRange = '1h';

        function setChartRange(range) {
            activeRange = range;

            const btn1h = document.getElementById('btnRange1h');
            const btn15m = document.getElementById('btnRange15m');
            const btnRt = document.getElementById('btnRangeRealtime');

            [btn1h, btn15m, btnRt].forEach(b => {
                b.className = 'px-3 py-1.5 rounded-lg text-slate-400 hover:text-white transition';
            });

            const badgeText = range === '1h' ? '1 Jam Terakhir' : (range === '15m' ? '15 Menit Terakhir' : 'Live Realtime');
            document.getElementById('rangeBadgeTemp').innerText = badgeText;
            document.getElementById('rangeBadgeHum').innerText = badgeText;

            if (range === '1h') {
                btn1h.className = 'px-3 py-1.5 rounded-lg bg-sky-600 text-white shadow-sm transition';
            } else if (range === '15m') {
                btn15m.className = 'px-3 py-1.5 rounded-lg bg-sky-600 text-white shadow-sm transition';
            } else {
                btnRt.className = 'px-3 py-1.5 rounded-lg bg-sky-600 text-white shadow-sm transition';
            }

            // Langsung fetch data sesuai rentang baru
            fetchLiveData();
        }

        // =========================================================================
        // AJAX TRIGGER CAPTURE & YOLO INFERENCE
        // =========================================================================
        let isCapturing = false;

        async function triggerCapture() {
            if (isCapturing) return;
            isCapturing = true;

            const btn = document.getElementById('btnCapture');
            const btnText = document.getElementById('btnText');
            const btnIcon = document.getElementById('btnIcon');
            const alertBox = document.getElementById('captureAlert');

            btn.disabled = true;
            btn.classList.add('opacity-75', 'cursor-not-allowed');
            btnIcon.innerHTML = '⏳';
            btnText.innerHTML = 'Mengambil Gambar & Memproses YOLO...';

            try {
                const response = await fetch(`/expo/rooms/${roomId}/capture-detect`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ room_id: roomId })
                });

                const data = await response.json();

                if (response.ok && data.status === 'success') {
                    // Update Foto Bounding Box
                    const img = document.getElementById('latestDetectionImg');
                    img.src = data.image_url;

                    // Update Angka Metrik
                    document.getElementById('detectedCountBadge').innerText = `${data.people_count} Orang`;
                    document.getElementById('metricPeopleCount').innerText = data.people_count;
                    document.getElementById('metricLatency').innerText = `${Math.round(data.latency_ms)} ms`;

                    // Tampilkan Notifikasi Sukses
                    alertBox.className = 'p-3 rounded-xl text-xs font-medium border bg-emerald-500/10 text-emerald-300 border-emerald-500/30 flex items-center justify-between transition-all';
                    document.getElementById('alertIcon').innerText = '✅';
                    document.getElementById('alertMessage').innerText = `Terdeteksi ${data.people_count} orang! Data berhasil dikirim ke ESP32 Remote via MQTT.`;
                    document.getElementById('alertTime').innerText = data.timestamp_wib.split(' ')[1];
                    alertBox.classList.remove('hidden');

                } else {
                    alertBox.className = 'p-3 rounded-xl text-xs font-medium border bg-rose-500/10 text-rose-300 border-rose-500/30 flex items-center justify-between transition-all';
                    document.getElementById('alertIcon').innerText = '⚠️';
                    document.getElementById('alertMessage').innerText = data.message || 'Gagal memproses capture kamera.';
                    alertBox.classList.remove('hidden');
                }

            } catch (err) {
                alertBox.className = 'p-3 rounded-xl text-xs font-medium border bg-rose-500/10 text-rose-300 border-rose-500/30 flex items-center justify-between transition-all';
                document.getElementById('alertIcon').innerText = '❌';
                document.getElementById('alertMessage').innerText = 'Koneksi ke backend gagal: ' + err.message;
                alertBox.classList.remove('hidden');
            } finally {
                isCapturing = false;
                btn.disabled = false;
                btn.classList.remove('opacity-75', 'cursor-not-allowed');
                btnIcon.innerHTML = '📸';
                btnText.innerHTML = 'Capture & Deteksi Okupansi Sekarang';
            }
        }


        // =========================================================================
        // REALTIME AJAX POLLING (Setiap 2.5 Detik)
        // =========================================================================
        async function fetchLiveData() {
            try {
                const res = await fetch(`/expo/rooms/${roomId}/live-data?range=${activeRange}`);
                if (!res.ok) return;
                const json = await res.json();
                if (json.status !== 'success') return;

                // 1. Update Grafik Suhu & Kelembaban
                if (json.sensor_chart && json.sensor_chart.length > 0) {
                    const labels = json.sensor_chart.map(d => d.time);
                    const temps  = json.sensor_chart.map(d => d.temperature);
                    const hums   = json.sensor_chart.map(d => d.humidity);

                    // Update Grafik Suhu
                    tempChart.data.labels = labels;
                    tempChart.data.datasets[0].data = temps;
                    tempChart.update();

                    // Update Grafik Kelembaban
                    humChart.data.labels = labels;
                    humChart.data.datasets[0].data = hums;
                    humChart.update();

                    // Update Nilai Suhu & Kelembaban Terkini
                    if (json.latest_sensor && json.latest_sensor.temperature !== null) {
                        document.getElementById('currentTemp').innerText = `${json.latest_sensor.temperature.toFixed(2)} °C`;
                        document.getElementById('currentHum').innerText = `${json.latest_sensor.humidity.toFixed(2)} %`;
                    }

                    // Update Mini Quick Stats
                    if (json.stats) {
                        if (json.stats.temp_min !== null) document.getElementById('minTemp').innerText = `${json.stats.temp_min} °C`;
                        if (json.stats.temp_max !== null) document.getElementById('maxTemp').innerText = `${json.stats.temp_max} °C`;
                        if (json.stats.temp_avg !== null) document.getElementById('avgTemp').innerText = `${json.stats.temp_avg} °C`;
                        if (json.stats.hum_min !== null) document.getElementById('minHum').innerText = `${json.stats.hum_min} %`;
                        if (json.stats.hum_max !== null) document.getElementById('maxHum').innerText = `${json.stats.hum_max} %`;
                        if (json.stats.hum_avg !== null) document.getElementById('avgHum').innerText = `${json.stats.hum_avg} %`;
                    }
                }

                // 2. Update Status AC Terkini
                if (json.ac_status) {
                    const s = json.ac_status;
                    const powerBadge = document.getElementById('acPowerBadge');
                    powerBadge.innerText = s.power;
                    if (s.power === 'ON') {
                        powerBadge.className = 'text-xs font-bold px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-400 border border-emerald-500/30';
                    } else {
                        powerBadge.className = 'text-xs font-bold px-3 py-1 rounded-full bg-slate-800 text-slate-400 border border-slate-700';
                    }

                    document.getElementById('acSetpointVal').innerHTML = `${s.setpoint}<span class="text-xl text-sky-400 font-normal">°C</span>`;
                    document.getElementById('acUpdatedTime').innerText = `Terakhir diupdate: ${s.updated_at} WIB`;
                    document.getElementById('acModeVal').innerText = s.mode;
                    document.getElementById('acFanVal').innerText = s.fan_speed;
                    document.getElementById('acSwingVal').innerText = s.swing;
                    document.getElementById('acControlSourceVal').innerText = s.control_source;
                }

                // 3. Update Badge Online Remote
                const onlineBadge = document.getElementById('remoteOnlineBadge');
                if (json.device_online) {
                    onlineBadge.className = 'text-xs font-semibold px-2.5 py-1 rounded-lg border bg-emerald-500/10 text-emerald-400 border-emerald-500/20';
                    onlineBadge.innerText = '🟢 Remote ESP32 Online';
                } else {
                    onlineBadge.className = 'text-xs font-semibold px-2.5 py-1 rounded-lg border bg-rose-500/10 text-rose-400 border-rose-500/20';
                    onlineBadge.innerText = '🔴 Remote Offline';
                }

                // 4. Update Baterai
                if (json.battery) {
                    const b = json.battery;
                    const bVal = document.getElementById('nodeBatteryVal');
                    bVal.innerText = `${b.percent}% (${b.millivolts} mV)`;
                    bVal.className = b.percent <= 20 ? 'font-semibold text-rose-400' : 'font-semibold text-emerald-400';
                }

                // 5. Update Riwayat AC (Feed)
                if (json.ac_history && json.ac_history.length > 0) {
                    const feed = document.getElementById('acHistoryFeed');
                    feed.innerHTML = json.ac_history.map(h => `
                        <div class="p-3 bg-slate-950/70 border border-slate-800 rounded-xl flex items-center justify-between text-xs">
                            <div class="flex items-center space-x-3">
                                <span class="font-mono text-[11px] text-slate-400">${h.time}</span>
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold ${h.power === 'ON' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-slate-800 text-slate-400'}">${h.power}</span>
                                <span class="text-white font-medium">Suhu: <strong class="text-sky-400">${h.setpoint}°C</strong></span>
                                <span class="text-slate-400">Mode: ${h.mode}</span>
                                <span class="text-slate-400 hidden sm:inline">Fan: ${h.fan_speed}</span>
                            </div>
                            <div class="text-[11px] font-semibold text-sky-400">
                                ${h.control_source}
                            </div>
                        </div>
                    `).join('');
                }

            } catch (e) {
                console.error("Error polling live data:", e);
            }
        }

        // Mulai Polling Realtime Tiap 2.5 Detik
        setInterval(fetchLiveData, 2500);
    </script>
</body>
</html>
