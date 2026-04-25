<?php

namespace App\Http\Controllers;

use App\Models\SensorReading;
use App\Models\SystemState;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ApiController extends Controller
{
    // Konfigurasi porting dari Python/Node
    protected $cfg = [
        'MORNING_WINDOW' => [5, 7],
        'EVENING_WINDOW' => [16, 18],
        'SOIL_DRY_ON' => 45.0,
        'SOIL_WET_OFF' => 70.0,
        'CRITICAL_DRY' => 20.0,
        'RAIN_SCORE_THRESHOLD' => 60,
        'RAIN_RH_HEAVY' => 92.0,
        'RAIN_RH_MODERATE' => 85.0,
        'RAIN_RH_LIGHT' => 78.0,
        'RAIN_SOIL_RISE_HEAVY' => 8.0,
        'RAIN_SOIL_RISE_LIGHT' => 3.0,
        'RAIN_TEMP_DROP' => 3.0,
        'RAIN_CLEAR_THRESHOLD' => 30,
        'RAIN_CONFIRM_READINGS' => 2,
        'RAIN_CLEAR_READINGS' => 3,
        'COOLDOWN_MINUTES' => 45,
        'POST_RAIN_COOLDOWN_MINUTES' => 120,
        'MIN_SESSION_GAP_MINUTES' => 10,
        'MAX_PUMP_DURATION_MINUTES' => 5,
        'MIN_PUMP_DURATION_SECONDS' => 30,
        'HOT_TEMP_THRESHOLD' => 34.0,
        'CONFIDENCE_NORMAL' => 60.0,
        'CONFIDENCE_HOT' => 40.0,
        'CONFIDENCE_MISSED' => 48.0,
        'CONTROL_DEBOUNCE_SECONDS' => 5,
        'SENSOR_DEBOUNCE_SECONDS' => 10,
        'SENSOR_TOLERANCE' => 1.0,
    ];

    public function root()
    {
        return response()->json([
            'status' => 'online',
            'message' => 'Siram Pintar API Laravel Sail berjalan',
            'version' => '6.0.0',
            'auth' => env('API_KEY') ? 'required' : 'disabled'
        ]);
    }

    public function dbTest()
    {
        try {
            DB::connection()->getPdo();
            return response()->json(['db_status' => 'connected']);
        } catch (\Exception $e) {
            return response()->json(['db_status' => 'error', 'detail' => $e->getMessage()]);
        }
    }

    public function modelInfo()
    {
        // Metadata model bisa diletakkan di config atau file json
        return response()->json([
            'best_k' => '?',
            'accuracy' => '?',
            'label_desc' => [
                'Kering' => 'Tanah membutuhkan air segera.',
                'Basah' => 'Tanah dalam kondisi cukup air.',
                'Normal' => 'Tanah dalam kondisi ideal.'
            ]
        ]);
    }

    public function receiveSensor(Request $request)
    {
        $data = $request->validate([
            'soil_moisture' => 'required|numeric|between:0,100',
            'temperature' => 'required|numeric|between:0,60',
            'air_humidity' => 'required|numeric|between:0,100',
            'hour' => 'nullable|integer|between:0,23',
            'minute' => 'nullable|integer|between:0,59',
            'day' => 'nullable|integer|between:0,6',
        ]);

        $state = $this->getState();

        // Resolve Time WIT
        $timeInfo = $this->resolveTimeWit($data['hour'] ?? null, $data['minute'] ?? null, $data['day'] ?? null);
        $currentTotalMinutes = $timeInfo['h'] * 60 + $timeInfo['m'];

        // KNN Classification (Stub)
        $result = $this->classify($data['soil_moisture'], $data['temperature'], $data['air_humidity']);

        // Debounce / Anomaly check
        $skipEval = $this->shouldSkipSensor($data, $state);

        if ($skipEval) {
            $elapsedSpam = $this->elapsedSecondsReal($state->last_sensor_ts);
            if ($elapsedSpam < 2.0) {
                return response()->json([
                    'received' => true,
                    'timestamp' => $state->last_updated ? $state->last_updated->toDateTimeString() : now()->toDateTimeString(),
                    'device_time' => sprintf("%02d:%02d", $timeInfo['h'], $timeInfo['m']),
                    'time_source' => $timeInfo['source'],
                    'debounced' => true,
                    'sensor' => $data,
                    'classification' => $result,
                    'pump_status' => $state->pump_status,
                    'pump_action' => null,
                    'mode' => $state->mode,
                    'auto_info' => null
                ]);
            }
        }

        $finalAction = null;
        $smartEval = [];

        if ($state->mode === 'auto' && !$skipEval) {
            $smartEval = $this->evaluateSmartWatering($result, $timeInfo['h'], $timeInfo['m'], $data['soil_moisture'], $data['air_humidity'], $data['temperature'], $state, $currentTotalMinutes);
            $finalAction = $smartEval['action'] ?? null;
        }

        // Update State
        $state->update([
            'last_label' => $result['label'],
            'last_updated' => now(),
            'last_soil_moisture' => $data['soil_moisture'],
            'last_temperature' => $data['temperature'],
            'last_sensor_ts' => now(),
            'last_sensor_soil' => $data['soil_moisture'],
        ]);

        $newState = $state->fresh();
        $pumpStatusLogged = $finalAction === 'on' ? true : ($finalAction === 'off' ? false : $newState->pump_status);

        // Log to database
        SensorReading::create([
            'id' => (string) Str::uuid(),
            'timestamp' => now(),
            'soil_moisture' => $data['soil_moisture'],
            'temperature' => $data['temperature'],
            'air_humidity' => $data['air_humidity'],
            'label' => $result['label'],
            'confidence' => $result['confidence'],
            'needs_watering' => $result['needs_watering'],
            'description' => $result['description'],
            'probabilities' => $result['probabilities'],
            'pump_status' => $pumpStatusLogged,
            'mode' => $state->mode,
        ]);

        return response()->json([
            'received' => true,
            'timestamp' => now()->toDateTimeString(),
            'device_time' => sprintf("%02d:%02d", $timeInfo['h'], $timeInfo['m']),
            'time_source' => $timeInfo['source'],
            'debounced' => $skipEval,
            'sensor' => $data,
            'classification' => $result,
            'pump_status' => $newState->pump_status,
            'pump_action' => $finalAction,
            'mode' => $newState->mode,
            'auto_info' => $state->mode === 'auto' ? $smartEval : null,
        ]);
    }

    public function getStatus()
    {
        $state = $this->getState();
        $latest = SensorReading::orderBy('timestamp', 'desc')->first();

        return response()->json([
            'pump_status' => $state->pump_status,
            'mode' => $state->mode,
            'last_label' => $state->last_label,
            'last_updated' => $state->last_updated ? $state->last_updated->toDateTimeString() : null,
            'is_raining' => $state->rain_detected,
            'rain_score' => $state->rain_score,
            'missed_session' => $state->missed_session,
            'watering_windows' => [
                'morning' => sprintf("%02d:00–%02d:59 WIT", $this->cfg['MORNING_WINDOW'][0], $this->cfg['MORNING_WINDOW'][1]),
                'evening' => sprintf("%02d:00–%02d:59 WIT", $this->cfg['EVENING_WINDOW'][0], $this->cfg['EVENING_WINDOW'][1]),
            ],
            'thresholds' => [
                'soil_dry_on' => $this->cfg['SOIL_DRY_ON'],
                'soil_wet_off' => $this->cfg['SOIL_WET_OFF'],
                'critical_dry' => $this->cfg['CRITICAL_DRY'],
            ],
            'latest_data' => $latest
        ]);
    }

    public function history(Request $request)
    {
        $limit = $request->query('limit', 50);
        $records = SensorReading::orderBy('timestamp', 'desc')->limit($limit)->get()->reverse()->values();

        return response()->json([
            'total' => $records->count(),
            'records' => $records
        ]);
    }

    public function control(Request $request)
    {
        $data = $request->validate([
            'action' => 'required|string|in:on,off,ON,OFF',
            'mode' => 'nullable|string|in:auto,manual',
        ]);

        $action = strtolower($data['action']);
        $mode = strtolower($data['mode'] ?? 'manual');

        $state = $this->getState();
        $pumpOn = ($action === 'on');

        if ($state->pump_status === $pumpOn && $state->mode === $mode) {
            return response()->json([
                'success' => true,
                'debounced' => true,
                'message' => 'Status pompa dan mode tidak berubah.',
                'pump_status' => $state->pump_status,
                'mode' => $state->mode,
                'timestamp' => $state->last_control_ts ? $state->last_control_ts->toIso8601String() : now()->toIso8601String(),
            ]);
        }

        $updateData = ['mode' => $mode, 'last_control_ts' => now()];

        if ($state->pump_status !== $pumpOn) {
            $updateData['pump_status'] = $pumpOn;
            if (!$pumpOn) {
                $updateData['pump_start_ts'] = null;
                $updateData['pump_start_minute'] = null;
                $updateData['last_watered_ts'] = now();
                $timeInfo = $this->resolveTimeWit();
                $updateData['last_watered_minute'] = $timeInfo['h'] * 60 + $timeInfo['m'];
            } else {
                $updateData['pump_start_ts'] = now();
                $timeInfo = $this->resolveTimeWit();
                $updateData['pump_start_minute'] = $timeInfo['h'] * 60 + $timeInfo['m'];
            }
        }

        $state->update($updateData);

        return response()->json([
            'success' => true,
            'debounced' => false,
            'pump_status' => $state->fresh()->pump_status,
            'mode' => $state->fresh()->mode,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function predict(Request $request)
    {
        $data = $request->validate([
            'soil_moisture' => 'required|numeric',
            'temperature' => 'required|numeric',
            'air_humidity' => 'required|numeric',
        ]);

        return response()->json([
            'input' => $data,
            'result' => $this->classify($data['soil_moisture'], $data['temperature'], $data['air_humidity']),
        ]);
    }

    public function getConfig()
    {
        return response()->json([
            'watering_windows' => [
                'morning' => sprintf("%02d:00–%02d:59", $this->cfg['MORNING_WINDOW'][0], $this->cfg['MORNING_WINDOW'][1]),
                'evening' => sprintf("%02d:00–%02d:59", $this->cfg['EVENING_WINDOW'][0], $this->cfg['EVENING_WINDOW'][1]),
            ],
            'soil_thresholds' => [
                'dry_on_threshold' => $this->cfg['SOIL_DRY_ON'],
                'wet_off_threshold' => $this->cfg['SOIL_WET_OFF'],
                'critical_emergency' => $this->cfg['CRITICAL_DRY'],
            ],
            'rain_detection' => [
                'score_to_confirm' => $this->cfg['RAIN_SCORE_THRESHOLD'],
                'score_to_clear' => $this->cfg['RAIN_CLEAR_THRESHOLD'],
                'rh_heavy' => $this->cfg['RAIN_RH_HEAVY'],
                'rh_moderate' => $this->cfg['RAIN_RH_MODERATE'],
                'rh_light' => $this->cfg['RAIN_RH_LIGHT'],
            ],
            'pump_control' => [
                'max_duration_min' => $this->cfg['MAX_PUMP_DURATION_MINUTES'],
                'min_duration_sec' => $this->cfg['MIN_PUMP_DURATION_SECONDS'],
                'cooldown_normal' => $this->cfg['COOLDOWN_MINUTES'],
                'cooldown_post_rain' => $this->cfg['POST_RAIN_COOLDOWN_MINUTES'],
            ],
            'knn_confidence' => [
                'normal' => $this->cfg['CONFIDENCE_NORMAL'],
                'hot_weather' => $this->cfg['CONFIDENCE_HOT'],
                'missed_session' => $this->cfg['CONFIDENCE_MISSED'],
                'hot_threshold' => $this->cfg['HOT_TEMP_THRESHOLD'],
            ],
        ]);
    }

    public function resetRain()
    {
        $this->getState()->update([
            'rain_detected' => false,
            'rain_score' => 0,
            'rain_confirm_count' => 0,
            'rain_clear_count' => 0,
            'rain_started_minute' => null,
            'missed_session' => false,
        ]);
        return response()->json(['success' => true, 'message' => 'State hujan dan hutang siram di-reset.']);
    }

    // --- Private Helpers ---

    private function getState()
    {
        return SystemState::firstOrCreate(['id' => 1], [
            'mode' => 'auto',
            'pump_status' => false
        ]);
    }

    private function resolveTimeWit($h = null, $m = null, $d = null)
    {
        if ($h !== null && $m !== null && $d !== null) {
            return ['h' => (int) $h, 'm' => (int) $m, 'd' => (int) $d, 'source' => 'esp32'];
        }
        $now = Carbon::now('Asia/Jayapura'); // WIT
        return ['h' => $now->hour, 'm' => $now->minute, 'd' => $now->dayOfWeek, 'source' => 'server'];
    }

    private function classify($soil, $temp, $rh)
    {
        /**
         * PURE PHP KNN / RULE-BASED LOGIC
         * Karena hosting tidak mendukung Python, kita menggunakan logika PHP murni.
         * Nilai threshold ini disesuaikan dengan info dari model_info.json Anda.
         */
        
        $label = 'Normal';
        $confidence = 90.0;
        $needsWatering = false;
        $description = '';

        if ($soil < 40.0) {
            $label = 'Kering';
            $needsWatering = true;
            $description = 'Kelembaban tanah < 40% — perlu disiram segera.';
            $confidence = ($soil < 20) ? 98.0 : 85.0;
        } elseif ($soil >= 40.0 && $soil <= 70.0) {
            $label = 'Lembab';
            $needsWatering = false;
            $description = 'Kelembaban tanah 40-70% — kondisi optimal.';
            $confidence = 90.0;
        } else {
            $label = 'Basah';
            $needsWatering = false;
            $description = 'Kelembaban tanah > 70% — tidak perlu disiram.';
            $confidence = 95.0;
        }

        // Penyesuaian berdasarkan Suhu & Kelembaban Udara (Khas KNN)
        if ($temp > 34.0 && $label === 'Lembab') {
            $description .= ' (Suhu panas, awasi penguapan cepat)';
            $confidence -= 5.0;
        }

        return [
            'label' => $label,
            'confidence' => $confidence,
            'probabilities' => [
                'Kering' => ($label === 'Kering' ? $confidence : (100 - $confidence) / 2),
                'Lembab' => ($label === 'Lembab' ? $confidence : (100 - $confidence) / 2),
                'Basah' => ($label === 'Basah' ? $confidence : (100 - $confidence) / 2),
            ],
            'needs_watering' => $needsWatering,
            'description' => $description
        ];
    }

    private function shouldSkipSensor($data, $state)
    {
        if ($data['soil_moisture'] <= 0 || $data['temperature'] <= 0 || $data['temperature'] >= 60)
            return true;
        if ($state->last_sensor_soil !== null) {
            if (abs($data['soil_moisture'] - $state->last_sensor_soil) > 30.0)
                return true;
        }
        $elapsed = $this->elapsedSecondsReal($state->last_sensor_ts);
        if ($elapsed > $this->cfg['SENSOR_DEBOUNCE_SECONDS'])
            return false;
        if ($state->last_sensor_soil === null)
            return false;
        return abs($data['soil_moisture'] - $state->last_sensor_soil) <= $this->cfg['SENSOR_TOLERANCE'];
    }

    private function elapsedSecondsReal($ts)
    {
        if (!$ts)
            return 999999;
        return now()->diffInSeconds(Carbon::parse($ts));
    }

    private function evaluateSmartWatering($result, $hour, $minute, $soil, $rh, $temp, $state, $currentMin)
    {
        // 1. Inisialisasi Response
        $resp = [
            'action' => null,
            'reason' => '',
            'blocked_reason' => null,
            'is_raining' => false,
            'rain_score' => 0,
            'hot_mode' => $temp >= $this->cfg['HOT_TEMP_THRESHOLD'],
            'missed_session' => (bool)$state->missed_session,
            'decision_path' => []
        ];

        // 2. Cek Safety Lockout (Batas harian)
        $today = now()->toDateString();
        if ($state->session_count_date !== $today) {
            $state->update(['session_count_today' => 0, 'session_count_date' => $today]);
            $state = $state->fresh();
        }

        if ($state->session_count_today >= 10) {
            $resp['blocked_reason'] = 'Safety Lockout: Melebihi batas harian (10x).';
            $resp['decision_path'][] = 'SAFETY_LOCKOUT';
            return $resp;
        }

        // 3. Hitung Skor Hujan (Logic v6)
        $rainInfo = $this->computeRainScore($rh, $soil, $temp, $state->last_soil_moisture, $state->last_temperature, $state->pump_status);
        $rainStatus = $this->updateRainState($rainInfo['score'], $rainInfo['signals'], $state, $currentMin);
        
        $resp['is_raining'] = $rainStatus['is_raining'];
        $resp['rain_score'] = $rainInfo['score'];

        // 4. Dynamic Thresholds (Kepintaran Adaptif)
        $dynamicDryOn = $this->cfg['SOIL_DRY_ON'];
        $dynamicWetOff = $this->cfg['SOIL_WET_OFF'];

        if ($resp['hot_mode']) {
            $dynamicDryOn += 5.0; // Siram lebih awal jika panas
            $dynamicWetOff += 5.0;
            $resp['decision_path'][] = 'T-HOT_ADJUST';
        } elseif ($temp < 25.0 && $rh > 80.0) {
            $dynamicDryOn -= 5.0; // Tunda jika dingin/lembab
            $dynamicWetOff -= 5.0;
            $resp['decision_path'][] = 'T-COOL_ADJUST';
        }

        if ($state->missed_session) {
            $dynamicWetOff += 5.0; // Kompensasi jika sesi sebelumnya terlewat
            $resp['decision_path'][] = 'T-MISSED_ADJUST';
        }

        $dynamicWetOff = min(95.0, $dynamicWetOff);
        $dynamicDryOn = max($this->cfg['CRITICAL_DRY'] + 5.0, $dynamicDryOn);

        // 5. Cek Window Waktu
        $inWindow = false;
        $windowLabel = '';
        if ($hour >= $this->cfg['MORNING_WINDOW'][0] && $hour <= $this->cfg['MORNING_WINDOW'][1]) {
            $inWindow = true; $windowLabel = 'pagi';
        } elseif ($hour >= $this->cfg['EVENING_WINDOW'][0] && $hour <= $this->cfg['EVENING_WINDOW'][1]) {
            $inWindow = true; $windowLabel = 'sore';
        }

        // Emergency Night Mode
        $nightEmergency = (!$inWindow && $soil <= $this->cfg['CRITICAL_DRY'] && !$resp['is_raining']);
        if ($nightEmergency) $windowLabel = 'malam-darurat';

        // 6. LOGIKA EKSEKUSI (Jika Pompa Sedang Nyala)
        if ($state->pump_status) {
            $elapsedSec = $this->elapsedSecondsReal($state->pump_start_ts);
            $maxSec = $nightEmergency ? 60 : ($this->cfg['MAX_PUMP_DURATION_MINUTES'] * 60);

            if ($elapsedSec >= $maxSec) {
                $state->update(['pump_status' => false, 'last_watered_ts' => now(), 'last_watered_minute' => $currentMin, 'pump_start_ts' => null]);
                $resp['action'] = 'off';
                $resp['reason'] = "Auto-stop: batas maksimal ({$elapsedSec}s).";
                $resp['decision_path'][] = 'A1';
                return $resp;
            }

            if ($elapsedSec < $this->cfg['MIN_PUMP_DURATION_SECONDS']) {
                $resp['reason'] = "Warmup ({$elapsedSec}s).";
                $resp['decision_path'][] = 'A-warmup';
                return $resp;
            }

            if ($soil >= $dynamicWetOff) {
                $state->update(['pump_status' => false, 'last_watered_ts' => now(), 'last_watered_minute' => $currentMin, 'pump_start_ts' => null, 'missed_session' => false]);
                $resp['action'] = 'off';
                $resp['reason'] = "Tanah cukup ({$soil}% >= {$dynamicWetOff}%).";
                $resp['decision_path'][] = 'A2';
                return $resp;
            }

            if ($resp['is_raining']) {
                $state->update(['pump_status' => false, 'last_watered_ts' => now(), 'last_watered_minute' => $currentMin, 'pump_start_ts' => null]);
                $resp['action'] = 'off';
                $resp['reason'] = "Terdeteksi hujan, berhenti menyiram.";
                $resp['decision_path'][] = 'A3';
                return $resp;
            }

            $resp['reason'] = "Pompa ON ({$elapsedSec}s). Tanah={$soil}%.";
            $resp['decision_path'][] = 'A4-running';
            return $resp;
        }

        // 7. LOGIKA EKSEKUSI (Jika Pompa Sedang Mati)
        // B1: Darurat
        if ($nightEmergency || ($soil <= $this->cfg['CRITICAL_DRY'] && !$resp['is_raining'])) {
            $state->update(['pump_status' => true, 'pump_start_ts' => now(), 'pump_start_minute' => $currentMin, 'session_count_today' => $state->session_count_today + 1]);
            $resp['action'] = 'on';
            $resp['reason'] = "SIRAM DARURAT [{$windowLabel}]: tanah {$soil}% <= {$this->cfg['CRITICAL_DRY']}%";
            $resp['decision_path'][] = 'B1';
            return $resp;
        }

        // B2: Cek Window
        if (!$inWindow) {
            $resp['blocked_reason'] = "Di luar jam aman (WIT {$hour}:{$minute})";
            $resp['decision_path'][] = 'B2';
            return $resp;
        }

        // B3: Cek Hujan
        if ($resp['is_raining']) {
            $resp['blocked_reason'] = "Sedang hujan (Skor: {$resp['rain_score']})";
            $resp['decision_path'][] = 'B3';
            return $resp;
        }

        // B4: Sudah Basah
        if ($soil >= $dynamicWetOff) {
            if ($state->missed_session) $state->update(['missed_session' => false]);
            $resp['blocked_reason'] = "Tanah sudah basah ({$soil}%)";
            $resp['decision_path'][] = 'B4';
            return $resp;
        }

        // B5: Cooldown
        $effectiveCooldown = $state->missed_session ? $this->cfg['POST_RAIN_COOLDOWN_MINUTES'] : $this->cfg['COOLDOWN_MINUTES'];
        $elapsedCD = $currentMin - ($state->last_watered_minute ?? -9999);
        if ($elapsedCD < 0) $elapsedCD += 1440; // Over midnight

        if ($elapsedCD < $effectiveCooldown) {
            $resp['blocked_reason'] = "Cooldown: sisa " . ($effectiveCooldown - $elapsedCD) . " mnt.";
            $resp['decision_path'][] = 'B5';
            return $resp;
        }

        // B6: KNN Decision
        if (!$result['needs_watering']) {
            $resp['blocked_reason'] = "KNN Label: {$result['label']} ({$result['confidence']}%)";
            $resp['decision_path'][] = 'B6';
            return $resp;
        }

        // B7: Confidence Threshold
        $threshold = $resp['hot_mode'] ? $this->cfg['CONFIDENCE_HOT'] : ($state->missed_session ? $this->cfg['CONFIDENCE_MISSED'] : $this->cfg['CONFIDENCE_NORMAL']);
        if ($result['confidence'] < $threshold) {
            $resp['blocked_reason'] = "Confidence {$result['confidence']}% < {$threshold}%";
            $resp['decision_path'][] = 'B7';
            return $resp;
        }

        // B8: Final Threshold Check
        if ($soil > $dynamicDryOn) {
            $resp['blocked_reason'] = "Tanah {$soil}% > Ambang batas aktif ({$dynamicDryOn}%)";
            $resp['decision_path'][] = 'B8';
            return $resp;
        }

        // ACTION: ON!
        $state->update(['pump_status' => true, 'pump_start_ts' => now(), 'pump_start_minute' => $currentMin, 'session_count_today' => $state->session_count_today + 1]);
        $resp['action'] = 'on';
        $resp['reason'] = "Siram [{$windowLabel}]: KNN={$result['label']} ({$result['confidence']}%), tanah={$soil}%";
        $resp['decision_path'][] = 'B-FINAL';

        return $resp;
    }

    private function computeRainScore($rh, $soil, $temp, $lastSoil, $lastTemp, $pumpOn)
    {
        $score = 0;
        $signals = [];

        if ($rh >= $this->cfg['RAIN_RH_HEAVY']) {
            $score += 50; $signals[] = "RH Heavy ({$rh}%)";
        } elseif ($rh >= $this->cfg['RAIN_RH_MODERATE']) {
            $score += 30; $signals[] = "RH Moderate ({$rh}%)";
        } elseif ($rh >= $this->cfg['RAIN_RH_LIGHT']) {
            $score += 15; $signals[] = "RH Light ({$rh}%)";
        }

        if (!$pumpOn && $lastSoil !== null) {
            $delta = $soil - $lastSoil;
            if ($delta >= $this->cfg['RAIN_SOIL_RISE_HEAVY']) {
                $score += 35; $signals[] = "Soil rise +{$delta}%";
            } elseif ($delta >= $this->cfg['RAIN_SOIL_RISE_LIGHT']) {
                $score += 20; $signals[] = "Soil rise +{$delta}%";
            }
        }

        if ($lastTemp !== null) {
            $drop = $lastTemp - $temp;
            if ($drop >= $this->cfg['RAIN_TEMP_DROP']) {
                $score += 15; $signals[] = "Temp drop -{$drop}C";
            }
        }

        return ['score' => min($score, 100), 'signals' => $signals];
    }

    private function updateRainState($score, $signals, $state, $currentMin)
    {
        $currentlyRaining = $state->rain_detected;
        $confirmCount = $state->rain_confirm_count;
        $clearCount = $state->rain_clear_count;

        if ($score >= $this->cfg['RAIN_SCORE_THRESHOLD']) {
            $confirmCount++; $clearCount = 0;
            if (!$currentlyRaining && $confirmCount >= $this->cfg['RAIN_CONFIRM_READINGS']) {
                $state->update(['rain_detected' => true, 'rain_score' => $score, 'rain_confirm_count' => $confirmCount, 'rain_clear_count' => 0, 'rain_started_minute' => $currentMin, 'missed_session' => true]);
            } else {
                $state->update(['rain_score' => $score, 'rain_confirm_count' => $confirmCount, 'rain_clear_count' => 0]);
            }
        } elseif ($score <= $this->cfg['RAIN_CLEAR_THRESHOLD']) {
            $clearCount++; $confirmCount = 0;
            if ($currentlyRaining && $clearCount >= $this->cfg['RAIN_CLEAR_READINGS']) {
                $state->update(['rain_detected' => false, 'rain_score' => $score, 'rain_confirm_count' => 0, 'rain_clear_count' => $clearCount]);
            } else {
                $state->update(['rain_score' => $score, 'rain_confirm_count' => 0, 'rain_clear_count' => $clearCount]);
            }
        }

        return ['is_raining' => $state->fresh()->rain_detected];
    }
}
