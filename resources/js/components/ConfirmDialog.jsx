import React, { createContext, useContext, useState, useCallback, useRef } from 'react';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { AlertTriangle } from 'lucide-react';

const ConfirmContext = createContext(null);

/**
 * Promise-based confirmation dialog. Usage:
 *   const confirm = useConfirm();
 *   if (await confirm({ title, description, confirmText, variant: 'danger' })) { ... }
 */
export function useConfirm() {
  const ctx = useContext(ConfirmContext);
  if (!ctx) throw new Error('useConfirm must be used within a ConfirmProvider');
  return ctx;
}

export function ConfirmProvider({ children }) {
  const [state, setState] = useState({ open: false, options: {} });
  const resolver = useRef(null);

  const confirm = useCallback((options = {}) => {
    return new Promise((resolve) => {
      resolver.current = resolve;
      setState({ open: true, options });
    });
  }, []);

  const settle = useCallback((result) => {
    setState((s) => ({ ...s, open: false }));
    if (resolver.current) {
      resolver.current(result);
      resolver.current = null;
    }
  }, []);

  const o = state.options;
  const danger = o.variant === 'danger';

  return (
    <ConfirmContext.Provider value={confirm}>
      {children}
      <Dialog open={state.open} onOpenChange={(open) => { if (!open) settle(false); }}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <div className="flex items-start gap-3">
              <div className={`p-2 rounded-full shrink-0 ${danger ? 'bg-rose-100 text-rose-600' : 'bg-amber-100 text-amber-600'}`}>
                <AlertTriangle className="w-5 h-5" />
              </div>
              <div className="space-y-1 pt-0.5">
                <DialogTitle>{o.title ?? 'Xác nhận'}</DialogTitle>
                <DialogDescription>{o.description ?? 'Bạn có chắc chắn muốn tiếp tục?'}</DialogDescription>
              </div>
            </div>
          </DialogHeader>
          <DialogFooter className="gap-2 sm:gap-2">
            <Button variant="outline" onClick={() => settle(false)}>{o.cancelText ?? 'Hủy'}</Button>
            <Button
              className={danger ? 'bg-rose-600 hover:bg-rose-700 text-white' : ''}
              onClick={() => settle(true)}
            >
              {o.confirmText ?? 'Xác nhận'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </ConfirmContext.Provider>
  );
}
