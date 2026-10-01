import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { FinanceInvoicesPage } from '@/features/finance/pages/FinanceInvoicesPage';
import { FinanceUnitsPage } from '@/features/finance/pages/FinanceUnitsPage';
import { FinanceFundsPage } from '@/features/finance/pages/FinanceFundsPage';
import { FinanceOpeningBalancePage } from '@/features/finance/pages/FinanceOpeningBalancePage';
import { FinanceRequestsPage } from '@/features/finance/pages/FinanceRequestsPage';
import { FinanceReportsPage } from '@/features/finance/pages/FinanceReportsPage';
import { FinanceAssetsPage } from '@/features/finance/pages/FinanceAssetsPage';
import { FinanceAccrualsPage } from '@/features/finance/pages/FinanceAccrualsPage';
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
  {
    path: 'finance/units',
    element: <PermissionGuard permission="finance.account.view"><FinanceUnitsPage /></PermissionGuard>,
  },
  {
    path: 'finance/funds',
    element: <PermissionGuard permission="finance.account.view"><FinanceFundsPage /></PermissionGuard>,
  },
  {
    path: 'finance/opening-balances',
    element: <PermissionGuard permission="finance.account.view"><FinanceOpeningBalancePage /></PermissionGuard>,
  },
  {
    path: 'finance/assets',
    element: <PermissionGuard permission="finance.asset.view"><FinanceAssetsPage /></PermissionGuard>,
  },
  {
    path: 'finance/accruals',
    element: <PermissionGuard permission="finance.accrual.view"><FinanceAccrualsPage /></PermissionGuard>,
  },
  {
    path: 'finance/requests',
    element: (
      <PermissionGuard permission={['finance.closing.view', 'finance.journal.view']}>
        <FinanceRequestsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'finance/reports',
    element: <PermissionGuard permission="finance.report.view"><FinanceReportsPage /></PermissionGuard>,
  },
];

export const financeMenu: MenuChild[] = [
  { label: 'Tagihan & Invoice', to: '/finance/invoices', permission: 'finance.invoice.view' },
  { label: 'Pembayaran', to: '/finance/payments', permission: 'finance.payment.view' },
  { label: 'Tabungan / Wallet', to: '/finance/savings', permission: 'wallet.view' },
  { label: 'Unit Bisnis', to: '/finance/units', permission: 'finance.account.view' },
  { label: 'Dana & Peruntukan', to: '/finance/funds', permission: 'finance.account.view' },
  { label: 'Saldo Awal', to: '/finance/opening-balances', permission: 'finance.account.view' },
  { label: 'Aset Tetap', to: '/finance/assets', permission: 'finance.asset.view' },
  { label: 'Akrual', to: '/finance/accruals', permission: 'finance.accrual.view' },
  { label: 'Permohonan', to: '/finance/requests', permission: 'finance.closing.view' },
  { label: 'Laporan Akuntansi', to: '/finance/reports', permission: 'finance.report.view' },
];
