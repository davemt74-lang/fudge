<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Ui.php';

$page = $_GET['page'] ?? 'dashboard';
$action = $_GET['action'] ?? '';

if ($action === 'logout') {
    $auth->logout();
    redirect('?page=login');
}

if ($page === 'login') {
    if ($auth->user()) redirect('?page=dashboard');
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Security::validateCsrf();
        if ($auth->attempt($_POST['email'] ?? '', $_POST['password'] ?? '')) redirect('?page=dashboard');
        $error = 'Invalid email or password.';
    }
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in · Fudge Donuts Ops</title><link rel="stylesheet" href="assets/app.css"></head>
    <body class="login-body"><main class="login-card"><div class="brand-mark">FD</div><h1>Fudge Donuts Ops</h1><p class="muted">Sign in to manage production.</p>
    <?php if (!empty($_GET['installed'])): ?><div class="alert success">Installation complete. Sign in with the first user you created.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert danger"><?=h($error)?></div><?php endif; ?>
    <form method="post" class="form-grid"><?=Ui::csrf()?>
    <label class="span-2">Email<input type="email" name="email" required autofocus></label>
    <label class="span-2">Password<input type="password" name="password" required></label>
    <button class="btn primary span-2">Sign In</button></form></main></body></html><?php
    exit;
}

$auth->requireLogin();
$uid = (int)user()['id'];

if (in_array($page, ['purchasing','inventory-counts','lots'], true)) {
    require_once dirname(__DIR__) . '/app/Phase2AController.php';
    handle_phase2a_page($page);
}

if (in_array($page, ['recipes','costing'], true)) {
    require_once dirname(__DIR__) . '/app/Phase2BController.php';
    handle_phase2b_page($page);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::validateCsrf();
    $postAction = $_POST['form_action'] ?? '';
    try {
        switch ($postAction) {
            case 'save_ingredient':
                require_permission('ingredients.manage');
                $id=(int)($_POST['id']??0);
                $data=[trim($_POST['name']??''),trim($_POST['sku']??'')?:null,(int)($_POST['category_id']??0)?:null,trim($_POST['inventory_unit']??'oz'),(float)($_POST['reorder_point']??0),(float)($_POST['target_stock']??0),trim($_POST['storage_location']??'')?:null,(int)($_POST['is_active']??1)];
                if ($data[0]==='') throw new RuntimeException('Ingredient name is required.');
                if ($id) {$before=$db->one('SELECT * FROM ingredients WHERE id=?',[$id]);$db->exec('UPDATE ingredients SET name=?,sku=?,category_id=?,inventory_unit=?,reorder_point=?,target_stock=?,storage_location=?,is_active=? WHERE id=?',[...$data,$id]);audit('ingredient.updated','ingredient',$id,$before,$data);}
                else {$id=$db->insert('INSERT INTO ingredients(name,sku,category_id,inventory_unit,reorder_point,target_stock,storage_location,is_active) VALUES(?,?,?,?,?,?,?,?)',$data);audit('ingredient.created','ingredient',$id,null,$data);}
                flash('success','Ingredient saved.'); redirect('?page=ingredients');
            case 'save_packaging':
                require_permission('packaging.manage');
                $id=(int)($_POST['id']??0);
                $data=[trim($_POST['name']??''),trim($_POST['sku']??'')?:null,trim($_POST['inventory_unit']??'each'),(float)($_POST['current_unit_cost']??0),(float)($_POST['reorder_point']??0),(float)($_POST['target_stock']??0),(int)($_POST['is_active']??1)];
                if ($data[0]==='') throw new RuntimeException('Packaging name is required.');
                if ($id) {$before=$db->one('SELECT * FROM packaging_items WHERE id=?',[$id]);$db->exec('UPDATE packaging_items SET name=?,sku=?,inventory_unit=?,current_unit_cost=?,reorder_point=?,target_stock=?,is_active=? WHERE id=?',[...$data,$id]);audit('packaging.updated','packaging',$id,$before,$data);}
                else {$id=$db->insert('INSERT INTO packaging_items(name,sku,inventory_unit,current_unit_cost,reorder_point,target_stock,is_active) VALUES(?,?,?,?,?,?,?)',$data);audit('packaging.created','packaging',$id,null,$data);}
                flash('success','Packaging item saved.'); redirect('?page=packaging');
            case 'adjust_inventory':
                require_permission('inventory.adjust');
                $type=$_POST['item_type']??'';$id=(int)($_POST['item_id']??0);$qty=(float)($_POST['quantity_delta']??0);$unit=trim($_POST['unit']??'each');$reason=trim($_POST['reason']??'Manual Adjustment');
                if (!$id || $qty==0) throw new RuntimeException('Select an item and enter a non-zero quantity.');
                $inventory->adjust($type,$id,$qty,$unit,$reason,null,$uid);
                audit('inventory.adjusted',$type,$id,null,['delta'=>$qty,'unit'=>$unit,'reason'=>$reason]);
                flash('success','Inventory adjustment recorded.'); redirect('?page=inventory');
            case 'save_supplier':
                require_permission('suppliers.manage');
                $id=(int)($_POST['id']??0);$data=[trim($_POST['name']??''),trim($_POST['website']??'')?:null,trim($_POST['phone']??'')?:null,trim($_POST['email']??'')?:null,trim($_POST['notes']??'')?:null,(int)($_POST['is_active']??1)];
                if ($data[0]==='') throw new RuntimeException('Supplier name is required.');
                if ($id) {$db->exec('UPDATE suppliers SET name=?,website=?,phone=?,email=?,notes=?,is_active=? WHERE id=?',[...$data,$id]);} else {$id=$db->insert('INSERT INTO suppliers(name,website,phone,email,notes,is_active) VALUES(?,?,?,?,?,?)',$data);}
                audit('supplier.saved','supplier',$id,null,$data);flash('success','Supplier saved.');redirect('?page=suppliers');
            case 'save_supplier_item':
                require_permission('suppliers.manage');
                $supplierId=(int)($_POST['supplier_id']??0);$catalogRef=trim($_POST['catalog_ref']??'');$type=$_POST['item_type']??'';$itemId=(int)($_POST['item_id']??0);$qty=(float)($_POST['package_quantity']??1);$price=(float)($_POST['package_price']??0);
                if($catalogRef!==''&&str_contains($catalogRef,':')){[$type,$rawId]=explode(':',$catalogRef,2);$itemId=(int)$rawId;}
                if (!$supplierId||!$itemId||!in_array($type,['ingredient','packaging'],true)) throw new RuntimeException('Supplier and item are required.');
                if($qty<=0) throw new RuntimeException('Package quantity must be greater than zero.');
                if($price<0) throw new RuntimeException('Package price cannot be negative.');
                $packageUnit=trim($_POST['package_unit']??'each');
                $inventoryUnit=$type==='ingredient'
                    ? (string)$db->scalar('SELECT inventory_unit FROM ingredients WHERE id=?',[$itemId])
                    : (string)$db->scalar('SELECT inventory_unit FROM packaging_items WHERE id=?',[$itemId]);
                if($inventoryUnit==='') throw new RuntimeException('Linked inventory item was not found.');
                $unitCost=$units->normalizedUnitCost($price,$qty,$packageUnit,$inventoryUnit);
                $id=$db->insert('INSERT INTO supplier_items(supplier_id,item_type,item_id,supplier_sku,product_url,package_description,package_quantity,package_unit,package_price,unit_cost,is_preferred,last_price_update) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW())',[$supplierId,$type,$itemId,trim($_POST['supplier_sku']??'')?:null,trim($_POST['product_url']??'')?:null,trim($_POST['package_description']??'')?:null,$qty,$packageUnit,$price,$unitCost,(int)($_POST['is_preferred']??0)]);
                audit('supplier_item.created','supplier_item',$id,null,['price'=>$price]);flash('success','Supplier item linked.');redirect('?page=suppliers');
            case 'update_supplier_price':
                require_permission('suppliers.update_prices');
                $supplierItemId=(int)$_POST['supplier_item_id'];
                $impactRef=Security::reference('PRICE');
                try{$costing->captureSnapshots($uid,'supplier_price_before',$impactRef);}catch(Throwable $ignored){}
                $pricing->updatePrice($supplierItemId,(float)$_POST['new_price'],(float)($_POST['package_quantity']??0)?:null,trim($_POST['source_reference']??'')?:null,trim($_POST['notes']??''),$uid);
                try{$costing->captureSnapshots($uid,'supplier_price_after',$impactRef);}catch(Throwable $ignored){}
                audit('supplier.price_updated','supplier_item',$supplierItemId,null,['new_price'=>(float)$_POST['new_price'],'cost_impact_reference'=>$impactRef]);flash('success','Supplier price updated, history preserved, and cost impact snapshot captured.');redirect('?page=suppliers');
            case 'save_product':
                require_permission('products.manage');
                $id=(int)($_POST['id']??0);$data=[trim($_POST['name']??''),trim($_POST['sku']??''),$_POST['product_type']??'box',(int)($_POST['box_capacity']??0)?:null,(float)($_POST['price']??0),(int)($_POST['is_active']??1)];
                if($data[0]===''||$data[1]==='') throw new RuntimeException('Product name and SKU are required.');
                if((float)$data[4]<0) throw new RuntimeException('Product price cannot be negative.');
                $impactRef=Security::reference('PRODUCT');
                if($id){try{$costing->captureSnapshots($uid,'product_price_before',$impactRef);}catch(Throwable $ignored){}$db->exec('UPDATE products SET name=?,sku=?,product_type=?,box_capacity=?,price=?,is_active=? WHERE id=?',[...$data,$id]);}
                else $id=$db->insert('INSERT INTO products(name,sku,product_type,box_capacity,price,is_active) VALUES(?,?,?,?,?,?)',$data);
                try{$costing->captureSnapshots($uid,$id&&isset($_POST['id'])&&((int)$_POST['id'])>0?'product_price_after':'product_created',$impactRef);}catch(Throwable $ignored){}
                audit('product.saved','product',$id,null,$data+['cost_impact_reference'=>$impactRef]);flash('success','Product saved.');redirect('?page=products');
            case 'save_flavor':
                require_permission('flavors.manage');
                $id=(int)($_POST['id']??0);$name=trim($_POST['name']??'');$slug=trim($_POST['slug']??'');$data=[$name,$slug,trim($_POST['description']??'')?:null,trim($_POST['image_url']??'')?:null,(float)($_POST['target_weight_oz']??0)?:null,(int)($_POST['seasonal']??0),(int)($_POST['is_active']??1)];
                if ($id) $db->exec('UPDATE flavors SET name=?,slug=?,description=?,image_url=?,target_weight_oz=?,seasonal=?,is_active=? WHERE id=?',[...$data,$id]);
                else $id=$db->insert('INSERT INTO flavors(name,slug,description,image_url,target_weight_oz,seasonal,is_active) VALUES(?,?,?,?,?,?,?)',$data);
                audit('flavor.saved','flavor',$id,null,$data);flash('success','Flavor saved.');redirect('?page=flavors');
            case 'save_customer':
                require_permission('customers.manage');
                $id=(int)($_POST['id']??0);$data=[trim($_POST['first_name']??''),trim($_POST['last_name']??'')?:null,trim($_POST['email']??'')?:null,trim($_POST['phone']??'')?:null,trim($_POST['notes']??'')?:null];
                if ($id) $db->exec('UPDATE customers SET first_name=?,last_name=?,email=?,phone=?,notes=? WHERE id=?',[...$data,$id]); else $id=$db->insert('INSERT INTO customers(first_name,last_name,email,phone,notes) VALUES(?,?,?,?,?)',$data);
                audit('customer.saved','customer',$id,null,$data);flash('success','Customer saved.');redirect('?page=customers');
            case 'create_batch':
                require_permission('production.create_batch');
                $code=trim($_POST['batch_code']??''); if($code==='') $code=Security::reference('BATCH');
                $id=$db->insert('INSERT INTO production_batches(batch_code,scheduled_for,status,notes,created_by) VALUES(?,?,'scheduled',?,?)',[$code,$_POST['scheduled_for']?:null,trim($_POST['notes']??'')?:null,$uid]);
                audit('production.batch_created','production_batch',$id,null,['batch_code'=>$code]);flash('success','Production batch created.');redirect('?page=production');
            case 'create_order':
                require_permission('orders.create');
                $productId=(int)($_POST['product_id']??0);$qty=max(1,(int)($_POST['quantity']??1));$product=$db->one('SELECT * FROM products WHERE id=?',[$productId]); if(!$product) throw new RuntimeException('Select a product.');
                $number=Security::reference('ORD');$subtotal=(float)$product['price']*$qty;
                $orderId=$db->insert('INSERT INTO orders(order_number,customer_id,status,sales_channel,fulfillment_type,fulfillment_at,subtotal,total,created_by,notes) VALUES(?,?,'new','manual',?,?,?,?,?,?)',[$number,(int)($_POST['customer_id']??0)?:null,$_POST['fulfillment_type']??'pickup',$_POST['fulfillment_at']?:null,$subtotal,$subtotal,$uid,trim($_POST['notes']??'')?:null]);
                $db->insert('INSERT INTO order_items(order_id,product_id,quantity,unit_price,line_total) VALUES(?,?,?,?,?)',[$orderId,$productId,$qty,$product['price'],$subtotal]);
                audit('order.created','order',$orderId,null,['order_number'=>$number,'total'=>$subtotal]);flash('success','Order created.');redirect('?page=orders');
            case 'save_team_member':
                require_permission('team.manage_members');
                $name=trim($_POST['name']??'');$email=strtolower(trim($_POST['email']??''));$password=(string)($_POST['password']??'');
                if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<10) throw new RuntimeException('Name, valid email and a password of at least 10 characters are required.');
                $newId=$db->insert('INSERT INTO users(name,email,password_hash,job_title,hourly_rate,status) VALUES(?,?,?,?,?,?)',[$name,$email,password_hash($password,PASSWORD_DEFAULT),trim($_POST['job_title']??'')?:null,(float)($_POST['hourly_rate']??0)?:null,$_POST['status']??'active']);
                $roleId=(int)($_POST['role_id']??0); if($roleId)$db->exec('INSERT INTO user_roles(user_id,role_id) VALUES(?,?)',[$newId,$roleId]);
                audit('team.member_created','user',$newId,null,['email'=>$email]);flash('success','Team member created.');redirect('?page=team');
            case 'update_team_member':
                require_permission('team.manage_members');
                $memberId=(int)($_POST['member_id']??0);
                $member=$db->one('SELECT * FROM users WHERE id=?',[$memberId]);
                if(!$member) throw new RuntimeException('Team member not found.');
                $isOwner=(int)$db->scalar('SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.slug="owner"',[$memberId])>0;
                $name=trim($_POST['name']??'');
                $email=strtolower(trim($_POST['email']??''));
                if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Name and a valid email are required.');
                $status=$_POST['status']??'active';
                if($isOwner && $status!=='active') throw new RuntimeException('Owner accounts cannot be deactivated.');
                $db->transaction(function(Database $db) use($memberId,$name,$email,$status,$isOwner){
                    $db->exec('UPDATE users SET name=?,email=?,job_title=?,hourly_rate=?,status=? WHERE id=?',[
                        $name,$email,trim($_POST['job_title']??'')?:null,(float)($_POST['hourly_rate']??0)?:null,$status,$memberId
                    ]);
                    if(!$isOwner){
                        $roleId=(int)($_POST['role_id']??0);
                        if(!$roleId) throw new RuntimeException('Select a role.');
                        $db->exec('DELETE FROM user_roles WHERE user_id=?',[$memberId]);
                        $db->exec('INSERT INTO user_roles(user_id,role_id) VALUES(?,?)',[$memberId,$roleId]);
                    }
                    $password=(string)($_POST['new_password']??'');
                    if($password!==''){
                        if(strlen($password)<10) throw new RuntimeException('New password must be at least 10 characters.');
                        $db->exec('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$memberId]);
                    }
                });
                audit('team.member_updated','user',$memberId,$member,['email'=>$email,'status'=>$status]);
                flash('success','Team member updated.');
                redirect('?page=team&member='.$memberId);

            case 'save_user_permission_overrides':
                require_permission('team.manage_roles');
                $memberId=(int)($_POST['member_id']??0);
                $member=$db->one('SELECT id FROM users WHERE id=?',[$memberId]);
                if(!$member) throw new RuntimeException('Team member not found.');
                $isOwner=(int)$db->scalar('SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.slug="owner"',[$memberId])>0;
                if($isOwner) throw new RuntimeException('Owner permissions are protected and cannot be overridden.');
                $overrides=$_POST['overrides']??[];
                $db->transaction(function(Database $db) use($memberId,$overrides){
                    $db->exec('DELETE FROM user_permission_overrides WHERE user_id=?',[$memberId]);
                    foreach($overrides as $permissionId=>$effect){
                        if(!in_array($effect,['allow','deny'],true)) continue;
                        $db->exec('INSERT INTO user_permission_overrides(user_id,permission_id,effect) VALUES(?,?,?)',[$memberId,(int)$permissionId,$effect]);
                    }
                });
                audit('team.permission_overrides_updated','user',$memberId,null,['overrides'=>$overrides]);
                flash('success','Individual permission overrides updated.');
                redirect('?page=team&member='.$memberId);

            case 'save_role_permissions':
                require_permission('team.manage_roles');
                $roleId=(int)($_POST['role_id']??0);$role=$db->one('SELECT * FROM roles WHERE id=?',[$roleId]);if(!$role) throw new RuntimeException('Role not found.');if($role['slug']==='owner') throw new RuntimeException('Owner permissions are protected.');
                $db->transaction(function(Database $db) use($roleId){$db->exec('DELETE FROM role_permissions WHERE role_id=?',[$roleId]);foreach(($_POST['permissions']??[]) as $pid){$db->exec('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)',[$roleId,(int)$pid]);}});
                audit('role.permissions_updated','role',$roleId,null,['permissions'=>$_POST['permissions']??[]]);flash('success','Role permissions updated.');redirect('?page=team&role='.$roleId);
            case 'save_llm_provider':
                require_permission('ai.manage_providers');
                $providerId=(int)($_POST['provider_id']??0);$provider=$db->one('SELECT * FROM llm_providers WHERE id=?',[$providerId]);if(!$provider)throw new RuntimeException('Provider not found.');
                $db->exec('UPDATE llm_providers SET base_url=?,default_model=?,timeout_seconds=?,enabled=?,is_default=? WHERE id=?',[trim($_POST['base_url']??''),trim($_POST['default_model']??''),(int)($_POST['timeout_seconds']??30),(int)($_POST['enabled']??0),(int)($_POST['is_default']??0),$providerId]);
                $secret=trim($_POST['api_key']??''); if($secret!==''){require_permission('ai.manage_api_keys');$enc=Security::encrypt($secret,$config['app']['key']);$last4=substr($secret,-4);$db->exec('INSERT INTO llm_credentials(provider_id,encrypted_secret,secret_last4,updated_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE encrypted_secret=VALUES(encrypted_secret),secret_last4=VALUES(secret_last4),updated_by=VALUES(updated_by)',[$providerId,$enc,$last4,$uid]);}
                audit('ai.provider_updated','llm_provider',$providerId,null,['enabled'=>(int)($_POST['enabled']??0)]);flash('success','LLM provider saved.');redirect('?page=ai');
            case 'run_ai':
                require_permission('ai.use');
                $providerId=(int)($_POST['provider_id']??0);$prompt=trim($_POST['prompt']??'');if($prompt==='')throw new RuntimeException('Enter a question.');
                $snapshot=['open_orders'=>(int)$db->scalar("SELECT COUNT(*) FROM orders WHERE status NOT IN ('fulfilled','cancelled')"),'active_batches'=>(int)$db->scalar("SELECT COUNT(*) FROM production_batches WHERE status NOT IN ('completed','cancelled')"),'low_ingredients'=>(int)$db->scalar("SELECT COUNT(*) FROM ingredients i WHERE (SELECT COALESCE(SUM(quantity_delta),0) FROM inventory_transactions t WHERE t.item_type='ingredient' AND t.item_id=i.id) < i.reorder_point"),'today_sales'=>(float)$db->scalar("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)=CURDATE()")];
                $provider=$db->one('SELECT * FROM llm_providers WHERE id=?',[$providerId]);$result=$llm->run($providerId,$provider['default_model']??'','You are the Fudge Donuts operations assistant. Use only the supplied business snapshot when making operational claims. Be concise.','Operational snapshot: '.json_encode($snapshot)."\n\nQuestion: ".$prompt,$uid);$_SESSION['ai_result']=$result['text'];redirect('?page=ai');
        }
    } catch (Throwable $e) {
        flash('danger',$e->getMessage());
        redirect('?page='.urlencode($page));
    }
}

