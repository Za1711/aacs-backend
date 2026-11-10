<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AACS Expo - Live Showcase</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Google Fonts: Lora (Serif) + Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,500;0,600;0,700;1,400&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            -webkit-tap-highlight-color: transparent;
            background-color: #f5f1e7;
        }
        .font-serif-title {
            font-family: 'Lora', Georgia, serif;
        }
        /* Custom smooth scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #ede6d8;
        }
        ::-webkit-scrollbar-thumb {
            background: #c9bea9;
            border-radius: 9999px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #a89c85;
        }
    </style>
</head>
<body class="text-slate-800 min-h-screen antialiased flex flex-col overflow-x-hidden" style="background-color: #f5f1e7;">

    <!-- Top Navigation / Header (SISAMAR Warm Ivory & Deep Navy Aesthetic) -->
    <header class="border-b border-[#e5decf] bg-[#f5f1e7]/90 backdrop-blur-md sticky top-0 z-50 transition-all">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3.5 sm:py-0 sm:h-20 flex flex-col sm:flex-row items-center justify-between gap-3 sm:gap-0">
            
            <!-- Logo & Brand Header -->
            <div class="flex items-center justify-between w-full sm:w-auto">
                <div class="flex items-center space-x-3.5">
                    <div class="w-10 h-10 sm:w-11 sm:h-11 rounded-2xl bg-[#1b3b6f] flex items-center justify-center shadow-sm text-white font-bold font-serif-title text-xl">
                        A
                    </div>
                    <div>
                        <div class="text-[10px] tracking-widest uppercase font-semibold text-[#9c7423]">
                            Sistem Kontrol AC Cerdas
                        </div>
                        <div class="flex items-center space-x-2">
                            <h1 class="text-lg sm:text-xl font-bold tracking-tight text-[#1b3b6f] font-serif-title">
                                AACS Live Expo
                            </h1>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-[#eef7ee] text-[#1e6b24] border border-[#c8e6c9]">
                                <span class="w-1.5 h-1.5 mr-1.5 rounded-full bg-[#1e6b24] animate-pulse"></span> Live
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Status Online Badge (Mobile Only, Right Top) -->
                <div class="sm:hidden">
                    <span id="remoteOnlineBadgeMobile" class="text-[10px] font-semibold px-2.5 py-1 rounded-xl border {{ $deviceOnline ? 'bg-[#eef7ee] text-[#1e6b24] border-[#c8e6c9]' : 'bg-[#fdebee] text-[#b71c1c] border-[#ffcdd2]' }}">
                        {{ $deviceOnline ? 'Online' : 'Offline' }}
                    </span>
                </div>
            </div>

            <!-- Tab Navigation & Room Selector -->
            <div class="flex items-center justify-between w-full sm:w-auto space-x-2 sm:space-x-3">
                <!-- Nav Tabs -->
                <div class="flex bg-[#e8e1d1] p-1 rounded-xl border border-[#ded5c2] text-xs font-semibold flex-1 sm:flex-none">
                    <a href="/expo?room_id={{ $currentRoom?->id ?? 119 }}" class="flex-1 sm:flex-initial text-center px-3.5 py-1.5 rounded-lg bg-white text-[#1b3b6f] shadow-sm transition">
                        Expo Demo
                    </a>
                    <a href="/?room_id={{ $currentRoom?->id ?? 119 }}" class="flex-1 sm:flex-initial text-center px-3.5 py-1.5 rounded-lg text-slate-600 hover:text-[#1b3b6f] transition">
                        CSV Export
                    </a>
                </div>

                <!-- Room Selector -->
                <div class="relative">
                    <select id="roomSelect" onchange="switchRoom(this.value)" class="bg-white border border-[#ded5c2] text-[#1b3b6f] text-xs font-semibold rounded-xl focus:ring-2 focus:ring-[#1b3b6f] block px-3 py-1.5 sm:py-2 shadow-sm pr-7 cursor-pointer">
                        @foreach($rooms as $room)
                            <option value="{{ $room->id }}" {{ ($currentRoom && $currentRoom->id == $room->id) ? 'selected' : '' }}>
                                Room {{ $room->id }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Status Online Badge (Desktop Only) -->
                <div class="hidden sm:block">
                    <span id="remoteOnlineBadge" class="text-xs font-semibold px-3 py-1.5 rounded-xl border {{ $deviceOnline ? 'bg-[#eef7ee] text-[#1e6b24] border-[#c8e6c9]' : 'bg-[#fdebee] text-[#b71c1c] border-[#ffcdd2]' }}">
                        {{ $deviceOnline ? '● Remote Online' : '○ Remote Offline' }}
                    </span>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-grow max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-5 sm:py-8 space-y-6 sm:space-y-8">

        @if(!$currentRoom)
            <div class="bg-white border border-[#e5decf] rounded-2xl p-6 text-amber-900 text-sm shadow-sm">
                Belum ada ruangan yang terdaftar di sistem.
            </div>
        @else

        <!-- ========================================================================= -->
        <!-- SEKSI 1: VISUALISASI KAMERA & DETEKSI YOLO (RESPONSIF HP & DESKTOP) -->
        <!-- ========================================================================= -->
        <div>
            <div class="mb-3 sm:mb-4">
                <div class="text-[10px] sm:text-[11px] tracking-widest uppercase font-semibold text-[#9c7423]">
                    Visi Komputer Real-time
                </div>
                <h2 class="text-lg sm:text-xl font-bold text-[#1b3b6f] font-serif-title">
                    1. Kamera Live Stream & Deteksi Okupansi
                </h2>
                <p class="text-xs text-slate-600 mt-0.5">
                    Ambil frame visual untuk mendeteksi kepala orang (YOLOv8n) dan kirimkan data secara instan ke ESP32 Remote.
                </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">

                <!-- KIRI: LIVE STREAM KAMERA -->
                <div class="bg-white border border-[#e5decf] rounded-2xl p-4 sm:p-5 flex flex-col justify-between shadow-[0_2px_12px_rgba(27,59,111,0.04)]">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-bold text-[#1b3b6f] font-serif-title">Live Video Stream</h3>
                        <span class="text-[11px] px-2.5 py-0.5 rounded-full bg-[#fdebee] text-[#b71c1c] border border-[#ffcdd2] flex items-center space-x-1.5 font-semibold">
                            <span class="w-1.5 h-1.5 rounded-full bg-[#b71c1c] animate-pulse"></span>
                            <span>STREAM (640x480)</span>
                        </span>
                    </div>

                    <div class="relative w-full aspect-[4/3] bg-slate-900 rounded-xl overflow-hidden border border-[#ded5c2] flex items-center justify-center">
                        <img id="liveStreamImg" src="/camera/stream" alt="Live Camera Stream" class="w-full h-full object-cover" onerror="handleStreamError(this)">
                        <div id="streamFallback" class="hidden absolute inset-0 bg-[#f5f1e7] flex flex-col items-center justify-center text-center p-4">
                            <p class="text-xs text-[#1b3b6f] font-semibold font-serif-title">Stream Kamera Standby</p>
                            <p class="text-[11px] text-slate-500 mt-1">Gunakan tombol capture untuk mengambil snapshot terbaru.</p>
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-between text-xs text-slate-500">
                        <span>Fokus hardware: Terkunci (240)</span>
                        <span id="streamStatusText" class="text-[#1e6b24] font-medium text-[11px]">● Terhubung ke Raspberry Pi</span>
                    </div>
                </div>

                <!-- KANAN: HASIL DETEKSI BOUNDING BOX & TOMBOL CAPTURE (NO CAMERA SYMBOL) -->
                <div class="bg-white border border-[#e5decf] rounded-2xl p-4 sm:p-5 flex flex-col justify-between shadow-[0_2px_12px_rgba(27,59,111,0.04)]">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-bold text-[#1b3b6f] font-serif-title">Hasil Deteksi Kepala (Bounding Box)</h3>
                        <span class="text-[11px] px-2.5 py-0.5 rounded-full bg-[#f4f7fb] text-[#1b3b6f] border border-[#d8e2ef] font-semibold">
                            Conf: >= 0.60
                        </span>
                    </div>

                    <div class="relative w-full aspect-[4/3] bg-slate-900 rounded-xl overflow-hidden border border-[#ded5c2] flex items-center justify-center">
                        <img id="latestDetectionImg" src="/captures/latest_detection.jpg?t={{ time() }}" alt="Hasil Bounding Box YOLO" class="w-full h-full object-cover" onerror="this.src='https://placehold.co/640x480/f5f1e7/1b3b6f?text=Belum+Ada+Capture';">
                        
                        <!-- Overlay Tag Hasil Okupansi -->
                        <div class="absolute top-3 left-3 bg-white/95 backdrop-blur-md px-3.5 py-1.5 rounded-xl border border-[#ded5c2] shadow-sm text-xs font-semibold text-[#1b3b6f] flex items-center space-x-1.5">
                            <span class="text-slate-500">Okupansi:</span>
                            <span id="detectedCountBadge" class="text-[#1b3b6f] text-sm font-extrabold">{{ $latestOccupancy ? $latestOccupancy->people_count : 0 }} Orang</span>
                        </div>
                    </div>

                    <!-- Tombol Aksi Capture & Metrik Ringkas -->
                    <div class="mt-4 space-y-3">
                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div class="bg-[#fbf9f4] p-2.5 rounded-xl border border-[#ede5d5]">
                                <div class="text-[10px] text-slate-500 font-medium">Terdeteksi</div>
                                <div id="metricPeopleCount" class="text-sm sm:text-base font-extrabold text-[#1b3b6f]">{{ $latestOccupancy ? $latestOccupancy->people_count : 0 }}</div>
                            </div>
                            <div class="bg-[#fbf9f4] p-2.5 rounded-xl border border-[#ede5d5]">
                                <div class="text-[10px] text-slate-500 font-medium">Latensi Inferensi</div>
                                <div id="metricLatency" class="text-sm sm:text-base font-extrabold text-[#9c7423]">~330 ms</div>
                            </div>
                            <div class="bg-[#fbf9f4] p-2.5 rounded-xl border border-[#ede5d5]">
                                <div class="text-[10px] text-slate-500 font-medium">Topik MQTT</div>
                                <div class="text-[11px] sm:text-xs font-bold text-slate-700 truncate">room/{{ $currentRoom->id }}/occupancy</div>
                            </div>
                        </div>

                        <!-- Action Button (SISAMAR Solid Navy Button, Tanpa Simbol Kamera) -->
                        <button id="btnCapture" onclick="triggerCapture()" class="w-full py-3.5 px-6 rounded-xl bg-[#1b3b6f] hover:bg-[#152e57] font-bold text-xs sm:text-sm text-white shadow-sm hover:shadow transition-all transform active:scale-98 flex items-center justify-center space-x-2 cursor-pointer">
                            <span id="btnSpinner" class="hidden">
                                <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                            </span>
                            <span id="btnText">Capture & Deteksi Okupansi Sekarang &rarr;</span>
                        </button>

                        <!-- Status Alert Box -->
                        <div id="captureAlert" class="hidden p-3 rounded-xl text-xs font-medium border flex items-center justify-between transition-all">
                            <div class="flex items-center space-x-2">
                                <span id="alertMessage">Data okupansi berhasil dikirim ke ESP32 Remote via MQTT!</span>
                            </div>
                            <span id="alertTime" class="text-[10px] text-slate-400"></span>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SEKSI 2: DUA GRAFIK TERPISAH (SUHU & KELEMBABAN RUANGAN - TEMA WARM IVORY) -->
        <!-- ========================================================================= -->
        <div>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-3 sm:mb-4 gap-2.5">
                <div>
                    <div class="text-[10px] sm:text-[11px] tracking-widest uppercase font-semibold text-[#9c7423]">
                        Pemantauan Sensor Lingkungan
                    </div>
                    <h2 class="text-lg sm:text-xl font-bold text-[#1b3b6f] font-serif-title">
                        2. Telemetri Sensor: Suhu & Kelembaban
                    </h2>
                    <p class="text-xs text-slate-600 mt-0.5">
                        Menampilkan tren suhu (°C) dan kelembaban (% RH) dari sensor aktif ruangan (Remote / Node).
                    </p>
                </div>

                <!-- Rentang Waktu Selector Pills (Warm Ivory Styling) -->
                <div class="flex items-center space-x-1 bg-[#e8e1d1] border border-[#ded5c2] p-1 rounded-xl text-xs font-semibold self-start sm:self-auto">
                    <button type="button" onclick="setChartRange('1h')" id="btnRange1h" class="px-3 py-1.5 rounded-lg bg-white text-[#1b3b6f] shadow-sm transition">
                        1 Jam
                    </button>
                    <button type="button" onclick="setChartRange('15m')" id="btnRange15m" class="px-3 py-1.5 rounded-lg text-slate-600 hover:text-[#1b3b6f] transition">
                        15 Menit
                    </button>
                    <button type="button" onclick="setChartRange('realtime')" id="btnRangeRealtime" class="px-3 py-1.5 rounded-lg text-slate-600 hover:text-[#1b3b6f] transition">
                        Live
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">

                <!-- GRAFIK 1: GRAFIK SUHU RUANGAN (°C) - WARM AMBER/TERRACOTTA -->
                <div class="bg-white border border-[#e5decf] rounded-2xl p-4 sm:p-5 shadow-[0_2px_12px_rgba(27,59,111,0.04)] flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center space-x-2.5">
                                <div class="w-8 h-8 rounded-xl bg-[#fbf5e6] text-[#b45309] border border-[#ebd8b0] flex items-center justify-center text-xs font-bold font-serif-title">
                                    °C
                                </div>
                                <div>
                                    <h3 class="text-sm font-bold text-[#1b3b6f] font-serif-title">Suhu Ruangan</h3>
                                    <div class="text-[11px] text-slate-500 flex items-center space-x-1.5">
                                        <span>Sensor Aktif</span>
                                        <span>&bull;</span>
                                        <span id="rangeBadgeTemp" class="font-medium text-[#b45309]">1 Jam Terakhir</span>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-xl sm:text-2xl font-extrabold text-[#b45309]" id="currentTemp">
                                    {{ $latestSensor ? number_format($latestSensor->temperature, 2) : '--' }} °C
                                </div>
                                <div class="text-[10px] text-slate-400">
                                    Saat Ini
                                </div>
                            </div>
                        </div>

                        <!-- Mini Quick Stats -->
                        <div class="grid grid-cols-3 gap-2 mb-3 text-center">
                            <div class="bg-[#fbf9f4] p-2 rounded-xl border border-[#ede5d5]">
                                <div class="text-[10px] text-slate-500 font-medium">Min</div>
                                <div id="minTemp" class="text-xs font-bold text-slate-700">{{ $stats['temp_min'] ?? '--' }} °C</div>
                            </div>
                            <div class="bg-[#fbf9f4] p-2 rounded-xl border border-[#ede5d5]">
                                <div class="text-[10px] text-slate-500 font-medium">Rata-rata</div>
                                <div id="avgTemp" class="text-xs font-bold text-[#b45309]">{{ $stats['temp_avg'] ?? '--' }} °C</div>
                            </div>
                            <div class="bg-[#fbf9f4] p-2 rounded-xl border border-[#ede5d5]">
                                <div class="text-[10px] text-slate-500 font-medium">Maks</div>
                                <div id="maxTemp" class="text-xs font-bold text-slate-700">{{ $stats['temp_max'] ?? '--' }} °C</div>
                            </div>
                        </div>

                        <!-- Chart Canvas (Responsive Height) -->
                        <div class="h-56 sm:h-64 w-full pt-1">
                            <canvas id="tempChart"></canvas>
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-between text-[11px] text-slate-500 pt-2 border-t border-[#f0eae0]">
                        <span>Topik: <code class="text-slate-700 font-mono">room/{{ $currentRoom->id }}/telemetry</code></span>
                        <span class="flex items-center space-x-1.5">
                            <span class="w-2 h-2 rounded-full bg-[#d97706]"></span>
                            <span class="text-slate-700 font-medium">Fluktuasi Suhu (°C)</span>
                        </span>
                    </div>
                </div>

                <!-- GRAFIK 2: GRAFIK KELEMBABAN RUANGAN (% RH) - DEEP NAVY / OCEAN BLUE -->
                <div class="bg-white border border-[#e5decf] rounded-2xl p-4 sm:p-5 shadow-[0_2px_12px_rgba(27,59,111,0.04)] flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center space-x-2.5">
                                <div class="w-8 h-8 rounded-xl bg-[#f0f4fa] text-[#1b3b6f] border border-[#d5e0f0] flex items-center justify-center text-xs font-bold font-serif-title">
                                    %
                                </div>
                                <div>
                                    <h3 class="text-sm font-bold text-[#1b3b6f] font-serif-title">Kelembaban Ruangan</h3>
                                    <div class="text-[11px] text-slate-500 flex items-center space-x-1.5">
                                        <span>Sensor Aktif</span>
                                        <span>&bull;</span>
                                        <span id="rangeBadgeHum" class="font-medium text-[#1b3b6f]">1 Jam Terakhir</span>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-xl sm:text-2xl font-extrabold text-[#1b3b6f]" id="currentHum">
                                    {{ $latestSensor ? number_format($latestSensor->humidity, 2) : '--' }} %
                                </div>
                                <div class="text-[10px] text-slate-400">
                                    Saat Ini
                                </div>
                            </div>
                        </div>

                        <!-- Mini Quick Stats -->
                        <div class="grid grid-cols-3 gap-2 mb-3 text-center">
                            <div class="bg-[#fbf9f4] p-2 rounded-xl border border-[#ede5d5]">
                                <div class="text-[10px] text-slate-500 font-medium">Min</div>
                                <div id="minHum" class="text-xs font-bold text-slate-700">{{ $stats['hum_min'] ?? '--' }} %</div>
                            </div>
                            <div class="bg-[#fbf9f4] p-2 rounded-xl border border-[#ede5d5]">
                                <div class="text-[10px] text-slate-500 font-medium">Rata-rata</div>
                                <div id="avgHum" class="text-xs font-bold text-[#1b3b6f]">{{ $stats['hum_avg'] ?? '--' }} %</div>
                            </div>
                            <div class="bg-[#fbf9f4] p-2 rounded-xl border border-[#ede5d5]">
                                <div class="text-[10px] text-slate-500 font-medium">Maks</div>
                                <div id="maxHum" class="text-xs font-bold text-slate-700">{{ $stats['hum_max'] ?? '--' }} %</div>
                            </div>
                        </div>

                        <!-- Chart Canvas (Responsive Height) -->
                        <div class="h-56 sm:h-64 w-full pt-1">
                            <canvas id="humChart"></canvas>
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-between text-[11px] text-slate-500 pt-2 border-t border-[#f0eae0]">
                        <span>Topik: <code class="text-slate-700 font-mono">room/{{ $currentRoom->id }}/telemetry</code></span>
                        <span class="flex items-center space-x-1.5">
                            <span class="w-2 h-2 rounded-full bg-[#1b3b6f]"></span>
                            <span class="text-slate-700 font-medium">Relatif Humidity (% RH)</span>
                        </span>
                    </div>
                </div>

            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SEKSI 3: MONITOR STATUS & PERUBAHAN REMOTE AC -->
        <!-- ========================================================================= -->
        <div>
            <div class="mb-3 sm:mb-4">
                <div class="text-[10px] sm:text-[11px] tracking-widest uppercase font-semibold text-[#9c7423]">
                    Integrasi Hardware Remote
                </div>
                <h2 class="text-lg sm:text-xl font-bold text-[#1b3b6f] font-serif-title">
                    3. Status Operasional & Perubahan Remote AC
                </h2>
                <p class="text-xs text-slate-600 mt-0.5">
                    Memantau suhu target setpoint, mode pendingin, dan riwayat kendali yang diterima oleh Remote ESP32.
                </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">

                <!-- KARTU STATUS AC TERKINI -->
                <div class="bg-white border border-[#e5decf] rounded-2xl p-4 sm:p-5 space-y-4 shadow-[0_2px_12px_rgba(27,59,111,0.04)]">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-bold text-[#1b3b6f] font-serif-title">Status Remote AC</h3>
                        <span id="acPowerBadge" class="text-xs font-bold px-3 py-1 rounded-full {{ ($latestAc && $latestAc->power === 'ON') ? 'bg-[#eef7ee] text-[#1e6b24] border border-[#c8e6c9]' : 'bg-[#f4efe4] text-slate-600 border border-[#ded6c5]' }}">
                            {{ $latestAc ? $latestAc->power : 'OFF' }}
                        </span>
                    </div>

                    <!-- Target Suhu Display Besar (Warm Ivory Tint) -->
                    <div class="bg-[#fbf9f4] border border-[#ede5d5] rounded-2xl p-4 text-center">
                        <div class="text-xs text-slate-500 font-medium">Suhu Setpoint Target</div>
                        <div id="acSetpointVal" class="text-3xl sm:text-4xl font-extrabold text-[#1b3b6f] mt-1 font-serif-title">
                            {{ $latestAc ? $latestAc->setpoint : '24' }}<span class="text-xl text-[#9c7423] font-normal">°C</span>
                        </div>
                        <div id="acUpdatedTime" class="text-[10px] text-slate-400 mt-1">
                            {{ $latestAc ? 'Update: ' . \Carbon\Carbon::parse($latestAc->created_at)->timezone('Asia/Jakarta')->format('H:i:s') . ' WIB' : 'Belum ada data' }}
                        </div>
                    </div>

                    <!-- Detail Pengaturan Remote -->
                    <div class="space-y-2 text-xs">
                        <div class="flex justify-between py-1.5 border-b border-[#f0eae0]">
                            <span class="text-slate-500">Mode AC:</span>
                            <span id="acModeVal" class="font-bold text-[#1b3b6f]">{{ $latestAc ? $latestAc->mode : 'COOL' }}</span>
                        </div>
                        <div class="flex justify-between py-1.5 border-b border-[#f0eae0]">
                            <span class="text-slate-500">Kecepatan Kipas:</span>
                            <span id="acFanVal" class="font-bold text-[#1b3b6f]">{{ $latestAc ? $latestAc->fan_speed : 'AUTO' }}</span>
                        </div>
                        <div class="flex justify-between py-1.5 border-b border-[#f0eae0]">
                            <span class="text-slate-500">Swing AC:</span>
                            <span id="acSwingVal" class="font-bold text-[#1b3b6f]">{{ $latestAc ? $latestAc->swing : 'AUTO' }}</span>
                        </div>
                        <div class="flex justify-between py-1.5 border-b border-[#f0eae0]">
                            <span class="text-slate-500">Sumber Kendali:</span>
                            <span id="acControlSourceVal" class="font-bold text-[#9c7423]">{{ $latestAc ? $latestAc->control_source : 'REMOTE' }}</span>
                        </div>
                        <div class="flex justify-between py-1.5">
                            <span class="text-slate-500">Baterai Node Sensor:</span>
                            <span id="nodeBatteryVal" class="font-bold {{ ($batteryStatus && $batteryStatus->percent <= 20) ? 'text-[#b71c1c]' : 'text-[#1e6b24]' }}">
                                {{ $batteryStatus && $batteryStatus->valid ? $batteryStatus->percent . '% (' . $batteryStatus->millivolts . ' mV)' : 'Tersambung (USB)' }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- DAFTAR RIWAYAT PERUBAHAN STATUS REMOTE (2 KOLOM DI DESKTOP) -->
                <div class="bg-white border border-[#e5decf] rounded-2xl p-4 sm:p-5 lg:col-span-2 flex flex-col justify-between shadow-[0_2px_12px_rgba(27,59,111,0.04)]">
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-bold text-[#1b3b6f] font-serif-title">
                                Riwayat Perubahan Status Terkini
                            </h3>
                            <span class="text-[11px] text-slate-400">10 event terakhir</span>
                        </div>

                        <div class="space-y-2" id="acHistoryFeed">
                            @forelse($acHistory as $h)
                                <div class="p-2.5 sm:p-3 bg-[#fbf9f4] border border-[#ede5d5] rounded-xl flex items-center justify-between text-xs">
                                    <div class="flex items-center space-x-2 sm:space-x-3 overflow-hidden">
                                        <span class="font-mono text-[11px] text-slate-500 flex-shrink-0">{{ \Carbon\Carbon::parse($h->created_at)->timezone('Asia/Jakarta')->format('H:i:s') }}</span>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold flex-shrink-0 {{ $h->power === 'ON' ? 'bg-[#eef7ee] text-[#1e6b24] border border-[#c8e6c9]' : 'bg-[#f4efe4] text-slate-600' }}">{{ $h->power }}</span>
                                        <span class="text-slate-700 font-medium truncate">Suhu: <strong class="text-[#1b3b6f]">{{ $h->setpoint }}°C</strong></span>
                                        <span class="text-slate-500 hidden sm:inline">Mode: {{ $h->mode }}</span>
                                    </div>
                                    <div class="text-[11px] font-semibold text-[#1b3b6f] flex-shrink-0 ml-2">
                                        {{ $h->control_source }}
                                    </div>
                                </div>
                            @empty
                                <div class="text-center py-8 text-xs text-slate-400 font-serif-title">Belum ada log perubahan status AC.</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-t border-[#f0eae0] text-xs text-slate-500 flex items-center justify-between">
                        <span>Topik: <code class="text-slate-700 font-mono">room/{{ $currentRoom->id }}/ac/status</code></span>
                        <span class="text-[#1e6b24] font-medium text-[11px]">● Auto-update live</span>
                    </div>
                </div>

            </div>
        </div>

        @endif

    </main>

    <footer class="border-t border-[#e5decf] bg-[#f5f1e7]/80 py-6 mt-10 text-center text-xs text-slate-500">
        <p>AACS Backend &copy; 2026 &bull; Automatic Air Conditioner System &bull; Laboratorium IoT</p>
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
            document.getElementById('streamStatusText').className = 'text-[#b45309] font-medium text-[11px]';
        }

        // =========================================================================
        // INISIALISASI DUA GRAFIK MANDIRI DENGAN WARNA PASTEL HANGAT & ELEGAN
        // =========================================================================
        const tempCtx = document.getElementById('tempChart').getContext('2d');
        const humCtx  = document.getElementById('humChart').getContext('2d');

        // Data Awal dari Server (Blade Injection)
        const initialLabels = {!! json_encode($sensorLogs->map(fn($l) => \Carbon\Carbon::parse($l->created_at)->timezone('Asia/Jakarta')->format('H:i:s'))) !!};
        const initialTemps  = {!! json_encode($sensorLogs->map(fn($l) => (float)$l->temperature)) !!};
        const initialHums   = {!! json_encode($sensorLogs->map(fn($l) => (float)$l->humidity)) !!};

        // Konfigurasi Chart.js Tema Warm Ivory & Deep Navy
        function createWarmChartConfig(label, strokeColor, fillColor, unit, suggestedMin, suggestedMax) {
            return {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [
                        {
                            label: label,
                            data: [],
                            borderColor: strokeColor,
                            backgroundColor: fillColor,
                            borderWidth: 2,
                            tension: 0.35,
                            fill: true,
                            pointRadius: 1.5,
                            pointHoverRadius: 5,
                            pointBackgroundColor: strokeColor
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 350 },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#ffffff',
                            titleColor: '#1b3b6f',
                            bodyColor: '#4a5568',
                            borderColor: '#e5decf',
                            borderWidth: 1,
                            padding: 8,
                            boxPadding: 4,
                            usePointStyle: true,
                            callbacks: {
                                label: function(context) {
                                    return ` ${context.dataset.label}: ${context.parsed.y} ${unit}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: 'rgba(229, 222, 207, 0.5)' },
                            ticks: { color: '#8c8273', font: { size: 9 }, maxTicksLimit: 5 }
                        },
                        y: {
                            type: 'linear',
                            grid: { color: 'rgba(229, 222, 207, 0.5)' },
                            suggestedMin: suggestedMin,
                            suggestedMax: suggestedMax,
                            ticks: {
                                color: '#524b42',
                                font: { size: 9 },
                                callback: function(v) { return v + ' ' + unit; }
                            }
                        }
                    }
                }
            };
        }

        // 1. Inisialisasi Grafik Suhu (Warm Terracotta / Amber)
        const tempChart = new Chart(tempCtx, createWarmChartConfig('Suhu Ruangan', '#d97706', 'rgba(217, 119, 6, 0.08)', '°C', 22, 34));
        tempChart.data.labels = initialLabels;
        tempChart.data.datasets[0].data = initialTemps;
        tempChart.update();

        // 2. Inisialisasi Grafik Kelembaban (Deep Navy Blue)
        const humChart = new Chart(humCtx, createWarmChartConfig('Kelembaban', '#1b3b6f', 'rgba(27, 59, 111, 0.08)', '%', 50, 90));
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
                b.className = 'px-3 py-1.5 rounded-lg text-slate-600 hover:text-[#1b3b6f] transition text-xs font-semibold';
            });

            const badgeText = range === '1h' ? '1 Jam Terakhir' : (range === '15m' ? '15 Menit' : 'Live');
            document.getElementById('rangeBadgeTemp').innerText = badgeText;
            document.getElementById('rangeBadgeHum').innerText = badgeText;

            if (range === '1h') {
                btn1h.className = 'px-3 py-1.5 rounded-lg bg-white text-[#1b3b6f] shadow-sm transition text-xs font-semibold';
            } else if (range === '15m') {
                btn15m.className = 'px-3 py-1.5 rounded-lg bg-white text-[#1b3b6f] shadow-sm transition text-xs font-semibold';
            } else {
                btnRt.className = 'px-3 py-1.5 rounded-lg bg-white text-[#1b3b6f] shadow-sm transition text-xs font-semibold';
            }

            fetchLiveData();
        }

        // =========================================================================
        // AJAX TRIGGER CAPTURE & YOLO INFERENCE (TANPA IKON KAMERA)
        // =========================================================================
        let isCapturing = false;

        async function triggerCapture() {
            if (isCapturing) return;
            isCapturing = true;

            const btn = document.getElementById('btnCapture');
            const btnText = document.getElementById('btnText');
            const btnSpinner = document.getElementById('btnSpinner');
            const alertBox = document.getElementById('captureAlert');

            btn.disabled = true;
            btn.classList.add('opacity-75', 'cursor-not-allowed');
            btnSpinner.classList.remove('hidden');
            btnText.innerHTML = 'Memproses Deteksi YOLO...';

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
                    alertBox.className = 'p-3 rounded-xl text-xs font-medium border bg-[#eef7ee] text-[#1e6b24] border-[#c8e6c9] flex items-center justify-between transition-all';
                    document.getElementById('alertMessage').innerText = `Terdeteksi ${data.people_count} orang. Data berhasil diteruskan ke Remote via MQTT!`;
                    document.getElementById('alertTime').innerText = data.timestamp_wib.split(' ')[1];
                    alertBox.classList.remove('hidden');

                } else {
                    alertBox.className = 'p-3 rounded-xl text-xs font-medium border bg-[#fdebee] text-[#b71c1c] border-[#ffcdd2] flex items-center justify-between transition-all';
                    document.getElementById('alertMessage').innerText = data.message || 'Gagal memproses capture kamera.';
                    alertBox.classList.remove('hidden');
                }

            } catch (err) {
                alertBox.className = 'p-3 rounded-xl text-xs font-medium border bg-[#fdebee] text-[#b71c1c] border-[#ffcdd2] flex items-center justify-between transition-all';
                document.getElementById('alertMessage').innerText = 'Koneksi ke backend gagal: ' + err.message;
                alertBox.classList.remove('hidden');
            } finally {
                isCapturing = false;
                btn.disabled = false;
                btn.classList.remove('opacity-75', 'cursor-not-allowed');
                btnSpinner.classList.add('hidden');
                btnText.innerHTML = 'Capture & Deteksi Okupansi Sekarang &rarr;';
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

                    tempChart.data.labels = labels;
                    tempChart.data.datasets[0].data = temps;
                    tempChart.update();

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
                        powerBadge.className = 'text-xs font-bold px-3 py-1 rounded-full bg-[#eef7ee] text-[#1e6b24] border border-[#c8e6c9]';
                    } else {
                        powerBadge.className = 'text-xs font-bold px-3 py-1 rounded-full bg-[#f4efe4] text-slate-600 border border-[#ded6c5]';
                    }

                    document.getElementById('acSetpointVal').innerHTML = `${s.setpoint}<span class="text-xl text-[#9c7423] font-normal">°C</span>`;
                    document.getElementById('acUpdatedTime').innerText = `Update: ${s.updated_at} WIB`;
                    document.getElementById('acModeVal').innerText = s.mode;
                    document.getElementById('acFanVal').innerText = s.fan_speed;
                    document.getElementById('acSwingVal').innerText = s.swing;
                    document.getElementById('acControlSourceVal').innerText = s.control_source;
                }

                // 3. Update Badge Online Remote
                const updateOnlineBadge = (id) => {
                    const b = document.getElementById(id);
                    if (!b) return;
                    if (json.device_online) {
                        b.className = 'text-xs font-semibold px-3 py-1.5 rounded-xl border bg-[#eef7ee] text-[#1e6b24] border-[#c8e6c9]';
                        b.innerText = '● Remote Online';
                    } else {
                        b.className = 'text-xs font-semibold px-3 py-1.5 rounded-xl border bg-[#fdebee] text-[#b71c1c] border-[#ffcdd2]';
                        b.innerText = '○ Remote Offline';
                    }
                };
                updateOnlineBadge('remoteOnlineBadge');

                const mb = document.getElementById('remoteOnlineBadgeMobile');
                if (mb) {
                    mb.className = json.device_online ? 'text-[10px] font-semibold px-2.5 py-1 rounded-xl border bg-[#eef7ee] text-[#1e6b24] border-[#c8e6c9]' : 'text-[10px] font-semibold px-2.5 py-1 rounded-xl border bg-[#fdebee] text-[#b71c1c] border-[#ffcdd2]';
                    mb.innerText = json.device_online ? 'Online' : 'Offline';
                }

                // 4. Update Baterai
                if (json.battery) {
                    const b = json.battery;
                    const bVal = document.getElementById('nodeBatteryVal');
                    bVal.innerText = `${b.percent}% (${b.millivolts} mV)`;
                    bVal.className = b.percent <= 20 ? 'font-bold text-[#b71c1c]' : 'font-bold text-[#1e6b24]';
                }

                // 5. Update Riwayat AC (Feed)
                if (json.ac_history && json.ac_history.length > 0) {
                    const feed = document.getElementById('acHistoryFeed');
                    feed.innerHTML = json.ac_history.map(h => `
                        <div class="p-2.5 sm:p-3 bg-[#fbf9f4] border border-[#ede5d5] rounded-xl flex items-center justify-between text-xs">
                            <div class="flex items-center space-x-2 sm:space-x-3 overflow-hidden">
                                <span class="font-mono text-[11px] text-slate-500 flex-shrink-0">${h.time}</span>
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold flex-shrink-0 ${h.power === 'ON' ? 'bg-[#eef7ee] text-[#1e6b24] border border-[#c8e6c9]' : 'bg-[#f4efe4] text-slate-600' }}">${h.power}</span>
                                <span class="text-slate-700 font-medium truncate">Suhu: <strong class="text-[#1b3b6f]">${h.setpoint}°C</strong></span>
                                <span class="text-slate-500 hidden sm:inline">Mode: {{ $h->mode }}</span>
                            </div>
                            <div class="text-[11px] font-semibold text-[#1b3b6f] flex-shrink-0 ml-2">
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
