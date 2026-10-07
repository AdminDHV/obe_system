import React from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';

export default function VerifyEmail({ status }) {
    const { post, processing } = useForm();

    const submit = (e) => {
        e.preventDefault();
        post(route('verification.send'));
    };

    return (
        <GuestLayout>
            <Head title="Xác minh email" />

            <Card className="shadow-none">
                <CardHeader className="text-center">
                    <CardTitle className="text-xl">Xác minh địa chỉ email</CardTitle>
                    <CardDescription>
                        Cảm ơn bạn đã đăng ký! Vui lòng nhấn vào liên kết trong email chúng tôi vừa gửi để xác minh tài khoản.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {status === 'verification-link-sent' && (
                        <div className="mb-4 text-sm font-medium text-green-600 bg-green-50 border border-green-200 rounded-md p-3">
                            Liên kết xác minh mới đã được gửi đến email của bạn.
                        </div>
                    )}

                    <form onSubmit={submit} className="space-y-4">
                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing ? 'Đang gửi...' : 'Gửi lại email xác minh'}
                        </Button>
                    </form>
                </CardContent>
                <CardFooter className="justify-center">
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        Đăng xuất
                    </Link>
                </CardFooter>
            </Card>
        </GuestLayout>
    );
}
