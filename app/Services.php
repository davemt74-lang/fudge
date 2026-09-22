<?php
final class InventoryService
{
    public function __construct(private Database $db) {}

    public function adjust(string $itemType, int $itemId, float $qty, string $unit, string $reason, ?int $lotId = null, ?int $userId = null): int
    {
        if (!in_array($itemType, ['ingredient','packaging'], true)) throw new InvalidArgumentException('Invalid inventory item type.');
        return $this->db->insert(
            'INSERT INTO inventory_transactions (item_type,item_id,lot_id,quantity_delta,unit,reason,created_by,created_at) VALUES (?,?,?,?,?,?,?,NOW())',
            [$itemType,$itemId,$lotId,$qty,$unit,$reason,$userId]
        );
    }

    public function onHand(string $itemType, int $itemId): float
    {
        return (float)$this->db->scalar('SELECT COALESCE(SUM(quantity_delta),0) FROM inventory_transactions WHERE item_type=? AND item_id=?', [$itemType,$itemId]);
    }
}

final class SupplierPricingService
{
    public function __construct(private Database $db) {}

    public function updatePrice(int $supplierItemId, float $newPrice, ?float $packageQty, ?string $sourceRef, string $notes, int $userId): void
    {
        $this->db->transaction(function(Database $db) use ($supplierItemId,$newPrice,$packageQty,$sourceRef,$notes,$userId) {
            $item = $db->one('SELECT * FROM supplier_items WHERE id=? FOR UPDATE', [$supplierItemId]);
            if (!$item) throw new RuntimeException('Supplier item not found.');
            $qty = $packageQty ?: (float)$item['package_quantity'];
            $unitCost = $qty > 0 ? $newPrice / $qty : 0;
            $db->insert('INSERT INTO supplier_price_history (supplier_item_id,old_price,new_price,package_quantity,unit_cost,source_reference,notes,effective_at,created_by) VALUES (?,?,?,?,?,?,?,NOW(),?)', [
                $supplierItemId,$item['package_price'],$newPrice,$qty,$unitCost,$sourceRef,$notes,$userId
            ]);
            $db->exec('UPDATE supplier_items SET package_price=?, package_quantity=?, unit_cost=?, last_price_update=NOW(), updated_at=NOW() WHERE id=?', [$newPrice,$qty,$unitCost,$supplierItemId]);
        });
    }
}
