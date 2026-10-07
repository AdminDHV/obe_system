import React from 'react';
import InputError from '@/components/InputError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Link, useForm, usePage } from '@inertiajs/react';
import { Mail, User, Phone, CheckCircle2, AlertCircle } from 'lucide-react';

export default function UpdateProfileInformation({
    mustVerifyEmail,
    status,
}) {
    const user = usePage().props.auth.user;
    const roles = user.roles || [];
    const isAdmin = roles.includes('super_admin');

    const { data, setData, patch, errors, processing, recentlySuccessful } =
        useForm({
            full_name: user.full_name || '',
            email: user.email || '',
            phone: user.phone || '',
        });

    const submit = (e) => {
        e.preventDefault();
        patch(route('profile.update'));
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                {/* Full Name */}
                <div className="space-y-1.5 col-span-2 md:col-span-1">
                    <Label htmlFor="full_name" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Họ và tên</Label>
                    <div className="relative">
                        <User className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                        <Input
                            id="full_name"
                            value={data.full_name}
                            onChange={(e) => setData('full_name', e.target.value)}
                            required
                            autoComplete="name"
                            className="pl-10 h-10 text-sm"
                            placeholder="Nhập họ và tên giảng viên"
                        />
                    </div>
                    <InputError message={errors.full_name} />
                </div>

                {/* Phone */}
                <div className="space-y-1.5 col-span-2 md:col-span-1">
                    <Label htmlFor="phone" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Số điện thoại</Label>
                    <div className="relative">
                        <Phone className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                        <Input
                            id="phone"
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                            autoComplete="tel"
                            className="pl-10 h-10 text-sm"
                            placeholder="Nhập số điện thoại liên hệ"
                        />
                    </div>
                    <InputError message={errors.phone} />
                </div>

                {/* Email */}
                <div className="space-y-1.5 col-span-2">
                    <Label htmlFor="email" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Địa chỉ Email</Label>
                    <div className="relative">
                        <Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                        <Input
                            id="email"
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            required
                            disabled={!isAdmin}
                            autoComplete="username"
                            className="pl-10 h-10 text-sm bg-muted/20 disabled:opacity-85 disabled:cursor-not-allowed"
                            placeholder="email@example.com"
                        />
                    </div>
                    {!isAdmin && (
                        <p className="text-[10px] font-medium text-muted-foreground/80 mt-1">
                            * Địa chỉ Email được quản lý bởi hệ thống và không thể tự thay đổi.
                        </p>
                    )}
                    <InputError message={errors.email} />
                </div>
            </div>

            {mustVerifyEmail && user.email_verified_at === null && (
                <div className="p-4 rounded-xl border bg-amber-50/50 border-amber-200 text-amber-800 text-xs flex gap-3 items-start">
                    <AlertCircle className="w-4 h-4 shrink-0 mt-0.5 text-amber-600" />
                    <div>
                        <p className="font-semibold">Địa chỉ email của bạn chưa được xác minh.</p>
                        <Link
                            href={route('verification.send')}
                            method="post"
                            as="button"
                            className="mt-1 font-bold text-primary underline underline-offset-2 hover:text-primary/80"
                        >
                            Nhấp vào đây để gửi lại email xác minh
                        </Link>
                        {status === 'verification-link-sent' && (
                            <p className="mt-1.5 text-xs font-semibold text-emerald-700">
                                Liên kết xác minh mới đã được gửi tới email của bạn.
                            </p>
                        )}
                    </div>
                </div>
            )}

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={processing} className="h-10 px-6 font-semibold">
                    {processing ? 'Đang lưu...' : 'Lưu thay đổi'}
                </Button>

                {recentlySuccessful && (
                    <p className="text-xs font-semibold text-emerald-600 flex items-center gap-1">
                        <CheckCircle2 className="size-3.5" /> Đã cập nhật thành công.
                    </p>
                )}
            </div>
        </form>
    );
}
