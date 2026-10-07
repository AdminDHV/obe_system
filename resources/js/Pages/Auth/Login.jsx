import React from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError';
import PasswordInput from '@/components/PasswordInput';

export default function Login({ status, canResetPassword }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Đăng nhập" />

            <Card className="shadow-none">
                <CardHeader className="text-center">
                    <CardTitle className="text-xl">Đăng nhập</CardTitle>
                    <CardDescription>
                        Nhập thông tin tài khoản để truy cập hệ thống
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {status && (
                        <div className="mb-4 text-sm font-medium text-green-600 bg-green-50 border border-green-200 rounded-md p-3">
                            {status}
                        </div>
                    )}

                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                value={data.email}
                                autoComplete="username"
                                autoFocus
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="name@example.com"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <Label htmlFor="password">Mật khẩu</Label>
                                {canResetPassword && (
                                    <Link
                                        href={route('password.request')}
                                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                                    >
                                        Quên mật khẩu?
                                    </Link>
                                )}
                            </div>
                            <PasswordInput
                                id="password"
                                name="password"
                                value={data.password}
                                autoComplete="current-password"
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="••••••••"
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="flex items-center space-x-2">
                            <Checkbox
                                id="remember"
                                name="remember"
                                checked={data.remember}
                                onCheckedChange={(checked) => setData('remember', checked)}
                            />
                            <Label htmlFor="remember" className="text-sm font-normal text-muted-foreground">
                                Ghi nhớ đăng nhập
                            </Label>
                        </div>

                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing ? 'Đang xử lý...' : 'Đăng nhập'}
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </GuestLayout>
    );
}
