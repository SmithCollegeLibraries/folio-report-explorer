import { useCallback } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { deleteReport } from '../api/client';
import { useAuth } from './useAuth';

export function useReportDeletion(reportId: number, reportName: string) {
  const { isAdmin } = useAuth();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const deleteMutation = useMutation({
    mutationFn: () => deleteReport(reportId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['reports'] });
      void queryClient.invalidateQueries({ queryKey: ['report', reportId] });
      navigate('/reports');
    },
  });

  const confirmDelete = useCallback(() => {
    if (window.confirm(`Delete "${reportName}"?`)) {
      deleteMutation.mutate();
    }
  }, [deleteMutation.mutate, reportName]);

  return {
    isAdmin,
    isDeleting: deleteMutation.isPending,
    confirmDelete,
  };
}
