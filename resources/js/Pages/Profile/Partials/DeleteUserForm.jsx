import InputError from '@/components/InputError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

export default function DeleteUserForm() {
    const [open, setOpen] = useState(false);
    const passwordInput = useRef();

    const {
        data,
        setData,
        delete: destroy,
        processing,
        reset,
        errors,
        clearErrors,
    } = useForm({
        password: '',
    });

    const deleteUser = (e) => {
        e.preventDefault();

        destroy(route('profile.destroy'), {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
            onError: () => passwordInput.current.focus(),
            onFinish: () => reset(),
        });
    };

    const handleClose = () => {
        setOpen(false);
        clearErrors();
        reset();
    };

    return (
        <div className="space-y-4">
            <div>
                <p className="text-sm text-muted-foreground">
                    Khi xóa tài khoản, tất cả dữ liệu sẽ bị xóa vĩnh viễn. Vui lòng sao lưu dữ liệu cần giữ trước khi xóa.
                </p>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogTrigger asChild>
                    <Button variant="destructive">Xóa tài khoản</Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Bạn có chắc chắn muốn xóa tài khoản?</DialogTitle>
                        <DialogDescription>
                            Hành động này không thể hoàn tác. Tất cả dữ liệu sẽ bị xóa vĩnh viễn.
                            Vui lòng nhập mật khẩu để xác nhận.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={deleteUser} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="password" className="sr-only">
                                Mật khẩu
                            </Label>
                            <Input
                                id="password"
                                type="password"
                                name="password"
                                ref={passwordInput}
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="Nhập mật khẩu để xác nhận"
                                autoFocus
                            />
                            <InputError message={errors.password} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={handleClose}>
                                Hủy
                            </Button>
                            <Button type="submit" variant="destructive" disabled={processing}>
                                {processing ? 'Đang xóa...' : 'Xóa tài khoản'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
