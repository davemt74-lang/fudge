<?php
final class PurchasingService
{
    public function __construct(private Database $db) {}

    public function createPurchaseOrder(int $supplierId, ?string $expectedAt, ?string $notes, int $userId): int
    {
        $supplier = $this->db->one('SELECT id FROM suppliers WHERE id=? AND is_active=1', [$supplierId]);
        if (!$supplier) throw new RuntimeException('Select an active supplier.');

        $number = 'PO-' . date('Ymd-His') . '-' . random_int(10, 99);
        return $this->db->insert(
            'INSERT INTO purchase_orders (po_number,supplier_id,status,expected_at,notes,created_by)
             VALUES (?,?,"draft",?,?,?)',
            [$number,$supplierId,$expectedAt ?: null,$notes ?: null,$userId]
        );
    }

    public function addItem(int $purchaseOrderId, int $supplierItemId, float $orderedPackages): int
    {
        if ($orderedPackages <= 0) throw new RuntimeException('Ordered package quantity must be greater than zero.');

        return $this->db->transaction(function(Database $db) use ($purchaseOrderId,$supplierItemId,$orderedPackages) {
            $po = $db->one('SELECT * FROM purchase_orders WHERE id=? FOR UPDATE', [$purchaseOrderId]);
            if (!$po || $po['status'] !== 'draft') throw new RuntimeException('Items can only be added to a draft purchase order.');

            $item = $db->one('SELECT * FROM supplier_items WHERE id=? AND supplier_id=?', [$supplierItemId,$po['supplier_id']]);
            if (!$item) throw new RuntimeException('The supplier item does not belong to this purchase order supplier.');

            if ($item['item_type'] === 'ingredient') {
                $catalog = $db->one('SELECT name,inventory_unit FROM ingredients WHERE id=?', [$item['item_id']]);
            } else {
                $catalog = $db->one('SELECT name,inventory_unit FROM packaging_items WHERE id=?', [$item['item_id']]);
            }
            if (!$catalog) throw new RuntimeException('The linked catalog item no longer exists.');

            $lineTotal = round($orderedPackages * (float)$item['package_price'], 2);
            $id = $db->insert(
                'INSERT INTO purchase_order_items
                 (purchase_order_id,supplier_item_id,item_type,item_id,description,ordered_packages,package_quantity,package_unit,package_price,line_total,inventory_unit)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $purchaseOrderId,$supplierItemId,$item['item_type'],$item['item_id'],$catalog['name'],
                    $orderedPackages,$item['package_quantity'],$item['package_unit'],$item['package_price'],$lineTotal,$catalog['inventory_unit']
                ]
            );

            $this->recalculateTotals($purchaseOrderId, $db);
            return $id;
        });
    }

    public function removeItem(int $purchaseOrderItemId): void
    {
        $this->db->transaction(function(Database $db) use ($purchaseOrderItemId) {
            $item = $db->one(
                'SELECT poi.*,po.status FROM purchase_order_items poi
                 JOIN purchase_orders po ON po.id=poi.purchase_order_id
                 WHERE poi.id=? FOR UPDATE',
                [$purchaseOrderItemId]
            );
            if (!$item || $item['status'] !== 'draft') throw new RuntimeException('Only draft purchase order items can be removed.');
            $db->exec('DELETE FROM purchase_order_items WHERE id=?', [$purchaseOrderItemId]);
            $this->recalculateTotals((int)$item['purchase_order_id'], $db);
        });
    }

    public function submit(int $purchaseOrderId, int $userId): void
    {
        $this->db->transaction(function(Database $db) use ($purchaseOrderId,$userId) {
            $po = $db->one('SELECT * FROM purchase_orders WHERE id=? FOR UPDATE', [$purchaseOrderId]);
            if (!$po || $po['status'] !== 'draft') throw new RuntimeException('Only draft purchase orders can be submitted.');
            $count = (int)$db->scalar('SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id=?', [$purchaseOrderId]);
            if ($count < 1) throw new RuntimeException('Add at least one item before submitting the purchase order.');
            $db->exec(
                'UPDATE purchase_orders SET status="submitted",ordered_at=NOW(),approved_by=? WHERE id=?',
                [$userId,$purchaseOrderId]
            );
        });
    }

    public function cancel(int $purchaseOrderId): void
    {
        $po = $this->db->one('SELECT status FROM purchase_orders WHERE id=?', [$purchaseOrderId]);
        if (!$po || in_array($po['status'], ['received','cancelled'], true)) throw new RuntimeException('This purchase order cannot be cancelled.');
        $received = (float)$this->db->scalar('SELECT COALESCE(SUM(received_quantity),0) FROM purchase_order_items WHERE purchase_order_id=?', [$purchaseOrderId]);
        if ($received > 0) throw new RuntimeException('A partially received purchase order cannot be cancelled.');
        $this->db->exec('UPDATE purchase_orders SET status="cancelled" WHERE id=?', [$purchaseOrderId]);
    }

    public function receive(int $purchaseOrderId, array $receipts, ?string $notes, int $userId): int
    {
        return $this->db->transaction(function(Database $db) use ($purchaseOrderId,$receipts,$notes,$userId) {
            $po = $db->one('SELECT * FROM purchase_orders WHERE id=? FOR UPDATE', [$purchaseOrderId]);
            if (!$po || !in_array($po['status'], ['submitted','partial'], true)) {
                throw new RuntimeException('Only submitted or partially received purchase orders can be received.');
            }

            $sessionId = $db->insert(
                'INSERT INTO receiving_sessions (purchase_order_id,received_by,notes) VALUES (?,?,?)',
                [$purchaseOrderId,$userId,$notes ?: null]
            );

            $receivedAnything = false;
            foreach ($receipts as $purchaseOrderItemId => $receipt) {
                $packages = (float)($receipt['packages'] ?? 0);
                if ($packages <= 0) continue;

                $item = $db->one(
                    'SELECT * FROM purchase_order_items WHERE id=? AND purchase_order_id=? FOR UPDATE',
                    [(int)$purchaseOrderItemId,$purchaseOrderId]
                );
                if (!$item) throw new RuntimeException('A purchase order line was not found.');

                $remainingPackages = max(0, (float)$item['ordered_packages'] - (float)$item['received_packages']);
                if ($packages - $remainingPackages > 0.0001) {
                    throw new RuntimeException('Received quantity cannot exceed the remaining ordered quantity for ' . $item['description'] . '.');
                }

                $receivedQty = $packages * (float)$item['package_quantity'];
                $unitCost = (float)$item['package_quantity'] > 0
                    ? (float)$item['package_price'] / (float)$item['package_quantity']
                    : 0;

                $lotId = null;
                $lotNumber = trim((string)($receipt['lot_number'] ?? ''));
                $expiresAt = trim((string)($receipt['expires_at'] ?? ''));

                if ($item['item_type'] === 'ingredient') {
                    if ($lotNumber === '') $lotNumber = 'RCV-' . $sessionId . '-' . $item['id'];
                    $lotId = $db->insert(
                        'INSERT INTO ingredient_lots
                         (ingredient_id,supplier_item_id,lot_number,received_at,expires_at,unit_cost,notes)
                         VALUES (?,?,?,NOW(),?,?,?)',
                        [
                            $item['item_id'],$item['supplier_item_id'],$lotNumber,
                            $expiresAt !== '' ? $expiresAt : null,$unitCost,
                            'Received on ' . $po['po_number']
                        ]
                    );
                }

                $receivingItemId = $db->insert(
                    'INSERT INTO receiving_items
                     (receiving_session_id,purchase_order_item_id,received_packages,received_quantity,lot_number,expires_at,unit_cost,ingredient_lot_id)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [
                        $sessionId,$item['id'],$packages,$receivedQty,
                        $lotNumber !== '' ? $lotNumber : null,$expiresAt !== '' ? $expiresAt : null,
                        $unitCost,$lotId
                    ]
                );

                $txId = $db->insert(
                    'INSERT INTO inventory_transactions
                     (item_type,item_id,lot_id,quantity_delta,unit,reason,reference_type,reference_id,notes,created_by,created_at)
                     VALUES (?,?,?,?,?,"Purchase Receipt","receiving_item",?,?,?,NOW())',
                    [
                        $item['item_type'],$item['item_id'],$lotId,$receivedQty,$item['inventory_unit'],
                        $receivingItemId,$po['po_number'],$userId
                    ]
                );

                $db->exec(
                    'UPDATE receiving_items SET inventory_transaction_id=? WHERE id=?',
                    [$txId,$receivingItemId]
                );
                $db->exec(
                    'UPDATE purchase_order_items
                     SET received_packages=received_packages+?, received_quantity=received_quantity+?
                     WHERE id=?',
                    [$packages,$receivedQty,$item['id']]
                );

                $receivedAnything = true;
            }

            if (!$receivedAnything) throw new RuntimeException('Enter at least one quantity to receive.');

            $remaining = (int)$db->scalar(
                'SELECT COUNT(*) FROM purchase_order_items
                 WHERE purchase_order_id=? AND received_packages + 0.0001 < ordered_packages',
                [$purchaseOrderId]
            );
            $db->exec(
                'UPDATE purchase_orders SET status=? WHERE id=?',
                [$remaining === 0 ? 'received' : 'partial',$purchaseOrderId]
            );

            return $sessionId;
        });
    }

    private function recalculateTotals(int $purchaseOrderId, Database $db): void
    {
        $subtotal = (float)$db->scalar(
            'SELECT COALESCE(SUM(line_total),0) FROM purchase_order_items WHERE purchase_order_id=?',
            [$purchaseOrderId]
        );
        $db->exec('UPDATE purchase_orders SET subtotal=?,total=? WHERE id=?', [$subtotal,$subtotal,$purchaseOrderId]);
    }
}

