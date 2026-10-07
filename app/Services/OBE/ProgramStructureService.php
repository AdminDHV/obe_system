<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\CohortPiWeight;
use App\Models\CourseOutcomeLevel;
use App\Models\CourseSyllabus;
use App\Models\MappingScaleWeight;
use App\Models\Plo;
use App\Models\Program;

/**
 * KHUNG ĐO của một chương trình: PLO → PI → các học phần đo PI đó (tín chỉ · mức I/R/M/A · CLO).
 *
 * Đây là NGUỒN DUY NHẤT cho khung đo. Trước đây engine (`WeightedAttainmentMethod`) và phiếu kết
 * quả của học viên (`StudentPiBreakdownService`) mỗi bên tự dựng lại khung từ `clo_pi_mappings`,
 * và hai bên dựng khác nhau, nên phiếu in ra không cộng lại đúng ô "Mức đạt" mà chính engine tính:
 *
 *   - Phiếu chỉ lấy CLO mà học viên CÓ kết quả, engine lấy MỌI CLO của đề cương map vào PI. Với
 *     môn chấm theo bài đánh giá, tập CLO hẹp hơn làm `CoursePiScorer` lọc rớt bài → lệch điểm.
 *   - Phiếu không loại học phần `tín chỉ = 0` / `mức = 0`, engine thì loại.
 *
 * Sửa một chỗ mà hai bên cùng đọc thì mới hết lệch được — cùng bài học với `CourseGradeFormula`.
 *
 * Khung KHÔNG phụ thuộc sinh viên nên nạp một lần rồi dùng lại cho cả chương trình.
 */
class ProgramStructureService
{
    /** @var array<string, array<int, array<string, mixed>>> "program_id|cohort_id" => cấu trúc */
    private array $cache = [];

    /**
     * Thứ tự "sâu dần" của mức, dùng để phá hoà khi hai mức TRÙNG trọng số (mặc định M và A cùng
     * bằng 3). Không có nó thì nhãn mức hiện ra phụ thuộc thứ tự dòng trả về từ DB — cùng một dữ
     * liệu lúc hiện "M" lúc hiện "A".
     */
    private const DEPTH = ['I' => 1, 'R' => 2, 'M' => 3, 'A' => 4];

