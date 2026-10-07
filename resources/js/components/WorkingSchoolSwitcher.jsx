import React from 'react';
import { router, usePage } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import SearchableSelect from '@/components/SearchableSelect';
import { setWorkingSchool, setWorkingSchoolCookie } from '@/lib/workingSchool';

/**
 * "Trường đang làm việc" ở header — dùng chung cho TOÀN console Manager.
 * Đổi trường → ghi cookie (server đọc) + đồng bộ localStorage (Reports/Dashboard cũ) → reload.
 *
 * Chỉ có MỘT trường thì không cần bộ chọn, nhưng vẫn phải HIỆN TÊN. Trước đây chỗ này trả về
 * null, mà giảng viên / cố vấn học tập bị kẹp cứng vào đúng một trường — nên mọi người dùng
 * không phải quản trị hệ thống đăng nhập vào đều không thấy một dấu hiệu nào cho biết họ đang
 * làm việc trong trường nào. Với sản phẩm đa-trường thì đó đúng là thứ tuyệt đối không được thiếu:
 * cả đợt rà soát tách dữ liệu theo trường cũng chỉ để tránh làm nhầm trường.
 */
export default function WorkingSchoolSwitcher() {
  const { workingSchools = [], workingSchoolId, workingSchoolName } = usePage().props;

  // Không chuyển được trường nào (đúng một trường, hoặc trường đã bị tắt) → nhãn tĩnh.
  // Vẫn phải hiện tên: đó mới là thứ người dùng cần biết.
  const ten = workingSchools.length === 1 ? workingSchools[0].name : workingSchoolName;

  if (!workingSchools || workingSchools.length <= 1) {
    if (!ten) return null;

    return (
      <div className="flex items-center gap-1.5 text-sm" title={`Bạn đang làm việc trong: ${ten}`}>
        <Building2 className="h-4 w-4 text-muted-foreground shrink-0" />
        <span className="max-w-[10rem] truncate font-medium sm:max-w-xs">{ten}</span>
      </div>
    );
  }


  const onChange = (v) => {
    if (!v || String(v) === String(workingSchoolId ?? '')) return;
    setWorkingSchool(v);
    setWorkingSchoolCookie(v);
    router.reload();
  };

  return (
    <div className="flex items-center gap-1.5">
      <Building2 className="h-4 w-4 text-muted-foreground shrink-0" />
      <SearchableSelect
        value={workingSchoolId ? String(workingSchoolId) : ''}
        onValueChange={onChange}
        className="h-8 w-44 sm:w-56"
        placeholder="Trường đang làm việc"
        options={workingSchools.map((s) => ({ value: String(s.id), label: s.name }))}
      />
    </div>
  );
}
