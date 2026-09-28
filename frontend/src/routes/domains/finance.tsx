import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { FinanceInvoicesPage } from '@/features/finance/pages/FinanceInvoicesPage';
import { FinancePaymentsPage } from '@/features/finance/pages/FinancePaymentsPage';
import { WalletSavingsPage } from '@/features/finance/pages/WalletSavingsPage';

export const domainRoutes: RouteObject[] = [
  {
    path: 'finance/invoices',
    element: <PermissionGuard permission={['finance.billing.view', 'finance.invoice.view']}><FinanceInvoicesPage /></PermissionGuard>,
  },
  {
    path: 'finance/payments',
    element: <PermissionGuard permission="finance.payment.view"><FinancePaymentsPage /></PermissionGuard>,
  },
  {
    path: 'finance/savings',
    element: <PermissionGuard permission="wallet.view"><WalletSavingsPage /></PermissionGuard>,
  },
];

export const financeMenu: MenuChild[] = [
  { label: 'Tagihan & Invoice', to: '/finance/invoices', permission: 'finance.invoice.view' },
  { label: 'Pembayaran', to: '/finance/payments', permission: 'finance.payment.view' },
  { label: 'Tabungan / Wallet', to: '/finance/savings', permission: 'wallet.view' },
];
