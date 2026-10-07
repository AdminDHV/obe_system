import React from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import InputError from '@/components/InputError';
import PasswordInput from '@/components/PasswordInput';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('password.confirm'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Xác nhận mật khẩu" />

            <Card className="shadow-none">
                <CardHeader className="text-center">
                    <CardTitle className="text-xl">Xác nhận mật khẩu</CardTitle>
                    <CardDescription>
                        Vui lòng xác nhận mật khẩu để tiếp tục truy cập.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="password">Mật khẩu</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                value={data.password}
                                autoFocus
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="••••••••"
                            />
                            <InputError message={errors.password} />
                        </div>

                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing ? 'Đang xác nhận...' : 'Xác nhận'}
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </GuestLayout>
    );
}
