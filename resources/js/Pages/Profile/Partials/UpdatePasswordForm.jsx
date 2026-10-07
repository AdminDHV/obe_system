import React, { useRef } from 'react';
import InputError from '@/components/InputError';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import PasswordInput from '@/components/PasswordInput';
import { useForm } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';

export default function UpdatePasswordForm() {
    const passwordInput = useRef();
    const currentPasswordInput = useRef();

    const {
        data,
        setData,
        errors,
        put,
        reset,
        processing,
        recentlySuccessful,
    } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const updatePassword = (e) => {
        e.preventDefault();

        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current?.focus();
                }

                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current?.focus();
                }
            },
        });
    };

    return (
        <form onSubmit={updatePassword} className="space-y-6 max-w-xl">
            {/* Current Password */}
            <div className="space-y-1.5">
                <Label htmlFor="current_password" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Mật khẩu hiện tại</Label>
                <PasswordInput
                    id="current_password"
                    ref={currentPasswordInput}
                    value={data.current_password}
                    onChange={(e) => setData('current_password', e.target.value)}
                    autoComplete="current-password"
                    placeholder="••••••••"
                />
                <InputError message={errors.current_password} />
            </div>

            {/* New Password */}
            <div className="space-y-1.5">
                <Label htmlFor="password" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Mật khẩu mới</Label>
                <PasswordInput
                    id="password"
                    ref={passwordInput}
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    autoComplete="new-password"
                    placeholder="••••••••"
                />
                <InputError message={errors.password} />
            </div>

            {/* Confirm New Password */}
            <div className="space-y-1.5">
                <Label htmlFor="password_confirmation" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Xác nhận mật khẩu mới</Label>
                <PasswordInput
                    id="password_confirmation"
                    value={data.password_confirmation}
                    onChange={(e) => setData('password_confirmation', e.target.value)}
                    autoComplete="new-password"
                    placeholder="••••••••"
                />
                <InputError message={errors.password_confirmation} />
            </div>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={processing} className="h-10 px-6 font-semibold">
                    {processing ? 'Đang cập nhật...' : 'Đổi mật khẩu'}
                </Button>

                {recentlySuccessful && (
                    <p className="text-xs font-semibold text-emerald-600 flex items-center gap-1">
                        <CheckCircle2 className="size-3.5" /> Đã đổi mật khẩu thành công.
                    </p>
                )}
            </div>
        </form>
    );
}
