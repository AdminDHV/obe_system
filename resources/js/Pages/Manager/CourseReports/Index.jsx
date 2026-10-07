import React from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FileText, Search, X } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import ListEmptyState from '@/components/ListEmptyState';

/**
 * Chọn học phần để làm báo cáo. Báo cáo gộp mọi lớp của học phần trong một học kỳ, nên ở đây
 * liệt kê sẵn những học kỳ mà học phần đó đã có lớp.
 */
export default function Index({ courses, currentFilters }) {
  const searchTimeout = React.useRef(null);

  const search = (value) => {
    if (searchTimeout.current) clearTimeout(searchTimeout.current);
    searchTimeout.current = setTimeout(() => {
      router.get(route('manager.reports.course.index'), value ? { q: value } : {}, { preserveState: true, replace: true });
    }, 300);
  };

  return (
    <DashboardLayout>
      <Head title="Báo cáo học phần" />

      <div className="space-y-5">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Báo cáo học phần</h1>
          <p className="text-sm text-muted-foreground mt-1">
            Chọn học phần và học kỳ, hệ thống dựng báo cáo theo mẫu của trường rồi xuất ra file Word.
            Số liệu gộp tất cả các lớp của học phần trong học kỳ đó.
          </p>
        </div>

        <div className="relative max-w-sm">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
          <Input
            defaultValue={currentFilters?.q || ''}
            onChange={(e) => search(e.target.value)}
            placeholder="Tìm theo mã hoặc tên học phần"
            className="pl-9"
          />
          {currentFilters?.q && (
            <button
              type="button"
              onClick={() => router.get(route('manager.reports.course.index'))}
              className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
            >
              <X className="h-4 w-4" />
            </button>
          )}
        </div>

        {courses.length === 0 ? (
          <ListEmptyState
            icon={FileText}
            what="học phần"
            filters={currentFilters}
            routeName="manager.reports.course.index"
            hint="Báo cáo lấy số liệu từ các lớp đã nhập điểm, nên hãy nhập phiếu lớp học phần trước."
          />
        ) : (
          <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            {courses.map((course) => (
              <Card key={course.uuid} className="p-4 flex flex-col gap-3">
                <div>
                  <div className="font-medium leading-snug">{course.name}</div>
                  <div className="text-sm text-muted-foreground mt-0.5">
                    {course.code} · {course.credits} tín chỉ
                    {course.periods ? ` · ${course.periods} tiết` : ''}
                  </div>
                  {course.managing_unit && (
                    <div className="text-sm text-muted-foreground">{course.managing_unit}</div>
                  )}
                </div>

                <div className="flex flex-wrap gap-2 mt-auto">
                  {course.terms.map((term) => (
                    <Button
                      key={`${term.nam_hoc}-${term.hoc_ky}`}
                      asChild
                      variant="outline"
                      size="sm"
                    >
                      <Link
                        href={route('manager.reports.course.show', course.uuid)}
                        data={{ nam_hoc: term.nam_hoc || '', hoc_ky: term.hoc_ky || '' }}
                      >
                        Học kỳ {term.hoc_ky || '—'} · {term.nam_hoc || 'chưa ghi năm học'}
                        <span className="text-muted-foreground ml-1">({term.so_lop} lớp)</span>
                      </Link>
                    </Button>
                  ))}
                </div>
              </Card>
            ))}
          </div>
        )}
      </div>
    </DashboardLayout>
  );
}