function edit_id(): int { return (int)($_GET['edit']??0); }

switch ($page) {
    case 'dashboard':
        require_permission('dashboard.view');
        Ui::layoutStart('Dashboard','dashboard'); Ui::pageHead('Good evening','Today’s Fudge Donuts operation at a glance.');
        $metrics=[
            ['Open Orders',(int)$db->scalar("SELECT COUNT(*) FROM orders WHERE status NOT IN ('fulfilled','cancelled')"),'Orders needing attention'],
            ['Active Batches',(int)$db->scalar("SELECT COUNT(*) FROM production_batches WHERE status NOT IN ('completed','cancelled')"),'Production in progress'],
            ['Low Ingredients',(int)$db->scalar("SELECT COUNT(*) FROM ingredients i WHERE (SELECT COALESCE(SUM(quantity_delta),0) FROM inventory_transactions t WHERE t.item_type='ingredient' AND t.item_id=i.id) < i.reorder_point"),'Below reorder point'],
            ["Today's Sales",'$'.number_format((float)$db->scalar("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)=CURDATE()"),2),'Recorded orders today'],
        ];
        echo '<div class="grid cols-4">';foreach($metrics as $m)echo '<div class="card metric"><div class="label">'.h($m[0]).'</div><div class="value">'.h($m[1]).'</div><div class="sub">'.h($m[2]).'</div></div>';echo '</div>';
        $recent=$db->all('SELECT o.*,CONCAT(COALESCE(c.first_name,"")," ",COALESCE(c.last_name,"")) customer FROM orders o LEFT JOIN customers c ON c.id=o.customer_id ORDER BY o.id DESC LIMIT 8');
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Recent Orders</h2><a class="btn small" href="?page=orders">View Orders</a></div><table><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Total</th></tr></thead><tbody>';
        foreach($recent as $r)echo '<tr><td>'.h($r['order_number']).'</td><td>'.h(trim($r['customer'])?:'Walk-in').'</td><td>'.Ui::statusBadge($r['status']).'</td><td>$'.number_format((float)$r['total'],2).'</td></tr>';
        if(!$recent)echo '<tr><td colspan="4" class="empty">No orders yet.</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'ingredients':
        require_permission('ingredients.view');Ui::layoutStart('Ingredients','ingredients');Ui::pageHead('Ingredients','Preloaded starter catalog with full manual add/edit control.',can('ingredients.manage')?'<a class="btn primary" href="?page=ingredients&edit=new">Add Ingredient</a>':'');
        if(isset($_GET['edit'])&&can('ingredients.manage')){$item=is_numeric($_GET['edit'])?$db->one('SELECT * FROM ingredients WHERE id=?',[edit_id()]):null;$cats=$db->all('SELECT * FROM ingredient_categories ORDER BY name');echo '<div class="modalish"><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_ingredient"><input type="hidden" name="id" value="'.h($item['id']??0).'">';Ui::formField('name','Name',$item['name']??'', 'text',true);Ui::formField('sku','SKU',$item['sku']??'');echo '<label>Category<select name="category_id"><option value="">—</option>';foreach($cats as $c)echo '<option value="'.$c['id'].'" '.(($item['category_id']??null)==$c['id']?'selected':'').'>'.h($c['name']).'</option>';echo '</select></label>';Ui::formField('inventory_unit','Inventory Unit',$item['inventory_unit']??'oz');Ui::formField('reorder_point','Reorder Point',$item['reorder_point']??0,'number',false,'step="0.001"');Ui::formField('target_stock','Target Stock',$item['target_stock']??0,'number',false,'step="0.001"');Ui::formField('storage_location','Storage Location',$item['storage_location']??'');echo '<label>Active<select name="is_active"><option value="1">Active</option><option value="0" '.(($item['is_active']??1)==0?'selected':'').'>Archived</option></select></label><div class="span-2 actions"><button class="btn primary">Save Ingredient</button><a class="btn" href="?page=ingredients">Cancel</a></div></form></div>';}else{$rows=$db->all("SELECT i.*,c.name category,(SELECT COALESCE(SUM(quantity_delta),0) FROM inventory_transactions t WHERE t.item_type='ingredient' AND t.item_id=i.id) on_hand FROM ingredients i LEFT JOIN ingredient_categories c ON c.id=i.category_id ORDER BY i.name");echo '<div class="table-card"><table><thead><tr><th>Ingredient</th><th>Category</th><th>On Hand</th><th>Reorder</th><th>Status</th><th></th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td><strong>'.h($r['name']).'</strong><div class="muted">'.h($r['sku']).'</div></td><td>'.h($r['category']).'</td><td>'.h($r['on_hand']).' '.h($r['inventory_unit']).'</td><td>'.h($r['reorder_point']).'</td><td>'.Ui::statusBadge($r['is_active']?'active':'inactive').'</td><td>'.(can('ingredients.manage')?'<a class="btn small" href="?page=ingredients&edit='.$r['id'].'">Edit</a>':'').'</td></tr>';echo '</tbody></table></div>';}Ui::layoutEnd();break;

    case 'packaging':
        require_permission('packaging.view');Ui::layoutStart('Packaging','packaging');Ui::pageHead('Packaging','Wrappers, stickers, boxes and other consumables.',can('packaging.manage')?'<a class="btn primary" href="?page=packaging&edit=new">Add Packaging</a>':'');
        if(isset($_GET['edit'])&&can('packaging.manage')){$item=is_numeric($_GET['edit'])?$db->one('SELECT * FROM packaging_items WHERE id=?',[edit_id()]):null;echo '<div class="modalish"><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_packaging"><input type="hidden" name="id" value="'.h($item['id']??0).'">';Ui::formField('name','Name',$item['name']??'','text',true);Ui::formField('sku','SKU',$item['sku']??'');Ui::formField('inventory_unit','Inventory Unit',$item['inventory_unit']??'each');Ui::formField('current_unit_cost','Current Unit Cost',$item['current_unit_cost']??0,'number',false,'step="0.0001"');Ui::formField('reorder_point','Reorder Point',$item['reorder_point']??0,'number');Ui::formField('target_stock','Target Stock',$item['target_stock']??0,'number');echo '<input type="hidden" name="is_active" value="'.h($item['is_active']??1).'"><div class="span-2 actions"><button class="btn primary">Save Packaging</button><a class="btn" href="?page=packaging">Cancel</a></div></form></div>';}else{$rows=$db->all("SELECT p.*,(SELECT COALESCE(SUM(quantity_delta),0) FROM inventory_transactions t WHERE t.item_type='packaging' AND t.item_id=p.id) on_hand FROM packaging_items p ORDER BY p.name");echo '<div class="table-card"><table><thead><tr><th>Item</th><th>On Hand</th><th>Unit Cost</th><th>Reorder</th><th></th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td><strong>'.h($r['name']).'</strong><div class="muted">'.h($r['sku']).'</div></td><td>'.h($r['on_hand']).' '.h($r['inventory_unit']).'</td><td>$'.number_format((float)$r['current_unit_cost'],4).'</td><td>'.h($r['reorder_point']).'</td><td>'.(can('packaging.manage')?'<a class="btn small" href="?page=packaging&edit='.$r['id'].'">Edit</a>':'').'</td></tr>';echo '</tbody></table></div>';}Ui::layoutEnd();break;

    case 'inventory':
        require_permission('inventory.view');Ui::layoutStart('Inventory','inventory');Ui::pageHead('Inventory','Ledger-based stock. All changes are recorded as transactions.','<div class="actions"><a class="btn" href="?page=inventory-counts">Physical Counts</a><a class="btn" href="?page=lots">Lots & Expiration</a></div>');
        if(can('inventory.adjust')){$ings=$db->all('SELECT id,name,inventory_unit FROM ingredients WHERE is_active=1 ORDER BY name');$pkgs=$db->all('SELECT id,name,inventory_unit FROM packaging_items WHERE is_active=1 ORDER BY name');echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Manual Adjustment / Initial Count</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="adjust_inventory"><label>Type<select name="item_type" id="inv-type"><option value="ingredient">Ingredient</option><option value="packaging">Packaging</option></select></label><label>Item<select name="item_id">';foreach($ings as $r)echo '<option value="'.$r['id'].'">'.h($r['name']).'</option>';echo '</select></label>';Ui::formField('quantity_delta','Quantity Change','','number',true,'step="0.001" placeholder="Use + for received/initial count, - for usage/waste"');Ui::formField('unit','Unit','oz');Ui::formField('reason','Reason','Initial Count');echo '<div class="span-2"><button class="btn primary">Record Transaction</button></div></form></div>';}
        $tx=$db->all("SELECT t.*,u.name user_name,CASE WHEN t.item_type='ingredient' THEN (SELECT name FROM ingredients WHERE id=t.item_id) ELSE (SELECT name FROM packaging_items WHERE id=t.item_id) END item_name FROM inventory_transactions t LEFT JOIN users u ON u.id=t.created_by ORDER BY t.id DESC LIMIT 100");echo '<div class="table-card"><div class="table-head"><h2>Recent Inventory Transactions</h2></div><table><thead><tr><th>Date</th><th>Item</th><th>Change</th><th>Reason</th><th>User</th></tr></thead><tbody>';foreach($tx as $r)echo '<tr><td>'.h($r['created_at']).'</td><td>'.h($r['item_name']).'</td><td>'.(($r['quantity_delta']>0)?'+':'').h($r['quantity_delta']).' '.h($r['unit']).'</td><td>'.h($r['reason']).'</td><td>'.h($r['user_name']).'</td></tr>';if(!$tx)echo '<tr><td colspan="5" class="empty">No inventory transactions yet. Record your opening inventory when V1 is installed.</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'suppliers':
        require_permission('suppliers.view');Ui::layoutStart('Suppliers','suppliers');Ui::pageHead('Suppliers','Supplier catalog, linked items, current pricing and immutable price history.');
        $suppliers=$db->all('SELECT * FROM suppliers ORDER BY name');$supplierItems=$db->all("SELECT si.*,s.name supplier,CASE WHEN si.item_type='ingredient' THEN (SELECT name FROM ingredients WHERE id=si.item_id) ELSE (SELECT name FROM packaging_items WHERE id=si.item_id) END item_name FROM supplier_items si JOIN suppliers s ON s.id=si.supplier_id ORDER BY s.name,item_name");$catalogIngredients=$db->all('SELECT id,name,inventory_unit FROM ingredients WHERE is_active=1 ORDER BY name');$catalogPackaging=$db->all('SELECT id,name,inventory_unit FROM packaging_items WHERE is_active=1 ORDER BY name');$unitRows=$db->all('SELECT symbol,name FROM units ORDER BY unit_type,name');
        $editSupplierId=(int)($_GET['edit_supplier']??0);
        if($editSupplierId&&can('suppliers.manage')){$es=$db->one('SELECT * FROM suppliers WHERE id=?',[$editSupplierId]);if($es)echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Edit Supplier</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_supplier"><input type="hidden" name="id" value="'.$es['id'].'"><label>Name<input name="name" value="'.h($es['name']).'" required></label><label>Website<input name="website" value="'.h($es['website']).'"></label><label>Email<input name="email" value="'.h($es['email']).'"></label><label>Phone<input name="phone" value="'.h($es['phone']).'"></label><label class="span-2">Notes<textarea name="notes">'.h($es['notes']).'</textarea></label><label>Active<select name="is_active"><option value="1" '.($es['is_active']?'selected':'').'>Active</option><option value="0" '.(!$es['is_active']?'selected':'').'>Archived</option></select></label><div class="span-2 actions"><button class="btn primary">Save Supplier</button><a class="btn" href="?page=suppliers">Cancel</a></div></form></div>';}

        if(can('suppliers.manage'))echo '<div class="grid cols-2" style="margin-bottom:18px"><div class="card"><h2 class="section-title">Add Supplier</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_supplier"><input type="hidden" name="id" value="0"><input type="hidden" name="is_active" value="1"><label>Name<input name="name" required></label><label>Website<input name="website"></label><label>Email<input name="email"></label><label>Phone<input name="phone"></label><label class="span-2">Notes<textarea name="notes"></textarea></label><button class="btn primary span-2">Add Supplier</button></form></div><div class="card"><h2 class="section-title">Link Supplier Item</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_supplier_item"><label>Supplier<select name="supplier_id">'.implode('',array_map(fn($s)=>'<option value="'.$s['id'].'">'.h($s['name']).'</option>',$suppliers)).'</select></label><label class="span-2">Catalog Item<select name="catalog_ref" required><option value="">Select ingredient or packaging</option><optgroup label="Ingredients">'.implode('',array_map(fn($i)=>'<option value="ingredient:'.$i['id'].'">'.h($i['name']).' · inventory in '.h($i['inventory_unit']).'</option>',$catalogIngredients)).'</optgroup><optgroup label="Packaging">'.implode('',array_map(fn($p)=>'<option value="packaging:'.$p['id'].'">'.h($p['name']).' · inventory in '.h($p['inventory_unit']).'</option>',$catalogPackaging)).'</optgroup></select></label><label>Package Quantity<input type="number" min="0.0001" step="0.0001" name="package_quantity" value="1" required><small>Total contents of one purchased package.</small></label><label>Package Unit<select name="package_unit">'.implode('',array_map(fn($u)=>'<option value="'.h($u['symbol']).'">'.h($u['name']).' ('.h($u['symbol']).')</option>',$unitRows)).'</select></label><label>Package Price<input type="number" step="0.01" name="package_price" value="0"></label><label>Supplier SKU<input name="supplier_sku"></label><label class="span-2">Product URL<input name="product_url"></label><input type="hidden" name="is_preferred" value="0"><button class="btn primary span-2">Link Item</button></form></div></div>';
        echo '<div class="table-card" style="margin-bottom:18px"><div class="table-head"><h2>Supplier Directory</h2></div><table><thead><tr><th>Supplier</th><th>Contact</th><th>Status</th><th></th></tr></thead><tbody>';foreach($suppliers as $s)echo '<tr><td><strong>'.h($s['name']).'</strong><div class="muted">'.h($s['website']).'</div></td><td>'.h($s['email']).'<div class="muted">'.h($s['phone']).'</div></td><td>'.Ui::statusBadge($s['is_active']?'active':'inactive').'</td><td>'.(can('suppliers.manage')?'<a class="btn small" href="?page=suppliers&edit_supplier='.$s['id'].'">Edit</a>':'').'</td></tr>';echo '</tbody></table></div>';
        echo '<div class="table-card"><div class="table-head"><h2>Supplier Pricing</h2></div><table><thead><tr><th>Supplier</th><th>Item</th><th>Package</th><th>Price</th><th>Unit Cost</th><th>Last Updated</th><th></th></tr></thead><tbody>';foreach($supplierItems as $r){echo '<tr><td>'.h($r['supplier']).'</td><td>'.h($r['item_name']).'</td><td>'.h($r['package_quantity']).' '.h($r['package_unit']).'</td><td>$'.number_format((float)$r['package_price'],2).'</td><td>$'.number_format((float)$r['unit_cost'],4).'</td><td>'.h($r['last_price_update']).'</td><td>';if(can('suppliers.update_prices'))echo '<details><summary class="btn small">Update Price</summary><form method="post" style="min-width:260px;margin-top:8px">'.Ui::csrf().'<input type="hidden" name="form_action" value="update_supplier_price"><input type="hidden" name="supplier_item_id" value="'.$r['id'].'"><label>New price<input type="number" step="0.01" name="new_price" value="'.h($r['package_price']).'" required></label><label>Package qty<input type="number" step="0.0001" name="package_quantity" value="'.h($r['package_quantity']).'"></label><label>Source/reference<input name="source_reference"></label><label>Notes<input name="notes"></label><button class="btn primary small">Save Price</button></form></details>';echo '</td></tr>';}if(!$supplierItems)echo '<tr><td colspan="7" class="empty">No supplier items linked yet.</td></tr>';echo '</tbody></table></div>';
        if(can('suppliers.view_price_history')){$hist=$db->all('SELECT h.*,s.name supplier FROM supplier_price_history h JOIN supplier_items si ON si.id=h.supplier_item_id JOIN suppliers s ON s.id=si.supplier_id ORDER BY h.id DESC LIMIT 50');echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Price History</h2></div><table><thead><tr><th>Date</th><th>Supplier</th><th>Old</th><th>New</th><th>Unit Cost</th><th>Source</th></tr></thead><tbody>';foreach($hist as $r)echo '<tr><td>'.h($r['effective_at']).'</td><td>'.h($r['supplier']).'</td><td>$'.number_format((float)$r['old_price'],2).'</td><td>$'.number_format((float)$r['new_price'],2).'</td><td>$'.number_format((float)$r['unit_cost'],4).'</td><td>'.h($r['source_reference']).'</td></tr>';echo '</tbody></table></div>';}Ui::layoutEnd();break;

    case 'products':
        require_permission('products.view');Ui::layoutStart('Products','products');Ui::pageHead('Products','Sellable products and editable pricing.');
        $editProductId=(int)($_GET['edit']??0);if($editProductId&&can('products.manage')){$ep=$db->one('SELECT * FROM products WHERE id=?',[$editProductId]);if($ep)echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Edit Product</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_product"><input type="hidden" name="id" value="'.$ep['id'].'"><label>Name<input name="name" value="'.h($ep['name']).'" required></label><label>SKU<input name="sku" value="'.h($ep['sku']).'" required></label><label>Type<select name="product_type"><option value="single" '.($ep['product_type']==='single'?'selected':'').'>Single</option><option value="box" '.($ep['product_type']==='box'?'selected':'').'>Box</option><option value="catering" '.($ep['product_type']==='catering'?'selected':'').'>Catering</option><option value="wholesale" '.($ep['product_type']==='wholesale'?'selected':'').'>Wholesale</option></select></label><label>Box Capacity<input type="number" name="box_capacity" value="'.h($ep['box_capacity']).'"></label><label>Price<input type="number" min="0" step="0.01" name="price" value="'.h($ep['price']).'" required></label><label>Active<select name="is_active"><option value="1" '.($ep['is_active']?'selected':'').'>Active</option><option value="0" '.(!$ep['is_active']?'selected':'').'>Archived</option></select></label><div class="span-2 actions"><button class="btn primary">Save Product</button><a class="btn" href="?page=products">Cancel</a></div></form></div>';}
        if(can('products.manage'))echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Add Product</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_product"><input type="hidden" name="id" value="0"><input type="hidden" name="is_active" value="1"><label>Name<input name="name" required></label><label>SKU<input name="sku" required></label><label>Type<select name="product_type"><option value="single">Single</option><option value="box">Box</option><option value="catering">Catering</option><option value="wholesale">Wholesale</option></select></label><label>Box Capacity<input type="number" name="box_capacity"></label><label>Price<input type="number" step="0.01" name="price" required></label><button class="btn primary">Add Product</button></form></div>';
        $rows=$db->all('SELECT * FROM products ORDER BY id');echo '<div class="table-card"><table><thead><tr><th>Product</th><th>SKU</th><th>Capacity</th><th>Price</th><th>Status</th><th></th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td><strong>'.h($r['name']).'</strong></td><td>'.h($r['sku']).'</td><td>'.h($r['box_capacity']).'</td><td>echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'flavors':
        require_permission('flavors.view');Ui::layoutStart('Flavors','flavors');Ui::pageHead('Flavors','Launch menu and future seasonal flavors.',can('flavors.manage')?'<a class="btn primary" href="?page=flavors&edit=new">Add Flavor</a>':'');
        if(isset($_GET['edit'])&&can('flavors.manage')){$item=is_numeric($_GET['edit'])?$db->one('SELECT * FROM flavors WHERE id=?',[edit_id()]):null;echo '<div class="modalish"><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_flavor"><input type="hidden" name="id" value="'.h($item['id']??0).'">';Ui::formField('name','Name',$item['name']??'','text',true);Ui::formField('slug','Slug',$item['slug']??'','text',true);Ui::formField('target_weight_oz','Target Weight (oz)',$item['target_weight_oz']??3.5,'number',false,'step="0.001"');Ui::formField('image_url','Image URL',$item['image_url']??'');Ui::formField('description','Description',$item['description']??'','textarea');echo '<label>Seasonal<select name="seasonal"><option value="0">No</option><option value="1" '.(($item['seasonal']??0)?'selected':'').'>Yes</option></select></label><input type="hidden" name="is_active" value="'.h($item['is_active']??1).'"><div class="span-2 actions"><button class="btn primary">Save Flavor</button><a class="btn" href="?page=flavors">Cancel</a></div></form></div>';}else{$rows=$db->all('SELECT * FROM flavors ORDER BY name');echo '<div class="grid cols-3">';foreach($rows as $r)echo '<div class="card"><div class="kicker">'.($r['seasonal']?'Seasonal':'Core Flavor').'</div><h2>'.h($r['name']).'</h2><p class="muted">'.h($r['description']).'</p><div>Target: <strong>'.h($r['target_weight_oz']).' oz</strong></div>'.(can('flavors.manage')?'<div style="margin-top:12px"><a class="btn small" href="?page=flavors&edit='.$r['id'].'">Edit</a></div>':'').'</div>';echo '</div>';}Ui::layoutEnd();break;

    case 'recipes':
        require_permission('recipes.view');Ui::layoutStart('Recipes','recipes');Ui::pageHead('Recipes','Versioned formulas. Published historical versions are retained.');
        $rows=$db->all("SELECT r.*,f.name flavor,(SELECT MAX(version_number) FROM recipe_versions rv WHERE rv.recipe_id=r.id) latest_version FROM recipes r LEFT JOIN flavors f ON f.id=r.flavor_id ORDER BY r.recipe_type,r.name");echo '<div class="table-card"><table><thead><tr><th>Recipe</th><th>Type</th><th>Flavor</th><th>Version</th><th>Status</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td><strong>'.h($r['name']).'</strong></td><td>'.h($r['recipe_type']).'</td><td>'.h($r['flavor']).'</td><td>v'.h($r['latest_version']??1).'</td><td>'.Ui::statusBadge($r['is_active']?'active':'inactive').'</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'customers':
        require_permission('customers.view');Ui::layoutStart('Customers','customers');Ui::pageHead('Customers','Order history foundation and customer records.');
        $editCustomerId=(int)($_GET['edit']??0);if($editCustomerId&&can('customers.manage')){$ec=$db->one('SELECT * FROM customers WHERE id=?',[$editCustomerId]);if($ec)echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Edit Customer</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_customer"><input type="hidden" name="id" value="'.$ec['id'].'"><label>First Name<input name="first_name" value="'.h($ec['first_name']).'" required></label><label>Last Name<input name="last_name" value="'.h($ec['last_name']).'"></label><label>Email<input type="email" name="email" value="'.h($ec['email']).'"></label><label>Phone<input name="phone" value="'.h($ec['phone']).'"></label><label class="span-2">Notes<textarea name="notes">'.h($ec['notes']).'</textarea></label><div class="span-2 actions"><button class="btn primary">Save Customer</button><a class="btn" href="?page=customers">Cancel</a></div></form></div>';}
        if(can('customers.manage'))echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Add Customer</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_customer"><input type="hidden" name="id" value="0"><label>First Name<input name="first_name" required></label><label>Last Name<input name="last_name"></label><label>Email<input type="email" name="email"></label><label>Phone<input name="phone"></label><label class="span-2">Notes<textarea name="notes"></textarea></label><button class="btn primary">Add Customer</button></form></div>';
        $rows=$db->all('SELECT c.*,(SELECT COUNT(*) FROM orders o WHERE o.customer_id=c.id) orders_count,(SELECT COALESCE(SUM(total),0) FROM orders o WHERE o.customer_id=c.id) lifetime FROM customers c ORDER BY c.id DESC');echo '<div class="table-card"><table><thead><tr><th>Customer</th><th>Contact</th><th>Orders</th><th>Lifetime</th><th></th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td><strong>'.h($r['first_name'].' '.$r['last_name']).'</strong></td><td>'.h($r['email']).'<div class="muted">'.h($r['phone']).'</div></td><td>'.h($r['orders_count']).'</td><td>if(!$rows)echo '<tr><td colspan="4" class="empty">No customers yet.</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'orders':
        require_permission('orders.view');Ui::layoutStart('Orders','orders');Ui::pageHead('Orders','Manual orders now; visual mix-and-match composer is the next order phase.');
        if(can('orders.create')){$customers=$db->all('SELECT id,first_name,last_name FROM customers ORDER BY first_name');$products=$db->all('SELECT * FROM products WHERE is_active=1 ORDER BY id');echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Create Order</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="create_order"><label>Customer<select name="customer_id"><option value="">Walk-in / no customer</option>';foreach($customers as $c)echo '<option value="'.$c['id'].'">'.h($c['first_name'].' '.$c['last_name']).'</option>';echo '</select></label><label>Product<select name="product_id">';foreach($products as $p)echo '<option value="'.$p['id'].'">'.h($p['name']).' — $'.number_format((float)$p['price'],2).'</option>';echo '</select></label><label>Quantity<input type="number" min="1" name="quantity" value="1"></label><label>Fulfillment<select name="fulfillment_type"><option value="pickup">Pickup</option><option value="delivery">Delivery</option><option value="shipping">Shipping</option></select></label><label>Fulfillment Time<input type="datetime-local" name="fulfillment_at"></label><label>Notes<input name="notes"></label><button class="btn primary">Create Order</button></form></div>';}
        $rows=$db->all('SELECT o.*,CONCAT(COALESCE(c.first_name,"")," ",COALESCE(c.last_name,"")) customer FROM orders o LEFT JOIN customers c ON c.id=o.customer_id ORDER BY o.id DESC');echo '<div class="table-card"><table><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Fulfillment</th><th>Total</th><th>Created</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td><strong>'.h($r['order_number']).'</strong></td><td>'.h(trim($r['customer'])?:'Walk-in').'</td><td>'.Ui::statusBadge($r['status']).'</td><td>'.h($r['fulfillment_type']).'<div class="muted">'.h($r['fulfillment_at']).'</div></td><td>$'.number_format((float)$r['total'],2).'</td><td>'.h($r['created_at']).'</td></tr>';if(!$rows)echo '<tr><td colspan="6" class="empty">No orders yet.</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'production':
        require_permission('production.view');Ui::layoutStart('Production','production');Ui::pageHead('Production','Batch planning and production status foundation.');
        if(can('production.create_batch'))echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Create Batch</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="create_batch"><label>Batch Code<input name="batch_code" placeholder="Auto-generated if blank"></label><label>Scheduled Date<input type="date" name="scheduled_for" value="'.date('Y-m-d').'"></label><label class="span-2">Notes<textarea name="notes"></textarea></label><button class="btn primary">Create Batch</button></form></div>';
        $rows=$db->all('SELECT b.*,u.name creator FROM production_batches b LEFT JOIN users u ON u.id=b.created_by ORDER BY b.id DESC');echo '<div class="table-card"><table><thead><tr><th>Batch</th><th>Scheduled</th><th>Status</th><th>Created By</th><th>Created</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td><strong>'.h($r['batch_code']).'</strong></td><td>'.h($r['scheduled_for']).'</td><td>'.Ui::statusBadge($r['status']).'</td><td>'.h($r['creator']).'</td><td>'.h($r['created_at']).'</td></tr>';if(!$rows)echo '<tr><td colspan="5" class="empty">No production batches yet.</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'team':
        require_permission('team.view');Ui::layoutStart('Team','team');Ui::pageHead('Team Members','Roles, feature permissions and production access.');
        $roles=$db->all('SELECT * FROM roles WHERE is_active=1 ORDER BY id');if(can('team.manage_members'))echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Add Team Member</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_team_member"><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Job Title<input name="job_title"></label><label>Role<select name="role_id">';foreach($roles as $r)echo '<option value="'.$r['id'].'">'.h($r['name']).'</option>';echo '</select></label><label>Hourly Rate<input type="number" step="0.01" name="hourly_rate"></label><label>Status<select name="status"><option value="active">Active</option><option value="invited">Invited</option></select></label><label class="span-2">Temporary Password<input type="password" name="password" minlength="10" required></label><button class="btn primary">Create Team Member</button></form></div>';
        $members=$db->all("SELECT u.*,GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id GROUP BY u.id ORDER BY u.name");echo '<div class="table-card"><div class="table-head"><h2>Members</h2></div><table><thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Last Login</th><th></th></tr></thead><tbody>';foreach($members as $m)echo '<tr><td><strong>'.h($m['name']).'</strong><div class="muted">'.h($m['email']).'</div></td><td>'.h($m['roles']).'</td><td>'.Ui::statusBadge($m['status']).'</td><td>'.h($m['last_login_at']).'</td><td><a class="btn small" href="?page=team&member='.$m['id'].'">Manage</a></td></tr>';echo '</tbody></table></div>';
        $selectedMember=(int)($_GET['member']??0);
        if($selectedMember){
            $member=$db->one('SELECT * FROM users WHERE id=?',[$selectedMember]);
            if($member){
                $memberRole=$db->one('SELECT r.* FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? ORDER BY r.id LIMIT 1',[$selectedMember]);
                $memberIsOwner=$memberRole&&$memberRole['slug']==='owner';
                if(can('team.manage_members')){
                    echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Manage '.h($member['name']).'</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="update_team_member"><input type="hidden" name="member_id" value="'.$selectedMember.'"><label>Name<input name="name" value="'.h($member['name']).'" required></label><label>Email<input type="email" name="email" value="'.h($member['email']).'" required></label><label>Job Title<input name="job_title" value="'.h($member['job_title']).'"></label><label>Hourly Rate<input type="number" step="0.01" name="hourly_rate" value="'.h($member['hourly_rate']??'').'"></label>';
                    if($memberIsOwner){
                        echo '<label>Role<input value="Owner" disabled><small>Owner role is protected.</small></label><input type="hidden" name="status" value="active">';
                    } else {
                        echo '<label>Role<select name="role_id">';
                        foreach($roles as $r) echo '<option value="'.$r['id'].'" '.(($memberRole['id']??0)==$r['id']?'selected':'').'>'.h($r['name']).'</option>';
                        echo '</select></label><label>Status<select name="status">';
                        foreach(['active'=>'Active','invited'=>'Invited','suspended'=>'Suspended','inactive'=>'Inactive','former'=>'Former'] as $value=>$label) echo '<option value="'.$value.'" '.($member['status']===$value?'selected':'').'>'.h($label).'</option>';
                        echo '</select></label>';
                    }
                    echo '<label class="span-2">Reset Password<input type="password" name="new_password" minlength="10"><small>Leave blank to keep the current password.</small></label><div class="span-2"><button class="btn primary">Save Team Member</button></div></form></div>';
                }

                if(can('team.manage_roles')){
                    echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Individual Permission Overrides</h2>';
                    if($memberIsOwner){
                        echo '<div class="alert info">Owner permissions are protected and always allowed.</div>';
                    } else {
                        $existing=[];foreach($db->all('SELECT permission_id,effect FROM user_permission_overrides WHERE user_id=?',[$selectedMember]) as $ov)$existing[(int)$ov['permission_id']]=$ov['effect'];
                        $allPerms=$db->all('SELECT * FROM permissions ORDER BY module,label');
                        echo '<p class="muted">Role permissions remain the default. Use an individual override only for exceptions.</p><form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_user_permission_overrides"><input type="hidden" name="member_id" value="'.$selectedMember.'"><div class="permission-grid">';
                        $module='';
                        foreach($allPerms as $p){
                            if($p['module']!==$module){if($module!=='')echo '</div>';echo '<div class="module">'.h($p['module']).'</div><div class="perms">';$module=$p['module'];}
                            $effect=$existing[(int)$p['id']]??'';
                            echo '<label style="display:flex;align-items:center;gap:6px">'.h($p['label']).' <select name="overrides['.$p['id'].']"><option value="" '.($effect===''?'selected':'').'>Role default</option><option value="allow" '.($effect==='allow'?'selected':'').'>Allow</option><option value="deny" '.($effect==='deny'?'selected':'').'>Deny</option></select></label>';
                        }
                        if($module!=='')echo '</div>';
                        echo '</div><button class="btn primary" style="margin-top:14px">Save Individual Overrides</button></form>';
                    }
                    echo '</div>';
                }
            }
        }

        if(can('team.manage_roles')){$selected=(int)($_GET['role']??($roles[1]['id']??0));$role=$db->one('SELECT * FROM roles WHERE id=?',[$selected]);$assigned=array_flip(array_column($db->all('SELECT permission_id FROM role_permissions WHERE role_id=?',[$selected]),'permission_id'));$perms=$db->all('SELECT * FROM permissions ORDER BY module,label');echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Role Permissions</h2><div class="actions" style="margin-bottom:12px">';foreach($roles as $r)echo '<a class="btn small '.($r['id']==$selected?'primary':'').'" href="?page=team&role='.$r['id'].'">'.h($r['name']).'</a>';echo '</div>';if($role&&$role['slug']==='owner')echo '<div class="alert info">Owner permissions are protected and always include every feature.</div>';elseif($role){echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_role_permissions"><input type="hidden" name="role_id" value="'.$selected.'"><div class="permission-grid">';$current='';foreach($perms as $p){if($p['module']!==$current){if($current!=='')echo '</div>';echo '<div class="module">'.h($p['module']).'</div><div class="perms">';$current=$p['module'];}echo '<label><input type="checkbox" name="permissions[]" value="'.$p['id'].'" '.(isset($assigned[$p['id']])?'checked':'').'> '.h($p['label']).'</label>';}if($current!=='')echo '</div>';echo '</div><button class="btn primary" style="margin-top:14px">Save Role Permissions</button></form>';}}Ui::layoutEnd();break;

    case 'ai':
        require_permission('ai.view');Ui::layoutStart('AI / LLM','ai');Ui::pageHead('AI / LLM','Optional operational intelligence. Provider keys are configured here, never during installation.');
        $providers=$db->all('SELECT p.*,c.secret_last4 FROM llm_providers p LEFT JOIN llm_credentials c ON c.provider_id=p.id ORDER BY p.id');
        echo '<div class="grid cols-3">';foreach($providers as $p){echo '<div class="card"><div class="kicker">'.h($p['provider_type']).'</div><h2>'.h($p['name']).'</h2><div class="muted">'.h($p['base_url']).'</div><p>API key: <strong>'.($p['secret_last4']?'••••'.$p['secret_last4']:'Not configured').'</strong></p>';if(can('ai.manage_providers')){echo '<form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_llm_provider"><input type="hidden" name="provider_id" value="'.$p['id'].'"><label class="span-2">Base URL<input name="base_url" value="'.h($p['base_url']).'"></label><label class="span-2">Model<input name="default_model" value="'.h($p['default_model']).'" placeholder="Enter provider model"></label><label>Timeout<input type="number" name="timeout_seconds" value="'.h($p['timeout_seconds']).'"></label><label>Enabled<select name="enabled"><option value="0">Disabled</option><option value="1" '.($p['enabled']?'selected':'').'>Enabled</option></select></label><input type="hidden" name="is_default" value="'.h($p['is_default']).'">';if(can('ai.manage_api_keys'))echo '<label class="span-2">Replace API Key<input type="password" name="api_key" autocomplete="new-password"><small>Leave blank to keep the existing key.</small></label>';echo '<button class="btn primary span-2">Save Provider</button></form>';}echo '</div>';}echo '</div>';
        if(can('ai.use')){$aiResult=$_SESSION['ai_result']??null;unset($_SESSION['ai_result']);echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Operations Assistant</h2>';if($aiResult)echo '<div class="alert info" style="white-space:pre-wrap">'.h($aiResult).'</div>';echo '<form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="run_ai"><label>Provider<select name="provider_id">';foreach($providers as $p)if($p['enabled'])echo '<option value="'.$p['id'].'">'.h($p['name'].' / '.$p['default_model']).'</option>';echo '</select></label><label class="span-2">Ask about operations<textarea name="prompt" required placeholder="What should I produce tomorrow? Which inventory is at risk?"></textarea></label><button class="btn primary">Ask Assistant</button></form></div>';}Ui::layoutEnd();break;

    case 'reports':
        require_permission('reports.view');Ui::layoutStart('Reports','reports');Ui::pageHead('Reports','Operational reporting foundation.');
        $sales=(float)$db->scalar('SELECT COALESCE(SUM(total),0) FROM orders');$orders=(int)$db->scalar('SELECT COUNT(*) FROM orders');$avg=$orders?$sales/$orders:0;$waste=(int)$db->scalar('SELECT COALESCE(SUM(waste_quantity),0) FROM production_batch_items');echo '<div class="grid cols-4"><div class="card metric"><div class="label">Sales</div><div class="value">$'.number_format($sales,2).'</div></div><div class="card metric"><div class="label">Orders</div><div class="value">'.$orders.'</div></div><div class="card metric"><div class="label">Average Order</div><div class="value">$'.number_format($avg,2).'</div></div><div class="card metric"><div class="label">Recorded Waste Units</div><div class="value">'.$waste.'</div></div></div>';Ui::layoutEnd();break;

    case 'audit':
        require_permission('audit.view');Ui::layoutStart('Audit Log','audit');Ui::pageHead('Audit Log','Who changed what and when.');
        $rows=$db->all('SELECT a.*,u.name user_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 200');echo '<div class="table-card"><table><thead><tr><th>Date</th><th>User</th><th>Action</th><th>Entity</th><th>ID</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td>'.h($r['created_at']).'</td><td>'.h($r['user_name']).'</td><td>'.h($r['action']).'</td><td>'.h($r['entity_type']).'</td><td>'.h($r['entity_id']).'</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    default:
        http_response_code(404); echo 'Page not found.';
}
.number_format((float)$r['price'],2).'</td><td>'.Ui::statusBadge($r['is_active']?'active':'inactive').'</td><td>'.(can('products.manage')?'<a class="btn small" href="?page=products&edit='.$r['id'].'">Edit</a>':'').'</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'flavors':
        require_permission('flavors.view');Ui::layoutStart('Flavors','flavors');Ui::pageHead('Flavors','Launch menu and future seasonal flavors.',can('flavors.manage')?'<a class="btn primary" href="?page=flavors&edit=new">Add Flavor</a>':'');
        if(isset($_GET['edit'])&&can('flavors.manage')){$item=is_numeric($_GET['edit'])?$db->one('SELECT * FROM flavors WHERE id=?',[edit_id()]):null;echo '<div class="modalish"><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_flavor"><input type="hidden" name="id" value="'.h($item['id']??0).'">';Ui::formField('name','Name',$item['name']??'','text',true);Ui::formField('slug','Slug',$item['slug']??'','text',true);Ui::formField('target_weight_oz','Target Weight (oz)',$item['target_weight_oz']??3.5,'number',false,'step="0.001"');Ui::formField('image_url','Image URL',$item['image_url']??'');Ui::formField('description','Description',$item['description']??'','textarea');echo '<label>Seasonal<select name="seasonal"><option value="0">No</option><option value="1" '.(($item['seasonal']??0)?'selected':'').'>Yes</option></select></label><input type="hidden" name="is_active" value="'.h($item['is_active']??1).'"><div class="span-2 actions"><button class="btn primary">Save Flavor</button><a class="btn" href="?page=flavors">Cancel</a></div></form></div>';}else{$rows=$db->all('SELECT * FROM flavors ORDER BY name');echo '<div class="grid cols-3">';foreach($rows as $r)echo '<div class="card"><div class="kicker">'.($r['seasonal']?'Seasonal':'Core Flavor').'</div><h2>'.h($r['name']).'</h2><p class="muted">'.h($r['description']).'</p><div>Target: <strong>'.h($r['target_weight_oz']).' oz</strong></div>'.(can('flavors.manage')?'<div style="margin-top:12px"><a class="btn small" href="?page=flavors&edit='.$r['id'].'">Edit</a></div>':'').'</div>';echo '</div>';}Ui::layoutEnd();break;

    case 'recipes':
        require_permission('recipes.view');Ui::layoutStart('Recipes','recipes');Ui::pageHead('Recipes','Versioned formulas. Published historical versions are retained.');
        $rows=$db->all("SELECT r.*,f.name flavor,(SELECT MAX(version_number) FROM recipe_versions rv WHERE rv.recipe_id=r.id) latest_version FROM recipes r LEFT JOIN flavors f ON f.id=r.flavor_id ORDER BY r.recipe_type,r.name");echo '<div class="table-card"><table><thead><tr><th>Recipe</th><th>Type</th><th>Flavor</th><th>Version</th><th>Status</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td><strong>'.h($r['name']).'</strong></td><td>'.h($r['recipe_type']).'</td><td>'.h($r['flavor']).'</td><td>v'.h($r['latest_version']??1).'</td><td>'.Ui::statusBadge($r['is_active']?'active':'inactive').'</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'customers':
        require_permission('customers.view');Ui::layoutStart('Customers','customers');Ui::pageHead('Customers','Order history foundation and customer records.');
        if(can('customers.manage'))echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Add Customer</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_customer"><input type="hidden" name="id" value="0"><label>First Name<input name="first_name" required></label><label>Last Name<input name="last_name"></label><label>Email<input type="email" name="email"></label><label>Phone<input name="phone"></label><label class="span-2">Notes<textarea name="notes"></textarea></label><button class="btn primary">Add Customer</button></form></div>';
        $rows=$db->all('SELECT c.*,(SELECT COUNT(*) FROM orders o WHERE o.customer_id=c.id) orders_count,(SELECT COALESCE(SUM(total),0) FROM orders o WHERE o.customer_id=c.id) lifetime FROM customers c ORDER BY c.id DESC');echo '<div class="table-card"><table><thead><tr><th>Customer</th><th>Contact</th><th>Orders</th><th>Lifetime</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td><strong>'.h($r['first_name'].' '.$r['last_name']).'</strong></td><td>'.h($r['email']).'<div class="muted">'.h($r['phone']).'</div></td><td>'.h($r['orders_count']).'</td><td>$'.number_format((float)$r['lifetime'],2).'</td></tr>';if(!$rows)echo '<tr><td colspan="4" class="empty">No customers yet.</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'orders':
        require_permission('orders.view');Ui::layoutStart('Orders','orders');Ui::pageHead('Orders','Manual orders now; visual mix-and-match composer is the next order phase.');
        if(can('orders.create')){$customers=$db->all('SELECT id,first_name,last_name FROM customers ORDER BY first_name');$products=$db->all('SELECT * FROM products WHERE is_active=1 ORDER BY id');echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Create Order</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="create_order"><label>Customer<select name="customer_id"><option value="">Walk-in / no customer</option>';foreach($customers as $c)echo '<option value="'.$c['id'].'">'.h($c['first_name'].' '.$c['last_name']).'</option>';echo '</select></label><label>Product<select name="product_id">';foreach($products as $p)echo '<option value="'.$p['id'].'">'.h($p['name']).' — $'.number_format((float)$p['price'],2).'</option>';echo '</select></label><label>Quantity<input type="number" min="1" name="quantity" value="1"></label><label>Fulfillment<select name="fulfillment_type"><option value="pickup">Pickup</option><option value="delivery">Delivery</option><option value="shipping">Shipping</option></select></label><label>Fulfillment Time<input type="datetime-local" name="fulfillment_at"></label><label>Notes<input name="notes"></label><button class="btn primary">Create Order</button></form></div>';}
        $rows=$db->all('SELECT o.*,CONCAT(COALESCE(c.first_name,"")," ",COALESCE(c.last_name,"")) customer FROM orders o LEFT JOIN customers c ON c.id=o.customer_id ORDER BY o.id DESC');echo '<div class="table-card"><table><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Fulfillment</th><th>Total</th><th>Created</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td><strong>'.h($r['order_number']).'</strong></td><td>'.h(trim($r['customer'])?:'Walk-in').'</td><td>'.Ui::statusBadge($r['status']).'</td><td>'.h($r['fulfillment_type']).'<div class="muted">'.h($r['fulfillment_at']).'</div></td><td>$'.number_format((float)$r['total'],2).'</td><td>'.h($r['created_at']).'</td></tr>';if(!$rows)echo '<tr><td colspan="6" class="empty">No orders yet.</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'production':
        require_permission('production.view');Ui::layoutStart('Production','production');Ui::pageHead('Production','Batch planning and production status foundation.');
        if(can('production.create_batch'))echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Create Batch</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="create_batch"><label>Batch Code<input name="batch_code" placeholder="Auto-generated if blank"></label><label>Scheduled Date<input type="date" name="scheduled_for" value="'.date('Y-m-d').'"></label><label class="span-2">Notes<textarea name="notes"></textarea></label><button class="btn primary">Create Batch</button></form></div>';
        $rows=$db->all('SELECT b.*,u.name creator FROM production_batches b LEFT JOIN users u ON u.id=b.created_by ORDER BY b.id DESC');echo '<div class="table-card"><table><thead><tr><th>Batch</th><th>Scheduled</th><th>Status</th><th>Created By</th><th>Created</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td><strong>'.h($r['batch_code']).'</strong></td><td>'.h($r['scheduled_for']).'</td><td>'.Ui::statusBadge($r['status']).'</td><td>'.h($r['creator']).'</td><td>'.h($r['created_at']).'</td></tr>';if(!$rows)echo '<tr><td colspan="5" class="empty">No production batches yet.</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'team':
        require_permission('team.view');Ui::layoutStart('Team','team');Ui::pageHead('Team Members','Roles, feature permissions and production access.');
        $roles=$db->all('SELECT * FROM roles WHERE is_active=1 ORDER BY id');if(can('team.manage_members'))echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Add Team Member</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_team_member"><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Job Title<input name="job_title"></label><label>Role<select name="role_id">';foreach($roles as $r)echo '<option value="'.$r['id'].'">'.h($r['name']).'</option>';echo '</select></label><label>Hourly Rate<input type="number" step="0.01" name="hourly_rate"></label><label>Status<select name="status"><option value="active">Active</option><option value="invited">Invited</option></select></label><label class="span-2">Temporary Password<input type="password" name="password" minlength="10" required></label><button class="btn primary">Create Team Member</button></form></div>';
        $members=$db->all("SELECT u.*,GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id GROUP BY u.id ORDER BY u.name");echo '<div class="table-card"><div class="table-head"><h2>Members</h2></div><table><thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Last Login</th><th></th></tr></thead><tbody>';foreach($members as $m)echo '<tr><td><strong>'.h($m['name']).'</strong><div class="muted">'.h($m['email']).'</div></td><td>'.h($m['roles']).'</td><td>'.Ui::statusBadge($m['status']).'</td><td>'.h($m['last_login_at']).'</td><td><a class="btn small" href="?page=team&member='.$m['id'].'">Manage</a></td></tr>';echo '</tbody></table></div>';
        $selectedMember=(int)($_GET['member']??0);
        if($selectedMember){
            $member=$db->one('SELECT * FROM users WHERE id=?',[$selectedMember]);
            if($member){
                $memberRole=$db->one('SELECT r.* FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? ORDER BY r.id LIMIT 1',[$selectedMember]);
                $memberIsOwner=$memberRole&&$memberRole['slug']==='owner';
                if(can('team.manage_members')){
                    echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Manage '.h($member['name']).'</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="update_team_member"><input type="hidden" name="member_id" value="'.$selectedMember.'"><label>Name<input name="name" value="'.h($member['name']).'" required></label><label>Email<input type="email" name="email" value="'.h($member['email']).'" required></label><label>Job Title<input name="job_title" value="'.h($member['job_title']).'"></label><label>Hourly Rate<input type="number" step="0.01" name="hourly_rate" value="'.h($member['hourly_rate']??'').'"></label>';
                    if($memberIsOwner){
                        echo '<label>Role<input value="Owner" disabled><small>Owner role is protected.</small></label><input type="hidden" name="status" value="active">';
                    } else {
                        echo '<label>Role<select name="role_id">';
                        foreach($roles as $r) echo '<option value="'.$r['id'].'" '.(($memberRole['id']??0)==$r['id']?'selected':'').'>'.h($r['name']).'</option>';
                        echo '</select></label><label>Status<select name="status">';
                        foreach(['active'=>'Active','invited'=>'Invited','suspended'=>'Suspended','inactive'=>'Inactive','former'=>'Former'] as $value=>$label) echo '<option value="'.$value.'" '.($member['status']===$value?'selected':'').'>'.h($label).'</option>';
                        echo '</select></label>';
                    }
                    echo '<label class="span-2">Reset Password<input type="password" name="new_password" minlength="10"><small>Leave blank to keep the current password.</small></label><div class="span-2"><button class="btn primary">Save Team Member</button></div></form></div>';
                }

                if(can('team.manage_roles')){
                    echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Individual Permission Overrides</h2>';
                    if($memberIsOwner){
                        echo '<div class="alert info">Owner permissions are protected and always allowed.</div>';
                    } else {
                        $existing=[];foreach($db->all('SELECT permission_id,effect FROM user_permission_overrides WHERE user_id=?',[$selectedMember]) as $ov)$existing[(int)$ov['permission_id']]=$ov['effect'];
                        $allPerms=$db->all('SELECT * FROM permissions ORDER BY module,label');
                        echo '<p class="muted">Role permissions remain the default. Use an individual override only for exceptions.</p><form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_user_permission_overrides"><input type="hidden" name="member_id" value="'.$selectedMember.'"><div class="permission-grid">';
                        $module='';
                        foreach($allPerms as $p){
                            if($p['module']!==$module){if($module!=='')echo '</div>';echo '<div class="module">'.h($p['module']).'</div><div class="perms">';$module=$p['module'];}
                            $effect=$existing[(int)$p['id']]??'';
                            echo '<label style="display:flex;align-items:center;gap:6px">'.h($p['label']).' <select name="overrides['.$p['id'].']"><option value="" '.($effect===''?'selected':'').'>Role default</option><option value="allow" '.($effect==='allow'?'selected':'').'>Allow</option><option value="deny" '.($effect==='deny'?'selected':'').'>Deny</option></select></label>';
                        }
                        if($module!=='')echo '</div>';
                        echo '</div><button class="btn primary" style="margin-top:14px">Save Individual Overrides</button></form>';
                    }
                    echo '</div>';
                }
            }
        }

        if(can('team.manage_roles')){$selected=(int)($_GET['role']??($roles[1]['id']??0));$role=$db->one('SELECT * FROM roles WHERE id=?',[$selected]);$assigned=array_flip(array_column($db->all('SELECT permission_id FROM role_permissions WHERE role_id=?',[$selected]),'permission_id'));$perms=$db->all('SELECT * FROM permissions ORDER BY module,label');echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Role Permissions</h2><div class="actions" style="margin-bottom:12px">';foreach($roles as $r)echo '<a class="btn small '.($r['id']==$selected?'primary':'').'" href="?page=team&role='.$r['id'].'">'.h($r['name']).'</a>';echo '</div>';if($role&&$role['slug']==='owner')echo '<div class="alert info">Owner permissions are protected and always include every feature.</div>';elseif($role){echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_role_permissions"><input type="hidden" name="role_id" value="'.$selected.'"><div class="permission-grid">';$current='';foreach($perms as $p){if($p['module']!==$current){if($current!=='')echo '</div>';echo '<div class="module">'.h($p['module']).'</div><div class="perms">';$current=$p['module'];}echo '<label><input type="checkbox" name="permissions[]" value="'.$p['id'].'" '.(isset($assigned[$p['id']])?'checked':'').'> '.h($p['label']).'</label>';}if($current!=='')echo '</div>';echo '</div><button class="btn primary" style="margin-top:14px">Save Role Permissions</button></form>';}}Ui::layoutEnd();break;

    case 'ai':
        require_permission('ai.view');Ui::layoutStart('AI / LLM','ai');Ui::pageHead('AI / LLM','Optional operational intelligence. Provider keys are configured here, never during installation.');
        $providers=$db->all('SELECT p.*,c.secret_last4 FROM llm_providers p LEFT JOIN llm_credentials c ON c.provider_id=p.id ORDER BY p.id');
        echo '<div class="grid cols-3">';foreach($providers as $p){echo '<div class="card"><div class="kicker">'.h($p['provider_type']).'</div><h2>'.h($p['name']).'</h2><div class="muted">'.h($p['base_url']).'</div><p>API key: <strong>'.($p['secret_last4']?'••••'.$p['secret_last4']:'Not configured').'</strong></p>';if(can('ai.manage_providers')){echo '<form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_llm_provider"><input type="hidden" name="provider_id" value="'.$p['id'].'"><label class="span-2">Base URL<input name="base_url" value="'.h($p['base_url']).'"></label><label class="span-2">Model<input name="default_model" value="'.h($p['default_model']).'" placeholder="Enter provider model"></label><label>Timeout<input type="number" name="timeout_seconds" value="'.h($p['timeout_seconds']).'"></label><label>Enabled<select name="enabled"><option value="0">Disabled</option><option value="1" '.($p['enabled']?'selected':'').'>Enabled</option></select></label><input type="hidden" name="is_default" value="'.h($p['is_default']).'">';if(can('ai.manage_api_keys'))echo '<label class="span-2">Replace API Key<input type="password" name="api_key" autocomplete="new-password"><small>Leave blank to keep the existing key.</small></label>';echo '<button class="btn primary span-2">Save Provider</button></form>';}echo '</div>';}echo '</div>';
        if(can('ai.use')){$aiResult=$_SESSION['ai_result']??null;unset($_SESSION['ai_result']);echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Operations Assistant</h2>';if($aiResult)echo '<div class="alert info" style="white-space:pre-wrap">'.h($aiResult).'</div>';echo '<form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="run_ai"><label>Provider<select name="provider_id">';foreach($providers as $p)if($p['enabled'])echo '<option value="'.$p['id'].'">'.h($p['name'].' / '.$p['default_model']).'</option>';echo '</select></label><label class="span-2">Ask about operations<textarea name="prompt" required placeholder="What should I produce tomorrow? Which inventory is at risk?"></textarea></label><button class="btn primary">Ask Assistant</button></form></div>';}Ui::layoutEnd();break;

    case 'reports':
        require_permission('reports.view');Ui::layoutStart('Reports','reports');Ui::pageHead('Reports','Operational reporting foundation.');
        $sales=(float)$db->scalar('SELECT COALESCE(SUM(total),0) FROM orders');$orders=(int)$db->scalar('SELECT COUNT(*) FROM orders');$avg=$orders?$sales/$orders:0;$waste=(int)$db->scalar('SELECT COALESCE(SUM(waste_quantity),0) FROM production_batch_items');echo '<div class="grid cols-4"><div class="card metric"><div class="label">Sales</div><div class="value">$'.number_format($sales,2).'</div></div><div class="card metric"><div class="label">Orders</div><div class="value">'.$orders.'</div></div><div class="card metric"><div class="label">Average Order</div><div class="value">$'.number_format($avg,2).'</div></div><div class="card metric"><div class="label">Recorded Waste Units</div><div class="value">'.$waste.'</div></div></div>';Ui::layoutEnd();break;

    case 'audit':
        require_permission('audit.view');Ui::layoutStart('Audit Log','audit');Ui::pageHead('Audit Log','Who changed what and when.');
        $rows=$db->all('SELECT a.*,u.name user_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 200');echo '<div class="table-card"><table><thead><tr><th>Date</th><th>User</th><th>Action</th><th>Entity</th><th>ID</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td>'.h($r['created_at']).'</td><td>'.h($r['user_name']).'</td><td>'.h($r['action']).'</td><td>'.h($r['entity_type']).'</td><td>'.h($r['entity_id']).'</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    default:
        http_response_code(404); echo 'Page not found.';
}
.number_format((float)$r['lifetime'],2).'</td><td>'.(can('customers.manage')?'<a class="btn small" href="?page=customers&edit='.$r['id'].'">Edit</a>':'').'</td></tr>';if(!$rows)echo '<tr><td colspan="4" class="empty">No customers yet.</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'orders':
        require_permission('orders.view');Ui::layoutStart('Orders','orders');Ui::pageHead('Orders','Manual orders now; visual mix-and-match composer is the next order phase.');
        if(can('orders.create')){$customers=$db->all('SELECT id,first_name,last_name FROM customers ORDER BY first_name');$products=$db->all('SELECT * FROM products WHERE is_active=1 ORDER BY id');echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Create Order</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="create_order"><label>Customer<select name="customer_id"><option value="">Walk-in / no customer</option>';foreach($customers as $c)echo '<option value="'.$c['id'].'">'.h($c['first_name'].' '.$c['last_name']).'</option>';echo '</select></label><label>Product<select name="product_id">';foreach($products as $p)echo '<option value="'.$p['id'].'">'.h($p['name']).' — $'.number_format((float)$p['price'],2).'</option>';echo '</select></label><label>Quantity<input type="number" min="1" name="quantity" value="1"></label><label>Fulfillment<select name="fulfillment_type"><option value="pickup">Pickup</option><option value="delivery">Delivery</option><option value="shipping">Shipping</option></select></label><label>Fulfillment Time<input type="datetime-local" name="fulfillment_at"></label><label>Notes<input name="notes"></label><button class="btn primary">Create Order</button></form></div>';}
        $rows=$db->all('SELECT o.*,CONCAT(COALESCE(c.first_name,"")," ",COALESCE(c.last_name,"")) customer FROM orders o LEFT JOIN customers c ON c.id=o.customer_id ORDER BY o.id DESC');echo '<div class="table-card"><table><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Fulfillment</th><th>Total</th><th>Created</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td><strong>'.h($r['order_number']).'</strong></td><td>'.h(trim($r['customer'])?:'Walk-in').'</td><td>'.Ui::statusBadge($r['status']).'</td><td>'.h($r['fulfillment_type']).'<div class="muted">'.h($r['fulfillment_at']).'</div></td><td>$'.number_format((float)$r['total'],2).'</td><td>'.h($r['created_at']).'</td></tr>';if(!$rows)echo '<tr><td colspan="6" class="empty">No orders yet.</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'production':
        require_permission('production.view');Ui::layoutStart('Production','production');Ui::pageHead('Production','Batch planning and production status foundation.');
        if(can('production.create_batch'))echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Create Batch</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="create_batch"><label>Batch Code<input name="batch_code" placeholder="Auto-generated if blank"></label><label>Scheduled Date<input type="date" name="scheduled_for" value="'.date('Y-m-d').'"></label><label class="span-2">Notes<textarea name="notes"></textarea></label><button class="btn primary">Create Batch</button></form></div>';
        $rows=$db->all('SELECT b.*,u.name creator FROM production_batches b LEFT JOIN users u ON u.id=b.created_by ORDER BY b.id DESC');echo '<div class="table-card"><table><thead><tr><th>Batch</th><th>Scheduled</th><th>Status</th><th>Created By</th><th>Created</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td><strong>'.h($r['batch_code']).'</strong></td><td>'.h($r['scheduled_for']).'</td><td>'.Ui::statusBadge($r['status']).'</td><td>'.h($r['creator']).'</td><td>'.h($r['created_at']).'</td></tr>';if(!$rows)echo '<tr><td colspan="5" class="empty">No production batches yet.</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'team':
        require_permission('team.view');Ui::layoutStart('Team','team');Ui::pageHead('Team Members','Roles, feature permissions and production access.');
        $roles=$db->all('SELECT * FROM roles WHERE is_active=1 ORDER BY id');if(can('team.manage_members'))echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Add Team Member</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_team_member"><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Job Title<input name="job_title"></label><label>Role<select name="role_id">';foreach($roles as $r)echo '<option value="'.$r['id'].'">'.h($r['name']).'</option>';echo '</select></label><label>Hourly Rate<input type="number" step="0.01" name="hourly_rate"></label><label>Status<select name="status"><option value="active">Active</option><option value="invited">Invited</option></select></label><label class="span-2">Temporary Password<input type="password" name="password" minlength="10" required></label><button class="btn primary">Create Team Member</button></form></div>';
        $members=$db->all("SELECT u.*,GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id GROUP BY u.id ORDER BY u.name");echo '<div class="table-card"><div class="table-head"><h2>Members</h2></div><table><thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Last Login</th><th></th></tr></thead><tbody>';foreach($members as $m)echo '<tr><td><strong>'.h($m['name']).'</strong><div class="muted">'.h($m['email']).'</div></td><td>'.h($m['roles']).'</td><td>'.Ui::statusBadge($m['status']).'</td><td>'.h($m['last_login_at']).'</td><td><a class="btn small" href="?page=team&member='.$m['id'].'">Manage</a></td></tr>';echo '</tbody></table></div>';
        $selectedMember=(int)($_GET['member']??0);
        if($selectedMember){
            $member=$db->one('SELECT * FROM users WHERE id=?',[$selectedMember]);
            if($member){
                $memberRole=$db->one('SELECT r.* FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? ORDER BY r.id LIMIT 1',[$selectedMember]);
                $memberIsOwner=$memberRole&&$memberRole['slug']==='owner';
                if(can('team.manage_members')){
                    echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Manage '.h($member['name']).'</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="update_team_member"><input type="hidden" name="member_id" value="'.$selectedMember.'"><label>Name<input name="name" value="'.h($member['name']).'" required></label><label>Email<input type="email" name="email" value="'.h($member['email']).'" required></label><label>Job Title<input name="job_title" value="'.h($member['job_title']).'"></label><label>Hourly Rate<input type="number" step="0.01" name="hourly_rate" value="'.h($member['hourly_rate']??'').'"></label>';
                    if($memberIsOwner){
                        echo '<label>Role<input value="Owner" disabled><small>Owner role is protected.</small></label><input type="hidden" name="status" value="active">';
                    } else {
                        echo '<label>Role<select name="role_id">';
                        foreach($roles as $r) echo '<option value="'.$r['id'].'" '.(($memberRole['id']??0)==$r['id']?'selected':'').'>'.h($r['name']).'</option>';
                        echo '</select></label><label>Status<select name="status">';
                        foreach(['active'=>'Active','invited'=>'Invited','suspended'=>'Suspended','inactive'=>'Inactive','former'=>'Former'] as $value=>$label) echo '<option value="'.$value.'" '.($member['status']===$value?'selected':'').'>'.h($label).'</option>';
                        echo '</select></label>';
                    }
                    echo '<label class="span-2">Reset Password<input type="password" name="new_password" minlength="10"><small>Leave blank to keep the current password.</small></label><div class="span-2"><button class="btn primary">Save Team Member</button></div></form></div>';
                }

                if(can('team.manage_roles')){
                    echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Individual Permission Overrides</h2>';
                    if($memberIsOwner){
                        echo '<div class="alert info">Owner permissions are protected and always allowed.</div>';
                    } else {
                        $existing=[];foreach($db->all('SELECT permission_id,effect FROM user_permission_overrides WHERE user_id=?',[$selectedMember]) as $ov)$existing[(int)$ov['permission_id']]=$ov['effect'];
                        $allPerms=$db->all('SELECT * FROM permissions ORDER BY module,label');
                        echo '<p class="muted">Role permissions remain the default. Use an individual override only for exceptions.</p><form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_user_permission_overrides"><input type="hidden" name="member_id" value="'.$selectedMember.'"><div class="permission-grid">';
                        $module='';
                        foreach($allPerms as $p){
                            if($p['module']!==$module){if($module!=='')echo '</div>';echo '<div class="module">'.h($p['module']).'</div><div class="perms">';$module=$p['module'];}
                            $effect=$existing[(int)$p['id']]??'';
                            echo '<label style="display:flex;align-items:center;gap:6px">'.h($p['label']).' <select name="overrides['.$p['id'].']"><option value="" '.($effect===''?'selected':'').'>Role default</option><option value="allow" '.($effect==='allow'?'selected':'').'>Allow</option><option value="deny" '.($effect==='deny'?'selected':'').'>Deny</option></select></label>';
                        }
                        if($module!=='')echo '</div>';
                        echo '</div><button class="btn primary" style="margin-top:14px">Save Individual Overrides</button></form>';
                    }
                    echo '</div>';
                }
            }
        }

        if(can('team.manage_roles')){$selected=(int)($_GET['role']??($roles[1]['id']??0));$role=$db->one('SELECT * FROM roles WHERE id=?',[$selected]);$assigned=array_flip(array_column($db->all('SELECT permission_id FROM role_permissions WHERE role_id=?',[$selected]),'permission_id'));$perms=$db->all('SELECT * FROM permissions ORDER BY module,label');echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Role Permissions</h2><div class="actions" style="margin-bottom:12px">';foreach($roles as $r)echo '<a class="btn small '.($r['id']==$selected?'primary':'').'" href="?page=team&role='.$r['id'].'">'.h($r['name']).'</a>';echo '</div>';if($role&&$role['slug']==='owner')echo '<div class="alert info">Owner permissions are protected and always include every feature.</div>';elseif($role){echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_role_permissions"><input type="hidden" name="role_id" value="'.$selected.'"><div class="permission-grid">';$current='';foreach($perms as $p){if($p['module']!==$current){if($current!=='')echo '</div>';echo '<div class="module">'.h($p['module']).'</div><div class="perms">';$current=$p['module'];}echo '<label><input type="checkbox" name="permissions[]" value="'.$p['id'].'" '.(isset($assigned[$p['id']])?'checked':'').'> '.h($p['label']).'</label>';}if($current!=='')echo '</div>';echo '</div><button class="btn primary" style="margin-top:14px">Save Role Permissions</button></form>';}}Ui::layoutEnd();break;

    case 'ai':
        require_permission('ai.view');Ui::layoutStart('AI / LLM','ai');Ui::pageHead('AI / LLM','Optional operational intelligence. Provider keys are configured here, never during installation.');
        $providers=$db->all('SELECT p.*,c.secret_last4 FROM llm_providers p LEFT JOIN llm_credentials c ON c.provider_id=p.id ORDER BY p.id');
        echo '<div class="grid cols-3">';foreach($providers as $p){echo '<div class="card"><div class="kicker">'.h($p['provider_type']).'</div><h2>'.h($p['name']).'</h2><div class="muted">'.h($p['base_url']).'</div><p>API key: <strong>'.($p['secret_last4']?'••••'.$p['secret_last4']:'Not configured').'</strong></p>';if(can('ai.manage_providers')){echo '<form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_llm_provider"><input type="hidden" name="provider_id" value="'.$p['id'].'"><label class="span-2">Base URL<input name="base_url" value="'.h($p['base_url']).'"></label><label class="span-2">Model<input name="default_model" value="'.h($p['default_model']).'" placeholder="Enter provider model"></label><label>Timeout<input type="number" name="timeout_seconds" value="'.h($p['timeout_seconds']).'"></label><label>Enabled<select name="enabled"><option value="0">Disabled</option><option value="1" '.($p['enabled']?'selected':'').'>Enabled</option></select></label><input type="hidden" name="is_default" value="'.h($p['is_default']).'">';if(can('ai.manage_api_keys'))echo '<label class="span-2">Replace API Key<input type="password" name="api_key" autocomplete="new-password"><small>Leave blank to keep the existing key.</small></label>';echo '<button class="btn primary span-2">Save Provider</button></form>';}echo '</div>';}echo '</div>';
        if(can('ai.use')){$aiResult=$_SESSION['ai_result']??null;unset($_SESSION['ai_result']);echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Operations Assistant</h2>';if($aiResult)echo '<div class="alert info" style="white-space:pre-wrap">'.h($aiResult).'</div>';echo '<form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="run_ai"><label>Provider<select name="provider_id">';foreach($providers as $p)if($p['enabled'])echo '<option value="'.$p['id'].'">'.h($p['name'].' / '.$p['default_model']).'</option>';echo '</select></label><label class="span-2">Ask about operations<textarea name="prompt" required placeholder="What should I produce tomorrow? Which inventory is at risk?"></textarea></label><button class="btn primary">Ask Assistant</button></form></div>';}Ui::layoutEnd();break;

    case 'reports':
        require_permission('reports.view');Ui::layoutStart('Reports','reports');Ui::pageHead('Reports','Operational reporting foundation.');
        $sales=(float)$db->scalar('SELECT COALESCE(SUM(total),0) FROM orders');$orders=(int)$db->scalar('SELECT COUNT(*) FROM orders');$avg=$orders?$sales/$orders:0;$waste=(int)$db->scalar('SELECT COALESCE(SUM(waste_quantity),0) FROM production_batch_items');echo '<div class="grid cols-4"><div class="card metric"><div class="label">Sales</div><div class="value">$'.number_format($sales,2).'</div></div><div class="card metric"><div class="label">Orders</div><div class="value">'.$orders.'</div></div><div class="card metric"><div class="label">Average Order</div><div class="value">$'.number_format($avg,2).'</div></div><div class="card metric"><div class="label">Recorded Waste Units</div><div class="value">'.$waste.'</div></div></div>';Ui::layoutEnd();break;

    case 'audit':
        require_permission('audit.view');Ui::layoutStart('Audit Log','audit');Ui::pageHead('Audit Log','Who changed what and when.');
        $rows=$db->all('SELECT a.*,u.name user_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 200');echo '<div class="table-card"><table><thead><tr><th>Date</th><th>User</th><th>Action</th><th>Entity</th><th>ID</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td>'.h($r['created_at']).'</td><td>'.h($r['user_name']).'</td><td>'.h($r['action']).'</td><td>'.h($r['entity_type']).'</td><td>'.h($r['entity_id']).'</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    default:
        http_response_code(404); echo 'Page not found.';
}
.number_format((float)$r['price'],2).'</td><td>'.Ui::statusBadge($r['is_active']?'active':'inactive').'</td><td>'.(can('products.manage')?'<a class="btn small" href="?page=products&edit='.$r['id'].'">Edit</a>':'').'</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'flavors':
        require_permission('flavors.view');Ui::layoutStart('Flavors','flavors');Ui::pageHead('Flavors','Launch menu and future seasonal flavors.',can('flavors.manage')?'<a class="btn primary" href="?page=flavors&edit=new">Add Flavor</a>':'');
        if(isset($_GET['edit'])&&can('flavors.manage')){$item=is_numeric($_GET['edit'])?$db->one('SELECT * FROM flavors WHERE id=?',[edit_id()]):null;echo '<div class="modalish"><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_flavor"><input type="hidden" name="id" value="'.h($item['id']??0).'">';Ui::formField('name','Name',$item['name']??'','text',true);Ui::formField('slug','Slug',$item['slug']??'','text',true);Ui::formField('target_weight_oz','Target Weight (oz)',$item['target_weight_oz']??3.5,'number',false,'step="0.001"');Ui::formField('image_url','Image URL',$item['image_url']??'');Ui::formField('description','Description',$item['description']??'','textarea');echo '<label>Seasonal<select name="seasonal"><option value="0">No</option><option value="1" '.(($item['seasonal']??0)?'selected':'').'>Yes</option></select></label><input type="hidden" name="is_active" value="'.h($item['is_active']??1).'"><div class="span-2 actions"><button class="btn primary">Save Flavor</button><a class="btn" href="?page=flavors">Cancel</a></div></form></div>';}else{$rows=$db->all('SELECT * FROM flavors ORDER BY name');echo '<div class="grid cols-3">';foreach($rows as $r)echo '<div class="card"><div class="kicker">'.($r['seasonal']?'Seasonal':'Core Flavor').'</div><h2>'.h($r['name']).'</h2><p class="muted">'.h($r['description']).'</p><div>Target: <strong>'.h($r['target_weight_oz']).' oz</strong></div>'.(can('flavors.manage')?'<div style="margin-top:12px"><a class="btn small" href="?page=flavors&edit='.$r['id'].'">Edit</a></div>':'').'</div>';echo '</div>';}Ui::layoutEnd();break;

    case 'recipes':
        require_permission('recipes.view');Ui::layoutStart('Recipes','recipes');Ui::pageHead('Recipes','Versioned formulas. Published historical versions are retained.');
        $rows=$db->all("SELECT r.*,f.name flavor,(SELECT MAX(version_number) FROM recipe_versions rv WHERE rv.recipe_id=r.id) latest_version FROM recipes r LEFT JOIN flavors f ON f.id=r.flavor_id ORDER BY r.recipe_type,r.name");echo '<div class="table-card"><table><thead><tr><th>Recipe</th><th>Type</th><th>Flavor</th><th>Version</th><th>Status</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td><strong>'.h($r['name']).'</strong></td><td>'.h($r['recipe_type']).'</td><td>'.h($r['flavor']).'</td><td>v'.h($r['latest_version']??1).'</td><td>'.Ui::statusBadge($r['is_active']?'active':'inactive').'</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'customers':
        require_permission('customers.view');Ui::layoutStart('Customers','customers');Ui::pageHead('Customers','Order history foundation and customer records.');
        if(can('customers.manage'))echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Add Customer</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_customer"><input type="hidden" name="id" value="0"><label>First Name<input name="first_name" required></label><label>Last Name<input name="last_name"></label><label>Email<input type="email" name="email"></label><label>Phone<input name="phone"></label><label class="span-2">Notes<textarea name="notes"></textarea></label><button class="btn primary">Add Customer</button></form></div>';
        $rows=$db->all('SELECT c.*,(SELECT COUNT(*) FROM orders o WHERE o.customer_id=c.id) orders_count,(SELECT COALESCE(SUM(total),0) FROM orders o WHERE o.customer_id=c.id) lifetime FROM customers c ORDER BY c.id DESC');echo '<div class="table-card"><table><thead><tr><th>Customer</th><th>Contact</th><th>Orders</th><th>Lifetime</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td><strong>'.h($r['first_name'].' '.$r['last_name']).'</strong></td><td>'.h($r['email']).'<div class="muted">'.h($r['phone']).'</div></td><td>'.h($r['orders_count']).'</td><td>$'.number_format((float)$r['lifetime'],2).'</td></tr>';if(!$rows)echo '<tr><td colspan="4" class="empty">No customers yet.</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'orders':
        require_permission('orders.view');Ui::layoutStart('Orders','orders');Ui::pageHead('Orders','Manual orders now; visual mix-and-match composer is the next order phase.');
        if(can('orders.create')){$customers=$db->all('SELECT id,first_name,last_name FROM customers ORDER BY first_name');$products=$db->all('SELECT * FROM products WHERE is_active=1 ORDER BY id');echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Create Order</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="create_order"><label>Customer<select name="customer_id"><option value="">Walk-in / no customer</option>';foreach($customers as $c)echo '<option value="'.$c['id'].'">'.h($c['first_name'].' '.$c['last_name']).'</option>';echo '</select></label><label>Product<select name="product_id">';foreach($products as $p)echo '<option value="'.$p['id'].'">'.h($p['name']).' — $'.number_format((float)$p['price'],2).'</option>';echo '</select></label><label>Quantity<input type="number" min="1" name="quantity" value="1"></label><label>Fulfillment<select name="fulfillment_type"><option value="pickup">Pickup</option><option value="delivery">Delivery</option><option value="shipping">Shipping</option></select></label><label>Fulfillment Time<input type="datetime-local" name="fulfillment_at"></label><label>Notes<input name="notes"></label><button class="btn primary">Create Order</button></form></div>';}
        $rows=$db->all('SELECT o.*,CONCAT(COALESCE(c.first_name,"")," ",COALESCE(c.last_name,"")) customer FROM orders o LEFT JOIN customers c ON c.id=o.customer_id ORDER BY o.id DESC');echo '<div class="table-card"><table><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Fulfillment</th><th>Total</th><th>Created</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td><strong>'.h($r['order_number']).'</strong></td><td>'.h(trim($r['customer'])?:'Walk-in').'</td><td>'.Ui::statusBadge($r['status']).'</td><td>'.h($r['fulfillment_type']).'<div class="muted">'.h($r['fulfillment_at']).'</div></td><td>$'.number_format((float)$r['total'],2).'</td><td>'.h($r['created_at']).'</td></tr>';if(!$rows)echo '<tr><td colspan="6" class="empty">No orders yet.</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'production':
        require_permission('production.view');Ui::layoutStart('Production','production');Ui::pageHead('Production','Batch planning and production status foundation.');
        if(can('production.create_batch'))echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Create Batch</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="create_batch"><label>Batch Code<input name="batch_code" placeholder="Auto-generated if blank"></label><label>Scheduled Date<input type="date" name="scheduled_for" value="'.date('Y-m-d').'"></label><label class="span-2">Notes<textarea name="notes"></textarea></label><button class="btn primary">Create Batch</button></form></div>';
        $rows=$db->all('SELECT b.*,u.name creator FROM production_batches b LEFT JOIN users u ON u.id=b.created_by ORDER BY b.id DESC');echo '<div class="table-card"><table><thead><tr><th>Batch</th><th>Scheduled</th><th>Status</th><th>Created By</th><th>Created</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td><strong>'.h($r['batch_code']).'</strong></td><td>'.h($r['scheduled_for']).'</td><td>'.Ui::statusBadge($r['status']).'</td><td>'.h($r['creator']).'</td><td>'.h($r['created_at']).'</td></tr>';if(!$rows)echo '<tr><td colspan="5" class="empty">No production batches yet.</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    case 'team':
        require_permission('team.view');Ui::layoutStart('Team','team');Ui::pageHead('Team Members','Roles, feature permissions and production access.');
        $roles=$db->all('SELECT * FROM roles WHERE is_active=1 ORDER BY id');if(can('team.manage_members'))echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Add Team Member</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_team_member"><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Job Title<input name="job_title"></label><label>Role<select name="role_id">';foreach($roles as $r)echo '<option value="'.$r['id'].'">'.h($r['name']).'</option>';echo '</select></label><label>Hourly Rate<input type="number" step="0.01" name="hourly_rate"></label><label>Status<select name="status"><option value="active">Active</option><option value="invited">Invited</option></select></label><label class="span-2">Temporary Password<input type="password" name="password" minlength="10" required></label><button class="btn primary">Create Team Member</button></form></div>';
        $members=$db->all("SELECT u.*,GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id GROUP BY u.id ORDER BY u.name");echo '<div class="table-card"><div class="table-head"><h2>Members</h2></div><table><thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Last Login</th><th></th></tr></thead><tbody>';foreach($members as $m)echo '<tr><td><strong>'.h($m['name']).'</strong><div class="muted">'.h($m['email']).'</div></td><td>'.h($m['roles']).'</td><td>'.Ui::statusBadge($m['status']).'</td><td>'.h($m['last_login_at']).'</td><td><a class="btn small" href="?page=team&member='.$m['id'].'">Manage</a></td></tr>';echo '</tbody></table></div>';
        $selectedMember=(int)($_GET['member']??0);
        if($selectedMember){
            $member=$db->one('SELECT * FROM users WHERE id=?',[$selectedMember]);
            if($member){
                $memberRole=$db->one('SELECT r.* FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? ORDER BY r.id LIMIT 1',[$selectedMember]);
                $memberIsOwner=$memberRole&&$memberRole['slug']==='owner';
                if(can('team.manage_members')){
                    echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Manage '.h($member['name']).'</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="update_team_member"><input type="hidden" name="member_id" value="'.$selectedMember.'"><label>Name<input name="name" value="'.h($member['name']).'" required></label><label>Email<input type="email" name="email" value="'.h($member['email']).'" required></label><label>Job Title<input name="job_title" value="'.h($member['job_title']).'"></label><label>Hourly Rate<input type="number" step="0.01" name="hourly_rate" value="'.h($member['hourly_rate']??'').'"></label>';
                    if($memberIsOwner){
                        echo '<label>Role<input value="Owner" disabled><small>Owner role is protected.</small></label><input type="hidden" name="status" value="active">';
                    } else {
                        echo '<label>Role<select name="role_id">';
                        foreach($roles as $r) echo '<option value="'.$r['id'].'" '.(($memberRole['id']??0)==$r['id']?'selected':'').'>'.h($r['name']).'</option>';
                        echo '</select></label><label>Status<select name="status">';
                        foreach(['active'=>'Active','invited'=>'Invited','suspended'=>'Suspended','inactive'=>'Inactive','former'=>'Former'] as $value=>$label) echo '<option value="'.$value.'" '.($member['status']===$value?'selected':'').'>'.h($label).'</option>';
                        echo '</select></label>';
                    }
                    echo '<label class="span-2">Reset Password<input type="password" name="new_password" minlength="10"><small>Leave blank to keep the current password.</small></label><div class="span-2"><button class="btn primary">Save Team Member</button></div></form></div>';
                }

                if(can('team.manage_roles')){
                    echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Individual Permission Overrides</h2>';
                    if($memberIsOwner){
                        echo '<div class="alert info">Owner permissions are protected and always allowed.</div>';
                    } else {
                        $existing=[];foreach($db->all('SELECT permission_id,effect FROM user_permission_overrides WHERE user_id=?',[$selectedMember]) as $ov)$existing[(int)$ov['permission_id']]=$ov['effect'];
                        $allPerms=$db->all('SELECT * FROM permissions ORDER BY module,label');
                        echo '<p class="muted">Role permissions remain the default. Use an individual override only for exceptions.</p><form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_user_permission_overrides"><input type="hidden" name="member_id" value="'.$selectedMember.'"><div class="permission-grid">';
                        $module='';
                        foreach($allPerms as $p){
                            if($p['module']!==$module){if($module!=='')echo '</div>';echo '<div class="module">'.h($p['module']).'</div><div class="perms">';$module=$p['module'];}
                            $effect=$existing[(int)$p['id']]??'';
                            echo '<label style="display:flex;align-items:center;gap:6px">'.h($p['label']).' <select name="overrides['.$p['id'].']"><option value="" '.($effect===''?'selected':'').'>Role default</option><option value="allow" '.($effect==='allow'?'selected':'').'>Allow</option><option value="deny" '.($effect==='deny'?'selected':'').'>Deny</option></select></label>';
                        }
                        if($module!=='')echo '</div>';
                        echo '</div><button class="btn primary" style="margin-top:14px">Save Individual Overrides</button></form>';
                    }
                    echo '</div>';
                }
            }
        }

        if(can('team.manage_roles')){$selected=(int)($_GET['role']??($roles[1]['id']??0));$role=$db->one('SELECT * FROM roles WHERE id=?',[$selected]);$assigned=array_flip(array_column($db->all('SELECT permission_id FROM role_permissions WHERE role_id=?',[$selected]),'permission_id'));$perms=$db->all('SELECT * FROM permissions ORDER BY module,label');echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Role Permissions</h2><div class="actions" style="margin-bottom:12px">';foreach($roles as $r)echo '<a class="btn small '.($r['id']==$selected?'primary':'').'" href="?page=team&role='.$r['id'].'">'.h($r['name']).'</a>';echo '</div>';if($role&&$role['slug']==='owner')echo '<div class="alert info">Owner permissions are protected and always include every feature.</div>';elseif($role){echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_role_permissions"><input type="hidden" name="role_id" value="'.$selected.'"><div class="permission-grid">';$current='';foreach($perms as $p){if($p['module']!==$current){if($current!=='')echo '</div>';echo '<div class="module">'.h($p['module']).'</div><div class="perms">';$current=$p['module'];}echo '<label><input type="checkbox" name="permissions[]" value="'.$p['id'].'" '.(isset($assigned[$p['id']])?'checked':'').'> '.h($p['label']).'</label>';}if($current!=='')echo '</div>';echo '</div><button class="btn primary" style="margin-top:14px">Save Role Permissions</button></form>';}}Ui::layoutEnd();break;

    case 'ai':
        require_permission('ai.view');Ui::layoutStart('AI / LLM','ai');Ui::pageHead('AI / LLM','Optional operational intelligence. Provider keys are configured here, never during installation.');
        $providers=$db->all('SELECT p.*,c.secret_last4 FROM llm_providers p LEFT JOIN llm_credentials c ON c.provider_id=p.id ORDER BY p.id');
        echo '<div class="grid cols-3">';foreach($providers as $p){echo '<div class="card"><div class="kicker">'.h($p['provider_type']).'</div><h2>'.h($p['name']).'</h2><div class="muted">'.h($p['base_url']).'</div><p>API key: <strong>'.($p['secret_last4']?'••••'.$p['secret_last4']:'Not configured').'</strong></p>';if(can('ai.manage_providers')){echo '<form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_llm_provider"><input type="hidden" name="provider_id" value="'.$p['id'].'"><label class="span-2">Base URL<input name="base_url" value="'.h($p['base_url']).'"></label><label class="span-2">Model<input name="default_model" value="'.h($p['default_model']).'" placeholder="Enter provider model"></label><label>Timeout<input type="number" name="timeout_seconds" value="'.h($p['timeout_seconds']).'"></label><label>Enabled<select name="enabled"><option value="0">Disabled</option><option value="1" '.($p['enabled']?'selected':'').'>Enabled</option></select></label><input type="hidden" name="is_default" value="'.h($p['is_default']).'">';if(can('ai.manage_api_keys'))echo '<label class="span-2">Replace API Key<input type="password" name="api_key" autocomplete="new-password"><small>Leave blank to keep the existing key.</small></label>';echo '<button class="btn primary span-2">Save Provider</button></form>';}echo '</div>';}echo '</div>';
        if(can('ai.use')){$aiResult=$_SESSION['ai_result']??null;unset($_SESSION['ai_result']);echo '<div class="card" style="margin-top:18px"><h2 class="section-title">Operations Assistant</h2>';if($aiResult)echo '<div class="alert info" style="white-space:pre-wrap">'.h($aiResult).'</div>';echo '<form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="run_ai"><label>Provider<select name="provider_id">';foreach($providers as $p)if($p['enabled'])echo '<option value="'.$p['id'].'">'.h($p['name'].' / '.$p['default_model']).'</option>';echo '</select></label><label class="span-2">Ask about operations<textarea name="prompt" required placeholder="What should I produce tomorrow? Which inventory is at risk?"></textarea></label><button class="btn primary">Ask Assistant</button></form></div>';}Ui::layoutEnd();break;

    case 'reports':
        require_permission('reports.view');Ui::layoutStart('Reports','reports');Ui::pageHead('Reports','Operational reporting foundation.');
        $sales=(float)$db->scalar('SELECT COALESCE(SUM(total),0) FROM orders');$orders=(int)$db->scalar('SELECT COUNT(*) FROM orders');$avg=$orders?$sales/$orders:0;$waste=(int)$db->scalar('SELECT COALESCE(SUM(waste_quantity),0) FROM production_batch_items');echo '<div class="grid cols-4"><div class="card metric"><div class="label">Sales</div><div class="value">$'.number_format($sales,2).'</div></div><div class="card metric"><div class="label">Orders</div><div class="value">'.$orders.'</div></div><div class="card metric"><div class="label">Average Order</div><div class="value">$'.number_format($avg,2).'</div></div><div class="card metric"><div class="label">Recorded Waste Units</div><div class="value">'.$waste.'</div></div></div>';Ui::layoutEnd();break;

    case 'audit':
        require_permission('audit.view');Ui::layoutStart('Audit Log','audit');Ui::pageHead('Audit Log','Who changed what and when.');
        $rows=$db->all('SELECT a.*,u.name user_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 200');echo '<div class="table-card"><table><thead><tr><th>Date</th><th>User</th><th>Action</th><th>Entity</th><th>ID</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td>'.h($r['created_at']).'</td><td>'.h($r['user_name']).'</td><td>'.h($r['action']).'</td><td>'.h($r['entity_type']).'</td><td>'.h($r['entity_id']).'</td></tr>';echo '</tbody></table></div>';Ui::layoutEnd();break;

    default:
        http_response_code(404); echo 'Page not found.';
}
