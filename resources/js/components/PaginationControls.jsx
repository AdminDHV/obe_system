import React from 'react';
import { Button } from '@/components/ui/button';

export default function PaginationControls({ currentPage, totalPages, onPageChange, totalItems, perPage = 15 }) {
  if (totalItems === 0) return null;

  const startItem = (currentPage - 1) * perPage + 1;
  const endItem = Math.min(totalItems, currentPage * perPage);

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
}
