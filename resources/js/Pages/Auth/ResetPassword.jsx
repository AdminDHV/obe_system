import React from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import InputError from '@/components/InputError';
import PasswordInput from '@/components/PasswordInput';

export default function ResetPassword({ token, email }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('password.store'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Đặt lại mật khẩu" />

            <Card className="shadow-none">
                <CardHeader className="text-center">
                    <CardTitle className="text-xl">Đặt lại mật khẩu</CardTitle>
                    <CardDescription>
                        Nhập mật khẩu mới cho tài khoản của bạn
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                value={data.email}
                                autoComplete="username"
                                readOnly
                                onChange={(e) => setData('email', e.target.value)}
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="password">Mật khẩu mới</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                value={data.password}
                                autoComplete="new-password"
                                autoFocus
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="••••••••"
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="password_confirmation">Xác nhận mật khẩu</Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                value={data.password_confirmation}
                                autoComplete="new-password"
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                placeholder="••••••••"
                            />
                            <InputError message={errors.password_confirmation} />
                        </div>

                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing ? 'Đang xử lý...' : 'Đặt lại mật khẩu'}
                        </Button>
                    </form>
                </CardContent>
                <CardFooter className="justify-center">
                    <p className="text-sm text-muted-foreground">
                        <Link
                            href={route('login')}
                            className="font-medium text-primary underline-offset-4 hover:underline"
                        >
                            Quay lại đăng nhập
                        </Link>
                    </p>
                </CardFooter>
            </Card>
        </GuestLayout>
    );
}
