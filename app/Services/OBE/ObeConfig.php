<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\SystemSetting;

/**
 * Central reader for OBE measurement parameters, stored in `system_settings`
 * (key/value) with sane defaults so the engine never hard-codes coefficients
 * or thresholds. Values are cached per request.
 */
class ObeConfig
{
    /** @var array<string, array<string,string>> cache theo trường: "school:<id>" hoặc "global" */
    private static array $cache = [];

    /**
     * Trường đang được đo/xuất báo cáo. Đặt ở các ĐIỂM VÀO (đo lại, tính mức đạt, xuất báo cáo)
     * thay vì truyền xuống hàng chục chỗ gọi `ObeConfig::`. Chưa đặt ⇒ chỉ đọc dòng mặc định
     * (`school_id IS NULL`) — đúng hành vi trước khi có tham số theo trường.
     */
    private static ?int $schoolId = null;

    /** Bậc đào tạo của ngành đang đo — quyết định ngưỡng mặc định khi trường chưa khai riêng. */
    private static ?string $level = null;

    private const DEFAULTS = [
        'obe.process_weight' => '0.4',
        'obe.final_weight' => '0.6',
        // PLO achievement level cut-offs (level 4 / 3 / 2 / 1); below the last → 0.
        'obe.plo_level_thresholds' => '8.5,7.0,5.5,4.0',
        // Attainment thresholds
        'obe.clo_pass_threshold' => '5',          // CLO passes at ≥ 5
        'obe.plo_pass_threshold' => '5.5',        // PLO pass threshold on the 0-10 scale (StandardAverage)
        'obe.plo_attainment_ratio_pass' => '0.5', // PLO pass threshold on the 0..1 ratio scale (WeightedAttainment)
        'obe.target_attainment_percent' => '70',
        // Attainment level cut-offs (% students passing → level 3 / 2 / 1).
        'obe.attainment_level_thresholds' => '80,70,60',
        // Minimum course mark for its credits to count towards accumulation.
        // Not used for measurement: failed courses are still measured but earn no credits.
        'obe.course_pass_threshold' => '5.5',
        // Minimum % of accumulated credits for a student to count in cohort statistics.
        'obe.measurement_min_completion_percent' => '80',
    ];

    /**
     * Ngưỡng theo BẬC ĐÀO TẠO, dùng khi trường chưa khai tham số riêng.
     *
     * Đại học: qua môn 4,0 (Thông tư 08/2021/TT-BGDĐT, điểm D). Thạc sĩ: qua môn 5,5 (Thông tư
     * 23/2021/TT-BGDĐT, điểm C). Sàn đạt PLO của mỗi học viên và thang 5 mức lấy theo tài liệu WCML
     * của trường: đại học 20/40/60/80 %, thạc sĩ 27,5/55/70/85 % (ghi ở thang 10 cho `plo_level_thresholds`).
     *
     * Ngành chưa khai bậc ⇒ rơi về `DEFAULTS`, tức giữ nguyên số của dữ liệu cũ.
     *
     * @var array<string, array<string,string>>
     */
    public const LEVEL_DEFAULTS = [
        \App\Models\Program::LEVEL_DAI_HOC => [
            'obe.clo_pass_threshold' => '4',
            'obe.course_pass_threshold' => '4',
            'obe.plo_attainment_ratio_pass' => '0.4',
            'obe.plo_level_thresholds' => '8.0,6.0,4.0,2.0',
        ],
        \App\Models\Program::LEVEL_THAC_SI => [
            'obe.clo_pass_threshold' => '5.5',
            'obe.course_pass_threshold' => '5.5',
            'obe.plo_attainment_ratio_pass' => '0.55',
            'obe.plo_level_thresholds' => '8.5,7.0,5.5,2.75',
        ],
    ];

    /** Chạy $fn với tham số đo của một trường (tự trả lại ngữ cảnh cũ, kể cả khi $fn ném lỗi). */
    public static function forSchool(?int $schoolId, callable $fn): mixed
    {
        return self::forProgram($schoolId, null, $fn);
    }