final class InventoryCountService
{
    public function __construct(private Database $db) {}

    public function start(string $scope, ?string $notes, int $userId): int
    {
        if (!in_array($scope, ['all','ingredient','packaging'], true)) throw new RuntimeException('Invalid inventory count scope.');

        return $this->db->transaction(function(Database $db) use ($scope,$notes,$userId) {
            $number = 'COUNT-' . date('Ymd-His') . '-' . random_int(10,99);
            $countId = $db->insert(
                'INSERT INTO inventory_counts (count_number,status,created_by,notes) VALUES (?,"open",?,?)',
                [$number,$userId,$notes ?: null]
            );

            if ($scope === 'all' || $scope === 'ingredient') {
                $ingredients = $db->all(
                    "SELECT i.id,i.inventory_unit,
                            (SELECT COALESCE(SUM(t.quantity_delta),0)
                             FROM inventory_transactions t
                             WHERE t.item_type='ingredient' AND t.item_id=i.id) expected
                     FROM ingredients i WHERE i.is_active=1 ORDER BY i.name"
                );
                foreach ($ingredients as $item) {
                    $db->exec(
                        'INSERT INTO inventory_count_items
                         (inventory_count_id,item_type,item_id,expected_quantity,unit)
                         VALUES (?,"ingredient",?,?,?)',
                        [$countId,$item['id'],$item['expected'],$item['inventory_unit']]
                    );
                }
            }

            if ($scope === 'all' || $scope === 'packaging') {
                $packaging = $db->all(
                    "SELECT p.id,p.inventory_unit,
                            (SELECT COALESCE(SUM(t.quantity_delta),0)
                             FROM inventory_transactions t
                             WHERE t.item_type='packaging' AND t.item_id=p.id) expected
                     FROM packaging_items p WHERE p.is_active=1 ORDER BY p.name"
                );
                foreach ($packaging as $item) {
                    $db->exec(
                        'INSERT INTO inventory_count_items
                         (inventory_count_id,item_type,item_id,expected_quantity,unit)
                         VALUES (?,"packaging",?,?,?)',
                        [$countId,$item['id'],$item['expected'],$item['inventory_unit']]
                    );
                }
            }

            return $countId;
        });
    }

