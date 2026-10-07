import React from 'react';
import { Link, router } from '@inertiajs/react';
import { FilterX } from 'lucide-react';
import { Button } from '@/components/ui/button';

/**
 * Khối "bảng đang trống" dùng chung cho mọi màn hình danh sách.
 *
 * Trước đây chín màn hình đều in cứng "Không tìm thấy … / Thử thay đổi bộ lọc tìm kiếm", kể cả
 * khi người dùng CHƯA đặt bộ lọc nào. Trường mới nhận hệ thống, mở Học phần ra thấy trống, rồi
 * được bảo đi sửa bộ lọc họ không hề đặt — trong khi việc thật sự cần làm là tải file khung lên.
 * Câu chỉ dẫn sai còn tệ hơn không có câu nào: nó đẩy người ta đi nhầm đường.
 *
 * Nên ở đây tách đúng hai tình huống:
 *   - đang lọc mà không ra gì  → nói là do bộ lọc, cho nút xóa lọc.
 *   - không lọc gì mà vẫn trống → trường chưa có dữ liệu, chỉ đúng chỗ tạo ra nó.
 *
 * `filters` nhận thẳng prop `currentFilters` của trang. Khóa `page` và các khóa sắp xếp không
 * tính là lọc, và giá trị 'all' là "không lọc" theo quy ước của các ô Select trong dự án.
 */
const BO_QUA = ['page', 'sort', 'sort_field', 'sort_dir', 'per_page'];

export function hasActiveFilters(filters = {}) {
  return Object.entries(filters ?? {}).some(([k, v]) =>
    !BO_QUA.includes(k) && v !== null && v !== undefined && v !== '' && v !== 'all');
}

export default function ListEmptyState({ icon: Icon, what, filters, routeName, hint = null }) {
  const dangLoc = hasActiveFilters(filters);

  return (
    <div className="p-12 text-center">
      {Icon && <Icon className="w-12 h-12 text-muted-foreground/50 mx-auto mb-2" />}

      {dangLoc ? (
        <>
          <p className="text-sm font-medium text-muted-foreground">Không có {what} nào khớp bộ lọc</p>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="mt-3 h-8"
            onClick={() => router.get(route(routeName), {}, { preserveState: false })}
          >
            <FilterX className="w-3.5 h-3.5" /> <span className="ml-1">Xóa bộ lọc</span>
          </Button>
        </>
      ) : (
        <>
          <p className="text-sm font-medium text-muted-foreground">Trường này chưa có {what} nào</p>
          {hint && <p className="text-xs text-muted-foreground/80 mt-1 max-w-md mx-auto">{hint}</p>}
        </>
      )}
    </div>
  );
}

/** Câu gợi ý dùng lại: phần lớn danh mục sinh ra từ file khung ở Bước 1. */
export function GoiYKhung({ children }) {
  return (
    <>
      {children}{' '}
      <Link href={route('manager.reports.index', { step: 1 })} className="text-primary hover:underline">
        Trung tâm đo lường — Bước 1
      </Link>.
    </>
  );
}
