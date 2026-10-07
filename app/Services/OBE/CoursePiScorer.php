<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\Assessment;
use App\Models\CourseClass;
use App\Models\Student;
use App\Models\StudentCloResult;
use App\Models\StudentGrade;
use App\Models\StudentRubricScore;
use Illuminate\Support\Collection;

/**
 * A student's PI score (0-10 scale) inside ONE course.
 *
 * Extracted so the program engine (WeightedAttainmentMethod) and the per-course report
 * (CoursePiAttainmentService) share one implementation instead of drifting apart.
 *
 * $classId semantics:
 *   null    → LẦN HỌC GẦN NHẤT của sinh viên ở môn đó (theo năm học · học kỳ · id lớp).
 *             Trước đây là "gộp mọi lớp", nên SV học lại bị TRỘN điểm lần rớt với lần học lại:
 *             rớt 3.0 rồi học lại 8.0 ra 5.5 thay vì 8.0. Điều đó vừa sai thực tế (bảng điểm lấy
 *             lần học lại thay thế) vừa mâu thuẫn với cách tính tín chỉ tích lũy — vốn đã coi môn
 *             đó là ĐẠT.
 *   int     → chỉ một lớp cụ thể. Dùng cho báo cáo cấp lớp, nơi giảng viên chỉ được thấy sinh viên
 *             mình thực dạy.
 *
 * Scoring one student touches the same few tables for every (PI, course) pair, so the student's
 * rows are loaded ONCE and reused. Aggregating one student used to cost ~546 queries; the whole
 * program structure is a handful now. Only the latest student is kept — measurement walks students
 * one at a time, so a bigger cache would only grow memory.
 */
class CoursePiScorer
{
    /** Structural, student-independent: syllabus_id → assessments (with CLO links). */
    private array $assessmentsBySyllabus = [];

    private ?int $ctxStudentId = null;

    /** @var array<int,int> class_id → syllabus_id (the student's classes) */
    private array $ctxClassSyllabus = [];

    /** @var array<int,int> syllabus_id → class_id của LẦN HỌC GẦN NHẤT */
    private array $ctxLatestClass = [];

    /** @var array<int, array<int,float>> class_id → rubric_criteria_id → điểm câu */
    private array $ctxQuestionScores = [];

    /** @var array<int,bool> class_id → có điểm câu hỏi */
    private array $ctxQuestionClasses = [];

    /** @var array<int, array<int, array<int,float>>> class_id → assessment_id → [scores] */
    private array $ctxGrades = [];

    /**
     * @param  array<int,int>  $cloIds  CLOs of this course that measure the PI
     * @return float|null null ⇒ the student has no data for this PI in this course
     */
    public function score(Student $student, int $syllabusId, array $cloIds, ?int $classId = null): ?float
    {
        $this->loadStudent($student);
        $cloIds = array_values(array_unique(array_map('intval', $cloIds)));

        // Không chỉ định lớp ⇒ dùng LẦN HỌC GẦN NHẤT, không gộp mọi lần.
        $classId ??= $this->ctxLatestClass[$syllabusId] ?? null;

        $fromQuestions = $this->fromQuestions($syllabusId, $cloIds, $classId);
        if ($fromQuestions !== null) {
            return $fromQuestions;
        }

        return $this->fromAssessmentGrades($student, $syllabusId, $cloIds, $classId);
    }

    /**
     * LỚP của lần học gần nhất, để phiếu kết quả hiển thị đúng lần mà engine đã chấm.
     *
     * Phiếu từng gộp mọi lớp bằng `keyBy('clo_id')` nên với sinh viên HỌC LẠI, dòng nào của DB
     * trả sau thì đè dòng trước — có khi in ra điểm CLO của lần rớt trong khi ô "Mức đạt" ở trên
     * lại tính theo lần học lại.
     */
    public function latestClassFor(Student $student, int $syllabusId): ?int
    {
        $this->loadStudent($student);

        return $this->ctxLatestClass[$syllabusId] ?? null;
    }