    public function save(int $countId, array $values, int $userId): void
    {
        $count = $this->db->one('SELECT status FROM inventory_counts WHERE id=?', [$countId]);
        if (!$count || $count['status'] !== 'open') throw new RuntimeException('Only open counts can be edited.');

        foreach ($values as $itemId => $value) {
            if ($value === '' || $value === null) continue;
            if (!is_numeric($value)) throw new RuntimeException('Inventory count values must be numeric.');
            $this->db->exec(
                'UPDATE inventory_count_items
                 SET counted_quantity=?,variance_quantity=?-expected_quantity,counted_by=?,counted_at=NOW()
                 WHERE id=? AND inventory_count_id=?',
                [(float)$value,(float)$value,$userId,(int)$itemId,$countId]
            );
        }
    }

    public function complete(int $countId, int $userId): array
    {
        return $this->db->transaction(function(Database $db) use ($countId,$userId) {
            $count = $db->one('SELECT * FROM inventory_counts WHERE id=? FOR UPDATE', [$countId]);
            if (!$count || $count['status'] !== 'open') throw new RuntimeException('Only open counts can be completed.');

            $missing = (int)$db->scalar(
                'SELECT COUNT(*) FROM inventory_count_items WHERE inventory_count_id=? AND counted_quantity IS NULL',
                [$countId]
            );
            if ($missing > 0) throw new RuntimeException('Count every item before completing this inventory count.');

            $items = $db->all(
                'SELECT * FROM inventory_count_items WHERE inventory_count_id=? ORDER BY id',
                [$countId]
            );
            $adjustments = 0;
            foreach ($items as $item) {
                $variance = (float)$item['counted_quantity'] - (float)$item['expected_quantity'];
                $txId = null;
                if (abs($variance) > 0.000001) {
                    $txId = $db->insert(
                        'INSERT INTO inventory_transactions
                         (item_type,item_id,quantity_delta,unit,reason,reference_type,reference_id,notes,created_by,created_at)
                         VALUES (?,?,?,?,?,"inventory_count",?,?,?,NOW())',
                        [
                            $item['item_type'],$item['item_id'],$variance,$item['unit'],
                            'Physical Count Reconciliation',$countId,$count['count_number'],$userId
                        ]
                    );
                    $adjustments++;
                }
                $db->exec(
                    'UPDATE inventory_count_items SET variance_quantity=?,adjustment_transaction_id=? WHERE id=?',
                    [$variance,$txId,$item['id']]
                );
            }

            $db->exec(
                'UPDATE inventory_counts SET status="completed",completed_at=NOW(),completed_by=? WHERE id=?',
                [$userId,$countId]
            );

            return ['adjustments'=>$adjustments,'items'=>count($items)];
        });
    }

