import { Link } from '@inertiajs/react';

export default function GuestLayout({ children }) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-muted/40 p-4">
            <div className="w-full max-w-md space-y-6">
                <div className="flex flex-col items-center space-y-2 text-center">
                    <Link href="/" className="flex items-center space-x-2">
                        <div className="flex size-10 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                            <span className="font-bold text-sm">DHV</span>
                        </div>
                    </Link>
                    <h1 className="text-2xl font-bold tracking-tight">
                        OBE System
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Hệ thống Quản lý Chuẩn đầu ra
                    </p>
                </div>
                {children}
            </div>
        </div>
    );
}