    /** Nạp mọi dòng điểm của 1 sinh viên trong 3 truy vấn, dùng lại cho mọi (PI × môn). */
    private function loadStudent(Student $student): void
    {
        if ($this->ctxStudentId === $student->id) {
            return;
        }

        $this->ctxStudentId = $student->id;
        $this->ctxClassSyllabus = [];
        $this->ctxLatestClass = [];
        $this->ctxQuestionScores = [];
        $this->ctxQuestionClasses = [];
        $this->ctxGrades = [];

        $cloRows = StudentCloResult::where('student_id', $student->id)
            ->get(['class_id', 'clo_id', 'achieved_score']);
        $gradeRows = StudentGrade::where('student_id', $student->id)
            ->whereNotNull('score')
            ->get(['class_id', 'assessment_id', 'score']);
        $questionRows = StudentRubricScore::where('student_id', $student->id)
            ->whereNotNull('class_id')
            ->get(['class_id', 'rubric_criteria_id', 'score']);
        $questionClassIds = $questionRows->pluck('class_id')->unique();

        // Lớp vắng thi cũng là một lần học: dù học viên chưa có điểm nào khác ở đó, lần học gần nhất
        // vẫn là lớp này chứ không lùi về lần học cũ hơn.
        $vangThi = array_keys(StudentGrade::vangThiCuoiKy([$student->id]));

        $classIds = $cloRows->pluck('class_id')
            ->merge($gradeRows->pluck('class_id'))
            ->merge($questionClassIds)
            ->merge($vangThi)
            ->filter()->unique();

        // Sắp theo năm học · học kỳ · id ⇒ lớp cuối cùng của mỗi môn là LẦN HỌC GẦN NHẤT.
        $classes = CourseClass::whereIn('id', $classIds)
            ->orderBy('academic_year')->orderBy('semester')->orderBy('id')
            ->get(['id', 'syllabus_id']);

        foreach ($classes as $class) {
            $this->ctxClassSyllabus[(int) $class->id] = (int) $class->syllabus_id;
            $this->ctxLatestClass[(int) $class->syllabus_id] = (int) $class->id;
        }

        foreach ($questionClassIds as $classId) {
            $this->ctxQuestionClasses[(int) $classId] = true;
        }
        foreach ($questionRows as $row) {
            $this->ctxQuestionScores[(int) $row->class_id][(int) $row->rubric_criteria_id] = (float) $row->score;
        }
        foreach ($gradeRows as $row) {
            $this->ctxGrades[(int) $row->class_id][(int) $row->assessment_id][] = (float) $row->score;
        }

        // Lớp mà học viên vắng thi cuối kỳ, chưa thi lại: bỏ điểm của lớp đó khỏi phép đo — học viên
        // đứng ngoài cỡ mẫu của môn. Lần học gần nhất vẫn trỏ về lớp này nên môn ra "chưa có dữ liệu".
        foreach ($vangThi as $classId) {
            unset($this->ctxQuestionScores[$classId], $this->ctxQuestionClasses[$classId], $this->ctxGrades[$classId]);
        }
    }

    /**
     * Question-scored course: PI trong môn tính THẲNG từ các câu đo mọi CLO của môn nối vào PI
     * (`QuestionOutcomeScorer`, WCML công thức (7)–(8)) — không lấy trung bình điểm CLO. Môn chỉ có một
     * CLO nối PI thì ra đúng điểm CLO đó.
     *
     * Never falls back to `student_grades`: those carry assessment weights, which belong to the
     * course mark, not to outcome measurement.
     *
     * @param  array<int,int>  $cloIds
     * @return float|null null ⇒ not question-scored ⇒ caller uses assessment grades
     */
    private function fromQuestions(int $syllabusId, array $cloIds, ?int $classId): ?float
    {
        $classIds = $classId !== null
            ? [$classId]
            : array_keys(array_filter($this->ctxClassSyllabus, fn ($sid) => $sid === $syllabusId));

        $values = [];
        foreach ($classIds as $cid) {
            if (! isset($this->ctxQuestionScores[$cid])) {
                continue;
            }
            $value = QuestionOutcomeScorer::score($this->assessmentsFor($syllabusId), $this->ctxQuestionScores[$cid], $cloIds);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values === [] ? null : array_sum($values) / count($values);
    }

    /**
     * Assessment-scored course: take the assessments measuring those CLOs and combine them with
     * the shared process 0.4 / final 0.6 rule.
     *
     * @param  array<int,int>  $cloIds
     */
    private function fromAssessmentGrades(Student $student, int $syllabusId, array $cloIds, ?int $classId): ?float
    {
        $assessments = $this->assessmentsFor($syllabusId)
            ->filter(fn (Assessment $a) => $a->mappedCloIds()->intersect($cloIds)->isNotEmpty());

        if ($assessments->isEmpty()) {
            return null;
        }

        $process = [];
        $final = [];
        foreach ($this->ctxGrades as $rowClassId => $byAssessment) {
            if ($classId !== null ? $rowClassId !== $classId : ($this->ctxClassSyllabus[$rowClassId] ?? null) !== $syllabusId) {
                continue;
            }
            foreach ($byAssessment as $assessmentId => $values) {
                $assessment = $assessments->get($assessmentId);
                if (! $assessment) {
                    continue;
                }
                foreach ($values as $value) {
                    if ($assessment->assessment_category === 'final') {
                        $final[] = $value;
                    } else {
                        $process[] = $value;
                    }
                }
            }
        }

        if ($process === [] && $final === []) {
            return null;
        }

        return ObeScoreFormula::weightedFromCategories($process, $final);
    }

    /** @return Collection<int,Assessment> keyed by id */
    private function assessmentsFor(int $syllabusId): Collection
    {
        return $this->assessmentsBySyllabus[$syllabusId] ??= Assessment::where('syllabus_id', $syllabusId)
            ->with(['cloMappings', 'rubric.criteria'])
            ->get()
            ->keyBy('id');
    }
}
