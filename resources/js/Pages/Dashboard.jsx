import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { AlertTriangle } from 'lucide-react';

export default function Dashboard() {
  const { auth } = usePage().props;
  const roles = auth?.user?.roles || [];

  return (
    <DashboardLayout>
      <Head title="Dashboard" />

      <div className="space-y-6">
        <div>
          <h2 className="text-2xl font-bold tracking-tight">Dashboard</h2>
          <p className="text-muted-foreground">
            Xin chào, <span className="font-semibold text-foreground">{auth?.user?.name || 'Người dùng'}</span>.
          </p>
        </div>
        <Card className="shadow-none">
          <CardHeader className="text-center">
            <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-lg bg-primary/10 text-primary">
              <AlertTriangle className="h-6 w-6" />
            </div>
            <CardTitle>Tài khoản chưa được phân quyền</CardTitle>
            <CardDescription>
              Tài khoản của bạn chưa được gán vai trò trong hệ thống.
              Vui lòng liên hệ quản trị viên để được cấp quyền truy cập phù hợp.
            </CardDescription>
          </CardHeader>
          <CardContent className="text-center">
            <p className="text-sm text-muted-foreground mb-4">
              Vai trò hiện tại: {roles.length > 0 ? roles.join(', ') : 'Chưa có'}
            </p>
            <Button variant="outline" asChild>
              <a href={route('profile.edit')}>Xem hồ sơ cá nhân</a>
            </Button>
          </CardContent>
        </Card>
      </div>
    </DashboardLayout>
  );
}
