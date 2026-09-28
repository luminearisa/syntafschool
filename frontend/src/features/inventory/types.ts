export interface InventoryCategoryOption { id: number; name: string; type: string }
export interface InventoryUnitOption { id: number; name: string; symbol: string | null }
export interface InventoryLocationOption { id: number; name: string; room_id: number | null; room?: { id: number; name: string } | null }

export interface InventoryLookups {
  categories: InventoryCategoryOption[];
  units: InventoryUnitOption[];
  locations: InventoryLocationOption[];
}

export interface InventoryMovement {
  id: number;
  movement_type: 'opening' | 'in' | 'out' | string;
  quantity: number;
  quantity_before: number;
  quantity_after: number;
  reason: string;
  reference: string | null;
  created_by_name: string | null;
  created_at: string | null;
}

export interface InventoryItem {
  id: number;
  sku: string;
  name: string;
  item_category_id: number | null;
  category_name: string | null;
  unit_id: number | null;
  unit_name: string | null;
  unit_symbol: string | null;
  storage_location_id: number | null;
  location_name: string | null;
  room_name: string | null;
  serial_number: string | null;
  quantity: number;
  minimum_quantity: number;
  is_low_stock: boolean;
  unit_cost: number | null;
  purchase_date: string | null;
  condition: string;
  is_active: boolean;
  notes: string | null;
  movements?: InventoryMovement[];
}

export interface InventoryItemPayload {
  sku: string;
  name: string;
  item_category_id?: number | null;
  unit_id?: number | null;
  storage_location_id?: number | null;
  serial_number?: string | null;
  quantity?: number;
  minimum_quantity?: number;
  unit_cost?: number | null;
  purchase_date?: string | null;
  condition: string;
  is_active: boolean;
  notes?: string | null;
}
