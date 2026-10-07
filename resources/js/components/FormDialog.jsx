import React from 'react';
import { usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription,
} from '@/components/ui/dialog';

const WIDTHS = {
  sm: 'sm:max-w-md',   // ~448px — very small forms
  md: 'sm:max-w-xl',   // ~576px — single-column 2-3 field forms
  lg: 'sm:max-w-2xl',  // ~672px — 2-column medium forms
  xl: 'sm:max-w-3xl',  // ~768px — 2-column large forms
};

/**
 * Shared modal wrapper for create/edit forms. Keeps every CRUD form consistent:
 * centered overlay, scrollable body for tall forms, standard header.
 *
 *   <FormDialog open={!!formMode} onClose={closeForm} title={...}>
 *     <form>...fields + buttons...</form>
 *   </FormDialog>
 *
 * Tự hiện BẢNG TÓM TẮT LỖI VALIDATE (`usePage().props.errors`) ngay đầu dialog. Các form CRUD gửi
 * bằng `router.post/put` + state cục bộ nên không có sẵn cơ chế hiện lỗi — trước đây bấm Lưu mà
 * thiếu field bắt buộc thì dialog ĐỨNG IM, người dùng không biết vì sao (bug thật, gặp ở màn Giảng
 * viên). Đặt ở đây để MỌI form dùng FormDialog đều được che, kể cả form chưa gắn <FieldError>.
 */
export default function FormDialog({ open, onClose, title, description, size = 'xl', children }) {
  const { errors } = usePage().props;
  const messages = Object.values(errors ?? {}).flat();

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) onClose(); }}>
      <DialogContent className={`${WIDTHS[size] ?? WIDTHS.xl} max-h-[85vh] overflow-y-auto`}>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          {description ? <DialogDescription>{description}</DialogDescription> : null}
        </DialogHeader>

        {messages.length > 0 && (
          <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm">
            <p className="flex items-center gap-2 font-medium text-destructive">
              <AlertTriangle className="w-4 h-4 shrink-0" />
              Chưa lưu được — vui lòng kiểm tra lại:
            </p>
            <ul className="mt-1.5 ml-6 list-disc space-y-0.5 text-destructive/90">
              {messages.map((m, i) => <li key={i}>{m}</li>)}
            </ul>
          </div>
        )}

        {children}
      </DialogContent>
    </Dialog>
  );
}