    /**
     * Như `forSchool` nhưng biết thêm BẬC ĐÀO TẠO của ngành đang đo: ngưỡng chưa được trường khai
     * riêng sẽ lấy mặc định của bậc thay vì mặc định chung.
     */
    public static function forProgram(?int $schoolId, ?string $level, callable $fn): mixed
    {
        $previousSchool = self::$schoolId;
        $previousLevel = self::$level;
        self::$schoolId = $schoolId;
        self::$level = $level;

        try {
            return $fn();
        } finally {
            self::$schoolId = $previousSchool;
            self::$level = $previousLevel;
        }
    }

    public static function level(): ?string
    {
        return self::$level;
    }

    public static function schoolId(): ?int
    {
        return self::$schoolId;
    }

    private static function get(string $key): string
    {
        $bucket = self::$schoolId === null ? 'global' : 'school:' . self::$schoolId;

        if (! isset(self::$cache[$bucket])) {
            // Dòng của trường ĐÈ lên dòng mặc định (school_id NULL).
            $rows = SystemSetting::where(fn ($q) => $q->whereNull('school_id')
                ->when(self::$schoolId, fn ($w) => $w->orWhere('school_id', self::$schoolId)))
                ->orderByRaw('CASE WHEN school_id IS NULL THEN 0 ELSE 1 END')
                ->pluck('value', 'key')
                ->toArray();

            self::$cache[$bucket] = $rows;
        }

        return self::$cache[$bucket][$key]
            ?? (self::$level !== null ? (self::LEVEL_DEFAULTS[self::$level][$key] ?? null) : null)
            ?? self::DEFAULTS[$key]
            ?? '';
    }

    public static function processWeight(): float
    {
        return (float) self::get('obe.process_weight');
    }

    public static function finalWeight(): float
    {
        return (float) self::get('obe.final_weight');
    }

    /** @return array<int,float> descending cut-offs for levels 4,3,2,1 */
    public static function ploLevelThresholds(): array
    {
        return array_map('floatval', explode(',', self::get('obe.plo_level_thresholds')));
    }

    public static function cloPassThreshold(): float
    {
        return (float) self::get('obe.clo_pass_threshold');
    }

    public static function ploPassThreshold(): float
    {
        return (float) self::get('obe.plo_pass_threshold');
    }

    /** PLO pass threshold on the 0..1 ratio scale (WeightedAttainmentMethod). */
    public static function ploAttainmentRatioPass(): float
    {
        return (float) self::get('obe.plo_attainment_ratio_pass');
    }

    public static function targetAttainmentPercent(): float
    {
        return (float) self::get('obe.target_attainment_percent');
    }

    /**
     * Target attainment (%) for one PLO: the school-specific `obe_targets` row if declared,
     * otherwise the global target.
     */
    public static function targetForPlo(int $ploId, ?int $schoolId = null): float
    {
        $row = \App\Models\ObeTarget::where('target_type', 'plo')
            ->where('plo_id', $ploId)
            ->when($schoolId, fn ($q) => $q->where('school_id', $schoolId))
            ->value('target_percent');

        return $row !== null ? (float) $row : self::targetAttainmentPercent();
    }

    /** Minimum course mark for its credits to count towards accumulation. */
    public static function coursePassThreshold(): float
    {
        return (float) self::get('obe.course_pass_threshold');
    }

    /** Minimum % of accumulated credits for a student to count in cohort attainment stats. */
    public static function measurementMinCompletionPercent(): float
    {
        return (float) self::get('obe.measurement_min_completion_percent');
    }

    /** @return array<int,float> descending % cut-offs for attainment levels 3,2,1 */
    public static function attainmentLevelThresholds(): array
    {
        return array_map('floatval', explode(',', self::get('obe.attainment_level_thresholds')));
    }

    /** Test helper — drop the in-request cache. */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
