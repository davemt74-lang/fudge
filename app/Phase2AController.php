<?php
function phase2a_name(Database $db, string $type, int $id): string
{
    if ($type === 'ingredient') {
        return (string)($db->scalar('SELECT name FROM ingredients WHERE id=?', [$id]) ?: 'Unknown Ingredient');
    }
    return (string)($db->scalar('SELECT name FROM packaging_items WHERE id=?', [$id]) ?: 'Unknown Packaging');
}

function handle_phase2a_page(string $page): never
{
    global $db,$purchasing,$inventoryCounts,$reorders;
    $uid = (int)user()['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Security::validateCsrf();
        $action = $_POST['form_action'] ?? '';
        try {
            switch ($action) {
                case 'create_po':
                    require_permission('purchasing.manage');
                    $id = $purchasing->createPurchaseOrder(
                        (int)($_POST['supplier_id'] ?? 0),
                        trim($_POST['expected_at'] ?? '') ?: null,
                        trim($_POST['notes'] ?? '') ?: null,
                        $uid
                    );
                    audit('purchasing.po_created','purchase_order',$id,null,null);
                    flash('success','Purchase order created.');
                    redirect('?page=purchasing&po='.$id);

                case 'add_po_item':
                    require_permission('purchasing.manage');
                    $poId=(int)($_POST['purchase_order_id']??0);
                    $itemId=$purchasing->addItem($poId,(int)($_POST['supplier_item_id']??0),(float)($_POST['ordered_packages']??0));
                    audit('purchasing.po_item_added','purchase_order_item',$itemId,null,['purchase_order_id'=>$poId]);
                    flash('success','Purchase order item added.');
                    redirect('?page=purchasing&po='.$poId);

                case 'remove_po_item':
                    require_permission('purchasing.manage');
                    $poId=(int)($_POST['purchase_order_id']??0);
                    $itemId=(int)($_POST['purchase_order_item_id']??0);
                    $purchasing->removeItem($itemId);
                    audit('purchasing.po_item_removed','purchase_order_item',$itemId,null,['purchase_order_id'=>$poId]);
                    flash('success','Purchase order item removed.');
                    redirect('?page=purchasing&po='.$poId);

                case 'submit_po':
                    require_permission('purchasing.submit');
                    $poId=(int)($_POST['purchase_order_id']??0);
                    $purchasing->submit($poId,$uid);
                    audit('purchasing.po_submitted','purchase_order',$poId,null,null);
                    flash('success','Purchase order submitted.');
                    redirect('?page=purchasing&po='.$poId);

                case 'cancel_po':
                    require_permission('purchasing.cancel');
                    $poId=(int)($_POST['purchase_order_id']??0);
                    $purchasing->cancel($poId);
                    audit('purchasing.po_cancelled','purchase_order',$poId,null,null);
                    flash('success','Purchase order cancelled.');
                    redirect('?page=purchasing&po='.$poId);

                case 'receive_po':
                    require_permission('purchasing.receive');
                    $poId=(int)($_POST['purchase_order_id']??0);
                    $receipts=[];
                    foreach (($_POST['receive']??[]) as $lineId=>$row) {
                        $receipts[(int)$lineId]=[
                            'packages'=>$row['packages']??0,
                            'lot_number'=>$row['lot_number']??'',
                            'expires_at'=>$row['expires_at']??'',
                        ];
                    }
                    $sessionId=$purchasing->receive($poId,$receipts,trim($_POST['notes']??'')?:null,$uid);
                    audit('purchasing.received','receiving_session',$sessionId,null,['purchase_order_id'=>$poId]);
                    flash('success','Receipt posted to inventory.');
                    redirect('?page=purchasing&po='.$poId);

                case 'start_inventory_count':
                    require_permission('inventory.count');
                    $countId=$inventoryCounts->start($_POST['scope']??'all',trim($_POST['notes']??'')?:null,$uid);
                    audit('inventory.count_started','inventory_count',$countId,null,['scope'=>$_POST['scope']??'all']);
                    flash('success','Inventory count started.');
                    redirect('?page=inventory-counts&count='.$countId);

                case 'save_inventory_count':
                    require_permission('inventory.count');
                    $countId=(int)($_POST['inventory_count_id']??0);
                    $inventoryCounts->save($countId,$_POST['counted']??[],$uid);
                    audit('inventory.count_saved','inventory_count',$countId,null,null);
                    flash('success','Count progress saved.');
                    redirect('?page=inventory-counts&count='.$countId);

                case 'complete_inventory_count':
                    require_permission('inventory.count');
                    $countId=(int)($_POST['inventory_count_id']??0);
                    $result=$inventoryCounts->complete($countId,$uid);
                    audit('inventory.count_completed','inventory_count',$countId,null,$result);
                    flash('success','Inventory count completed with '.$result['adjustments'].' reconciliation adjustment(s).');
                    redirect('?page=inventory-counts&count='.$countId);

                case 'cancel_inventory_count':
                    require_permission('inventory.count');
                    $countId=(int)($_POST['inventory_count_id']??0);
                    $inventoryCounts->cancel($countId);
                    audit('inventory.count_cancelled','inventory_count',$countId,null,null);
                    flash('success','Inventory count cancelled.');
                    redirect('?page=inventory-counts');
            }
        } catch (Throwable $e) {
            flash('danger',$e->getMessage());
            redirect('?page='.urlencode($page).(isset($_GET['po'])?'&po='.(int)$_GET['po']:'').(isset($_GET['count'])?'&count='.(int)$_GET['count']:''));
        }
    }

    if ($page === 'purchasing') {
        require_permission('purchasing.view');
        Ui::layoutStart('Purchasing','purchasing');
        Ui::pageHead(
            'Purchasing & Receiving',
            'Purchase orders, receiving, supplier costs and reorder recommendations.',
            '<div class="actions"><a class="btn" href="?page=lots">Lots & Expiration</a><a class="btn" href="?page=inventory-counts">Inventory Counts</a></div>'
        );

        $suggestions=$reorders->suggestions();
        if ($suggestions) {
            echo '<div class="card" style="margin-bottom:18px"><div class="table-head"><h2>Reorder Suggestions</h2><span class="badge warn">'.count($suggestions).' items below reorder point</span></div><table><thead><tr><th>Item</th><th>On Hand</th><th>Target</th><th>Need</th><th>Recommended Purchase</th><th>Estimated Cost</th></tr></thead><tbody>';
            foreach($suggestions as $s) {
                $purchase = $s['packages_to_buy'] === null
                    ? 'No supplier package configured'
                    : ((int)$s['packages_to_buy'].' package'.((int)$s['packages_to_buy']===1?'':'s').' · '.number_format((float)$s['purchase_quantity'],2).' '.h($s['unit']).'<div class="muted">'.h($s['recommended_supplier']).'</div>');
                echo '<tr><td><strong>'.h($s['name']).'</strong><div class="muted">'.h(ucfirst($s['item_type'])).'</div></td><td>'.number_format((float)$s['on_hand'],2).' '.h($s['unit']).'</td><td>'.number_format((float)$s['target_stock'],2).' '.h($s['unit']).'</td><td><strong>'.number_format((float)$s['suggested_quantity'],2).' '.h($s['unit']).'</strong></td><td>'.$purchase.'</td><td>'.($s['estimated_cost']===null?'—':'
            echo '</tbody></table></div>';
        }

        if (can('purchasing.manage')) {
            $suppliers=$db->all('SELECT id,name FROM suppliers WHERE is_active=1 ORDER BY name');
            echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">New Purchase Order</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="create_po"><label>Supplier<select name="supplier_id" required><option value="">Select supplier</option>';
            foreach($suppliers as $s) echo '<option value="'.$s['id'].'">'.h($s['name']).'</option>';
            echo '</select></label><label>Expected Date<input type="date" name="expected_at"></label><label class="span-2">Notes<textarea name="notes"></textarea></label><button class="btn primary">Create Purchase Order</button></form></div>';
        }

        $selectedPo=(int)($_GET['po']??0);
        if ($selectedPo) {
            $po=$db->one('SELECT po.*,s.name supplier,u.name creator FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id LEFT JOIN users u ON u.id=po.created_by WHERE po.id=?',[$selectedPo]);
            if ($po) {
                $lines=$db->all('SELECT * FROM purchase_order_items WHERE purchase_order_id=? ORDER BY id',[$selectedPo]);
                echo '<div class="card" style="margin-bottom:18px"><div class="page-head"><div><div class="kicker">Purchase Order</div><h1>'.h($po['po_number']).'</h1><div class="muted">'.h($po['supplier']).' · Created by '.h($po['creator']).'</div></div><div>'.Ui::statusBadge($po['status']).'</div></div>';
                echo '<div class="grid cols-4"><div class="metric"><div class="label">Total</div><div class="value">$'.number_format((float)$po['total'],2).'</div></div><div class="metric"><div class="label">Expected</div><div class="value" style="font-size:20px">'.h($po['expected_at']?:'—').'</div></div><div class="metric"><div class="label">Ordered</div><div class="value" style="font-size:20px">'.h($po['ordered_at']?:'Draft').'</div></div><div class="metric"><div class="label">Lines</div><div class="value">'.count($lines).'</div></div></div></div>';

                if ($po['status']==='draft' && can('purchasing.manage')) {
                    $supplierItems=$db->all("SELECT si.*,CASE WHEN si.item_type='ingredient' THEN (SELECT name FROM ingredients WHERE id=si.item_id) ELSE (SELECT name FROM packaging_items WHERE id=si.item_id) END item_name FROM supplier_items si WHERE si.supplier_id=? ORDER BY item_name",[$po['supplier_id']]);
                    echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Add Item</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="add_po_item"><input type="hidden" name="purchase_order_id" value="'.$po['id'].'"><label class="span-2">Supplier Item<select name="supplier_item_id" required><option value="">Select item</option>';
                    foreach($supplierItems as $si) echo '<option value="'.$si['id'].'">'.h($si['item_name']).' — '.h($si['package_quantity'].' '.$si['package_unit']).' — $'.number_format((float)$si['package_price'],2).'</option>';
                    echo '</select></label><label>Packages<input type="number" min="0.0001" step="0.0001" name="ordered_packages" value="1" required></label><div><button class="btn primary" style="margin-top:27px">Add Line</button></div></form></div>';
                }

                echo '<div class="table-card"><div class="table-head"><h2>Purchase Order Lines</h2></div><table><thead><tr><th>Item</th><th>Ordered</th><th>Received</th><th>Price</th><th>Line Total</th><th></th></tr></thead><tbody>';
                foreach($lines as $line) {
                    echo '<tr><td><strong>'.h($line['description']).'</strong><div class="muted">'.h($line['package_quantity'].' '.$line['package_unit'].' / package').'</div></td><td>'.number_format((float)$line['ordered_packages'],2).' pkg</td><td>'.number_format((float)$line['received_packages'],2).' pkg<div class="muted">'.number_format((float)$line['received_quantity'],2).' '.h($line['inventory_unit']).'</div></td><td>$'.number_format((float)$line['package_price'],2).'</td><td>$'.number_format((float)$line['line_total'],2).'</td><td>';
                    if($po['status']==='draft'&&can('purchasing.manage')) echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="remove_po_item"><input type="hidden" name="purchase_order_id" value="'.$po['id'].'"><input type="hidden" name="purchase_order_item_id" value="'.$line['id'].'"><button class="btn small danger" data-confirm="Remove this PO line?">Remove</button></form>';
                    echo '</td></tr>';
                }
                if(!$lines) echo '<tr><td colspan="6" class="empty">Add supplier items to this purchase order.</td></tr>';
                echo '</tbody></table></div>';

                if ($po['status']==='draft' && can('purchasing.submit')) {
                    echo '<form method="post" style="margin-top:14px">'.Ui::csrf().'<input type="hidden" name="form_action" value="submit_po"><input type="hidden" name="purchase_order_id" value="'.$po['id'].'"><button class="btn primary">Submit Purchase Order</button></form>';
                }

                if (in_array($po['status'],['submitted','partial'],true) && can('purchasing.receive')) {
                    echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Receive Shipment</h2><form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="receive_po"><input type="hidden" name="purchase_order_id" value="'.$po['id'].'"><div class="table-card" style="box-shadow:none"><table><thead><tr><th>Item</th><th>Remaining</th><th>Receive Packages</th><th>Lot #</th><th>Expires</th></tr></thead><tbody>';
                    foreach($lines as $line) {
                        $remaining=max(0,(float)$line['ordered_packages']-(float)$line['received_packages']);
                        if($remaining<=0) continue;
                        echo '<tr><td><strong>'.h($line['description']).'</strong><div class="muted">'.h(ucfirst($line['item_type'])).'</div></td><td>'.number_format($remaining,2).' pkg</td><td><input style="width:110px" type="number" min="0" max="'.h($remaining).'" step="0.0001" name="receive['.$line['id'].'][packages]" value="0"></td><td><input name="receive['.$line['id'].'][lot_number]" '.($line['item_type']==='packaging'?'disabled':'').'></td><td><input type="date" name="receive['.$line['id'].'][expires_at]" '.($line['item_type']==='packaging'?'disabled':'').'></td></tr>';
                    }
                    echo '</tbody></table></div><label style="display:block;margin-top:12px">Receiving Notes<textarea name="notes" style="display:block;width:100%;margin-top:6px"></textarea></label><button class="btn primary" style="margin-top:12px">Post Receipt</button></form></div>';
                }

                if (!in_array($po['status'],['received','cancelled'],true) && can('purchasing.cancel')) {
                    echo '<form method="post" style="margin-top:14px">'.Ui::csrf().'<input type="hidden" name="form_action" value="cancel_po"><input type="hidden" name="purchase_order_id" value="'.$po['id'].'"><button class="btn danger" data-confirm="Cancel this purchase order?">Cancel Purchase Order</button></form>';
                }
            }
        }

        $rows=$db->all('SELECT po.*,s.name supplier,(SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id=po.id) line_count FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id ORDER BY po.id DESC LIMIT 100');
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Purchase Orders</h2></div><table><thead><tr><th>PO</th><th>Supplier</th><th>Status</th><th>Lines</th><th>Total</th><th>Expected</th><th></th></tr></thead><tbody>';
        foreach($rows as $r) echo '<tr><td><strong>'.h($r['po_number']).'</strong></td><td>'.h($r['supplier']).'</td><td>'.Ui::statusBadge($r['status']).'</td><td>'.h($r['line_count']).'</td><td>$'.number_format((float)$r['total'],2).'</td><td>'.h($r['expected_at']).'</td><td><a class="btn small" href="?page=purchasing&po='.$r['id'].'">Open</a></td></tr>';
        if(!$rows) echo '<tr><td colspan="7" class="empty">No purchase orders yet.</td></tr>';
        echo '</tbody></table></div>';

        Ui::layoutEnd();
        exit;
    }

    if ($page === 'inventory-counts') {
        require_permission('inventory.count');
        Ui::layoutStart('Inventory Counts','inventory');
        Ui::pageHead('Physical Inventory Counts','Snapshot expected stock, count actual stock, then post auditable reconciliation transactions.','<a class="btn" href="?page=inventory">Back to Inventory</a>');
        echo '<div class="alert info">Pause receiving, production consumption, waste entries, and manual stock adjustments while a physical count is open. If inventory changes after the count begins, completion is blocked and a fresh count is required.</div>';

        echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Start New Count</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="start_inventory_count"><label>Scope<select name="scope"><option value="all">Ingredients + Packaging</option><option value="ingredient">Ingredients Only</option><option value="packaging">Packaging Only</option></select></label><label>Notes<input name="notes" placeholder="Monthly count, opening count, etc."></label><button class="btn primary">Start Count</button></form></div>';

        $countId=(int)($_GET['count']??0);
        if($countId){
            $count=$db->one('SELECT ic.*,u.name creator,cu.name completed_user FROM inventory_counts ic LEFT JOIN users u ON u.id=ic.created_by LEFT JOIN users cu ON cu.id=ic.completed_by WHERE ic.id=?',[$countId]);
            if($count){
                $items=$db->all("SELECT ici.*,CASE WHEN ici.item_type='ingredient' THEN (SELECT name FROM ingredients WHERE id=ici.item_id) ELSE (SELECT name FROM packaging_items WHERE id=ici.item_id) END item_name FROM inventory_count_items ici WHERE ici.inventory_count_id=? ORDER BY ici.item_type,item_name",[$countId]);
                echo '<div class="card" style="margin-bottom:18px"><div class="page-head"><div><div class="kicker">Inventory Count</div><h1>'.h($count['count_number']).'</h1><div class="muted">Started by '.h($count['creator']).' · '.h($count['started_at']).'</div></div>'.Ui::statusBadge($count['status']).'</div></div>';
                echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_inventory_count"><input type="hidden" name="inventory_count_id" value="'.$countId.'"><div class="table-card"><table><thead><tr><th>Item</th><th>Expected</th><th>Counted</th><th>Variance</th></tr></thead><tbody>';
                foreach($items as $item){
                    $variance=$item['counted_quantity']===null?null:(float)$item['counted_quantity']-(float)$item['expected_quantity'];
                    echo '<tr><td><strong>'.h($item['item_name']).'</strong><div class="muted">'.h(ucfirst($item['item_type'])).'</div></td><td>'.number_format((float)$item['expected_quantity'],3).' '.h($item['unit']).'</td><td>';
                    if($count['status']==='open') echo '<input type="number" step="0.001" name="counted['.$item['id'].']" value="'.h($item['counted_quantity']??'').'" style="width:130px">';
                    else echo number_format((float)$item['counted_quantity'],3).' '.h($item['unit']);
                    echo '</td><td>'.($variance===null?'—':(($variance>0?'+':'').number_format($variance,3).' '.h($item['unit']))).'</td></tr>';
                }
                echo '</tbody></table></div>';
                if($count['status']==='open'){
                    echo '<div class="actions" style="margin-top:14px"><button class="btn" type="submit">Save Progress</button></form><form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="complete_inventory_count"><input type="hidden" name="inventory_count_id" value="'.$countId.'"><button class="btn primary" data-confirm="Complete count and post all variances to inventory?">Complete & Reconcile</button></form><form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="cancel_inventory_count"><input type="hidden" name="inventory_count_id" value="'.$countId.'"><button class="btn danger" data-confirm="Cancel this count?">Cancel Count</button></form></div>';
                } else echo '</form>';
            }
        }

        $counts=$db->all('SELECT ic.*,u.name creator FROM inventory_counts ic LEFT JOIN users u ON u.id=ic.created_by ORDER BY ic.id DESC LIMIT 50');
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Count History</h2></div><table><thead><tr><th>Count</th><th>Status</th><th>Started</th><th>Completed</th><th>Created By</th><th></th></tr></thead><tbody>';
        foreach($counts as $c) echo '<tr><td><strong>'.h($c['count_number']).'</strong></td><td>'.Ui::statusBadge($c['status']).'</td><td>'.h($c['started_at']).'</td><td>'.h($c['completed_at']).'</td><td>'.h($c['creator']).'</td><td><a class="btn small" href="?page=inventory-counts&count='.$c['id'].'">Open</a></td></tr>';
        if(!$counts) echo '<tr><td colspan="6" class="empty">No counts yet.</td></tr>';
        echo '</tbody></table></div>';

        Ui::layoutEnd();
        exit;
    }

    if ($page === 'lots') {
        require_permission('lots.view');
        Ui::layoutStart('Lots & Expiration','inventory');
        Ui::pageHead('Ingredient Lots & Expiration','Trace received ingredients back to supplier purchase orders and expiration dates.','<a class="btn" href="?page=purchasing">Back to Purchasing</a>');

        $rows=$db->all(
            "SELECT l.*,i.name ingredient,s.name supplier,
                    (SELECT COALESCE(SUM(t.quantity_delta),0) FROM inventory_transactions t WHERE t.lot_id=l.id) on_hand,
                    DATEDIFF(l.expires_at,CURDATE()) days_to_expiry
             FROM ingredient_lots l
             JOIN ingredients i ON i.id=l.ingredient_id
             LEFT JOIN supplier_items si ON si.id=l.supplier_item_id
             LEFT JOIN suppliers s ON s.id=si.supplier_id
             ORDER BY CASE WHEN l.expires_at IS NULL THEN 1 ELSE 0 END,l.expires_at,l.id DESC"
        );
        echo '<div class="table-card"><table><thead><tr><th>Ingredient</th><th>Lot</th><th>Supplier</th><th>Received</th><th>Expires</th><th>On Hand</th><th>Status</th></tr></thead><tbody>';
        foreach($rows as $r){
            $status='No Expiration';
            $class='';
            if($r['expires_at']){
                if((int)$r['days_to_expiry']<0){$status='Expired';$class='bad';}
                elseif((int)$r['days_to_expiry']<=7){$status='Use Soon';$class='warn';}
                else{$status='Fresh';$class='good';}
            }
            echo '<tr><td><strong>'.h($r['ingredient']).'</strong></td><td>'.h($r['lot_number']).'</td><td>'.h($r['supplier']).'</td><td>'.h($r['received_at']).'</td><td>'.h($r['expires_at']?:'—').'</td><td>'.number_format((float)$r['on_hand'],3).'</td><td><span class="badge '.$class.'">'.h($status).'</span></td></tr>';
        }
        if(!$rows) echo '<tr><td colspan="7" class="empty">Lots appear automatically when ingredient purchase orders are received.</td></tr>';
        echo '</tbody></table></div>';

        Ui::layoutEnd();
        exit;
    }

    http_response_code(404);
    exit('Page not found.');
}
.number_format((float)$s['estimated_cost'],2)).'</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        if (can('purchasing.manage')) {
            $suppliers=$db->all('SELECT id,name FROM suppliers WHERE is_active=1 ORDER BY name');
            echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">New Purchase Order</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="create_po"><label>Supplier<select name="supplier_id" required><option value="">Select supplier</option>';
            foreach($suppliers as $s) echo '<option value="'.$s['id'].'">'.h($s['name']).'</option>';
            echo '</select></label><label>Expected Date<input type="date" name="expected_at"></label><label class="span-2">Notes<textarea name="notes"></textarea></label><button class="btn primary">Create Purchase Order</button></form></div>';
        }

        $selectedPo=(int)($_GET['po']??0);
        if ($selectedPo) {
            $po=$db->one('SELECT po.*,s.name supplier,u.name creator FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id LEFT JOIN users u ON u.id=po.created_by WHERE po.id=?',[$selectedPo]);
            if ($po) {
                $lines=$db->all('SELECT * FROM purchase_order_items WHERE purchase_order_id=? ORDER BY id',[$selectedPo]);
                echo '<div class="card" style="margin-bottom:18px"><div class="page-head"><div><div class="kicker">Purchase Order</div><h1>'.h($po['po_number']).'</h1><div class="muted">'.h($po['supplier']).' · Created by '.h($po['creator']).'</div></div><div>'.Ui::statusBadge($po['status']).'</div></div>';
                echo '<div class="grid cols-4"><div class="metric"><div class="label">Total</div><div class="value">$'.number_format((float)$po['total'],2).'</div></div><div class="metric"><div class="label">Expected</div><div class="value" style="font-size:20px">'.h($po['expected_at']?:'—').'</div></div><div class="metric"><div class="label">Ordered</div><div class="value" style="font-size:20px">'.h($po['ordered_at']?:'Draft').'</div></div><div class="metric"><div class="label">Lines</div><div class="value">'.count($lines).'</div></div></div></div>';

                if ($po['status']==='draft' && can('purchasing.manage')) {
                    $supplierItems=$db->all("SELECT si.*,CASE WHEN si.item_type='ingredient' THEN (SELECT name FROM ingredients WHERE id=si.item_id) ELSE (SELECT name FROM packaging_items WHERE id=si.item_id) END item_name FROM supplier_items si WHERE si.supplier_id=? ORDER BY item_name",[$po['supplier_id']]);
                    echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Add Item</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="add_po_item"><input type="hidden" name="purchase_order_id" value="'.$po['id'].'"><label class="span-2">Supplier Item<select name="supplier_item_id" required><option value="">Select item</option>';
                    foreach($supplierItems as $si) echo '<option value="'.$si['id'].'">'.h($si['item_name']).' — '.h($si['package_quantity'].' '.$si['package_unit']).' — $'.number_format((float)$si['package_price'],2).'</option>';
                    echo '</select></label><label>Packages<input type="number" min="0.0001" step="0.0001" name="ordered_packages" value="1" required></label><div><button class="btn primary" style="margin-top:27px">Add Line</button></div></form></div>';
                }

                echo '<div class="table-card"><div class="table-head"><h2>Purchase Order Lines</h2></div><table><thead><tr><th>Item</th><th>Ordered</th><th>Received</th><th>Price</th><th>Line Total</th><th></th></tr></thead><tbody>';
                foreach($lines as $line) {
                    echo '<tr><td><strong>'.h($line['description']).'</strong><div class="muted">'.h($line['package_quantity'].' '.$line['package_unit'].' / package').'</div></td><td>'.number_format((float)$line['ordered_packages'],2).' pkg</td><td>'.number_format((float)$line['received_packages'],2).' pkg<div class="muted">'.number_format((float)$line['received_quantity'],2).' '.h($line['inventory_unit']).'</div></td><td>$'.number_format((float)$line['package_price'],2).'</td><td>$'.number_format((float)$line['line_total'],2).'</td><td>';
                    if($po['status']==='draft'&&can('purchasing.manage')) echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="remove_po_item"><input type="hidden" name="purchase_order_id" value="'.$po['id'].'"><input type="hidden" name="purchase_order_item_id" value="'.$line['id'].'"><button class="btn small danger" data-confirm="Remove this PO line?">Remove</button></form>';
                    echo '</td></tr>';
                }
                if(!$lines) echo '<tr><td colspan="6" class="empty">Add supplier items to this purchase order.</td></tr>';
                echo '</tbody></table></div>';

                if ($po['status']==='draft' && can('purchasing.submit')) {
                    echo '<form method="post" style="margin-top:14px">'.Ui::csrf().'<input type="hidden" name="form_action" value="submit_po"><input type="hidden" name="purchase_order_id" value="'.$po['id'].'"><button class="btn primary">Submit Purchase Order</button></form>';
                }

                if (in_array($po['status'],['submitted','partial'],true) && can('purchasing.receive')) {
                    echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Receive Shipment</h2><form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="receive_po"><input type="hidden" name="purchase_order_id" value="'.$po['id'].'"><div class="table-card" style="box-shadow:none"><table><thead><tr><th>Item</th><th>Remaining</th><th>Receive Packages</th><th>Lot #</th><th>Expires</th></tr></thead><tbody>';
                    foreach($lines as $line) {
                        $remaining=max(0,(float)$line['ordered_packages']-(float)$line['received_packages']);
                        if($remaining<=0) continue;
                        echo '<tr><td><strong>'.h($line['description']).'</strong><div class="muted">'.h(ucfirst($line['item_type'])).'</div></td><td>'.number_format($remaining,2).' pkg</td><td><input style="width:110px" type="number" min="0" max="'.h($remaining).'" step="0.0001" name="receive['.$line['id'].'][packages]" value="0"></td><td><input name="receive['.$line['id'].'][lot_number]" '.($line['item_type']==='packaging'?'disabled':'').'></td><td><input type="date" name="receive['.$line['id'].'][expires_at]" '.($line['item_type']==='packaging'?'disabled':'').'></td></tr>';
                    }
                    echo '</tbody></table></div><label style="display:block;margin-top:12px">Receiving Notes<textarea name="notes" style="display:block;width:100%;margin-top:6px"></textarea></label><button class="btn primary" style="margin-top:12px">Post Receipt</button></form></div>';
                }

                if (!in_array($po['status'],['received','cancelled'],true) && can('purchasing.cancel')) {
                    echo '<form method="post" style="margin-top:14px">'.Ui::csrf().'<input type="hidden" name="form_action" value="cancel_po"><input type="hidden" name="purchase_order_id" value="'.$po['id'].'"><button class="btn danger" data-confirm="Cancel this purchase order?">Cancel Purchase Order</button></form>';
                }
            }
        }

        $rows=$db->all('SELECT po.*,s.name supplier,(SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id=po.id) line_count FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id ORDER BY po.id DESC LIMIT 100');
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Purchase Orders</h2></div><table><thead><tr><th>PO</th><th>Supplier</th><th>Status</th><th>Lines</th><th>Total</th><th>Expected</th><th></th></tr></thead><tbody>';
        foreach($rows as $r) echo '<tr><td><strong>'.h($r['po_number']).'</strong></td><td>'.h($r['supplier']).'</td><td>'.Ui::statusBadge($r['status']).'</td><td>'.h($r['line_count']).'</td><td>$'.number_format((float)$r['total'],2).'</td><td>'.h($r['expected_at']).'</td><td><a class="btn small" href="?page=purchasing&po='.$r['id'].'">Open</a></td></tr>';
        if(!$rows) echo '<tr><td colspan="7" class="empty">No purchase orders yet.</td></tr>';
        echo '</tbody></table></div>';

        Ui::layoutEnd();
        exit;
    }

    if ($page === 'inventory-counts') {
        require_permission('inventory.count');
        Ui::layoutStart('Inventory Counts','inventory');
        Ui::pageHead('Physical Inventory Counts','Snapshot expected stock, count actual stock, then post auditable reconciliation transactions.','<a class="btn" href="?page=inventory">Back to Inventory</a>');

        echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Start New Count</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="start_inventory_count"><label>Scope<select name="scope"><option value="all">Ingredients + Packaging</option><option value="ingredient">Ingredients Only</option><option value="packaging">Packaging Only</option></select></label><label>Notes<input name="notes" placeholder="Monthly count, opening count, etc."></label><button class="btn primary">Start Count</button></form></div>';

        $countId=(int)($_GET['count']??0);
        if($countId){
            $count=$db->one('SELECT ic.*,u.name creator,cu.name completed_user FROM inventory_counts ic LEFT JOIN users u ON u.id=ic.created_by LEFT JOIN users cu ON cu.id=ic.completed_by WHERE ic.id=?',[$countId]);
            if($count){
                $items=$db->all("SELECT ici.*,CASE WHEN ici.item_type='ingredient' THEN (SELECT name FROM ingredients WHERE id=ici.item_id) ELSE (SELECT name FROM packaging_items WHERE id=ici.item_id) END item_name FROM inventory_count_items ici WHERE ici.inventory_count_id=? ORDER BY ici.item_type,item_name",[$countId]);
                echo '<div class="card" style="margin-bottom:18px"><div class="page-head"><div><div class="kicker">Inventory Count</div><h1>'.h($count['count_number']).'</h1><div class="muted">Started by '.h($count['creator']).' · '.h($count['started_at']).'</div></div>'.Ui::statusBadge($count['status']).'</div></div>';
                echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_inventory_count"><input type="hidden" name="inventory_count_id" value="'.$countId.'"><div class="table-card"><table><thead><tr><th>Item</th><th>Expected</th><th>Counted</th><th>Variance</th></tr></thead><tbody>';
                foreach($items as $item){
                    $variance=$item['counted_quantity']===null?null:(float)$item['counted_quantity']-(float)$item['expected_quantity'];
                    echo '<tr><td><strong>'.h($item['item_name']).'</strong><div class="muted">'.h(ucfirst($item['item_type'])).'</div></td><td>'.number_format((float)$item['expected_quantity'],3).' '.h($item['unit']).'</td><td>';
                    if($count['status']==='open') echo '<input type="number" step="0.001" name="counted['.$item['id'].']" value="'.h($item['counted_quantity']??'').'" style="width:130px">';
                    else echo number_format((float)$item['counted_quantity'],3).' '.h($item['unit']);
                    echo '</td><td>'.($variance===null?'—':(($variance>0?'+':'').number_format($variance,3).' '.h($item['unit']))).'</td></tr>';
                }
                echo '</tbody></table></div>';
                if($count['status']==='open'){
                    echo '<div class="actions" style="margin-top:14px"><button class="btn" type="submit">Save Progress</button></form><form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="complete_inventory_count"><input type="hidden" name="inventory_count_id" value="'.$countId.'"><button class="btn primary" data-confirm="Complete count and post all variances to inventory?">Complete & Reconcile</button></form><form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="cancel_inventory_count"><input type="hidden" name="inventory_count_id" value="'.$countId.'"><button class="btn danger" data-confirm="Cancel this count?">Cancel Count</button></form></div>';
                } else echo '</form>';
            }
        }

        $counts=$db->all('SELECT ic.*,u.name creator FROM inventory_counts ic LEFT JOIN users u ON u.id=ic.created_by ORDER BY ic.id DESC LIMIT 50');
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Count History</h2></div><table><thead><tr><th>Count</th><th>Status</th><th>Started</th><th>Completed</th><th>Created By</th><th></th></tr></thead><tbody>';
        foreach($counts as $c) echo '<tr><td><strong>'.h($c['count_number']).'</strong></td><td>'.Ui::statusBadge($c['status']).'</td><td>'.h($c['started_at']).'</td><td>'.h($c['completed_at']).'</td><td>'.h($c['creator']).'</td><td><a class="btn small" href="?page=inventory-counts&count='.$c['id'].'">Open</a></td></tr>';
        if(!$counts) echo '<tr><td colspan="6" class="empty">No counts yet.</td></tr>';
        echo '</tbody></table></div>';

        Ui::layoutEnd();
        exit;
    }

    if ($page === 'lots') {
        require_permission('lots.view');
        Ui::layoutStart('Lots & Expiration','inventory');
        Ui::pageHead('Ingredient Lots & Expiration','Trace received ingredients back to supplier purchase orders and expiration dates.','<a class="btn" href="?page=purchasing">Back to Purchasing</a>');

        $rows=$db->all(
            "SELECT l.*,i.name ingredient,s.name supplier,
                    (SELECT COALESCE(SUM(t.quantity_delta),0) FROM inventory_transactions t WHERE t.lot_id=l.id) on_hand,
                    DATEDIFF(l.expires_at,CURDATE()) days_to_expiry
             FROM ingredient_lots l
             JOIN ingredients i ON i.id=l.ingredient_id
             LEFT JOIN supplier_items si ON si.id=l.supplier_item_id
             LEFT JOIN suppliers s ON s.id=si.supplier_id
             ORDER BY CASE WHEN l.expires_at IS NULL THEN 1 ELSE 0 END,l.expires_at,l.id DESC"
        );
        echo '<div class="table-card"><table><thead><tr><th>Ingredient</th><th>Lot</th><th>Supplier</th><th>Received</th><th>Expires</th><th>On Hand</th><th>Status</th></tr></thead><tbody>';
        foreach($rows as $r){
            $status='No Expiration';
            $class='';
            if($r['expires_at']){
                if((int)$r['days_to_expiry']<0){$status='Expired';$class='bad';}
                elseif((int)$r['days_to_expiry']<=7){$status='Use Soon';$class='warn';}
                else{$status='Fresh';$class='good';}
            }
            echo '<tr><td><strong>'.h($r['ingredient']).'</strong></td><td>'.h($r['lot_number']).'</td><td>'.h($r['supplier']).'</td><td>'.h($r['received_at']).'</td><td>'.h($r['expires_at']?:'—').'</td><td>'.number_format((float)$r['on_hand'],3).'</td><td><span class="badge '.$class.'">'.h($status).'</span></td></tr>';
        }
        if(!$rows) echo '<tr><td colspan="7" class="empty">Lots appear automatically when ingredient purchase orders are received.</td></tr>';
        echo '</tbody></table></div>';

        Ui::layoutEnd();
        exit;
    }

    http_response_code(404);
    exit('Page not found.');
}
