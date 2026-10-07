import React from 'react';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { HelpCircle } from 'lucide-react';
// Dùng CHUNG nguồn với trang Hướng dẫn — trước đây file này giữ bản sao riêng 4 bước nên
// thêm bước mới ở guideContent là drawer này lạc hậu ngay mà không ai biết.
import { STEPS } from '@/config/guideContent';


/**
 * Drawer "Hướng dẫn" — giải thích 4 bước đo lường chuẩn đầu ra.
 * Dùng ở Dashboard + Trung tâm đo lường. Truyền `trigger` để tự đặt nút, hoặc dùng nút mặc định.
 */
export default function HelpSheet({ trigger }) {
  return (
    <Sheet>
      <SheetTrigger asChild>
        {trigger ?? (
          <Button variant="outline" size="sm" className="gap-1.5">
            <HelpCircle className="h-4 w-4" /> Hướng dẫn
          </Button>
        )}
      </SheetTrigger>
      <SheetContent className="w-full overflow-y-auto sm:max-w-md">
        <SheetHeader>
          <SheetTitle>Hướng dẫn đo lường chuẩn đầu ra</SheetTitle>
          <SheetDescription>Từ dữ liệu của trường → báo cáo kiểm định → bảng cho học viên, khoa và giảng viên.</SheetDescription>
        </SheetHeader>

        <ol className="mt-6 space-y-4">
          {STEPS.map((s) => (
            <li key={s.n} className="flex gap-3">
              <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-primary-foreground">
                {s.n}
              </span>
              <div>
                <p className="font-semibold leading-tight">{s.title}</p>
                <p className="mt-0.5 text-sm text-muted-foreground">{s.short}</p>
              </div>
            </li>
          ))}
        </ol>

        <div className="mt-6 rounded-lg bg-muted/50 p-3 text-xs text-muted-foreground">
          Mẹo: mỗi phương án có file mẫu riêng ở Bước 2. Nhập điểm xong nhớ bấm <b>"Tính lại"</b> ở Bước 3 trước khi tạo báo cáo.
        </div>
      </SheetContent>
    </Sheet>
  );
}
