import React, { useState } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, usePage } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { 
  User, Lock, ShieldAlert, Clock, CheckCircle2, Award
} from 'lucide-react';
import { cn } from '@/lib/utils';

const EMPLOYMENT_STATUS_LABELS = {
    'full_time': 'Cơ hữu',
    'part_time': 'Thỉnh giảng',
    'on_leave': 'Nghỉ phép',
    'resigned': 'Đã nghỉ việc',
};

export default function Edit({ mustVerifyEmail, status }) {
    const { auth } = usePage().props;
    const user = auth?.user || {};
    const roles = user.roles || [];
    const isAdmin = roles.includes('super_admin');
    const isLecturer = roles.includes('lecturer');

    const [activeTab, setActiveTab] = useState('profile');

    const tabs = [
        { key: 'profile', label: 'Thông tin cá nhân', icon: User },
        { key: 'password', label: 'Bảo mật tài khoản', icon: Lock },
    ];

    if (isAdmin) {
        tabs.push({ key: 'delete', label: 'Khu vực nguy hiểm', icon: ShieldAlert });
    }

    return (
        <DashboardLayout>
            <Head title="Hồ sơ cá nhân" />

            <div className="space-y-6">
                <div>
                    <h2 className="text-2xl font-bold tracking-tight">Hồ sơ cá nhân</h2>
                    <p className="text-muted-foreground text-sm">
                        Quản lý thông tin tài khoản, mật khẩu và chi tiết công tác của bạn.
                    </p>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Left Column - Summary Card */}
                    <div className="lg:col-span-1 space-y-6">
                        <Card className="shadow-none border border-border/80 overflow-hidden">
                            {/* Card Header Background */}
                            <div className="h-24 bg-muted/45 border-b border-border/40" />
                            
                            <CardContent className="p-6 relative">
                                {/* Avatar */}
                                <div className="absolute -top-12 left-6 size-20 rounded-2xl border-4 border-background bg-muted flex items-center justify-center text-primary shadow-sm font-bold text-2xl uppercase">
                                    {user.avatar ? (
                                        <img src={user.avatar} alt="Avatar" className="size-full object-cover rounded-xl" />
                                    ) : (
                                        user.full_name?.charAt(0) || 'U'
                                    )}
                                </div>

                                <div className="pt-10 space-y-4">
                                    {/* Name & Title */}
                                    <div>
                                        <h3 className="text-lg font-bold flex items-center gap-1.5">
                                            {user.title_prefix && <span className="text-muted-foreground text-sm font-semibold">{user.title_prefix}</span>}
                                            {user.full_name ?? '—'}
                                        </h3>
                                        <p className="text-xs text-muted-foreground font-medium mt-0.5">
                                            {user.position ?? 'Cán bộ trường'}
                                        </p>
                                    </div>

                                    {/* Role Badge */}
                                    <div className="flex flex-wrap gap-1.5">
                                        {isAdmin && (
                                            <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                                                <Award className="size-3" /> Quản trị viên
                                            </span>
                                        )}
                                        {isLecturer && (
                                            <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                                <Award className="size-3" /> Giảng viên
                                            </span>
                                        )}
                                        {user.is_active && (
                                            <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                <CheckCircle2 className="size-3" /> Đang hoạt động
                                            </span>
                                        )}
                                    </div>

                                    {/* Detail rows */}
                                    <div className="pt-4 border-t space-y-3 text-xs">
                                        <div className="flex justify-between items-center">
                                            <span className="text-muted-foreground font-medium">Mã số cán bộ</span>
                                            <span className="font-bold text-foreground">{user.staff_code ?? '—'}</span>
                                        </div>
                                        <div className="flex justify-between items-start gap-4">
                                            <span className="text-muted-foreground font-medium shrink-0">Đơn vị / Khoa</span>
                                            <span className="font-bold text-foreground text-right">{user.department?.name ?? '—'}</span>
                                        </div>
                                        <div className="flex justify-between items-center">
                                            <span className="text-muted-foreground font-medium">Trạng thái công tác</span>
                                            <span className="font-semibold px-2 py-0.5 rounded bg-muted text-muted-foreground text-[10px]">
                                                {EMPLOYMENT_STATUS_LABELS[user.employment_status] ?? user.employment_status ?? 'Đang công tác'}
                                            </span>
                                        </div>
                                        {user.last_login_at && (
                                            <div className="flex justify-between items-center pt-2 text-[10px] text-muted-foreground">
                                                <span className="flex items-center gap-1"><Clock className="size-3" /> Đăng nhập gần nhất:</span>
                                                <span className="font-semibold">{new Date(user.last_login_at).toLocaleString('vi-VN')}</span>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Right Column - Tabs & Forms */}
                    <div className="lg:col-span-2 space-y-6">
                        {/* Tab Buttons bar */}
                        <div className="flex bg-muted/60 p-1 rounded-xl border max-w-md">
                            {tabs.map(tab => (
                                <button
                                    key={tab.key}
                                    onClick={() => setActiveTab(tab.key)}
                                    className={cn(
                                        "flex items-center justify-center gap-2 grow px-4 py-2 text-xs font-semibold rounded-lg transition-all",
                                        activeTab === tab.key
                                            ? "bg-card text-foreground shadow-sm"
                                            : "text-muted-foreground hover:text-foreground"
                                    )}
                                >
                                    <tab.icon className="size-3.5" />
                                    {tab.label}
                                </button>
                            ))}
                        </div>

                        {/* Tab Content: Profile details */}
                        {activeTab === 'profile' && (
                            <Card className="shadow-none border border-border/80">
                                <CardHeader className="pb-4">
                                    <CardTitle>Thông tin cá nhân</CardTitle>
                                    <CardDescription className="text-xs">
                                        Cập nhật họ tên, email, số điện thoại của bạn.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <UpdateProfileInformationForm
                                        mustVerifyEmail={mustVerifyEmail}
                                        status={status}
                                    />
                                </CardContent>
                            </Card>
                        )}

                        {/* Tab Content: Change Password */}
                        {activeTab === 'password' && (
                            <Card className="shadow-none border border-border/80">
                                <CardHeader className="pb-4">
                                    <CardTitle>Thay đổi mật khẩu</CardTitle>
                                    <CardDescription className="text-xs">
                                        Đảm bảo tài khoản của bạn sử dụng một mật khẩu ngẫu nhiên, dài để bảo mật.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <UpdatePasswordForm />
                                </CardContent>
                            </Card>
                        )}

                        {/* Tab Content: Danger Zone */}
                        {activeTab === 'delete' && isAdmin && (
                            <Card className="shadow-none border-2 border-destructive/20 bg-destructive/[0.01]">
                                <CardHeader className="pb-4">
                                    <CardTitle className="text-destructive">Khu vực nguy hiểm</CardTitle>
                                    <CardDescription className="text-xs">
                                        Xóa vĩnh viễn tài khoản của bạn khỏi hệ thống. Thao tác này không thể hoàn tác.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <DeleteUserForm />
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
}
