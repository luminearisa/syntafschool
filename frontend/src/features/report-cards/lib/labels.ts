import type { ReportCardStatus } from '../types';

export function reportStatusTone(status: ReportCardStatus): 'green' | 'blue' | 'amber' | 'gray' | 'red' {
  if (status === 'published' || status === 'approved') return 'green';
  if (status === 'ready_for_review') return 'amber';
  if (status === 'in_progress') return 'blue';
  if (status === 'draft') return 'gray';
  return 'red';
}
