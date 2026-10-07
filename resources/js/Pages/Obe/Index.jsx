import React, { useState, useMemo } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { toast } from 'sonner';
import { 
  Plus, Edit, Trash2, Search, Eye, Filter, Info, Check, AlertCircle, X
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import SearchableSelect from '@/components/SearchableSelect';
import { useConfirm } from '@/components/ConfirmDialog';
import FormDialog from '@/components/FormDialog';
import InfoHint from '@/components/InfoHint';

const MAPPING_COLORS = {
  'I': 'bg-slate-100 text-slate-800 border-slate-200',
  'R': 'bg-blue-100 text-blue-800 border-blue-200',
  'M': 'bg-emerald-100 text-emerald-800 border-emerald-200',
  'A': 'bg-amber-100 text-amber-800 border-amber-200',
};

const PaginationControls = ({ currentPage, totalPages, onPageChange, totalItems }) => {
  if (totalItems === 0) return null;
  const startItem = (currentPage - 1) * 10 + 1;
  const endItem = Math.min(totalItems, currentPage * 10);

  return (
    <div className="flex flex-col sm:flex-row items-center justify-between gap-4 px-6 py-4 border-t border-border bg-muted/10 text-xs">
      <div className="text-muted-foreground font-medium">
        Hiển thị <span className="font-semibold text-foreground">{startItem}-{endItem}</span> trong tổng số <span className="font-semibold text-foreground">{totalItems}</span> bản ghi
      </div>
      <div className="flex items-center gap-1.5 font-semibold">
        <Button
          variant="outline"
          onClick={() => onPageChange(currentPage - 1)}
          disabled={currentPage === 1}
          className="h-8 px-3 border shadow-none bg-background text-foreground hover:bg-muted"
        >
          Trước
        </Button>
        <span className="text-muted-foreground px-2">
          Trang {currentPage} / {totalPages}
        </span>
        <Button
          variant="outline"
          onClick={() => onPageChange(currentPage + 1)}
          disabled={currentPage === totalPages}
          className="h-8 px-3 border shadow-none bg-background text-foreground hover:bg-muted"
        >
          Sau
        </Button>
      </div>
    </div>
  );
};

export default function Index({ readOnly, plos, pis, clos, mappings, programs, courses, syllabi }) {
  const confirm = useConfirm();
  const [activeTab, setActiveTab] = useState('plo');
  const [searchQuery, setSearchQuery] = useState('');
  const [filterId, setFilterId] = useState('all');

  // Pagination states
  const ITEMS_PER_PAGE = 10;
  const [ploPage, setPloPage] = useState(1);
  const [piPage, setPiPage] = useState(1);
  const [cloPage, setCloPage] = useState(1);
  const [mappingPage, setMappingPage] = useState(1);

  // Modal control states
  const [isOpen, setIsOpen] = useState(false);
  const [modalType, setModalType] = useState('plo'); // plo, pi, clo, mapping
  const [modalMode, setModalMode] = useState('create'); // create, edit, view
  const [selectedRecord, setSelectedRecord] = useState(null);

  // Forms
  const ploForm = useForm({
    program_id: '',
    code: '',
    description: '',
  });

  const piForm = useForm({
    plo_id: '',
    code: '',
    description: '',
    weight: '',
  });

  const cloForm = useForm({
    syllabus_id: '',
    code: '',
    description: '',
  });

  const mappingForm = useForm({
    clo_id: '',
    pi_id: '',
    mapping_level: 'I',
  });

  // Reset filter and pagination when tab changes
  const handleTabChange = (tab) => {
    setActiveTab(tab);
    setSearchQuery('');
    setFilterId('all');
    setPloPage(1);
    setPiPage(1);
    setCloPage(1);
    setMappingPage(1);
  };

  const handleSearchChange = (e) => {
    setSearchQuery(e.target.value);
    setPloPage(1);
    setPiPage(1);
    setCloPage(1);
    setMappingPage(1);
  };

  const handleFilterChangeSelect = (value) => {
    setFilterId(value);
    setPloPage(1);
    setPiPage(1);
    setCloPage(1);
    setMappingPage(1);
  };

  // Open modal handlers
  const openCreateModal = (type) => {
    setModalType(type);
    setModalMode('create');
    setSelectedRecord(null);
    setIsOpen(true);

    if (type === 'plo') {
      ploForm.reset();
      ploForm.clearErrors();
      if (programs.length > 0) ploForm.setData('program_id', programs[0].id.toString());
    } else if (type === 'pi') {
      piForm.reset();
      piForm.clearErrors();
      if (plos.length > 0) piForm.setData('plo_id', plos[0].id.toString());
    } else if (type === 'clo') {
      cloForm.reset();
      cloForm.clearErrors();
      if (syllabi.length > 0) cloForm.setData('syllabus_id', syllabi[0].id.toString());
    } else if (type === 'mapping') {
      mappingForm.reset();
      mappingForm.clearErrors();
      if (clos.length > 0) mappingForm.setData('clo_id', clos[0].id.toString());
      if (pis.length > 0) mappingForm.setData('pi_id', pis[0].id.toString());
      mappingForm.setData('mapping_level', 'I');
    }
  };

  const openEditModal = (type, record) => {
    setModalType(type);
    setModalMode('edit');
    setSelectedRecord(record);
    setIsOpen(true);

    if (type === 'plo') {
      ploForm.setData({
        program_id: record.program_id.toString(),
        code: record.code,
        description: record.description,
      });
      ploForm.clearErrors();
    } else if (type === 'pi') {
      piForm.setData({
        plo_id: record.plo_id.toString(),
        code: record.code,
        description: record.description,
        weight: record.weight ?? '',
      });
      piForm.clearErrors();
    } else if (type === 'clo') {
      cloForm.setData({
        syllabus_id: record.syllabus_id.toString(),
        code: record.code,
        description: record.description,
      });
      cloForm.clearErrors();
    } else if (type === 'mapping') {
      mappingForm.setData({
        clo_id: record.clo_id.toString(),
        pi_id: record.pi_id.toString(),
        mapping_level: record.mapping_level,
      });
      mappingForm.clearErrors();
    }
  };

  const openViewModal = (type, record) => {
    setModalType(type);
    setModalMode('view');
    setSelectedRecord(record);
    setIsOpen(true);
  };

  const typeLabels = { plo: 'PLO', pi: 'PI', clo: 'CLO', mapping: 'Mapping' };
  const actionLabels = { create: 'Tạo', edit: 'Cập nhật' };

  // Submit handlers
  const handleSubmit = (e) => {
    e.preventDefault();
    const onSuccess = () => {
      setIsOpen(false);
      toast.success(`${actionLabels[modalMode]} ${typeLabels[modalType]} thành công!`);
    };

    if (modalType === 'plo') {
      if (modalMode === 'create') {
        ploForm.post(route('manager.obe.plos.store'), { onSuccess });
      } else {
        ploForm.put(route('manager.obe.plos.update', selectedRecord.uuid), { onSuccess });
      }
    } else if (modalType === 'pi') {
      if (modalMode === 'create') {
        piForm.post(route('manager.obe.pis.store'), { onSuccess });
      } else {
        piForm.put(route('manager.obe.pis.update', selectedRecord.uuid), { onSuccess });
      }
    } else if (modalType === 'clo') {
      if (modalMode === 'create') {
        cloForm.post(route('manager.obe.clos.store'), { onSuccess });
      } else {
        cloForm.put(route('manager.obe.clos.update', selectedRecord.uuid), { onSuccess });
      }
    } else if (modalType === 'mapping') {
      if (modalMode === 'create') {
        mappingForm.post(route('manager.obe.mappings.store'), { onSuccess });
      } else {
        mappingForm.put(route('manager.obe.mappings.update', selectedRecord.uuid), { onSuccess });
      }
    }
  };

  const handleDelete = async (type, record) => {
    if (!(await confirm({ title: 'Xác nhận xóa', description: 'Xóa bản ghi này?', confirmText: 'Xóa', variant: 'danger' }))) return;

    const onSuccess = () => toast.success(`Xóa ${typeLabels[type]} thành công!`);

    if (type === 'plo') {
      router.delete(route('manager.obe.plos.destroy', record.uuid), { onSuccess });
    } else if (type === 'pi') {
      router.delete(route('manager.obe.pis.destroy', record.uuid), { onSuccess });
    } else if (type === 'clo') {
      router.delete(route('manager.obe.clos.destroy', record.uuid), { onSuccess });
    } else if (type === 'mapping') {
      router.delete(route('manager.obe.mappings.destroy', record.uuid), { onSuccess });
    }
  };

  // Filtered lists
  const filteredPlos = useMemo(() => {
    return plos.filter(item => {
      const matchesSearch = item.code.toLowerCase().includes(searchQuery.toLowerCase()) ||
        item.description.toLowerCase().includes(searchQuery.toLowerCase());
      const matchesFilter = filterId === 'all' || item.program_id.toString() === filterId;
      return matchesSearch && matchesFilter;
    });
  }, [plos, searchQuery, filterId]);

  const filteredPis = useMemo(() => {
    return pis.filter(item => {
      if (item.is_dummy) return false; // PLO không chia PI: không phải PI trường khai
      const matchesSearch = item.code.toLowerCase().includes(searchQuery.toLowerCase()) ||
        item.description.toLowerCase().includes(searchQuery.toLowerCase()) ||
        (item.plo_code && item.plo_code.toLowerCase().includes(searchQuery.toLowerCase()));
      const matchesFilter = filterId === 'all' || item.plo_id.toString() === filterId;
      return matchesSearch && matchesFilter;
    });
  }, [pis, searchQuery, filterId]);

  const filteredClos = useMemo(() => {
    return clos.filter(item => {
      const matchesSearch = item.code.toLowerCase().includes(searchQuery.toLowerCase()) ||
        item.description.toLowerCase().includes(searchQuery.toLowerCase()) ||
        (item.course_name && item.course_name.toLowerCase().includes(searchQuery.toLowerCase())) ||
        (item.course_code && item.course_code.toLowerCase().includes(searchQuery.toLowerCase()));
      const selectedCourse = filterId === 'all' ? null : courses.find(c => String(c.id) === filterId);
      const matchesFilter = !selectedCourse || item.course_name === selectedCourse.name;
      return matchesSearch && matchesFilter;
    });
  }, [clos, searchQuery, filterId, courses]);

  const filteredMappings = useMemo(() => {
    return mappings.filter(item => {
      const matchesSearch = item.clo_code.toLowerCase().includes(searchQuery.toLowerCase()) ||
        item.pi_code.toLowerCase().includes(searchQuery.toLowerCase()) ||
        (item.course_name && item.course_name.toLowerCase().includes(searchQuery.toLowerCase()));
      const matchesFilter = filterId === 'all' || item.clo_id.toString() === filterId;
      return matchesSearch && matchesFilter;
    });
  }, [mappings, searchQuery, filterId]);

  // Paginated lists
  const paginatedPlos = useMemo(() => {
    const start = (ploPage - 1) * ITEMS_PER_PAGE;
    return filteredPlos.slice(start, start + ITEMS_PER_PAGE);
  }, [filteredPlos, ploPage]);

  const paginatedPis = useMemo(() => {
    const start = (piPage - 1) * ITEMS_PER_PAGE;
    return filteredPis.slice(start, start + ITEMS_PER_PAGE);
  }, [filteredPis, piPage]);

  const paginatedClos = useMemo(() => {
    const start = (cloPage - 1) * ITEMS_PER_PAGE;
    return filteredClos.slice(start, start + ITEMS_PER_PAGE);
  }, [filteredClos, cloPage]);

  const paginatedMappings = useMemo(() => {
    const start = (mappingPage - 1) * ITEMS_PER_PAGE;
    return filteredMappings.slice(start, start + ITEMS_PER_PAGE);
  }, [filteredMappings, mappingPage]);

  const totalPloPages = Math.ceil(filteredPlos.length / ITEMS_PER_PAGE) || 1;
  const totalPiPages = Math.ceil(filteredPis.length / ITEMS_PER_PAGE) || 1;
  const totalCloPages = Math.ceil(filteredClos.length / ITEMS_PER_PAGE) || 1;
  const totalMappingPages = Math.ceil(filteredMappings.length / ITEMS_PER_PAGE) || 1;

  return (
    <DashboardLayout>
      <Head title="Chuẩn đầu ra (OBE)" />

      <div className="space-y-6">
        {/* Header */}
        <div>
          <h2 className="text-2xl font-bold tracking-tight">Cấu hình Chuẩn đầu ra (OBE)</h2>
          <p className="text-muted-foreground text-sm">
            {readOnly 
              ? 'Tra cứu và tham chiếu chuẩn đầu ra PLO, chỉ số thực hiện PI, CLO môn học và ma trận liên kết.'
              : 'Quản lý chuẩn đầu ra PLO, chỉ số thực hiện PI, CLO môn học và thiết lập ma trận liên kết CLO - PI.'}
          </p>
        </div>

        {/* Tab Selection */}
        <div className="flex border-b border-border">
          <button 
            onClick={() => handleTabChange('plo')} 
            className={`px-4 py-2 border-b-2 font-semibold text-sm transition-all -mb-px ${activeTab === 'plo' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'}`}
          >
            PLO (Chuẩn đầu ra CTĐT)
          </button>
          <button 
            onClick={() => handleTabChange('pi')} 
            className={`px-4 py-2 border-b-2 font-semibold text-sm transition-all -mb-px ${activeTab === 'pi' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'}`}
          >
            PI (Chỉ số thực hiện)
          </button>
          <button 
            onClick={() => handleTabChange('clo')} 
            className={`px-4 py-2 border-b-2 font-semibold text-sm transition-all -mb-px ${activeTab === 'clo' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'}`}
          >
            CLO (Chuẩn đầu ra môn học)
          </button>
          <button 
            onClick={() => handleTabChange('mapping')} 
            className={`px-4 py-2 border-b-2 font-semibold text-sm transition-all -mb-px ${activeTab === 'mapping' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'}`}
          >
            Ma trận CLO - PI Mapping
          </button>
        </div>

        {/* Search and Filters bar */}
        <div className="flex flex-col sm:flex-row gap-3 items-stretch sm:items-center justify-between bg-card p-4 rounded-xl border border-border">
          <div className="flex-1 flex flex-col sm:flex-row gap-3 items-stretch sm:items-center">
            <div className="relative flex-1 max-w-md">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
              <Input
                placeholder="Tìm kiếm nhanh..."
                value={searchQuery}
                onChange={handleSearchChange}
                className="pl-9 h-10"
              />
            </div>

            {/* Filter by Program (only on PLO tab) */}
            {activeTab === 'plo' && (
              <SearchableSelect
                value={filterId}
                onValueChange={handleFilterChangeSelect}
                className="h-10 w-[220px]"
                placeholder="Tất cả Ngành"
                options={[
                  { value: 'all', label: 'Tất cả Ngành' },
                  ...programs.map(p => ({ value: String(p.id), label: `${p.code} - ${p.name}` })),
                ]}
              />
            )}

            {/* Filter by PLO (only on PI tab) */}
            {activeTab === 'pi' && (
              <SearchableSelect
                value={filterId}
                onValueChange={handleFilterChangeSelect}
                className="h-10 w-[220px]"
                placeholder="Tất cả PLO"
                options={[
                  { value: 'all', label: 'Tất cả PLO' },
                  ...plos.map(p => ({ value: String(p.id), label: `${p.code}` })),
                ]}
              />
            )}

            {/* Filter by CLO (only on Mapping tab) */}
            {activeTab === 'mapping' && (
              <SearchableSelect
                value={filterId}
                onValueChange={handleFilterChangeSelect}
                className="h-10 w-[220px]"
                placeholder="Tất cả CLO"
                options={[
                  { value: 'all', label: 'Tất cả CLO' },
                  ...clos.map(c => ({ value: String(c.id), label: `${c.course_name} - ${c.code}` })),
                ]}
              />
            )}

            {/* Filter by Course (only on CLO tab) */}
            {activeTab === 'clo' && (
              <SearchableSelect
                value={filterId}
                onValueChange={handleFilterChangeSelect}
                className="h-10 w-[220px]"
                placeholder="Tất cả Môn học"
                options={[
                  { value: 'all', label: 'Tất cả Môn học' },
                  ...courses.map(c => ({ value: String(c.id), label: `${c.name}` })),
                ]}
              />
            )}
          </div>

          {!readOnly && (
            <Button onClick={() => openCreateModal(activeTab)} className="h-10 gap-2 shrink-0">
              <Plus className="w-4 h-4" />
              Thêm mới
            </Button>
          )}
        </div>

        {/* CREATE / EDIT / VIEW PANEL (inline) */}
        <FormDialog
          open={isOpen}
          onClose={() => setIsOpen(false)}
          size="lg"
          title={modalMode === 'view'
            ? `Chi tiết ${modalType.toUpperCase()}`
            : modalMode === 'create'
              ? `Tạo ${modalType.toUpperCase()} mới`
              : `Cập nhật ${modalType.toUpperCase()}`}
        >

              {modalMode === 'view' ? (
                <div className="space-y-4 text-left max-w-2xl">
                  {modalType === 'plo' && selectedRecord && (
                    <div className="space-y-4">
                      <div className="border-b border-border/50 pb-3">
                        <span className="text-xs font-bold text-muted-foreground uppercase tracking-wider block mb-1">Chương trình đào tạo</span>
                        <div className="text-sm font-semibold text-foreground">{selectedRecord.program_name}</div>
                      </div>
                      <div className="border-b border-border/50 pb-3">
                        <span className="text-xs font-bold text-muted-foreground uppercase tracking-wider block mb-1">Mã PLO</span>
                        <div className="text-sm font-bold text-primary">{selectedRecord.code}</div>
                      </div>
                      <div className="pb-1">
                        <span className="text-xs font-bold text-muted-foreground uppercase tracking-wider block mb-1">Mô tả chuẩn đầu ra</span>
                        <div className="text-sm font-medium text-foreground leading-relaxed whitespace-pre-line">{selectedRecord.description}</div>
                      </div>
                    </div>
                  )}

                  {modalType === 'pi' && selectedRecord && (
                    <div className="space-y-4">
                      <div className="border-b border-border/50 pb-3">
                        <span className="text-xs font-bold text-muted-foreground uppercase tracking-wider block mb-1">Thuộc PLO</span>
                        <div className="text-sm font-semibold text-foreground">{selectedRecord.plo_code}</div>
                      </div>
                      <div className="border-b border-border/50 pb-3">
                        <span className="text-xs font-bold text-muted-foreground uppercase tracking-wider block mb-1">Mã PI</span>
                        <div className="text-sm font-bold text-primary">{selectedRecord.code}</div>
                      </div>
                      <div className="pb-1">
                        <span className="text-xs font-bold text-muted-foreground uppercase tracking-wider block mb-1">Mô tả chỉ số thực hiện</span>
                        <div className="text-sm font-medium text-foreground leading-relaxed whitespace-pre-line">{selectedRecord.description}</div>
                      </div>
                    </div>
                  )}

                  {modalType === 'clo' && selectedRecord && (
                    <div className="space-y-4">
                      <div className="border-b border-border/50 pb-3">
                        <span className="text-xs font-bold text-muted-foreground uppercase tracking-wider block mb-1">Môn học học phần</span>
                        <div className="text-sm font-semibold text-foreground">{selectedRecord.course_name} ({selectedRecord.course_code})</div>
                      </div>
                      <div className="border-b border-border/50 pb-3">
                        <span className="text-xs font-bold text-muted-foreground uppercase tracking-wider block mb-1">Phiên bản đề cương</span>
                        <div className="text-sm font-medium text-foreground">Phiên bản {selectedRecord.syllabus_version}</div>
                      </div>
                      <div className="border-b border-border/50 pb-3">
                        <span className="text-xs font-bold text-muted-foreground uppercase tracking-wider block mb-1">Mã CLO</span>
                        <div className="text-sm font-bold text-primary">{selectedRecord.code}</div>
                      </div>
                      <div className="pb-1">
                        <span className="text-xs font-bold text-muted-foreground uppercase tracking-wider block mb-1">Mô tả chuẩn đầu ra học phần</span>
                        <div className="text-sm font-medium text-foreground leading-relaxed whitespace-pre-line">{selectedRecord.description}</div>
                      </div>
                    </div>
                  )}

                  {modalType === 'mapping' && selectedRecord && (
                    <div className="space-y-4">
                      <div className="border-b border-border/50 pb-3">
                        <span className="text-xs font-bold text-muted-foreground uppercase tracking-wider block mb-1">Môn học học phần</span>
                        <div className="text-sm font-semibold text-foreground">{selectedRecord.course_name}</div>
                      </div>
                      <div className="border-b border-border/50 pb-3">
                        <span className="text-xs font-bold text-muted-foreground uppercase tracking-wider block mb-1">Mã CLO</span>
                        <div className="text-sm font-bold text-foreground">{selectedRecord.clo_code}</div>
                      </div>
                      <div className="border-b border-border/50 pb-3">
                        <span className="text-xs font-bold text-muted-foreground uppercase tracking-wider block mb-1">Mã PI liên kết</span>
                        <div className="text-sm font-bold text-foreground">{selectedRecord.pi_code} (PLO {selectedRecord.plo_code})</div>
                      </div>
                      <div className="pb-1">
                        <span className="text-xs font-bold text-muted-foreground uppercase tracking-wider block mb-1">Mức độ liên kết</span>
                        <div>
                          <Badge className={`${MAPPING_COLORS[selectedRecord.mapping_level]} font-bold px-2.5 py-0.5`}>
                            Mức {selectedRecord.mapping_level}
                          </Badge>
                        </div>
                      </div>
                    </div>
                  )}

                  <div className="flex justify-end mt-3 pt-3 border-t">
                    <Button type="button" variant="outline" onClick={() => setIsOpen(false)}>Đóng</Button>
                  </div>
                </div>
              ) : (
                <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {modalType === 'plo' && (
                    <>
                      <div className="space-y-1.5">
                        <Label>Chương trình đào tạo</Label>
                        <SearchableSelect
                          value={ploForm.data.program_id}
                          onValueChange={(value) => ploForm.setData('program_id', value)}
                          className="w-full h-9"
                          placeholder="Chọn chương trình"
                          options={programs.map(p => ({ value: String(p.id), label: `${p.code} - ${p.name}` }))}
                        />
                        {ploForm.errors.program_id && <p className="text-xs text-red-500">{ploForm.errors.program_id}</p>}
                      </div>
                      <div className="space-y-1.5">
                        <Label htmlFor="code">Mã PLO</Label>
                        <Input id="code" placeholder="Ví dụ: PLO1" value={ploForm.data.code} onChange={e => ploForm.setData('code', e.target.value)} required />
                        {ploForm.errors.code && <p className="text-xs text-red-500">{ploForm.errors.code}</p>}
                      </div>
                      <div className="space-y-1.5 md:col-span-2">
                        <Label htmlFor="description">Mô tả chuẩn đầu ra</Label>
                        <textarea id="description" placeholder="Nhập nội dung chi tiết..." value={ploForm.data.description} onChange={e => ploForm.setData('description', e.target.value)} className="w-full min-h-[72px] px-3 py-2 text-sm rounded-lg border border-border bg-background focus:outline-none focus:ring-1 focus:ring-ring" required />
                        {ploForm.errors.description && <p className="text-xs text-red-500">{ploForm.errors.description}</p>}
                      </div>
                    </>
                  )}

                  {modalType === 'pi' && (
                    <>
                      <div className="space-y-1.5">
                        <Label>Thuộc PLO</Label>
                        <SearchableSelect
                          value={piForm.data.plo_id}
                          onValueChange={(value) => piForm.setData('plo_id', value)}
                          className="w-full h-9"
                          placeholder="Chọn PLO"
                          options={plos.map(p => ({ value: String(p.id), label: `${p.code} - ${p.description.slice(0, 50)}...` }))}
                        />
                        {piForm.errors.plo_id && <p className="text-xs text-red-500">{piForm.errors.plo_id}</p>}
                      </div>
                      <div className="space-y-1.5">
                        <Label htmlFor="code">Mã PI</Label>
                        <Input id="code" placeholder="Ví dụ: PI1.1" value={piForm.data.code} onChange={e => piForm.setData('code', e.target.value)} required />
                        {piForm.errors.code && <p className="text-xs text-red-500">{piForm.errors.code}</p>}
                      </div>
                      <div className="space-y-1.5 md:col-span-2">
                        <Label htmlFor="description">Mô tả chỉ số thực hiện</Label>
                        <textarea id="description" placeholder="Nhập nội dung..." value={piForm.data.description} onChange={e => piForm.setData('description', e.target.value)} className="w-full min-h-[72px] px-3 py-2 text-sm rounded-lg border border-border bg-background focus:outline-none focus:ring-1 focus:ring-ring" required />
                        {piForm.errors.description && <p className="text-xs text-red-500">{piForm.errors.description}</p>}
                      </div>
                      <div className="space-y-1.5 md:col-span-2">
                        <Label htmlFor="weight" className="inline-flex items-center gap-1">
                          Trọng số trong PLO
                          <InfoHint title="Trọng số PI">Tỉ lệ chỉ số PI này chiếm trong PLO của nó. Các PI của cùng 1 PLO nên cộng lại = 1. Để trống = chia đều.</InfoHint>
                        </Label>
                        <Input id="weight" type="number" step="0.05" min="0" placeholder="trống = các PI bằng nhau" value={piForm.data.weight} onChange={e => piForm.setData('weight', e.target.value)} />
                        <p className="text-[11px] text-muted-foreground">Tổng trọng số các PI trong 1 PLO nên = 1 (để trống nếu chia đều).</p>
                        {piForm.errors.weight && <p className="text-xs text-red-500">{piForm.errors.weight}</p>}
                      </div>
                    </>
                  )}

                  {modalType === 'clo' && (
                    <>
                      <div className="space-y-1.5">
                        <Label>Đề cương môn học</Label>
                        <SearchableSelect
                          value={cloForm.data.syllabus_id}
                          onValueChange={(value) => cloForm.setData('syllabus_id', value)}
                          className="w-full h-9"
                          placeholder="Chọn đề cương"
                          options={syllabi.map(s => ({ value: String(s.id), label: `${s.course_name} (${s.course_code}) — V${s.version}` }))}
                        />
                        {cloForm.errors.syllabus_id && <p className="text-xs text-red-500">{cloForm.errors.syllabus_id}</p>}
                      </div>
                      <div className="space-y-1.5">
                        <Label htmlFor="code">Mã CLO</Label>
                        <Input id="code" placeholder="Ví dụ: CLO1" value={cloForm.data.code} onChange={e => cloForm.setData('code', e.target.value)} required />
                        {cloForm.errors.code && <p className="text-xs text-red-500">{cloForm.errors.code}</p>}
                      </div>
                      <div className="space-y-1.5 md:col-span-2">
                        <Label htmlFor="description">Mô tả chuẩn đầu ra học phần</Label>
                        <textarea id="description" placeholder="Nhập nội dung CLO..." value={cloForm.data.description} onChange={e => cloForm.setData('description', e.target.value)} className="w-full min-h-[72px] px-3 py-2 text-sm rounded-lg border border-border bg-background focus:outline-none focus:ring-1 focus:ring-ring" required />
                        {cloForm.errors.description && <p className="text-xs text-red-500">{cloForm.errors.description}</p>}
                      </div>
                    </>
                  )}

                  {modalType === 'mapping' && (
                    <>
                      <div className="space-y-1.5">
                        <Label>Chuẩn CLO</Label>
                        <SearchableSelect
                          value={mappingForm.data.clo_id}
                          onValueChange={(value) => mappingForm.setData('clo_id', value)}
                          className="w-full h-9"
                          placeholder="Chọn CLO"
                          options={clos.map(c => ({ value: String(c.id), label: `${c.course_name} — ${c.code}` }))}
                        />
                        {mappingForm.errors.clo_id && <p className="text-xs text-red-500">{mappingForm.errors.clo_id}</p>}
                      </div>
                      <div className="space-y-1.5">
                        <Label>Liên kết tới PI / PLO</Label>
                        <SearchableSelect
                          value={mappingForm.data.pi_id}
                          onValueChange={(value) => mappingForm.setData('pi_id', value)}
                          className="w-full h-9"
                          placeholder="Chọn PI hoặc PLO"
                          options={pis.map(p => ({
                            value: String(p.id),
                            label: p.is_dummy ? `${p.plo_code} — CLO nối thẳng PLO` : `${p.code} (PLO: ${p.plo_code})`,
                          }))}
                        />
                        <p className="text-[11px] text-muted-foreground">Khóa 2025 chọn dòng "PLO… map trực tiếp"; khóa 2026 chọn PI.</p>
                        {mappingForm.errors.pi_id && <p className="text-xs text-red-500">{mappingForm.errors.pi_id}</p>}
                      </div>
                      <div className="space-y-1.5">
                        <Label className="inline-flex items-center gap-1">
                          Mức độ Mapping
                          <InfoHint title="Mức I/R/M/A">Mức môn đóng góp cho PI: I=Giới thiệu, R=Củng cố, M=Thành thạo, A=Nâng cao. Quy ra số 1/2/3/3 khi tính — mức cao đóng góp nhiều hơn.</InfoHint>
                        </Label>
                        <SearchableSelect
                          value={mappingForm.data.mapping_level}
                          onValueChange={(value) => mappingForm.setData('mapping_level', value)}
                          className="w-full h-9"
                          placeholder="Chọn mức độ"
                          options={[
                            { value: 'I', label: 'Mức I (Introduce)' },
                            { value: 'R', label: 'Mức R (Reinforce)' },
                            { value: 'M', label: 'Mức M (Master)' },
                            { value: 'A', label: 'Mức A (Assess)' },
                          ]}
                        />
                        {mappingForm.errors.mapping_level && <p className="text-xs text-red-500">{mappingForm.errors.mapping_level}</p>}
                      </div>
                    </>
                  )}

                  <div className="md:col-span-2 flex justify-end gap-2 mt-3 pt-3 border-t">
                    <Button type="button" variant="outline" onClick={() => setIsOpen(false)}>Hủy</Button>
                    <Button type="submit" disabled={ploForm.processing || piForm.processing || cloForm.processing || mappingForm.processing}>
                      {(ploForm.processing || piForm.processing || cloForm.processing || mappingForm.processing) ? 'Đang lưu...' : 'Lưu lại'}
                    </Button>
                  </div>
                </form>
              )}
        </FormDialog>

        {/* Tab Contents */}
        <Card className="border border-border shadow-none">
          <CardContent className="p-0 overflow-x-auto">
            
            {/* 1. PLO TAB */}
            {activeTab === 'plo' && (
              <div className="flex flex-col">
                <table className="w-full text-sm text-left border-collapse">
                  <thead className="bg-muted/50 border-b border-border">
                    <tr>
                      <th className="px-6 py-4 font-semibold text-muted-foreground w-32">Mã PLO</th>
                      <th className="px-6 py-4 font-semibold text-muted-foreground w-1/4">Chương trình đào tạo</th>
                      <th className="px-6 py-4 font-semibold text-muted-foreground">Mô tả chuẩn đầu ra</th>
                      <th className="px-6 py-4 font-semibold text-muted-foreground w-32 text-center">Số PI con</th>
                      <th className="px-6 py-4 font-semibold text-muted-foreground w-32 text-right">Thao tác</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {filteredPlos.length === 0 ? (
                      <tr>
                        <td colSpan="5" className="px-6 py-10 text-center text-muted-foreground">Không tìm thấy dữ liệu PLO phù hợp.</td>
                      </tr>
                    ) : (
                      paginatedPlos.map((item) => (
                        <tr key={item.id} className="hover:bg-muted/20 transition">
                          <td className="px-6 py-4 font-bold text-foreground">{item.code}</td>
                          <td className="px-6 py-4 text-muted-foreground">{item.program_name}</td>
                          <td className="px-6 py-4 text-foreground truncate max-w-md">{item.description}</td>
                          <td className="px-6 py-4 text-center font-semibold">{item.pis_count}</td>
                          <td className="px-6 py-4 text-right">
                            <div className="flex gap-2 justify-end">
                              <button onClick={() => openViewModal('plo', item)} className="p-1.5 hover:bg-muted rounded text-muted-foreground hover:text-foreground">
                                <Eye className="w-4 h-4" />
                              </button>
                              {!readOnly && (
                                <>
                                  <button onClick={() => openEditModal('plo', item)} className="p-1.5 hover:bg-muted rounded text-amber-600 hover:text-amber-700">
                                    <Edit className="w-4 h-4" />
                                  </button>
                                  <button onClick={() => handleDelete('plo', item)} className="p-1.5 hover:bg-muted rounded text-rose-600 hover:text-rose-700">
                                    <Trash2 className="w-4 h-4" />
                                  </button>
                                </>
                              )}
                            </div>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
                <PaginationControls
                  currentPage={ploPage}
                  totalPages={totalPloPages}
                  onPageChange={setPloPage}
                  totalItems={filteredPlos.length}
                />
              </div>
            )}

            {/* 2. PI TAB */}
            {activeTab === 'pi' && (
              <div className="flex flex-col">
                <table className="w-full text-sm text-left border-collapse">
                  <thead className="bg-muted/50 border-b border-border">
                    <tr>
                      <th className="px-6 py-4 font-semibold text-muted-foreground w-32">Mã PI</th>
                      <th className="px-6 py-4 font-semibold text-muted-foreground w-32">Thuộc PLO</th>
                      <th className="px-6 py-4 font-semibold text-muted-foreground">Mô tả chỉ số thực hiện</th>
                      <th className="px-6 py-4 font-semibold text-muted-foreground w-32 text-right">Thao tác</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {filteredPis.length === 0 ? (
                      <tr>
                        <td colSpan="4" className="px-6 py-10 text-center text-muted-foreground">Không tìm thấy dữ liệu PI phù hợp.</td>
                      </tr>
                    ) : (
                      paginatedPis.map((item) => (
                        <tr key={item.id} className="hover:bg-muted/20 transition">
                          <td className="px-6 py-4 font-bold text-foreground">{item.code}</td>
                          <td className="px-6 py-4 font-semibold text-muted-foreground">{item.plo_code}</td>
                          <td className="px-6 py-4 text-foreground truncate max-w-md">{item.description}</td>
                          <td className="px-6 py-4 text-right">
                            <div className="flex gap-2 justify-end">
                              <button onClick={() => openViewModal('pi', item)} className="p-1.5 hover:bg-muted rounded text-muted-foreground hover:text-foreground">
                                <Eye className="w-4 h-4" />
                              </button>
                              {!readOnly && (
                                <>
                                  <button onClick={() => openEditModal('pi', item)} className="p-1.5 hover:bg-muted rounded text-amber-600 hover:text-amber-700">
                                    <Edit className="w-4 h-4" />
                                  </button>
                                  <button onClick={() => handleDelete('pi', item)} className="p-1.5 hover:bg-muted rounded text-rose-600 hover:text-rose-700">
                                    <Trash2 className="w-4 h-4" />
                                  </button>
                                </>
                              )}
                            </div>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
                <PaginationControls
                  currentPage={piPage}
                  totalPages={totalPiPages}
                  onPageChange={setPiPage}
                  totalItems={filteredPis.length}
                />
              </div>
            )}

            {/* 3. CLO TAB */}
            {activeTab === 'clo' && (
              <div className="flex flex-col">
                <table className="w-full text-sm text-left border-collapse">
                  <thead className="bg-muted/50 border-b border-border">
                    <tr>
                      <th className="px-6 py-4 font-semibold text-muted-foreground w-32">Mã CLO</th>
                      <th className="px-6 py-4 font-semibold text-muted-foreground w-1/4">Đề cương môn học</th>
                      <th className="px-6 py-4 font-semibold text-muted-foreground">Mô tả CLO học phần</th>
                      <th className="px-6 py-4 font-semibold text-muted-foreground w-32 text-right">Thao tác</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {filteredClos.length === 0 ? (
                      <tr>
                        <td colSpan="4" className="px-6 py-10 text-center text-muted-foreground">Không tìm thấy dữ liệu CLO phù hợp.</td>
                      </tr>
                    ) : (
                      paginatedClos.map((item) => (
                        <tr key={item.id} className="hover:bg-muted/20 transition">
                          <td className="px-6 py-4 font-bold text-foreground">{item.code}</td>
                          <td className="px-6 py-4 text-muted-foreground">
                            <span className="font-medium text-foreground">{item.course_name}</span>
                            <span className="text-xs block text-muted-foreground mt-0.5">{item.course_code} • Phiên bản {item.syllabus_version}</span>
                          </td>
                          <td className="px-6 py-4 text-foreground truncate max-w-md">{item.description}</td>
                          <td className="px-6 py-4 text-right">
                            <div className="flex gap-2 justify-end">
                              <button onClick={() => openViewModal('clo', item)} className="p-1.5 hover:bg-muted rounded text-muted-foreground hover:text-foreground">
                                <Eye className="w-4 h-4" />
                              </button>
                              {!readOnly && (
                                <>
                                  <button onClick={() => openEditModal('clo', item)} className="p-1.5 hover:bg-muted rounded text-amber-600 hover:text-amber-700">
                                    <Edit className="w-4 h-4" />
                                  </button>
                                  <button onClick={() => handleDelete('clo', item)} className="p-1.5 hover:bg-muted rounded text-rose-600 hover:text-rose-700">
                                    <Trash2 className="w-4 h-4" />
                                  </button>
                                </>
                              )}
                            </div>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
                <PaginationControls
                  currentPage={cloPage}
                  totalPages={totalCloPages}
                  onPageChange={setCloPage}
                  totalItems={filteredClos.length}
                />
              </div>
            )}

            {/* 4. MAPPING TAB */}
            {activeTab === 'mapping' && (
              <div className="flex flex-col">
                <table className="w-full text-sm text-left border-collapse">
                  <thead className="bg-muted/50 border-b border-border">
                    <tr>
                      <th className="px-6 py-4 font-semibold text-muted-foreground w-1/3">Học phần môn học</th>
                      <th className="px-6 py-4 font-semibold text-muted-foreground w-32">Mã CLO</th>
                      <th className="px-6 py-4 font-semibold text-muted-foreground w-32">PLO gốc</th>
                      <th className="px-6 py-4 font-semibold text-muted-foreground w-32">Mã PI</th>
                      <th className="px-6 py-4 font-semibold text-muted-foreground w-40 text-center">Mức độ liên kết</th>
                      <th className="px-6 py-4 font-semibold text-muted-foreground w-32 text-right">Thao tác</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {filteredMappings.length === 0 ? (
                      <tr>
                        <td colSpan="6" className="px-6 py-10 text-center text-muted-foreground">Không tìm thấy dữ liệu liên kết nào.</td>
                      </tr>
                    ) : (
                      paginatedMappings.map((item) => (
                        <tr key={item.id} className="hover:bg-muted/20 transition">
                          <td className="px-6 py-4 text-foreground font-medium">{item.course_name}</td>
                          <td className="px-6 py-4 font-bold text-foreground">{item.clo_code}</td>
                          <td className="px-6 py-4 font-semibold text-muted-foreground">{item.plo_code}</td>
                          <td className="px-6 py-4 font-bold text-foreground">{item.pi_code}</td>
                          <td className="px-6 py-4 text-center">
                            <Badge className={`${MAPPING_COLORS[item.mapping_level] || 'bg-slate-100'} border font-semibold px-2.5 py-1`}>
                              Mức {item.mapping_level}
                            </Badge>
                          </td>
                          <td className="px-6 py-4 text-right">
                            <div className="flex gap-2 justify-end">
                              <button onClick={() => openViewModal('mapping', item)} className="p-1.5 hover:bg-muted rounded text-muted-foreground hover:text-foreground">
                                <Eye className="w-4 h-4" />
                              </button>
                              {!readOnly && (
                                <>
                                  <button onClick={() => openEditModal('mapping', item)} className="p-1.5 hover:bg-muted rounded text-amber-600 hover:text-amber-700">
                                    <Edit className="w-4 h-4" />
                                  </button>
                                  <button onClick={() => handleDelete('mapping', item)} className="p-1.5 hover:bg-muted rounded text-rose-600 hover:text-rose-700">
                                    <Trash2 className="w-4 h-4" />
                                  </button>
                                </>
                              )}
                            </div>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
                <PaginationControls
                  currentPage={mappingPage}
                  totalPages={totalMappingPages}
                  onPageChange={setMappingPage}
                  totalItems={filteredMappings.length}
                />
              </div>
            )}

          </CardContent>
        </Card>
      </div>
    </DashboardLayout>
  );
}
