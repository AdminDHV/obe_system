import React, { useState } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link } from '@inertiajs/react';
import { Eye, Search, X } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

export default function Show({ department, programs, lecturers }) {
  const [programSearch, setProgramSearch] = useState('');
  const [lecturerSearch, setLecturerSearch] = useState('');

  const filteredPrograms = programs.filter(p =>
    !programSearch || p.code.toLowerCase().includes(programSearch.toLowerCase()) ||
    p.name.toLowerCase().includes(programSearch.toLowerCase())
  );

  const filteredLecturers = lecturers.filter(l =>
    !lecturerSearch || l.full_name.toLowerCase().includes(lecturerSearch.toLowerCase()) ||
    l.staff_code.toLowerCase().includes(lecturerSearch.toLowerCase()) ||
    (l.email && l.email.toLowerCase().includes(lecturerSearch.toLowerCase()))
  );

  return (
    <DashboardLayout>
      <Head title={department.name} />

      <div className="space-y-6">
        {/* Header */}
        <div>
          <h2 className="text-2xl font-bold tracking-tight">{department.name}</h2>
          <p className="text-muted-foreground">Mã khoa: {department.code} · {lecturers.length} giảng viên · {programs.length} ngành đào tạo</p>
        </div>

        {/* Programs Table */}
        <Card className="shadow-none">
          <div className="px-6 py-3 border-b flex items-center justify-between gap-4">
            <h3 className="font-semibold text-sm uppercase tracking-wider text-muted-foreground">Danh sách ngành đào tạo</h3>
            <div className="relative w-64">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Tìm mã ngành, tên ngành..."
                value={programSearch}
                onChange={(e) => setProgramSearch(e.target.value)}
                className="h-8 pl-8 pr-7 text-xs"
              />
              {programSearch && (
                <button onClick={() => setProgramSearch('')} className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                  <X className="h-3.5 w-3.5" />
                </button>
              )}
            </div>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/50">
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">#</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Mã ngành</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Tên ngành đào tạo</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Chi tiết</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {filteredPrograms.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="p-8 text-center text-sm text-muted-foreground">Không tìm thấy ngành đào tạo</td>
                  </tr>
                ) : (
                  filteredPrograms.map((p, index) => (
                    <tr key={p.uuid} className="hover:bg-muted/30 transition-colors">
                      <td className="p-4 text-muted-foreground">{index + 1}</td>
                      <td className="p-4 font-medium">{p.code}</td>
                      <td className="p-4">{p.name}</td>
                      <td className="p-4 text-center">
                        <Link
                          href={route('manager.programs.show', p.uuid)}
                          className="p-1.5 text-indigo-600 hover:bg-indigo-50 rounded-lg transition inline-block"
                          title="Xem chi tiết"
                        >
                          <Eye className="w-4 h-4" />
                        </Link>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </Card>

        {/* Lecturers Table */}
        <Card className="shadow-none">
          <div className="px-6 py-3 border-b flex items-center justify-between gap-4">
            <h3 className="font-semibold text-sm uppercase tracking-wider text-muted-foreground">Danh sách giảng viên</h3>
            <div className="relative w-64">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Tìm mã NV, họ tên, email..."
                value={lecturerSearch}
                onChange={(e) => setLecturerSearch(e.target.value)}
                className="h-8 pl-8 pr-7 text-xs"
              />
              {lecturerSearch && (
                <button onClick={() => setLecturerSearch('')} className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                  <X className="h-3.5 w-3.5" />
                </button>
              )}
            </div>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/50">
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">#</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Mã NV</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Họ tên</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Chức vụ</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Email</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">SĐT</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {filteredLecturers.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="p-8 text-center text-sm text-muted-foreground">Không tìm thấy giảng viên</td>
                  </tr>
                ) : (
                  filteredLecturers.map((l, index) => (
                    <tr key={l.uuid} className="hover:bg-muted/30 transition-colors">
                      <td className="p-4 text-muted-foreground">{index + 1}</td>
                      <td className="p-4 font-medium">{l.staff_code}</td>
                      <td className="p-4">{l.full_name}</td>
                      <td className="p-4 text-muted-foreground">{l.position ?? '—'}</td>
                      <td className="p-4 text-muted-foreground">{l.email}</td>
                      <td className="p-4 text-muted-foreground">{l.phone ?? '—'}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </Card>
      </div>
    </DashboardLayout>
  );
}
