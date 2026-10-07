import React, { useState, useMemo } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link } from '@inertiajs/react';
import {
  ArrowLeft, GraduationCap, Mail, Phone, BookOpen, Users, Building2, Briefcase, User
} from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { lecturerStatusMeta } from '@/lib/status';

export default function Show({ lecturer, classes = [] }) {
  const totalStudents = useMemo(() => {
    return classes.reduce((sum, c) => sum + (c.student_count ?? 0), 0);
  }, [classes]);

  return (
    <DashboardLayout>
      <Head title={`${lecturer.full_name} - Giảng viên`} />

      <div className="space-y-6">
        <Link
          href={route('manager.lecturers.index')}
          className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition"
        >
          <ArrowLeft className="w-4 h-4" />
          Quay lại danh sách giảng viên
        </Link>

        {/* Header */}
        <Card className="shadow-none">
          <CardContent className="p-6">
            <div className="flex flex-col md:flex-row justify-between gap-6">
              <div className="flex gap-4 items-center">
                <div className="flex items-center justify-center size-16 rounded-xl bg-primary/10 text-primary shrink-0">
                  <User className="size-8" />
                </div>
                <div>
                  <div className="flex items-center gap-3">
                    <h1 className="text-xl font-bold">{lecturer.full_name}</h1>
                    <span className={`inline-block px-2 py-0.5 rounded text-xs font-medium border ${lecturerStatusMeta(lecturer.employment_status).className}`}>
                      {lecturerStatusMeta(lecturer.employment_status).label}
                    </span>
                  </div>
                  <p className="text-sm text-muted-foreground flex items-center gap-1.5 mt-0.5">
                    <Briefcase className="w-3.5 h-3.5" />
                    <span>MSSS: </span>
                    <span className="font-semibold">{lecturer.staff_code}</span>
                  </p>
                  <div className="flex flex-wrap items-center gap-4 mt-2 text-xs text-muted-foreground">
                    {lecturer.position && (
                      <span className="flex items-center gap-1">
                        <GraduationCap className="w-3.5 h-3.5" />
                        {lecturer.position}
                      </span>
                    )}
                    {lecturer.department && (
                      <span className="flex items-center gap-1">
                        <Building2 className="w-3.5 h-3.5" />
                        {lecturer.department.name}
                      </span>
                    )}
                    {lecturer.email && (
                      <span className="flex items-center gap-1">
                        <Mail className="w-3.5 h-3.5" />
                        {lecturer.email}
                      </span>
                    )}
                    {lecturer.phone && (
                      <span className="flex items-center gap-1">
                        <Phone className="w-3.5 h-3.5" />
                        {lecturer.phone}
                      </span>
                    )}
                  </div>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Stats */}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <Card className="shadow-none">
            <CardContent className="p-6 flex items-center gap-4">
              <div className="flex items-center justify-center size-12 rounded-xl bg-primary/10 text-primary shrink-0">
                <BookOpen className="size-6" />
              </div>
              <div className="min-w-0">
                <p className="text-sm font-medium text-muted-foreground">Tổng số lớp</p>
                <div className="flex items-baseline gap-1.5 mt-0.5">
                  <span className="text-3xl font-bold tracking-tight">{classes.length}</span>
                  <span className="text-base font-medium text-muted-foreground">lớp</span>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card className="shadow-none">
            <CardContent className="p-6 flex items-center gap-4">
              <div className="flex items-center justify-center size-12 rounded-xl bg-primary/10 text-primary shrink-0">
                <Users className="size-6" />
              </div>
              <div className="min-w-0">
                <p className="text-sm font-medium text-muted-foreground">Tổng số SV</p>
                <div className="flex items-baseline gap-1.5 mt-0.5">
                  <span className="text-3xl font-bold tracking-tight">{totalStudents}</span>
                  <span className="text-base font-medium text-muted-foreground">SV</span>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Classes Table */}
        <Card className="shadow-none">
          <div className="px-6 py-4 border-b">
            <h3 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">Lớp đang dạy</h3>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/50">
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Mã lớp</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Môn học</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">HK</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Năm</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Số SV</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Chi tiết</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {classes.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="p-8 text-center text-sm text-muted-foreground">Giảng viên chưa được phân công lớp nào</td>
                  </tr>
                ) : (
                  classes.map((cls, index) => (
                    <tr key={cls.uuid} className="hover:bg-muted/30 transition-colors">
                      <td className="p-4">
                        <span className="font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded text-xs">{cls.class_code}</span>
                      </td>
                      <td className="p-4">
                        <div className="font-semibold">{cls.course_name}</div>
                        <div className="text-xs text-muted-foreground">{cls.course_code}</div>
                      </td>
                      <td className="p-4 text-center">{cls.semester}</td>
                      <td className="p-4 text-center">{cls.academic_year}</td>
                      <td className="p-4 text-center font-semibold">{cls.student_count}</td>
                      <td className="p-4 text-center">
                        <Link
                          href={route('manager.classes.show', cls.uuid)}
                          className="inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600 hover:text-indigo-700 transition bg-indigo-50 hover:bg-indigo-100 px-3 py-1.5 rounded-lg"
                        >
                          Chi tiết
                        </Link>
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
