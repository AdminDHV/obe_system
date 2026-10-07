import React from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import InputError from '@/components/InputError';

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('password.email'));
    };

    return (
        <GuestLayout>
            <Head title="Quên mật khẩu" />

            <Card className="shadow-none">
                <CardHeader className="text-center">
                    <CardTitle className="text-xl">Quên mật khẩu?</CardTitle>
                    <CardDescription>
                        Nhập email của bạn và chúng tôi sẽ gửi liên kết đặt lại mật khẩu
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
                                autoFocus
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="name@example.com"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing ? 'Đang gửi...' : 'Gửi liên kết đặt lại'}
                        </Button>
                    </form>
                </CardContent>
                <CardFooter className="justify-center">
                    <p className="text-sm text-muted-foreground">
                        Nhớ mật khẩu?{' '}
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
