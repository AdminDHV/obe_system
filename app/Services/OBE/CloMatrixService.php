<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;

/**
 * MA TRẬN → PLO/PI — một chỗ hiểu mã cột cho mọi đường nhập.
 *
 * Hai ma trận: bảng CLO – PLO/PI của đề cương ở khối "Chuẩn đầu ra" của phiếu lớp học phần (CLO nào đo
 * PLO/PI nào), và ma trận CTĐT môn × PLO/PI ở sheet `MaTran_CTDT` của file khai báo chương trình (môn đóng
 * góp PLO/PI nào, mức nào — mức này làm trọng số). Hai nơi mà hiểu mã cột theo hai cách thì cùng một mã
 * sẽ trỏ về hai PI khác nhau, nên quy tắc nằm ở đây.
 *
 * Quy tắc (khách chốt ở buổi họp lần 1):
 *  - Tiêu đề cột là mã PI thật của ngành, hoặc mã PLO.
 *  - PLO KHÔNG chia PI thì CLO nối thẳng vào PLO. Cách lưu: một dòng `pis` mã trùng mã PLO
 *    (`is_dummy = true`) để engine dùng chung công thức; màn hình và file xuất chỉ hiện mã PLO.
 *  - PLO ĐÃ chia PI thì phải ghi mã PI cụ thể — không thì không biết điểm chia về PI nào.
 *  - Mức chỉ nhận I, R, M, A.
 */
final class CloMatrixService
{
    public const LEVELS = ['I', 'R', 'M', 'A'];

    /**
     * @return array{type:?string, pi:?Pi, plo:?Plo, error:?string}
     */
    public function resolve(Program $program, string $code): array
    {
        $code = trim($code);

        $pi = Pi::where('code', $code)
            ->where('is_dummy', false)
            ->whereHas('plo', fn ($q) => $q->where('program_id', $program->id))
            ->first();
        if ($pi) {
            return ['type' => 'pi', 'pi' => $pi, 'plo' => null, 'error' => null];
        }

        $plo = Plo::where('program_id', $program->id)->where('code', $code)->with('pis')->first();
        if (! $plo) {
            return ['type' => null, 'pi' => null, 'plo' => null,
                'error' => "không có PLO/PI mã '{$code}' trong ngành {$program->code} — khai ở sheet PLO_PI của file khai báo chương trình."];
        }

        $real = $plo->pis->where('is_dummy', false);
        if ($real->isNotEmpty()) {
            return ['type' => null, 'pi' => null, 'plo' => $plo,
                'error' => "{$code} đã chia thành PI (" . $real->pluck('code')->implode(', ') . ') — ghi mã PI cụ thể thay cho PLO.'];
        }

        return ['type' => 'plo', 'pi' => null, 'plo' => $plo, 'error' => null];
    }

    /**
     * Cột của MA TRẬN CTĐT (môn × PLO/PI). Khác cột ma trận CLO ở một chỗ: bảng CTĐT thường ghi ở cấp
     * PLO kể cả khi PLO đã chia PI — khi đó mức của môn áp cho MỌI PI của PLO đó. PLO không chia PI thì
     * trỏ dòng lưu nối thẳng của PLO (tạo nếu chưa có). Chỉ gọi ở đường GHI.
     *
     * @return array{pis: array<int,Pi>, plo: ?Plo, error: ?string}
     */
    public function resolveCourseColumn(Program $program, string $code): array
    {
        $code = trim($code);

        $pi = Pi::where('code', $code)
            ->where('is_dummy', false)
            ->whereHas('plo', fn ($q) => $q->where('program_id', $program->id))
            ->with('plo')
            ->first();
        if ($pi) {
            return ['pis' => [$pi], 'plo' => $pi->plo, 'error' => null];
        }

        $plo = Plo::where('program_id', $program->id)->where('code', $code)->with('pis')->first();
        if (! $plo) {
            return ['pis' => [], 'plo' => null,
                'error' => "không có PLO/PI mã '{$code}' trong ngành {$program->code} — khai ở sheet PLO_PI."];
        }

        $real = $plo->pis->where('is_dummy', false)->sortBy('id')->values();
        if ($real->isNotEmpty()) {
            return ['pis' => $real->all(), 'plo' => $plo, 'error' => null];
        }

        return ['pis' => [$this->piFor(['type' => 'plo', 'pi' => null, 'plo' => $plo])], 'plo' => $plo, 'error' => null];
    }

    /** Mã hiển thị của một PI trong ma trận: PI nội bộ hiện mã PLO của nó. */
    public static function targetCode(Pi $pi): string
    {
        return $pi->is_dummy ? (string) ($pi->plo?->code ?? $pi->code) : (string) $pi->code;
    }

    /** PI thật, hoặc PI nội bộ đại diện cho PLO nối thẳng (tạo nếu chưa có). */
    public function piFor(array $target): Pi
    {
        if ($target['type'] === 'pi') {
            return $target['pi'];
        }

        /** @var Plo $plo */
        $plo = $target['plo'];

        return Pi::firstOrCreate(
            ['plo_id' => $plo->id, 'is_dummy' => true],
            ['code' => $plo->code, 'description' => $plo->description ?? $plo->code, 'weight' => 1],
        );
    }

    public static function normalizeLevel(mixed $value): string
    {
        return mb_strtoupper(trim((string) $value));
    }

    /**
     * Ghi ma trận của MỘT CLO đúng như vừa khai: thêm/cập nhật các ô có mức, gỡ ánh xạ không còn khai.
     *
     * @param array<int,string> $levelByPiId pi_id → I/R/M/A
     * @return int số ánh xạ đã ghi
     */
    public function sync(Clo $clo, array $levelByPiId): int
    {
        foreach ($levelByPiId as $piId => $level) {
            CloPiMapping::updateOrCreate(['clo_id' => $clo->id, 'pi_id' => $piId], ['mapping_level' => $level]);
        }
        CloPiMapping::where('clo_id', $clo->id)->whereNotIn('pi_id', array_keys($levelByPiId))->delete();

        return count($levelByPiId);
    }
}
