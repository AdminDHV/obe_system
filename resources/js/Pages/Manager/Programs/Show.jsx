import React, { useState } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

export default function Show({ program, courses, plos }) {
  const [courseSearch, setCourseSearch] = useState('');
  const [ploSearch, setPloSearch] = useState('');

  const totalCredits = courses.reduce((sum, c) => sum + (c.credits ?? 0), 0);

  const filteredCourses = courses.filter(c =>
    !courseSearch || c.code.toLowerCase().includes(courseSearch.toLowerCase()) ||
    c.name.toLowerCase().includes(courseSearch.toLowerCase())
  );

  const filteredPlos = plos.filter(p =>
    !ploSearch || p.code.toLowerCase().includes(ploSearch.toLowerCase()) ||
    p.name.toLowerCase().includes(ploSearch.toLowerCase()) ||
    (p.description && p.description.toLowerCase().includes(ploSearch.toLowerCase()))
  );

  return (
    <DashboardLayout>
      <Head title={program.name} />

      <div className="space-y-6">
        {/* Header */}
        <div>
          <h2 className="text-2xl font-bold tracking-tight">{program.name}</h2>
          <p className="text-muted-foreground">
            Mã ngành: {program.code} · {totalCredits} tín chỉ · {courses.length} môn học · {plos.length} PLO
          </p>
        </div>

        {/* Courses Table */}
        <Card className="shadow-none">
          <div className="px-6 py-3 border-b flex items-center justify-between gap-4">
            <h3 className="font-semibold text-sm uppercase tracking-wider text-muted-foreground">Danh sách khóa học</h3>
            <div className="relative w-64">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Tìm mã HP, tên học phần..."
                value={courseSearch}
                onChange={(e) => setCourseSearch(e.target.value)}
                className="h-8 pl-8 pr-7 text-xs"
              />
              {courseSearch && (
                <button onClick={() => setCourseSearch('')} className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
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
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Mã HP</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Tên học phần</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Tín chỉ</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {filteredCourses.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="p-8 text-center text-sm text-muted-foreground">Không tìm thấy khóa học</td>
                  </tr>
                ) : (
                  filteredCourses.map((c, index) => (
                    <tr key={index} className="hover:bg-muted/30 transition-colors">
                      <td className="p-4 text-muted-foreground">{index + 1}</td>
                      <td className="p-4 font-medium">{c.code}</td>
                      <td className="p-4">{c.name}</td>
                      <td className="p-4 text-center font-semibold">{c.credits}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </Card>

        {/* PLOs Table */}
        <Card className="shadow-none">
          <div className="px-6 py-3 border-b flex items-center justify-between gap-4">
            <h3 className="font-semibold text-sm uppercase tracking-wider text-muted-foreground">Chuẩn đầu ra chương trình (PLO)</h3>
            <div className="relative w-64">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Tìm mã PLO, tên PLO..."
                value={ploSearch}
                onChange={(e) => setPloSearch(e.target.value)}
                className="h-8 pl-8 pr-7 text-xs"
              />
              {ploSearch && (
                <button onClick={() => setPloSearch('')} className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
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
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Mã PLO</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Tên / Mô tả</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {filteredPlos.length === 0 ? (
                  <tr>
                    <td colSpan={3} className="p-8 text-center text-sm text-muted-foreground">Không tìm thấy PLO</td>
                  </tr>
                ) : (
                  filteredPlos.map((p, index) => (
                    <tr key={index} className="hover:bg-muted/30 transition-colors">
                      <td className="p-4 text-muted-foreground">{index + 1}</td>
                      <td className="p-4 font-medium">{p.code}</td>
                      <td className="p-4">
                        <span>{p.name}</span>
                        {p.description && (
                          <p className="text-xs text-muted-foreground mt-0.5">{p.description}</p>
                        )}
                      </td>
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