    public function cancel(int $countId): void
    {
        $count = $this->db->one('SELECT status FROM inventory_counts WHERE id=?', [$countId]);
        if (!$count || $count['status'] !== 'open') throw new RuntimeException('Only open counts can be cancelled.');
        $this->db->exec('UPDATE inventory_counts SET status="cancelled" WHERE id=?', [$countId]);
    }
}

final class ReorderService
{
    public function __construct(private Database $db) {}

    public function suggestions(): array
    {
        $ingredients = $this->db->all(
            "SELECT 'ingredient' item_type,i.id,i.name,i.inventory_unit unit,i.reorder_point,i.target_stock,
                    (SELECT COALESCE(SUM(t.quantity_delta),0) FROM inventory_transactions t
                     WHERE t.item_type='ingredient' AND t.item_id=i.id) on_hand,
                    (SELECT MIN(si.unit_cost) FROM supplier_items si WHERE si.item_type='ingredient' AND si.item_id=i.id AND si.unit_cost>0) best_unit_cost
             FROM ingredients i
             WHERE i.is_active=1
             HAVING on_hand < reorder_point"
        );
        $packaging = $this->db->all(
            "SELECT 'packaging' item_type,p.id,p.name,p.inventory_unit unit,p.reorder_point,p.target_stock,
                    (SELECT COALESCE(SUM(t.quantity_delta),0) FROM inventory_transactions t
                     WHERE t.item_type='packaging' AND t.item_id=p.id) on_hand,
                    (SELECT MIN(si.unit_cost) FROM supplier_items si WHERE si.item_type='packaging' AND si.item_id=p.id AND si.unit_cost>0) best_unit_cost
             FROM packaging_items p
             WHERE p.is_active=1
             HAVING on_hand < reorder_point"
        );

        $items = array_merge($ingredients,$packaging);
        foreach ($items as &$item) {
            $item['suggested_quantity'] = max(0, (float)$item['target_stock'] - (float)$item['on_hand']);
            $item['estimated_cost'] = $item['best_unit_cost'] !== null
                ? $item['suggested_quantity'] * (float)$item['best_unit_cost']
                : null;
        }
        unset($item);

        usort($items, fn($a,$b) => ($a['on_hand'] - $a['reorder_point']) <=> ($b['on_hand'] - $b['reorder_point']));
        return $items;
    }
}
