import React from 'react';
import { usePage } from '@inertiajs/react';

/**
 * Lỗi validate của MỘT ô nhập, đọc thẳng từ Inertia props (`errors`).
 *
 * Các form CRUD trong Manager gửi bằng `router.post/put` + state cục bộ (không dùng `useForm`),
 * nên lỗi 422 trả về nằm ở `usePage().props.errors` — trước đây KHÔNG form nào đọc, người dùng
 * bấm Lưu thì dialog cứ đứng im, không biết vì sao.
 *
 *   <Input value={...} />
 *   <FieldError name="phone" />
 */
export default function FieldError({ name, className = '' }) {
  const { errors } = usePage().props;
  const message = errors?.[name];

  if (!message) return null;

  return (
    <p className={`text-xs text-destructive mt-1 ${className}`}>
      {Array.isArray(message) ? message[0] : message}
    </p>
  );
}