    /**
     * @return array<int, array{plo_id:int, plo_code:string, pis:array<int, array{
     *     pi_id:int, pi_code:string, weight:float|null,
     *     syllabi:array<int, array{syllabus_id:int, course_code:string, course_name:string,
     *                              credit:float, weight:float, level:string, clo_ids:array<int,int>}>}>}>
     */
    public function forProgram(Program $program, ?int $cohortId = null): array
    {
        // Khoá theo CẢ KHÓA: trọng số PI đóng băng theo khóa nên hai khóa của cùng một ngành có
        // bộ khung khác nhau.
        $key = $program->id . '|' . ($cohortId ?? 0);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        // Trọng số PI riêng của khóa (nếu trường đã khai); không có thì rơi về `pis.weight`.
        $cohortWeights = $cohortId
            ? CohortPiWeight::where('cohort_id', $cohortId)->pluck('weight', 'pi_id')
            : collect();

        $intensity = MappingScaleWeight::forSchool($program->school_id); // ['I'=>1,'R'=>2,'M'=>3,'A'=>3]
        $plos = Plo::where('program_id', $program->id)->with('pis')->orderBy('id')->get();

        $piIds = $plos->flatMap(fn (Plo $plo) => $plo->pis->pluck('id'))->all();
        $mappings = CloPiMapping::whereIn('pi_id', $piIds)->get(['pi_id', 'clo_id', 'mapping_level']);

        // clo_id → syllabus_id (mỗi CLO thuộc đúng một đề cương).
        $cloSyllabus = Clo::whereIn('id', $mappings->pluck('clo_id')->unique())->pluck('syllabus_id', 'id');

        // syllabus_id → thông tin học phần.
        $courses = CourseSyllabus::whereIn('id', $cloSyllabus->unique()->values())
            ->with('course:id,code,name,credits')
            ->get(['id', 'course_id'])
            ->mapWithKeys(fn (CourseSyllabus $s) => [$s->id => [
                'course_id' => (int) $s->course_id,
                'credit' => (float) ($s->course?->credits ?? 0),
                'code' => (string) ($s->course?->code ?? ''),
                'name' => (string) ($s->course?->name ?? ''),
            ]]);

        $mappingsByPi = $mappings->groupBy('pi_id');

        // Ma trận CTĐT của khóa (File 1). Có dòng cho môn ⇒ mức của môn với PI lấy TẠI ĐÂY — đó là cam kết
        // của chương trình; ma trận CLO của phiếu chỉ còn cho biết CLO nào đo PI nào. CTĐT không ghi PI đó
        // cho môn ⇒ môn không đóng góp. Môn chưa có trong ma trận (dữ liệu cũ) ⇒ giữ quy tắc mức CLO nặng nhất.
        $ctdt = $cohortId ? CourseOutcomeLevel::forCohort($cohortId) : [];

        $structure = [];
        foreach ($plos as $plo) {
            $pis = [];

            foreach ($plo->pis as $pi) {
                $bySyllabus = [];
                foreach ($mappingsByPi->get($pi->id, collect()) as $mapping) {
                    $syllabusId = $cloSyllabus[$mapping->clo_id] ?? null;
                    if ($syllabusId === null) {
                        continue;
                    }

                    $level = (string) $mapping->mapping_level;
                    $weight = (float) ($intensity[$level] ?? 0);

                    $bySyllabus[$syllabusId]['clo_ids'][] = (int) $mapping->clo_id;

                    // Môn map vào cùng 1 PI qua nhiều CLO ở nhiều mức → lấy mức NẶNG NHẤT, hoà thì
                    // lấy mức sâu hơn (A trên M) để nhãn luôn ổn định.
                    $current = $bySyllabus[$syllabusId]['weight'] ?? -1.0;
                    $currentDepth = self::DEPTH[$bySyllabus[$syllabusId]['level'] ?? ''] ?? -1;
                    if ($weight > $current
                        || ($weight === $current && (self::DEPTH[$level] ?? 0) > $currentDepth)) {
                        $bySyllabus[$syllabusId]['weight'] = $weight;
                        $bySyllabus[$syllabusId]['level'] = $level;
                    }
                }

                $syllabi = [];
                foreach ($bySyllabus as $syllabusId => $info) {
                    $course = $courses[$syllabusId] ?? null;
                    if ($course !== null && isset($ctdt[$course['course_id']])) {
                        $ctdtLevel = $ctdt[$course['course_id']][$pi->id] ?? null;
                        $info['level'] = (string) $ctdtLevel;
                        $info['weight'] = $ctdtLevel === null ? 0.0 : (float) ($intensity[$ctdtLevel] ?? 0);
                    }
                    // Không có tín chỉ hoặc mức = 0 thì môn không đóng góp gì vào cả tử lẫn mẫu.
                    if ($course === null || $course['credit'] <= 0 || $info['weight'] <= 0) {
                        continue;
                    }

                    $syllabi[] = [
                        'syllabus_id' => (int) $syllabusId,
                        'course_code' => $course['code'],
                        'course_name' => $course['name'],
                        'credit' => $course['credit'],
                        'weight' => $info['weight'],
                        'level' => $info['level'],
                        'clo_ids' => array_values(array_unique($info['clo_ids'])),
                    ];
                }

                $override = $cohortWeights[$pi->id] ?? null;
                $weight = $override ?? $pi->weight;

                $pis[] = [
                    'pi_id' => (int) $pi->id,
                    'pi_code' => (string) $pi->code,
                    'weight' => $weight === null ? null : (float) $weight,
                    'syllabi' => $syllabi,
                ];
            }

            $structure[] = ['plo_id' => (int) $plo->id, 'plo_code' => (string) $plo->code, 'pis' => $pis];
        }

        return $this->cache[$key] = $structure;
    }

    /**
     * Cùng khung nhưng tra nhanh theo PI — phiếu học viên chỉ cần "PI này đo qua học phần nào".
     *
     * @return array<int, array<int, array<string, mixed>>> pi_id => syllabi
     */
    public function syllabiByPi(Program $program, ?int $cohortId = null): array
    {
        $out = [];
        foreach ($this->forProgram($program, $cohortId) as $plo) {
            foreach ($plo['pis'] as $pi) {
                $out[$pi['pi_id']] = $pi['syllabi'];
            }
        }

        return $out;
    }
}
