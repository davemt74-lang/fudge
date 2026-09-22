<?php
function handle_phase2b_page(string $page): never
{
    global $db,$costing,$recipeManager;
    $uid = (int)user()['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Security::validateCsrf();
        $action = $_POST['form_action'] ?? '';

        try {
            switch ($action) {
                case 'create_recipe':
                    require_permission('recipes.edit');
                    $id = $recipeManager->createRecipe(
                        trim($_POST['name'] ?? ''),
                        $_POST['recipe_type'] ?? 'finished',
                        (int)($_POST['flavor_id'] ?? 0) ?: null,
                        $uid
                    );
                    audit('recipe.created','recipe',$id,null,null);
                    flash('success','Recipe created with draft version 1.');
                    redirect('?page=recipes&recipe='.$id);

                case 'update_recipe':
                    require_permission('recipes.edit');
                    $recipeId=(int)($_POST['recipe_id']??0);
                    $before=$db->one('SELECT * FROM recipes WHERE id=?',[$recipeId]);
                    if(!$before) throw new RuntimeException('Recipe not found.');
                    $recipeManager->updateRecipe($recipeId,trim($_POST['name']??''),(int)($_POST['is_active']??1)===1);
                    audit('recipe.updated','recipe',$recipeId,$before,['name'=>trim($_POST['name']??''),'is_active'=>(int)($_POST['is_active']??1)]);
                    flash('success','Recipe updated.');
                    redirect('?page=recipes&recipe='.$recipeId);

                case 'create_recipe_draft':
                    require_permission('recipes.edit');
                    $recipeId=(int)($_POST['recipe_id']??0);
                    $versionId=$recipeManager->createDraftVersion($recipeId,$uid,true);
                    audit('recipe.draft_created','recipe_version',$versionId,null,['recipe_id'=>$recipeId]);
                    flash('success','Draft recipe version ready for editing.');
                    redirect('?page=recipes&recipe='.$recipeId.'&version='.$versionId);

                case 'save_recipe_meta':
                    require_permission('recipes.edit');
                    $recipeId=(int)($_POST['recipe_id']??0);
                    $versionId=(int)($_POST['version_id']??0);
                    $recipeManager->saveVersionMeta(
                        $versionId,
                        (float)($_POST['yield_quantity']??0),
                        trim($_POST['yield_unit']??'each'),
                        trim($_POST['notes']??'') ?: null
                    );
                    audit('recipe.version_meta_updated','recipe_version',$versionId,null,null);
                    flash('success','Draft yield and notes saved.');
                    redirect('?page=recipes&recipe='.$recipeId.'&version='.$versionId);

                case 'add_recipe_component':
                    require_permission('recipes.edit');
                    $recipeId=(int)($_POST['recipe_id']??0);
                    $versionId=(int)($_POST['version_id']??0);
                    $ref=trim($_POST['component_ref']??'');
                    if(!str_contains($ref,':')) throw new RuntimeException('Select a recipe component.');
                    [$type,$rawId]=explode(':',$ref,2);
                    $itemId=$recipeManager->addComponent(
                        $versionId,$type,(int)$rawId,
                        (float)($_POST['quantity']??0),
                        trim($_POST['unit']??'each')
                    );
                    audit('recipe.component_added','recipe_item',$itemId,null,['recipe_version_id'=>$versionId]);
                    flash('success','Recipe component added.');
                    redirect('?page=recipes&recipe='.$recipeId.'&version='.$versionId);

                case 'update_recipe_component':
                    require_permission('recipes.edit');
                    $recipeId=(int)($_POST['recipe_id']??0);
                    $versionId=(int)($_POST['version_id']??0);
                    $itemId=(int)($_POST['recipe_item_id']??0);
                    $recipeManager->updateComponent(
                        $versionId,$itemId,
                        (float)($_POST['quantity']??0),
                        trim($_POST['unit']??'each')
                    );
                    audit('recipe.component_updated','recipe_item',$itemId,null,['recipe_version_id'=>$versionId]);
                    flash('success','Recipe component updated.');
                    redirect('?page=recipes&recipe='.$recipeId.'&version='.$versionId);

                case 'remove_recipe_component':
                    require_permission('recipes.edit');
                    $recipeId=(int)($_POST['recipe_id']??0);
                    $versionId=(int)($_POST['version_id']??0);
                    $itemId=(int)($_POST['recipe_item_id']??0);
                    $recipeManager->removeComponent($versionId,$itemId);
                    audit('recipe.component_removed','recipe_item',$itemId,null,['recipe_version_id'=>$versionId]);
                    flash('success','Recipe component removed.');
                    redirect('?page=recipes&recipe='.$recipeId.'&version='.$versionId);

                case 'publish_recipe':
                    require_permission('recipes.publish');
                    $recipeId=(int)($_POST['recipe_id']??0);
                    $versionId=(int)($_POST['version_id']??0);
                    $cost=$recipeManager->publish($versionId);
                    $ref='recipe_publish:'.$versionId;
                    try {$costing->captureSnapshots($uid,'recipe_publish',$ref);} catch(Throwable $ignored) {}
                    audit('recipe.version_published','recipe_version',$versionId,null,['unit_cost'=>$cost['unit_cost'],'complete'=>$cost['complete']]);
                    flash('success','Recipe version published. Historical versions were preserved.');
                    redirect('?page=recipes&recipe='.$recipeId);

                case 'capture_cost_snapshot':
                    require_permission('costing.snapshot');
                    $ref=Security::reference('COST');
                    $result=$costing->captureSnapshots($uid,'manual',$ref);
                    audit('costing.snapshot_captured','cost_snapshot',null,null,$result+['reference'=>$ref]);
                    flash('success','Captured '.$result['recipes'].' recipe and '.$result['products'].' product cost snapshots.');
                    redirect('?page=costing');

                case 'save_product_packaging':
                    require_permission('products.manage');
                    $productId=(int)($_POST['product_id']??0);
                    $packagingId=(int)($_POST['packaging_item_id']??0);
                    $qty=(float)($_POST['quantity']??0);
                    $unit=trim($_POST['unit']??'each');
                    if(!$productId||!$packagingId||$qty<=0) throw new RuntimeException('Product, packaging item and a quantity greater than zero are required.');
                    if((int)$db->scalar('SELECT COUNT(*) FROM units WHERE symbol=?',[$unit])<1) throw new RuntimeException('Select a valid packaging unit.');
                    $impactRef=Security::reference('BOM');
                    try{$costing->captureSnapshots($uid,'product_packaging_before',$impactRef);}catch(Throwable $ignored){}
                    $db->exec(
                        'INSERT INTO product_packaging_components(product_id,packaging_item_id,quantity,unit)
                         VALUES(?,?,?,?)
                         ON DUPLICATE KEY UPDATE quantity=VALUES(quantity),unit=VALUES(unit)',
                        [$productId,$packagingId,$qty,$unit]
                    );
                    try{$costing->captureSnapshots($uid,'product_packaging_after',$impactRef);}catch(Throwable $ignored){}
                    audit('product.packaging_saved','product',$productId,null,['packaging_item_id'=>$packagingId,'quantity'=>$qty,'unit'=>$unit,'cost_impact_reference'=>$impactRef]);
                    flash('success','Product packaging BOM updated.');
                    redirect('?page=costing&product='.$productId);

                case 'remove_product_packaging':
                    require_permission('products.manage');
                    $id=(int)($_POST['component_id']??0);
                    $component=$db->one('SELECT * FROM product_packaging_components WHERE id=?',[$id]);
                    if(!$component) throw new RuntimeException('Packaging component not found.');
                    $impactRef=Security::reference('BOM');
                    try{$costing->captureSnapshots($uid,'product_packaging_before',$impactRef);}catch(Throwable $ignored){}
                    $db->exec('DELETE FROM product_packaging_components WHERE id=?',[$id]);
                    try{$costing->captureSnapshots($uid,'product_packaging_after',$impactRef);}catch(Throwable $ignored){}
                    audit('product.packaging_removed','product',(int)$component['product_id'],$component,['cost_impact_reference'=>$impactRef]);
                    flash('success','Product packaging component removed.');
                    redirect('?page=costing&product='.$component['product_id']);
            }
        } catch (Throwable $e) {
            flash('danger',$e->getMessage());
            $suffix='';
            if(isset($_POST['recipe_id'])) $suffix='&recipe='.(int)$_POST['recipe_id'];
            redirect('?page='.urlencode($page).$suffix);
        }
    }

    if ($page === 'recipes') {
        require_permission('recipes.view');
        Ui::layoutStart('Recipes','recipes');
        Ui::pageHead('Recipes & BOMs','Versioned formulas with nested recipe components and live cost previews.','<a class="btn" href="?page=costing">Costing Dashboard</a>');

        if (can('recipes.edit')) {
            $flavors=$db->all('SELECT id,name FROM flavors WHERE is_active=1 ORDER BY name');
            echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Create Recipe</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="create_recipe"><label>Name<input name="name" required></label><label>Type<select name="recipe_type"><option value="base">Base</option><option value="component">Component</option><option value="finished">Finished Flavor</option></select></label><label>Flavor (finished only)<select name="flavor_id"><option value="">—</option>';
            foreach($flavors as $f) echo '<option value="'.$f['id'].'">'.h($f['name']).'</option>';
            echo '</select></label><div><button class="btn primary" style="margin-top:27px">Create Recipe</button></div></form></div>';
        }

        $recipes=$db->all(
            'SELECT r.*,f.name flavor_name,
                    (SELECT id FROM recipe_versions rv WHERE rv.recipe_id=r.id AND rv.status="published" ORDER BY version_number DESC LIMIT 1) published_version_id,
                    (SELECT version_number FROM recipe_versions rv WHERE rv.recipe_id=r.id AND rv.status="published" ORDER BY version_number DESC LIMIT 1) published_version,
                    (SELECT id FROM recipe_versions rv WHERE rv.recipe_id=r.id AND rv.status="draft" ORDER BY version_number DESC LIMIT 1) draft_version_id
             FROM recipes r LEFT JOIN flavors f ON f.id=r.flavor_id
             ORDER BY r.recipe_type,r.name'
        );
        echo '<div class="table-card"><div class="table-head"><h2>Recipe Catalog</h2></div><table><thead><tr><th>Recipe</th><th>Type</th><th>Published</th><th>Live Unit Cost</th><th>Cost Status</th><th></th></tr></thead><tbody>';
        foreach($recipes as $r){
            $costText='—';$status='<span class="badge warn">No Published Version</span>';
            if($r['published_version_id']){
                try{
                    $cost=$costing->recipeVersionCost((int)$r['published_version_id']);
                    $costText='$'.number_format((float)$cost['unit_cost'],4).' / '.h($cost['yield_unit']);
                    $status=$cost['complete']?'<span class="badge good">Complete</span>':'<span class="badge warn">Needs Pricing</span>';
                }catch(Throwable $e){$status='<span class="badge bad">Cost Error</span>';}
            }
            echo '<tr><td><strong>'.h($r['name']).'</strong><div class="muted">'.h($r['flavor_name']).'</div></td><td>'.h(ucfirst($r['recipe_type'])).'</td><td>'.($r['published_version']?'v'.h($r['published_version']):'—').($r['draft_version_id']?' <span class="badge warn">Draft</span>':'').'</td><td>'.$costText.'</td><td>'.$status.'</td><td><a class="btn small" href="?page=recipes&recipe='.$r['id'].'">Open</a></td></tr>';
        }
        echo '</tbody></table></div>';

        $recipeId=(int)($_GET['recipe']??0);
        if($recipeId){
            $recipe=$db->one('SELECT r.*,f.name flavor_name FROM recipes r LEFT JOIN flavors f ON f.id=r.flavor_id WHERE r.id=?',[$recipeId]);
            if($recipe){
                echo '<div class="card" style="margin-top:18px"><div class="page-head"><div><div class="kicker">'.h($recipe['recipe_type']).'</div><h1>'.h($recipe['name']).'</h1><div class="muted">'.h($recipe['flavor_name']).'</div></div>'.Ui::statusBadge($recipe['is_active']?'active':'inactive').'</div>';
                if(can('recipes.edit')) echo '<form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="update_recipe"><input type="hidden" name="recipe_id" value="'.$recipeId.'"><label>Name<input name="name" value="'.h($recipe['name']).'" required></label><label>Active<select name="is_active"><option value="1" '.($recipe['is_active']?'selected':'').'>Active</option><option value="0" '.(!$recipe['is_active']?'selected':'').'>Archived</option></select></label><div class="span-2"><button class="btn">Save Recipe</button></div></form>';
                echo '</div>';

                $versions=$db->all('SELECT * FROM recipe_versions WHERE recipe_id=? ORDER BY version_number DESC',[$recipeId]);
                echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Versions</h2>';
                $draft=$db->one('SELECT * FROM recipe_versions WHERE recipe_id=? AND status="draft" ORDER BY version_number DESC LIMIT 1',[$recipeId]);
                if(!$draft&&can('recipes.edit')) echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="create_recipe_draft"><input type="hidden" name="recipe_id" value="'.$recipeId.'"><button class="btn primary small">Create Draft from Published</button></form>';
                echo '</div><table><thead><tr><th>Version</th><th>Status</th><th>Yield</th><th>Published</th><th></th></tr></thead><tbody>';
                foreach($versions as $v) echo '<tr><td>v'.h($v['version_number']).'</td><td>'.Ui::statusBadge($v['status']).'</td><td>'.h($v['yield_quantity'].' '.$v['yield_unit']).'</td><td>'.h($v['published_at']).'</td><td><a class="btn small" href="?page=recipes&recipe='.$recipeId.'&version='.$v['id'].'">View</a></td></tr>';
                echo '</tbody></table></div>';

                $versionId=(int)($_GET['version']??($draft['id']??($versions[0]['id']??0)));
                if($versionId){
                    $version=$db->one('SELECT * FROM recipe_versions WHERE id=? AND recipe_id=?',[$versionId,$recipeId]);
                    if($version){
                        try{$preview=$costing->recipeVersionCost($versionId);}catch(Throwable $e){$preview=null;$previewError=$e->getMessage();}
                        echo '<div class="card" style="margin-top:18px"><div class="page-head"><div><h2 style="margin:0">Version '.h($version['version_number']).'</h2><div class="muted">'.h(ucfirst($version['status'])).'</div></div>';
                        if($preview) echo '<div class="metric"><div class="label">Unit Cost</div><div class="value">$'.number_format((float)$preview['unit_cost'],4).'</div></div>';
                        echo '</div>';
                        if(isset($previewError)) echo '<div class="alert danger">'.h($previewError).'</div>';
                        if($preview&&!$preview['complete']) echo '<div class="alert info"><strong>Cost incomplete.</strong><br>'.h(implode(' · ',$preview['warnings'])).'</div>';

                        if($version['status']==='draft'&&can('recipes.edit')){
                            $units=$db->all('SELECT symbol,name FROM units ORDER BY unit_type,name');
                            echo '<form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_recipe_meta"><input type="hidden" name="recipe_id" value="'.$recipeId.'"><input type="hidden" name="version_id" value="'.$versionId.'"><label>Yield Quantity<input type="number" min="0.0001" step="0.0001" name="yield_quantity" value="'.h($version['yield_quantity']).'" required></label><label>Yield Unit<select name="yield_unit">';
                            foreach($units as $u) echo '<option value="'.h($u['symbol']).'" '.($u['symbol']===$version['yield_unit']?'selected':'').'>'.h($u['name'].' ('.$u['symbol'].')').'</option>';
                            echo '</select></label><label class="span-2">Notes<textarea name="notes">'.h($version['notes']).'</textarea></label><div class="span-2"><button class="btn">Save Draft Settings</button></div></form>';
                        }

                        $items=$db->all(
                            "SELECT ri.*,
                             CASE
                               WHEN ri.component_type='ingredient' THEN (SELECT name FROM ingredients WHERE id=ri.component_id)
                               WHEN ri.component_type='packaging' THEN (SELECT name FROM packaging_items WHERE id=ri.component_id)
                               ELSE (SELECT name FROM recipes WHERE id=ri.component_id)
                             END component_name
                             FROM recipe_items ri WHERE ri.recipe_version_id=? ORDER BY ri.sort_order,ri.id",
                            [$versionId]
                        );
                        echo '<div class="table-card" style="box-shadow:none;margin-top:16px"><table><thead><tr><th>Component</th><th>Type</th><th>Quantity</th><th>Cost</th><th></th></tr></thead><tbody>';
                        $costByItem=[];if($preview)foreach($preview['details'] as $d)if(isset($d['recipe_item_id']))$costByItem[(int)$d['recipe_item_id']]=$d['cost'];
                        foreach($items as $item){
                            echo '<tr><td><strong>'.h($item['component_name']).'</strong></td><td>'.h(ucfirst($item['component_type'])).'</td><td>';
                            if($version['status']==='draft'&&can('recipes.edit')){
                                echo '<form method="post" class="actions">'.Ui::csrf().'<input type="hidden" name="form_action" value="update_recipe_component"><input type="hidden" name="recipe_id" value="'.$recipeId.'"><input type="hidden" name="version_id" value="'.$versionId.'"><input type="hidden" name="recipe_item_id" value="'.$item['id'].'"><input style="width:90px" type="number" min="0" step="0.0001" name="quantity" value="'.h($item['quantity']).'" required><select name="unit">';
                                foreach($units as $u)echo '<option value="'.h($u['symbol']).'" '.($u['symbol']===$item['unit']?'selected':'').'>'.h($u['symbol']).'</option>';
                                echo '</select><button class="btn small">Save</button></form>';
                            } else echo h($item['quantity'].' '.$item['unit']);
                            $componentCost=$costByItem[(int)$item['id']]??null;
                            echo '</td><td>'.($componentCost===null?'—':'
                        echo '</tbody></table></div>';

                        if($version['status']==='draft'&&can('recipes.edit')){
                            $ingredients=$db->all('SELECT id,name FROM ingredients WHERE is_active=1 ORDER BY name');
                            $packaging=$db->all('SELECT id,name FROM packaging_items WHERE is_active=1 ORDER BY name');
                            $nested=$db->all('SELECT id,name FROM recipes WHERE is_active=1 AND id<>? ORDER BY name',[$recipeId]);
                            $units=$units??$db->all('SELECT symbol,name FROM units ORDER BY unit_type,name');
                            echo '<div style="margin-top:16px"><h3>Add Component</h3><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="add_recipe_component"><input type="hidden" name="recipe_id" value="'.$recipeId.'"><input type="hidden" name="version_id" value="'.$versionId.'"><label class="span-2">Component<select name="component_ref" required><option value="">Select component</option><optgroup label="Ingredients">';
                            foreach($ingredients as $i)echo '<option value="ingredient:'.$i['id'].'">'.h($i['name']).'</option>';
                            echo '</optgroup><optgroup label="Packaging">';
                            foreach($packaging as $p)echo '<option value="packaging:'.$p['id'].'">'.h($p['name']).'</option>';
                            echo '</optgroup><optgroup label="Sub-recipes">';
                            foreach($nested as $n)echo '<option value="recipe:'.$n['id'].'">'.h($n['name']).'</option>';
                            echo '</optgroup></select></label><label>Quantity<input type="number" min="0" step="0.0001" name="quantity" required></label><label>Unit<select name="unit">';
                            foreach($units as $u)echo '<option value="'.h($u['symbol']).'">'.h($u['name'].' ('.$u['symbol'].')').'</option>';
                            echo '</select></label><div class="span-2"><button class="btn">Add Component</button></div></form>';
                            if(can('recipes.publish')) echo '<form method="post" style="margin-top:12px">'.Ui::csrf().'<input type="hidden" name="form_action" value="publish_recipe"><input type="hidden" name="recipe_id" value="'.$recipeId.'"><input type="hidden" name="version_id" value="'.$versionId.'"><button class="btn primary" data-confirm="Publish this version and retire the previous published version?">Publish Version</button></form>';
                            echo '</div>';
                        }
                        echo '</div>';
                    }
                }
            }
        }

        Ui::layoutEnd();
        exit;
    }

    if ($page === 'costing') {
        require_permission('costing.view');
        Ui::layoutStart('Costing','costing');
        Ui::pageHead('Costing & Margin','Live recipe COGS and product margin driven by supplier pricing.','<a class="btn" href="?page=recipes">Recipes</a>');

        if(can('costing.snapshot')) echo '<form method="post" style="margin-bottom:18px">'.Ui::csrf().'<input type="hidden" name="form_action" value="capture_cost_snapshot"><button class="btn primary">Capture Cost Snapshot</button></form>';

        $products=$db->all('SELECT * FROM products WHERE is_active=1 ORDER BY id');
        echo '<div class="grid cols-3">';
        foreach($products as $product){
            try{$cost=$costing->productCost((int)$product['id']);}
            catch(Throwable $e){echo '<div class="card"><h2>'.h($product['name']).'</h2><div class="alert danger">'.h($e->getMessage()).'</div></div>';continue;}
            echo '<div class="card"><div class="kicker">'.h($product['sku']).'</div><h2>'.h($product['name']).'</h2><div class="two-col-stat"><div class="metric"><div class="label">Price</div><div class="value">$'.number_format((float)$cost['selling_price'],2).'</div></div><div class="metric"><div class="label">Direct COGS</div><div class="value">$'.number_format((float)$cost['direct_cogs'],2).'</div></div></div>';
            if($cost['complete']&&can('costing.view_margin')) echo '<p><strong>Gross contribution:</strong> $'.number_format((float)$cost['gross_profit'],2).' · '.number_format((float)$cost['margin_pct'],1).'%</p><span class="badge good">Complete Cost</span>';
            else echo '<span class="badge warn">Needs Pricing</span><p class="muted">'.h(implode(' · ',$cost['warnings'])).'</p>';
            echo '<div style="margin-top:12px"><a class="btn small" href="?page=costing&product='.$product['id'].'">Packaging BOM</a></div></div>';
        }
        echo '</div>';

        $flavors=$costing->flavorCosts();
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Flavor Unit Costs</h2></div><table><thead><tr><th>Flavor</th><th>Recipe</th><th>Unit Cost</th><th>Status</th><th>Warnings</th></tr></thead><tbody>';
        foreach($flavors as $f)echo '<tr><td><strong>'.h($f['flavor_name']).'</strong></td><td>'.h($f['recipe_name']).' v'.h($f['version_number']).'</td><td>$'.number_format((float)$f['unit_cost'],4).'</td><td>'.($f['complete']?'<span class="badge good">Complete</span>':'<span class="badge warn">Needs Pricing</span>').'</td><td>'.h(implode(' · ',$f['warnings'])).'</td></tr>';
        echo '</tbody></table></div>';

        $selectedProduct=(int)($_GET['product']??0);
        if($selectedProduct&&can('products.manage')){
            $p=$db->one('SELECT * FROM products WHERE id=?',[$selectedProduct]);
            if($p){
                $packaging=$db->all('SELECT id,name,inventory_unit FROM packaging_items WHERE is_active=1 ORDER BY name');
                $units=$db->all('SELECT symbol,name FROM units ORDER BY unit_type,name');
                $bom=$db->all('SELECT ppc.*,pi.name packaging_name FROM product_packaging_components ppc JOIN packaging_items pi ON pi.id=ppc.packaging_item_id WHERE ppc.product_id=? ORDER BY ppc.id',[$selectedProduct]);
                echo '<div class="card" style="margin-top:18px"><h2 class="section-title">'.h($p['name']).' Packaging BOM</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_product_packaging"><input type="hidden" name="product_id" value="'.$selectedProduct.'"><label>Packaging Item<select name="packaging_item_id">';
                foreach($packaging as $item)echo '<option value="'.$item['id'].'">'.h($item['name']).'</option>';
                echo '</select></label><label>Quantity<input type="number" min="0" step="0.0001" name="quantity" value="1" required></label><label>Unit<select name="unit">';
                foreach($units as $u)echo '<option value="'.h($u['symbol']).'">'.h($u['name'].' ('.$u['symbol'].')').'</option>';
                echo '</select></label><div><button class="btn" style="margin-top:27px">Add / Update</button></div></form><div class="list" style="margin-top:12px">';
                foreach($bom as $b)echo '<div class="list-row"><span><strong>'.h($b['packaging_name']).'</strong> · '.h($b['quantity'].' '.$b['unit']).'</span><form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="remove_product_packaging"><input type="hidden" name="component_id" value="'.$b['id'].'"><button class="btn small danger" data-confirm="Remove this packaging component?">Remove</button></form></div>';
                if(!$bom)echo '<div class="empty">No product-level packaging components.</div>';
                echo '</div></div>';
            }
        }

        $history=$db->all(
            'SELECT pcs.*,p.name product_name
             FROM product_cost_snapshots pcs JOIN products p ON p.id=pcs.product_id
             ORDER BY pcs.id DESC LIMIT 30'
        );
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Cost Snapshot History</h2></div><table><thead><tr><th>Date</th><th>Product</th><th>COGS</th><th>Price</th><th>Margin</th><th>Trigger</th><th>Status</th></tr></thead><tbody>';
        foreach($history as $h)echo '<tr><td>'.h($h['created_at']).'</td><td>'.h($h['product_name']).'</td><td>$'.number_format((float)$h['direct_cogs'],2).'</td><td>$'.number_format((float)$h['selling_price'],2).'</td><td>'.number_format((float)$h['margin_pct'],1).'%</td><td>'.h($h['trigger_type']).'<div class="muted">'.h($h['trigger_reference']).'</div></td><td>'.($h['is_complete']?'<span class="badge good">Complete</span>':'<span class="badge warn">Incomplete</span>').'</td></tr>';
        if(!$history)echo '<tr><td colspan="7" class="empty">No cost snapshots yet.</td></tr>';
        echo '</tbody></table></div>';

        $impacts=$db->all(
            "SELECT a.created_at,a.trigger_reference,a.trigger_type,p.name product_name,
                    b.direct_cogs before_cogs,a.direct_cogs after_cogs,
                    b.margin_pct before_margin,a.margin_pct after_margin,
                    b.is_complete before_complete,a.is_complete after_complete
             FROM product_cost_snapshots b
             JOIN product_cost_snapshots a
               ON a.product_id=b.product_id
              AND a.trigger_reference=b.trigger_reference
              AND a.trigger_type=REPLACE(b.trigger_type,'_before','_after')
             JOIN products p ON p.id=a.product_id
             WHERE b.trigger_type LIKE '%_before'
               AND (
                    ABS(a.direct_cogs-b.direct_cogs) > 0.000001
                    OR ABS(a.margin_pct-b.margin_pct) > 0.0001
               )
             ORDER BY a.id DESC LIMIT 30"
        );
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Recent Cost Impacts</h2></div><table><thead><tr><th>Date</th><th>Product</th><th>Trigger</th><th>COGS Change</th><th>Margin Change</th><th>Status</th></tr></thead><tbody>';
        foreach($impacts as $i){$dc=(float)$i['after_cogs']-(float)$i['before_cogs'];$dm=(float)$i['after_margin']-(float)$i['before_margin'];echo '<tr><td>'.h($i['created_at']).'</td><td>'.h($i['product_name']).'</td><td>'.h(str_replace('_after','',$i['trigger_type'])).'<div class="muted">'.h($i['trigger_reference']).'</div></td><td>'.($dc>=0?'+':'').' 
            "SELECT h.*,s.name supplier,
             CASE WHEN si.item_type='ingredient' THEN (SELECT name FROM ingredients WHERE id=si.item_id)
                  ELSE (SELECT name FROM packaging_items WHERE id=si.item_id) END item_name
             FROM supplier_price_history h
             JOIN supplier_items si ON si.id=h.supplier_item_id
             JOIN suppliers s ON s.id=si.supplier_id
             ORDER BY h.id DESC LIMIT 30"
        );
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Supplier Cost Changes</h2></div><table><thead><tr><th>Date</th><th>Item</th><th>Supplier</th><th>Old Unit Cost</th><th>New Unit Cost</th><th>Delta</th></tr></thead><tbody>';
        foreach($priceChanges as $h){$old=(float)($h['old_unit_cost']??0);$new=(float)$h['unit_cost'];$delta=$new-$old;echo '<tr><td>'.h($h['effective_at']).'</td><td>'.h($h['item_name']).'</td><td>'.h($h['supplier']).'</td><td>$'.number_format($old,4).'</td><td>$'.number_format($new,4).'</td><td>'.($delta>=0?'+':'').'$'.number_format($delta,4).'</td></tr>';}
        if(!$priceChanges)echo '<tr><td colspan="6" class="empty">Price changes appear here after using Supplier → Update Price.</td></tr>';
        echo '</tbody></table></div>';

        Ui::layoutEnd();
        exit;
    }

    http_response_code(404);
    exit('Page not found.');
}
.number_format((float)($costByItem[(int)$item['id']]??0),4).'</td><td>';
                            if($version['status']==='draft'&&can('recipes.edit')) echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="remove_recipe_component"><input type="hidden" name="recipe_id" value="'.$recipeId.'"><input type="hidden" name="version_id" value="'.$versionId.'"><input type="hidden" name="recipe_item_id" value="'.$item['id'].'"><button class="btn small danger" data-confirm="Remove this component from the draft?">Remove</button></form>';
                            echo '</td></tr>';
                        }
                        if(!$items)echo '<tr><td colspan="5" class="empty">No components yet.</td></tr>';
                        echo '</tbody></table></div>';

                        if($version['status']==='draft'&&can('recipes.edit')){
                            $ingredients=$db->all('SELECT id,name FROM ingredients WHERE is_active=1 ORDER BY name');
                            $packaging=$db->all('SELECT id,name FROM packaging_items WHERE is_active=1 ORDER BY name');
                            $nested=$db->all('SELECT id,name FROM recipes WHERE is_active=1 AND id<>? ORDER BY name',[$recipeId]);
                            $units=$units??$db->all('SELECT symbol,name FROM units ORDER BY unit_type,name');
                            echo '<div style="margin-top:16px"><h3>Add Component</h3><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="add_recipe_component"><input type="hidden" name="recipe_id" value="'.$recipeId.'"><input type="hidden" name="version_id" value="'.$versionId.'"><label class="span-2">Component<select name="component_ref" required><option value="">Select component</option><optgroup label="Ingredients">';
                            foreach($ingredients as $i)echo '<option value="ingredient:'.$i['id'].'">'.h($i['name']).'</option>';
                            echo '</optgroup><optgroup label="Packaging">';
                            foreach($packaging as $p)echo '<option value="packaging:'.$p['id'].'">'.h($p['name']).'</option>';
                            echo '</optgroup><optgroup label="Sub-recipes">';
                            foreach($nested as $n)echo '<option value="recipe:'.$n['id'].'">'.h($n['name']).'</option>';
                            echo '</optgroup></select></label><label>Quantity<input type="number" min="0" step="0.0001" name="quantity" required></label><label>Unit<select name="unit">';
                            foreach($units as $u)echo '<option value="'.h($u['symbol']).'">'.h($u['name'].' ('.$u['symbol'].')').'</option>';
                            echo '</select></label><div class="span-2 actions"><button class="btn">Add Component</button>';
                            if(can('recipes.publish')) echo '<button class="btn primary" type="submit" name="form_action" value="publish_recipe" data-confirm="Publish this version and retire the previous published version?">Publish Version</button>';
                            echo '</div></form></div>';
                        }
                        echo '</div>';
                    }
                }
            }
        }

        Ui::layoutEnd();
        exit;
    }

    if ($page === 'costing') {
        require_permission('costing.view');
        Ui::layoutStart('Costing','costing');
        Ui::pageHead('Costing & Margin','Live recipe COGS and product margin driven by supplier pricing.','<a class="btn" href="?page=recipes">Recipes</a>');

        if(can('costing.snapshot')) echo '<form method="post" style="margin-bottom:18px">'.Ui::csrf().'<input type="hidden" name="form_action" value="capture_cost_snapshot"><button class="btn primary">Capture Cost Snapshot</button></form>';

        $products=$db->all('SELECT * FROM products WHERE is_active=1 ORDER BY id');
        echo '<div class="grid cols-3">';
        foreach($products as $product){
            try{$cost=$costing->productCost((int)$product['id']);}
            catch(Throwable $e){echo '<div class="card"><h2>'.h($product['name']).'</h2><div class="alert danger">'.h($e->getMessage()).'</div></div>';continue;}
            echo '<div class="card"><div class="kicker">'.h($product['sku']).'</div><h2>'.h($product['name']).'</h2><div class="two-col-stat"><div class="metric"><div class="label">Price</div><div class="value">$'.number_format((float)$cost['selling_price'],2).'</div></div><div class="metric"><div class="label">Direct COGS</div><div class="value">$'.number_format((float)$cost['direct_cogs'],2).'</div></div></div>';
            if($cost['complete']&&can('costing.view_margin')) echo '<p><strong>Gross contribution:</strong> $'.number_format((float)$cost['gross_profit'],2).' · '.number_format((float)$cost['margin_pct'],1).'%</p><span class="badge good">Complete Cost</span>';
            else echo '<span class="badge warn">Needs Pricing</span><p class="muted">'.h(implode(' · ',$cost['warnings'])).'</p>';
            echo '<div style="margin-top:12px"><a class="btn small" href="?page=costing&product='.$product['id'].'">Packaging BOM</a></div></div>';
        }
        echo '</div>';

        $flavors=$costing->flavorCosts();
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Flavor Unit Costs</h2></div><table><thead><tr><th>Flavor</th><th>Recipe</th><th>Unit Cost</th><th>Status</th><th>Warnings</th></tr></thead><tbody>';
        foreach($flavors as $f)echo '<tr><td><strong>'.h($f['flavor_name']).'</strong></td><td>'.h($f['recipe_name']).' v'.h($f['version_number']).'</td><td>$'.number_format((float)$f['unit_cost'],4).'</td><td>'.($f['complete']?'<span class="badge good">Complete</span>':'<span class="badge warn">Needs Pricing</span>').'</td><td>'.h(implode(' · ',$f['warnings'])).'</td></tr>';
        echo '</tbody></table></div>';

        $selectedProduct=(int)($_GET['product']??0);
        if($selectedProduct&&can('products.manage')){
            $p=$db->one('SELECT * FROM products WHERE id=?',[$selectedProduct]);
            if($p){
                $packaging=$db->all('SELECT id,name,inventory_unit FROM packaging_items WHERE is_active=1 ORDER BY name');
                $units=$db->all('SELECT symbol,name FROM units ORDER BY unit_type,name');
                $bom=$db->all('SELECT ppc.*,pi.name packaging_name FROM product_packaging_components ppc JOIN packaging_items pi ON pi.id=ppc.packaging_item_id WHERE ppc.product_id=? ORDER BY ppc.id',[$selectedProduct]);
                echo '<div class="card" style="margin-top:18px"><h2 class="section-title">'.h($p['name']).' Packaging BOM</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_product_packaging"><input type="hidden" name="product_id" value="'.$selectedProduct.'"><label>Packaging Item<select name="packaging_item_id">';
                foreach($packaging as $item)echo '<option value="'.$item['id'].'">'.h($item['name']).'</option>';
                echo '</select></label><label>Quantity<input type="number" min="0" step="0.0001" name="quantity" value="1" required></label><label>Unit<select name="unit">';
                foreach($units as $u)echo '<option value="'.h($u['symbol']).'">'.h($u['name'].' ('.$u['symbol'].')').'</option>';
                echo '</select></label><div><button class="btn" style="margin-top:27px">Add / Update</button></div></form><div class="list" style="margin-top:12px">';
                foreach($bom as $b)echo '<div class="list-row"><span><strong>'.h($b['packaging_name']).'</strong> · '.h($b['quantity'].' '.$b['unit']).'</span><form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="remove_product_packaging"><input type="hidden" name="component_id" value="'.$b['id'].'"><button class="btn small danger" data-confirm="Remove this packaging component?">Remove</button></form></div>';
                if(!$bom)echo '<div class="empty">No product-level packaging components.</div>';
                echo '</div></div>';
            }
        }

        $history=$db->all(
            'SELECT pcs.*,p.name product_name
             FROM product_cost_snapshots pcs JOIN products p ON p.id=pcs.product_id
             ORDER BY pcs.id DESC LIMIT 30'
        );
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Cost Snapshot History</h2></div><table><thead><tr><th>Date</th><th>Product</th><th>COGS</th><th>Price</th><th>Margin</th><th>Trigger</th><th>Status</th></tr></thead><tbody>';
        foreach($history as $h)echo '<tr><td>'.h($h['created_at']).'</td><td>'.h($h['product_name']).'</td><td>$'.number_format((float)$h['direct_cogs'],2).'</td><td>$'.number_format((float)$h['selling_price'],2).'</td><td>'.number_format((float)$h['margin_pct'],1).'%</td><td>'.h($h['trigger_type']).'<div class="muted">'.h($h['trigger_reference']).'</div></td><td>'.($h['is_complete']?'<span class="badge good">Complete</span>':'<span class="badge warn">Incomplete</span>').'</td></tr>';
        if(!$history)echo '<tr><td colspan="7" class="empty">No cost snapshots yet.</td></tr>';
        echo '</tbody></table></div>';

        $priceChanges=$db->all(
            "SELECT h.*,s.name supplier,
             CASE WHEN si.item_type='ingredient' THEN (SELECT name FROM ingredients WHERE id=si.item_id)
                  ELSE (SELECT name FROM packaging_items WHERE id=si.item_id) END item_name
             FROM supplier_price_history h
             JOIN supplier_items si ON si.id=h.supplier_item_id
             JOIN suppliers s ON s.id=si.supplier_id
             ORDER BY h.id DESC LIMIT 30"
        );
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Supplier Cost Changes</h2></div><table><thead><tr><th>Date</th><th>Item</th><th>Supplier</th><th>Old Unit Cost</th><th>New Unit Cost</th><th>Delta</th></tr></thead><tbody>';
        foreach($priceChanges as $h){$old=(float)($h['old_unit_cost']??0);$new=(float)$h['unit_cost'];$delta=$new-$old;echo '<tr><td>'.h($h['effective_at']).'</td><td>'.h($h['item_name']).'</td><td>'.h($h['supplier']).'</td><td>$'.number_format($old,4).'</td><td>$'.number_format($new,4).'</td><td>'.($delta>=0?'+':'').'$'.number_format($delta,4).'</td></tr>';}
        if(!$priceChanges)echo '<tr><td colspan="6" class="empty">Price changes appear here after using Supplier → Update Price.</td></tr>';
        echo '</tbody></table></div>';

        Ui::layoutEnd();
        exit;
    }

    http_response_code(404);
    exit('Page not found.');
}
.number_format($dc,2).'</td><td>'.($dm>=0?'+':'').number_format($dm,2).' pts</td><td>'.(($i['before_complete']&&$i['after_complete'])?'<span class="badge good">Complete</span>':'<span class="badge warn">Incomplete</span>').'</td></tr>';}
        if(!$impacts)echo '<tr><td colspan="6" class="empty">Before/after cost impacts appear after supplier-price or packaging changes.</td></tr>';
        echo '</tbody></table></div>';

        $priceChanges=$db->all(
            "SELECT h.*,s.name supplier,
             CASE WHEN si.item_type='ingredient' THEN (SELECT name FROM ingredients WHERE id=si.item_id)
                  ELSE (SELECT name FROM packaging_items WHERE id=si.item_id) END item_name
             FROM supplier_price_history h
             JOIN supplier_items si ON si.id=h.supplier_item_id
             JOIN suppliers s ON s.id=si.supplier_id
             ORDER BY h.id DESC LIMIT 30"
        );
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Supplier Cost Changes</h2></div><table><thead><tr><th>Date</th><th>Item</th><th>Supplier</th><th>Old Unit Cost</th><th>New Unit Cost</th><th>Delta</th></tr></thead><tbody>';
        foreach($priceChanges as $h){$old=(float)($h['old_unit_cost']??0);$new=(float)$h['unit_cost'];$delta=$new-$old;echo '<tr><td>'.h($h['effective_at']).'</td><td>'.h($h['item_name']).'</td><td>'.h($h['supplier']).'</td><td>$'.number_format($old,4).'</td><td>$'.number_format($new,4).'</td><td>'.($delta>=0?'+':'').'$'.number_format($delta,4).'</td></tr>';}
        if(!$priceChanges)echo '<tr><td colspan="6" class="empty">Price changes appear here after using Supplier → Update Price.</td></tr>';
        echo '</tbody></table></div>';

        Ui::layoutEnd();
        exit;
    }

    http_response_code(404);
    exit('Page not found.');
}
.number_format((float)($costByItem[(int)$item['id']]??0),4).'</td><td>';
                            if($version['status']==='draft'&&can('recipes.edit')) echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="remove_recipe_component"><input type="hidden" name="recipe_id" value="'.$recipeId.'"><input type="hidden" name="version_id" value="'.$versionId.'"><input type="hidden" name="recipe_item_id" value="'.$item['id'].'"><button class="btn small danger" data-confirm="Remove this component from the draft?">Remove</button></form>';
                            echo '</td></tr>';
                        }
                        if(!$items)echo '<tr><td colspan="5" class="empty">No components yet.</td></tr>';
                        echo '</tbody></table></div>';

                        if($version['status']==='draft'&&can('recipes.edit')){
                            $ingredients=$db->all('SELECT id,name FROM ingredients WHERE is_active=1 ORDER BY name');
                            $packaging=$db->all('SELECT id,name FROM packaging_items WHERE is_active=1 ORDER BY name');
                            $nested=$db->all('SELECT id,name FROM recipes WHERE is_active=1 AND id<>? ORDER BY name',[$recipeId]);
                            $units=$units??$db->all('SELECT symbol,name FROM units ORDER BY unit_type,name');
                            echo '<div style="margin-top:16px"><h3>Add Component</h3><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="add_recipe_component"><input type="hidden" name="recipe_id" value="'.$recipeId.'"><input type="hidden" name="version_id" value="'.$versionId.'"><label class="span-2">Component<select name="component_ref" required><option value="">Select component</option><optgroup label="Ingredients">';
                            foreach($ingredients as $i)echo '<option value="ingredient:'.$i['id'].'">'.h($i['name']).'</option>';
                            echo '</optgroup><optgroup label="Packaging">';
                            foreach($packaging as $p)echo '<option value="packaging:'.$p['id'].'">'.h($p['name']).'</option>';
                            echo '</optgroup><optgroup label="Sub-recipes">';
                            foreach($nested as $n)echo '<option value="recipe:'.$n['id'].'">'.h($n['name']).'</option>';
                            echo '</optgroup></select></label><label>Quantity<input type="number" min="0" step="0.0001" name="quantity" required></label><label>Unit<select name="unit">';
                            foreach($units as $u)echo '<option value="'.h($u['symbol']).'">'.h($u['name'].' ('.$u['symbol'].')').'</option>';
                            echo '</select></label><div class="span-2 actions"><button class="btn">Add Component</button>';
                            if(can('recipes.publish')) echo '<button class="btn primary" type="submit" name="form_action" value="publish_recipe" data-confirm="Publish this version and retire the previous published version?">Publish Version</button>';
                            echo '</div></form></div>';
                        }
                        echo '</div>';
                    }
                }
            }
        }

        Ui::layoutEnd();
        exit;
    }

    if ($page === 'costing') {
        require_permission('costing.view');
        Ui::layoutStart('Costing','costing');
        Ui::pageHead('Costing & Margin','Live recipe COGS and product margin driven by supplier pricing.','<a class="btn" href="?page=recipes">Recipes</a>');

        if(can('costing.snapshot')) echo '<form method="post" style="margin-bottom:18px">'.Ui::csrf().'<input type="hidden" name="form_action" value="capture_cost_snapshot"><button class="btn primary">Capture Cost Snapshot</button></form>';

        $products=$db->all('SELECT * FROM products WHERE is_active=1 ORDER BY id');
        echo '<div class="grid cols-3">';
        foreach($products as $product){
            try{$cost=$costing->productCost((int)$product['id']);}
            catch(Throwable $e){echo '<div class="card"><h2>'.h($product['name']).'</h2><div class="alert danger">'.h($e->getMessage()).'</div></div>';continue;}
            echo '<div class="card"><div class="kicker">'.h($product['sku']).'</div><h2>'.h($product['name']).'</h2><div class="two-col-stat"><div class="metric"><div class="label">Price</div><div class="value">$'.number_format((float)$cost['selling_price'],2).'</div></div><div class="metric"><div class="label">Direct COGS</div><div class="value">$'.number_format((float)$cost['direct_cogs'],2).'</div></div></div>';
            if($cost['complete']&&can('costing.view_margin')) echo '<p><strong>Gross contribution:</strong> $'.number_format((float)$cost['gross_profit'],2).' · '.number_format((float)$cost['margin_pct'],1).'%</p><span class="badge good">Complete Cost</span>';
            else echo '<span class="badge warn">Needs Pricing</span><p class="muted">'.h(implode(' · ',$cost['warnings'])).'</p>';
            echo '<div style="margin-top:12px"><a class="btn small" href="?page=costing&product='.$product['id'].'">Packaging BOM</a></div></div>';
        }
        echo '</div>';

        $flavors=$costing->flavorCosts();
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Flavor Unit Costs</h2></div><table><thead><tr><th>Flavor</th><th>Recipe</th><th>Unit Cost</th><th>Status</th><th>Warnings</th></tr></thead><tbody>';
        foreach($flavors as $f)echo '<tr><td><strong>'.h($f['flavor_name']).'</strong></td><td>'.h($f['recipe_name']).' v'.h($f['version_number']).'</td><td>$'.number_format((float)$f['unit_cost'],4).'</td><td>'.($f['complete']?'<span class="badge good">Complete</span>':'<span class="badge warn">Needs Pricing</span>').'</td><td>'.h(implode(' · ',$f['warnings'])).'</td></tr>';
        echo '</tbody></table></div>';

        $selectedProduct=(int)($_GET['product']??0);
        if($selectedProduct&&can('products.manage')){
            $p=$db->one('SELECT * FROM products WHERE id=?',[$selectedProduct]);
            if($p){
                $packaging=$db->all('SELECT id,name,inventory_unit FROM packaging_items WHERE is_active=1 ORDER BY name');
                $units=$db->all('SELECT symbol,name FROM units ORDER BY unit_type,name');
                $bom=$db->all('SELECT ppc.*,pi.name packaging_name FROM product_packaging_components ppc JOIN packaging_items pi ON pi.id=ppc.packaging_item_id WHERE ppc.product_id=? ORDER BY ppc.id',[$selectedProduct]);
                echo '<div class="card" style="margin-top:18px"><h2 class="section-title">'.h($p['name']).' Packaging BOM</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_product_packaging"><input type="hidden" name="product_id" value="'.$selectedProduct.'"><label>Packaging Item<select name="packaging_item_id">';
                foreach($packaging as $item)echo '<option value="'.$item['id'].'">'.h($item['name']).'</option>';
                echo '</select></label><label>Quantity<input type="number" min="0" step="0.0001" name="quantity" value="1" required></label><label>Unit<select name="unit">';
                foreach($units as $u)echo '<option value="'.h($u['symbol']).'">'.h($u['name'].' ('.$u['symbol'].')').'</option>';
                echo '</select></label><div><button class="btn" style="margin-top:27px">Add / Update</button></div></form><div class="list" style="margin-top:12px">';
                foreach($bom as $b)echo '<div class="list-row"><span><strong>'.h($b['packaging_name']).'</strong> · '.h($b['quantity'].' '.$b['unit']).'</span><form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="remove_product_packaging"><input type="hidden" name="component_id" value="'.$b['id'].'"><button class="btn small danger" data-confirm="Remove this packaging component?">Remove</button></form></div>';
                if(!$bom)echo '<div class="empty">No product-level packaging components.</div>';
                echo '</div></div>';
            }
        }

        $history=$db->all(
            'SELECT pcs.*,p.name product_name
             FROM product_cost_snapshots pcs JOIN products p ON p.id=pcs.product_id
             ORDER BY pcs.id DESC LIMIT 30'
        );
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Cost Snapshot History</h2></div><table><thead><tr><th>Date</th><th>Product</th><th>COGS</th><th>Price</th><th>Margin</th><th>Trigger</th><th>Status</th></tr></thead><tbody>';
        foreach($history as $h)echo '<tr><td>'.h($h['created_at']).'</td><td>'.h($h['product_name']).'</td><td>$'.number_format((float)$h['direct_cogs'],2).'</td><td>$'.number_format((float)$h['selling_price'],2).'</td><td>'.number_format((float)$h['margin_pct'],1).'%</td><td>'.h($h['trigger_type']).'<div class="muted">'.h($h['trigger_reference']).'</div></td><td>'.($h['is_complete']?'<span class="badge good">Complete</span>':'<span class="badge warn">Incomplete</span>').'</td></tr>';
        if(!$history)echo '<tr><td colspan="7" class="empty">No cost snapshots yet.</td></tr>';
        echo '</tbody></table></div>';

        $priceChanges=$db->all(
            "SELECT h.*,s.name supplier,
             CASE WHEN si.item_type='ingredient' THEN (SELECT name FROM ingredients WHERE id=si.item_id)
                  ELSE (SELECT name FROM packaging_items WHERE id=si.item_id) END item_name
             FROM supplier_price_history h
             JOIN supplier_items si ON si.id=h.supplier_item_id
             JOIN suppliers s ON s.id=si.supplier_id
             ORDER BY h.id DESC LIMIT 30"
        );
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Supplier Cost Changes</h2></div><table><thead><tr><th>Date</th><th>Item</th><th>Supplier</th><th>Old Unit Cost</th><th>New Unit Cost</th><th>Delta</th></tr></thead><tbody>';
        foreach($priceChanges as $h){$old=(float)($h['old_unit_cost']??0);$new=(float)$h['unit_cost'];$delta=$new-$old;echo '<tr><td>'.h($h['effective_at']).'</td><td>'.h($h['item_name']).'</td><td>'.h($h['supplier']).'</td><td>$'.number_format($old,4).'</td><td>$'.number_format($new,4).'</td><td>'.($delta>=0?'+':'').'$'.number_format($delta,4).'</td></tr>';}
        if(!$priceChanges)echo '<tr><td colspan="6" class="empty">Price changes appear here after using Supplier → Update Price.</td></tr>';
        echo '</tbody></table></div>';

        Ui::layoutEnd();
        exit;
    }

    http_response_code(404);
    exit('Page not found.');
}
.number_format((float)$componentCost,4)).'</td><td>';
                            if($version['status']==='draft'&&can('recipes.edit')){
                                echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="remove_recipe_component"><input type="hidden" name="recipe_id" value="'.$recipeId.'"><input type="hidden" name="version_id" value="'.$versionId.'"><input type="hidden" name="recipe_item_id" value="'.$item['id'].'"><button class="btn small danger" data-confirm="Remove this recipe component?">Remove</button></form>';
                            }
                            echo '</td></tr>';
                        }
                        if(!$items)echo '<tr><td colspan="5" class="empty">No components yet.</td></tr>';
                        echo '</tbody></table></div>';

                        if($version['status']==='draft'&&can('recipes.edit')){
                            $ingredients=$db->all('SELECT id,name FROM ingredients WHERE is_active=1 ORDER BY name');
                            $packaging=$db->all('SELECT id,name FROM packaging_items WHERE is_active=1 ORDER BY name');
                            $nested=$db->all('SELECT id,name FROM recipes WHERE is_active=1 AND id<>? ORDER BY name',[$recipeId]);
                            $units=$units??$db->all('SELECT symbol,name FROM units ORDER BY unit_type,name');
                            echo '<div style="margin-top:16px"><h3>Add Component</h3><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="add_recipe_component"><input type="hidden" name="recipe_id" value="'.$recipeId.'"><input type="hidden" name="version_id" value="'.$versionId.'"><label class="span-2">Component<select name="component_ref" required><option value="">Select component</option><optgroup label="Ingredients">';
                            foreach($ingredients as $i)echo '<option value="ingredient:'.$i['id'].'">'.h($i['name']).'</option>';
                            echo '</optgroup><optgroup label="Packaging">';
                            foreach($packaging as $p)echo '<option value="packaging:'.$p['id'].'">'.h($p['name']).'</option>';
                            echo '</optgroup><optgroup label="Sub-recipes">';
                            foreach($nested as $n)echo '<option value="recipe:'.$n['id'].'">'.h($n['name']).'</option>';
                            echo '</optgroup></select></label><label>Quantity<input type="number" min="0" step="0.0001" name="quantity" required></label><label>Unit<select name="unit">';
                            foreach($units as $u)echo '<option value="'.h($u['symbol']).'">'.h($u['name'].' ('.$u['symbol'].')').'</option>';
                            echo '</select></label><div class="span-2"><button class="btn">Add Component</button></div></form>';
                            if(can('recipes.publish')) echo '<form method="post" style="margin-top:12px">'.Ui::csrf().'<input type="hidden" name="form_action" value="publish_recipe"><input type="hidden" name="recipe_id" value="'.$recipeId.'"><input type="hidden" name="version_id" value="'.$versionId.'"><button class="btn primary" data-confirm="Publish this version and retire the previous published version?">Publish Version</button></form>';
                            echo '</div>';
                        }
                        echo '</div>';
                    }
                }
            }
        }

        Ui::layoutEnd();
        exit;
    }

    if ($page === 'costing') {
        require_permission('costing.view');
        Ui::layoutStart('Costing','costing');
        Ui::pageHead('Costing & Margin','Live recipe COGS and product margin driven by supplier pricing.','<a class="btn" href="?page=recipes">Recipes</a>');

        if(can('costing.snapshot')) echo '<form method="post" style="margin-bottom:18px">'.Ui::csrf().'<input type="hidden" name="form_action" value="capture_cost_snapshot"><button class="btn primary">Capture Cost Snapshot</button></form>';

        $products=$db->all('SELECT * FROM products WHERE is_active=1 ORDER BY id');
        echo '<div class="grid cols-3">';
        foreach($products as $product){
            try{$cost=$costing->productCost((int)$product['id']);}
            catch(Throwable $e){echo '<div class="card"><h2>'.h($product['name']).'</h2><div class="alert danger">'.h($e->getMessage()).'</div></div>';continue;}
            echo '<div class="card"><div class="kicker">'.h($product['sku']).'</div><h2>'.h($product['name']).'</h2><div class="two-col-stat"><div class="metric"><div class="label">Price</div><div class="value">$'.number_format((float)$cost['selling_price'],2).'</div></div><div class="metric"><div class="label">Direct COGS</div><div class="value">$'.number_format((float)$cost['direct_cogs'],2).'</div></div></div>';
            if($cost['complete']&&can('costing.view_margin')) echo '<p><strong>Gross contribution:</strong> $'.number_format((float)$cost['gross_profit'],2).' · '.number_format((float)$cost['margin_pct'],1).'%</p><span class="badge good">Complete Cost</span>';
            else echo '<span class="badge warn">Needs Pricing</span><p class="muted">'.h(implode(' · ',$cost['warnings'])).'</p>';
            echo '<div style="margin-top:12px"><a class="btn small" href="?page=costing&product='.$product['id'].'">Packaging BOM</a></div></div>';
        }
        echo '</div>';

        $flavors=$costing->flavorCosts();
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Flavor Unit Costs</h2></div><table><thead><tr><th>Flavor</th><th>Recipe</th><th>Unit Cost</th><th>Status</th><th>Warnings</th></tr></thead><tbody>';
        foreach($flavors as $f)echo '<tr><td><strong>'.h($f['flavor_name']).'</strong></td><td>'.h($f['recipe_name']).' v'.h($f['version_number']).'</td><td>$'.number_format((float)$f['unit_cost'],4).'</td><td>'.($f['complete']?'<span class="badge good">Complete</span>':'<span class="badge warn">Needs Pricing</span>').'</td><td>'.h(implode(' · ',$f['warnings'])).'</td></tr>';
        echo '</tbody></table></div>';

        $selectedProduct=(int)($_GET['product']??0);
        if($selectedProduct&&can('products.manage')){
            $p=$db->one('SELECT * FROM products WHERE id=?',[$selectedProduct]);
            if($p){
                $packaging=$db->all('SELECT id,name,inventory_unit FROM packaging_items WHERE is_active=1 ORDER BY name');
                $units=$db->all('SELECT symbol,name FROM units ORDER BY unit_type,name');
                $bom=$db->all('SELECT ppc.*,pi.name packaging_name FROM product_packaging_components ppc JOIN packaging_items pi ON pi.id=ppc.packaging_item_id WHERE ppc.product_id=? ORDER BY ppc.id',[$selectedProduct]);
                echo '<div class="card" style="margin-top:18px"><h2 class="section-title">'.h($p['name']).' Packaging BOM</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_product_packaging"><input type="hidden" name="product_id" value="'.$selectedProduct.'"><label>Packaging Item<select name="packaging_item_id">';
                foreach($packaging as $item)echo '<option value="'.$item['id'].'">'.h($item['name']).'</option>';
                echo '</select></label><label>Quantity<input type="number" min="0" step="0.0001" name="quantity" value="1" required></label><label>Unit<select name="unit">';
                foreach($units as $u)echo '<option value="'.h($u['symbol']).'">'.h($u['name'].' ('.$u['symbol'].')').'</option>';
                echo '</select></label><div><button class="btn" style="margin-top:27px">Add / Update</button></div></form><div class="list" style="margin-top:12px">';
                foreach($bom as $b)echo '<div class="list-row"><span><strong>'.h($b['packaging_name']).'</strong> · '.h($b['quantity'].' '.$b['unit']).'</span><form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="remove_product_packaging"><input type="hidden" name="component_id" value="'.$b['id'].'"><button class="btn small danger" data-confirm="Remove this packaging component?">Remove</button></form></div>';
                if(!$bom)echo '<div class="empty">No product-level packaging components.</div>';
                echo '</div></div>';
            }
        }

        $history=$db->all(
            'SELECT pcs.*,p.name product_name
             FROM product_cost_snapshots pcs JOIN products p ON p.id=pcs.product_id
             ORDER BY pcs.id DESC LIMIT 30'
        );
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Cost Snapshot History</h2></div><table><thead><tr><th>Date</th><th>Product</th><th>COGS</th><th>Price</th><th>Margin</th><th>Trigger</th><th>Status</th></tr></thead><tbody>';
        foreach($history as $h)echo '<tr><td>'.h($h['created_at']).'</td><td>'.h($h['product_name']).'</td><td>$'.number_format((float)$h['direct_cogs'],2).'</td><td>$'.number_format((float)$h['selling_price'],2).'</td><td>'.number_format((float)$h['margin_pct'],1).'%</td><td>'.h($h['trigger_type']).'<div class="muted">'.h($h['trigger_reference']).'</div></td><td>'.($h['is_complete']?'<span class="badge good">Complete</span>':'<span class="badge warn">Incomplete</span>').'</td></tr>';
        if(!$history)echo '<tr><td colspan="7" class="empty">No cost snapshots yet.</td></tr>';
        echo '</tbody></table></div>';

        $impacts=$db->all(
            "SELECT a.created_at,a.trigger_reference,a.trigger_type,p.name product_name,
                    b.direct_cogs before_cogs,a.direct_cogs after_cogs,
                    b.margin_pct before_margin,a.margin_pct after_margin,
                    b.is_complete before_complete,a.is_complete after_complete
             FROM product_cost_snapshots b
             JOIN product_cost_snapshots a
               ON a.product_id=b.product_id
              AND a.trigger_reference=b.trigger_reference
              AND a.trigger_type=REPLACE(b.trigger_type,'_before','_after')
             JOIN products p ON p.id=a.product_id
             WHERE b.trigger_type LIKE '%_before'
               AND (
                    ABS(a.direct_cogs-b.direct_cogs) > 0.000001
                    OR ABS(a.margin_pct-b.margin_pct) > 0.0001
               )
             ORDER BY a.id DESC LIMIT 30"
        );
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Recent Cost Impacts</h2></div><table><thead><tr><th>Date</th><th>Product</th><th>Trigger</th><th>COGS Change</th><th>Margin Change</th><th>Status</th></tr></thead><tbody>';
        foreach($impacts as $i){$dc=(float)$i['after_cogs']-(float)$i['before_cogs'];$dm=(float)$i['after_margin']-(float)$i['before_margin'];echo '<tr><td>'.h($i['created_at']).'</td><td>'.h($i['product_name']).'</td><td>'.h(str_replace('_after','',$i['trigger_type'])).'<div class="muted">'.h($i['trigger_reference']).'</div></td><td>'.($dc>=0?'+':'').' 
            "SELECT h.*,s.name supplier,
             CASE WHEN si.item_type='ingredient' THEN (SELECT name FROM ingredients WHERE id=si.item_id)
                  ELSE (SELECT name FROM packaging_items WHERE id=si.item_id) END item_name
             FROM supplier_price_history h
             JOIN supplier_items si ON si.id=h.supplier_item_id
             JOIN suppliers s ON s.id=si.supplier_id
             ORDER BY h.id DESC LIMIT 30"
        );
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Supplier Cost Changes</h2></div><table><thead><tr><th>Date</th><th>Item</th><th>Supplier</th><th>Old Unit Cost</th><th>New Unit Cost</th><th>Delta</th></tr></thead><tbody>';
        foreach($priceChanges as $h){$old=(float)($h['old_unit_cost']??0);$new=(float)$h['unit_cost'];$delta=$new-$old;echo '<tr><td>'.h($h['effective_at']).'</td><td>'.h($h['item_name']).'</td><td>'.h($h['supplier']).'</td><td>$'.number_format($old,4).'</td><td>$'.number_format($new,4).'</td><td>'.($delta>=0?'+':'').'$'.number_format($delta,4).'</td></tr>';}
        if(!$priceChanges)echo '<tr><td colspan="6" class="empty">Price changes appear here after using Supplier → Update Price.</td></tr>';
        echo '</tbody></table></div>';

        Ui::layoutEnd();
        exit;
    }

    http_response_code(404);
    exit('Page not found.');
}
.number_format((float)($costByItem[(int)$item['id']]??0),4).'</td><td>';
                            if($version['status']==='draft'&&can('recipes.edit')) echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="remove_recipe_component"><input type="hidden" name="recipe_id" value="'.$recipeId.'"><input type="hidden" name="version_id" value="'.$versionId.'"><input type="hidden" name="recipe_item_id" value="'.$item['id'].'"><button class="btn small danger" data-confirm="Remove this component from the draft?">Remove</button></form>';
                            echo '</td></tr>';
                        }
                        if(!$items)echo '<tr><td colspan="5" class="empty">No components yet.</td></tr>';
                        echo '</tbody></table></div>';

                        if($version['status']==='draft'&&can('recipes.edit')){
                            $ingredients=$db->all('SELECT id,name FROM ingredients WHERE is_active=1 ORDER BY name');
                            $packaging=$db->all('SELECT id,name FROM packaging_items WHERE is_active=1 ORDER BY name');
                            $nested=$db->all('SELECT id,name FROM recipes WHERE is_active=1 AND id<>? ORDER BY name',[$recipeId]);
                            $units=$units??$db->all('SELECT symbol,name FROM units ORDER BY unit_type,name');
                            echo '<div style="margin-top:16px"><h3>Add Component</h3><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="add_recipe_component"><input type="hidden" name="recipe_id" value="'.$recipeId.'"><input type="hidden" name="version_id" value="'.$versionId.'"><label class="span-2">Component<select name="component_ref" required><option value="">Select component</option><optgroup label="Ingredients">';
                            foreach($ingredients as $i)echo '<option value="ingredient:'.$i['id'].'">'.h($i['name']).'</option>';
                            echo '</optgroup><optgroup label="Packaging">';
                            foreach($packaging as $p)echo '<option value="packaging:'.$p['id'].'">'.h($p['name']).'</option>';
                            echo '</optgroup><optgroup label="Sub-recipes">';
                            foreach($nested as $n)echo '<option value="recipe:'.$n['id'].'">'.h($n['name']).'</option>';
                            echo '</optgroup></select></label><label>Quantity<input type="number" min="0" step="0.0001" name="quantity" required></label><label>Unit<select name="unit">';
                            foreach($units as $u)echo '<option value="'.h($u['symbol']).'">'.h($u['name'].' ('.$u['symbol'].')').'</option>';
                            echo '</select></label><div class="span-2 actions"><button class="btn">Add Component</button>';
                            if(can('recipes.publish')) echo '<button class="btn primary" type="submit" name="form_action" value="publish_recipe" data-confirm="Publish this version and retire the previous published version?">Publish Version</button>';
                            echo '</div></form></div>';
                        }
                        echo '</div>';
                    }
                }
            }
        }

        Ui::layoutEnd();
        exit;
    }

    if ($page === 'costing') {
        require_permission('costing.view');
        Ui::layoutStart('Costing','costing');
        Ui::pageHead('Costing & Margin','Live recipe COGS and product margin driven by supplier pricing.','<a class="btn" href="?page=recipes">Recipes</a>');

        if(can('costing.snapshot')) echo '<form method="post" style="margin-bottom:18px">'.Ui::csrf().'<input type="hidden" name="form_action" value="capture_cost_snapshot"><button class="btn primary">Capture Cost Snapshot</button></form>';

        $products=$db->all('SELECT * FROM products WHERE is_active=1 ORDER BY id');
        echo '<div class="grid cols-3">';
        foreach($products as $product){
            try{$cost=$costing->productCost((int)$product['id']);}
            catch(Throwable $e){echo '<div class="card"><h2>'.h($product['name']).'</h2><div class="alert danger">'.h($e->getMessage()).'</div></div>';continue;}
            echo '<div class="card"><div class="kicker">'.h($product['sku']).'</div><h2>'.h($product['name']).'</h2><div class="two-col-stat"><div class="metric"><div class="label">Price</div><div class="value">$'.number_format((float)$cost['selling_price'],2).'</div></div><div class="metric"><div class="label">Direct COGS</div><div class="value">$'.number_format((float)$cost['direct_cogs'],2).'</div></div></div>';
            if($cost['complete']&&can('costing.view_margin')) echo '<p><strong>Gross contribution:</strong> $'.number_format((float)$cost['gross_profit'],2).' · '.number_format((float)$cost['margin_pct'],1).'%</p><span class="badge good">Complete Cost</span>';
            else echo '<span class="badge warn">Needs Pricing</span><p class="muted">'.h(implode(' · ',$cost['warnings'])).'</p>';
            echo '<div style="margin-top:12px"><a class="btn small" href="?page=costing&product='.$product['id'].'">Packaging BOM</a></div></div>';
        }
        echo '</div>';

        $flavors=$costing->flavorCosts();
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Flavor Unit Costs</h2></div><table><thead><tr><th>Flavor</th><th>Recipe</th><th>Unit Cost</th><th>Status</th><th>Warnings</th></tr></thead><tbody>';
        foreach($flavors as $f)echo '<tr><td><strong>'.h($f['flavor_name']).'</strong></td><td>'.h($f['recipe_name']).' v'.h($f['version_number']).'</td><td>$'.number_format((float)$f['unit_cost'],4).'</td><td>'.($f['complete']?'<span class="badge good">Complete</span>':'<span class="badge warn">Needs Pricing</span>').'</td><td>'.h(implode(' · ',$f['warnings'])).'</td></tr>';
        echo '</tbody></table></div>';

        $selectedProduct=(int)($_GET['product']??0);
        if($selectedProduct&&can('products.manage')){
            $p=$db->one('SELECT * FROM products WHERE id=?',[$selectedProduct]);
            if($p){
                $packaging=$db->all('SELECT id,name,inventory_unit FROM packaging_items WHERE is_active=1 ORDER BY name');
                $units=$db->all('SELECT symbol,name FROM units ORDER BY unit_type,name');
                $bom=$db->all('SELECT ppc.*,pi.name packaging_name FROM product_packaging_components ppc JOIN packaging_items pi ON pi.id=ppc.packaging_item_id WHERE ppc.product_id=? ORDER BY ppc.id',[$selectedProduct]);
                echo '<div class="card" style="margin-top:18px"><h2 class="section-title">'.h($p['name']).' Packaging BOM</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_product_packaging"><input type="hidden" name="product_id" value="'.$selectedProduct.'"><label>Packaging Item<select name="packaging_item_id">';
                foreach($packaging as $item)echo '<option value="'.$item['id'].'">'.h($item['name']).'</option>';
                echo '</select></label><label>Quantity<input type="number" min="0" step="0.0001" name="quantity" value="1" required></label><label>Unit<select name="unit">';
                foreach($units as $u)echo '<option value="'.h($u['symbol']).'">'.h($u['name'].' ('.$u['symbol'].')').'</option>';
                echo '</select></label><div><button class="btn" style="margin-top:27px">Add / Update</button></div></form><div class="list" style="margin-top:12px">';
                foreach($bom as $b)echo '<div class="list-row"><span><strong>'.h($b['packaging_name']).'</strong> · '.h($b['quantity'].' '.$b['unit']).'</span><form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="remove_product_packaging"><input type="hidden" name="component_id" value="'.$b['id'].'"><button class="btn small danger" data-confirm="Remove this packaging component?">Remove</button></form></div>';
                if(!$bom)echo '<div class="empty">No product-level packaging components.</div>';
                echo '</div></div>';
            }
        }

        $history=$db->all(
            'SELECT pcs.*,p.name product_name
             FROM product_cost_snapshots pcs JOIN products p ON p.id=pcs.product_id
             ORDER BY pcs.id DESC LIMIT 30'
        );
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Cost Snapshot History</h2></div><table><thead><tr><th>Date</th><th>Product</th><th>COGS</th><th>Price</th><th>Margin</th><th>Trigger</th><th>Status</th></tr></thead><tbody>';
        foreach($history as $h)echo '<tr><td>'.h($h['created_at']).'</td><td>'.h($h['product_name']).'</td><td>$'.number_format((float)$h['direct_cogs'],2).'</td><td>$'.number_format((float)$h['selling_price'],2).'</td><td>'.number_format((float)$h['margin_pct'],1).'%</td><td>'.h($h['trigger_type']).'<div class="muted">'.h($h['trigger_reference']).'</div></td><td>'.($h['is_complete']?'<span class="badge good">Complete</span>':'<span class="badge warn">Incomplete</span>').'</td></tr>';
        if(!$history)echo '<tr><td colspan="7" class="empty">No cost snapshots yet.</td></tr>';
        echo '</tbody></table></div>';

        $priceChanges=$db->all(
            "SELECT h.*,s.name supplier,
             CASE WHEN si.item_type='ingredient' THEN (SELECT name FROM ingredients WHERE id=si.item_id)
                  ELSE (SELECT name FROM packaging_items WHERE id=si.item_id) END item_name
             FROM supplier_price_history h
             JOIN supplier_items si ON si.id=h.supplier_item_id
             JOIN suppliers s ON s.id=si.supplier_id
             ORDER BY h.id DESC LIMIT 30"
        );
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Supplier Cost Changes</h2></div><table><thead><tr><th>Date</th><th>Item</th><th>Supplier</th><th>Old Unit Cost</th><th>New Unit Cost</th><th>Delta</th></tr></thead><tbody>';
        foreach($priceChanges as $h){$old=(float)($h['old_unit_cost']??0);$new=(float)$h['unit_cost'];$delta=$new-$old;echo '<tr><td>'.h($h['effective_at']).'</td><td>'.h($h['item_name']).'</td><td>'.h($h['supplier']).'</td><td>$'.number_format($old,4).'</td><td>$'.number_format($new,4).'</td><td>'.($delta>=0?'+':'').'$'.number_format($delta,4).'</td></tr>';}
        if(!$priceChanges)echo '<tr><td colspan="6" class="empty">Price changes appear here after using Supplier → Update Price.</td></tr>';
        echo '</tbody></table></div>';

        Ui::layoutEnd();
        exit;
    }

    http_response_code(404);
    exit('Page not found.');
}
.number_format($dc,2).'</td><td>'.($dm>=0?'+':'').number_format($dm,2).' pts</td><td>'.(($i['before_complete']&&$i['after_complete'])?'<span class="badge good">Complete</span>':'<span class="badge warn">Incomplete</span>').'</td></tr>';}
        if(!$impacts)echo '<tr><td colspan="6" class="empty">Before/after cost impacts appear after supplier-price or packaging changes.</td></tr>';
        echo '</tbody></table></div>';

        $priceChanges=$db->all(
            "SELECT h.*,s.name supplier,
             CASE WHEN si.item_type='ingredient' THEN (SELECT name FROM ingredients WHERE id=si.item_id)
                  ELSE (SELECT name FROM packaging_items WHERE id=si.item_id) END item_name
             FROM supplier_price_history h
             JOIN supplier_items si ON si.id=h.supplier_item_id
             JOIN suppliers s ON s.id=si.supplier_id
             ORDER BY h.id DESC LIMIT 30"
        );
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Supplier Cost Changes</h2></div><table><thead><tr><th>Date</th><th>Item</th><th>Supplier</th><th>Old Unit Cost</th><th>New Unit Cost</th><th>Delta</th></tr></thead><tbody>';
        foreach($priceChanges as $h){$old=(float)($h['old_unit_cost']??0);$new=(float)$h['unit_cost'];$delta=$new-$old;echo '<tr><td>'.h($h['effective_at']).'</td><td>'.h($h['item_name']).'</td><td>'.h($h['supplier']).'</td><td>$'.number_format($old,4).'</td><td>$'.number_format($new,4).'</td><td>'.($delta>=0?'+':'').'$'.number_format($delta,4).'</td></tr>';}
        if(!$priceChanges)echo '<tr><td colspan="6" class="empty">Price changes appear here after using Supplier → Update Price.</td></tr>';
        echo '</tbody></table></div>';

        Ui::layoutEnd();
        exit;
    }

    http_response_code(404);
    exit('Page not found.');
}
.number_format((float)($costByItem[(int)$item['id']]??0),4).'</td><td>';
                            if($version['status']==='draft'&&can('recipes.edit')) echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="remove_recipe_component"><input type="hidden" name="recipe_id" value="'.$recipeId.'"><input type="hidden" name="version_id" value="'.$versionId.'"><input type="hidden" name="recipe_item_id" value="'.$item['id'].'"><button class="btn small danger" data-confirm="Remove this component from the draft?">Remove</button></form>';
                            echo '</td></tr>';
                        }
                        if(!$items)echo '<tr><td colspan="5" class="empty">No components yet.</td></tr>';
                        echo '</tbody></table></div>';

                        if($version['status']==='draft'&&can('recipes.edit')){
                            $ingredients=$db->all('SELECT id,name FROM ingredients WHERE is_active=1 ORDER BY name');
                            $packaging=$db->all('SELECT id,name FROM packaging_items WHERE is_active=1 ORDER BY name');
                            $nested=$db->all('SELECT id,name FROM recipes WHERE is_active=1 AND id<>? ORDER BY name',[$recipeId]);
                            $units=$units??$db->all('SELECT symbol,name FROM units ORDER BY unit_type,name');
                            echo '<div style="margin-top:16px"><h3>Add Component</h3><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="add_recipe_component"><input type="hidden" name="recipe_id" value="'.$recipeId.'"><input type="hidden" name="version_id" value="'.$versionId.'"><label class="span-2">Component<select name="component_ref" required><option value="">Select component</option><optgroup label="Ingredients">';
                            foreach($ingredients as $i)echo '<option value="ingredient:'.$i['id'].'">'.h($i['name']).'</option>';
                            echo '</optgroup><optgroup label="Packaging">';
                            foreach($packaging as $p)echo '<option value="packaging:'.$p['id'].'">'.h($p['name']).'</option>';
                            echo '</optgroup><optgroup label="Sub-recipes">';
                            foreach($nested as $n)echo '<option value="recipe:'.$n['id'].'">'.h($n['name']).'</option>';
                            echo '</optgroup></select></label><label>Quantity<input type="number" min="0" step="0.0001" name="quantity" required></label><label>Unit<select name="unit">';
                            foreach($units as $u)echo '<option value="'.h($u['symbol']).'">'.h($u['name'].' ('.$u['symbol'].')').'</option>';
                            echo '</select></label><div class="span-2 actions"><button class="btn">Add Component</button>';
                            if(can('recipes.publish')) echo '<button class="btn primary" type="submit" name="form_action" value="publish_recipe" data-confirm="Publish this version and retire the previous published version?">Publish Version</button>';
                            echo '</div></form></div>';
                        }
                        echo '</div>';
                    }
                }
            }
        }

        Ui::layoutEnd();
        exit;
    }

    if ($page === 'costing') {
        require_permission('costing.view');
        Ui::layoutStart('Costing','costing');
        Ui::pageHead('Costing & Margin','Live recipe COGS and product margin driven by supplier pricing.','<a class="btn" href="?page=recipes">Recipes</a>');

        if(can('costing.snapshot')) echo '<form method="post" style="margin-bottom:18px">'.Ui::csrf().'<input type="hidden" name="form_action" value="capture_cost_snapshot"><button class="btn primary">Capture Cost Snapshot</button></form>';

        $products=$db->all('SELECT * FROM products WHERE is_active=1 ORDER BY id');
        echo '<div class="grid cols-3">';
        foreach($products as $product){
            try{$cost=$costing->productCost((int)$product['id']);}
            catch(Throwable $e){echo '<div class="card"><h2>'.h($product['name']).'</h2><div class="alert danger">'.h($e->getMessage()).'</div></div>';continue;}
            echo '<div class="card"><div class="kicker">'.h($product['sku']).'</div><h2>'.h($product['name']).'</h2><div class="two-col-stat"><div class="metric"><div class="label">Price</div><div class="value">$'.number_format((float)$cost['selling_price'],2).'</div></div><div class="metric"><div class="label">Direct COGS</div><div class="value">$'.number_format((float)$cost['direct_cogs'],2).'</div></div></div>';
            if($cost['complete']&&can('costing.view_margin')) echo '<p><strong>Gross contribution:</strong> $'.number_format((float)$cost['gross_profit'],2).' · '.number_format((float)$cost['margin_pct'],1).'%</p><span class="badge good">Complete Cost</span>';
            else echo '<span class="badge warn">Needs Pricing</span><p class="muted">'.h(implode(' · ',$cost['warnings'])).'</p>';
            echo '<div style="margin-top:12px"><a class="btn small" href="?page=costing&product='.$product['id'].'">Packaging BOM</a></div></div>';
        }
        echo '</div>';

        $flavors=$costing->flavorCosts();
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Flavor Unit Costs</h2></div><table><thead><tr><th>Flavor</th><th>Recipe</th><th>Unit Cost</th><th>Status</th><th>Warnings</th></tr></thead><tbody>';
        foreach($flavors as $f)echo '<tr><td><strong>'.h($f['flavor_name']).'</strong></td><td>'.h($f['recipe_name']).' v'.h($f['version_number']).'</td><td>$'.number_format((float)$f['unit_cost'],4).'</td><td>'.($f['complete']?'<span class="badge good">Complete</span>':'<span class="badge warn">Needs Pricing</span>').'</td><td>'.h(implode(' · ',$f['warnings'])).'</td></tr>';
        echo '</tbody></table></div>';

        $selectedProduct=(int)($_GET['product']??0);
        if($selectedProduct&&can('products.manage')){
            $p=$db->one('SELECT * FROM products WHERE id=?',[$selectedProduct]);
            if($p){
                $packaging=$db->all('SELECT id,name,inventory_unit FROM packaging_items WHERE is_active=1 ORDER BY name');
                $units=$db->all('SELECT symbol,name FROM units ORDER BY unit_type,name');
                $bom=$db->all('SELECT ppc.*,pi.name packaging_name FROM product_packaging_components ppc JOIN packaging_items pi ON pi.id=ppc.packaging_item_id WHERE ppc.product_id=? ORDER BY ppc.id',[$selectedProduct]);
                echo '<div class="card" style="margin-top:18px"><h2 class="section-title">'.h($p['name']).' Packaging BOM</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="save_product_packaging"><input type="hidden" name="product_id" value="'.$selectedProduct.'"><label>Packaging Item<select name="packaging_item_id">';
                foreach($packaging as $item)echo '<option value="'.$item['id'].'">'.h($item['name']).'</option>';
                echo '</select></label><label>Quantity<input type="number" min="0" step="0.0001" name="quantity" value="1" required></label><label>Unit<select name="unit">';
                foreach($units as $u)echo '<option value="'.h($u['symbol']).'">'.h($u['name'].' ('.$u['symbol'].')').'</option>';
                echo '</select></label><div><button class="btn" style="margin-top:27px">Add / Update</button></div></form><div class="list" style="margin-top:12px">';
                foreach($bom as $b)echo '<div class="list-row"><span><strong>'.h($b['packaging_name']).'</strong> · '.h($b['quantity'].' '.$b['unit']).'</span><form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="remove_product_packaging"><input type="hidden" name="component_id" value="'.$b['id'].'"><button class="btn small danger" data-confirm="Remove this packaging component?">Remove</button></form></div>';
                if(!$bom)echo '<div class="empty">No product-level packaging components.</div>';
                echo '</div></div>';
            }
        }

        $history=$db->all(
            'SELECT pcs.*,p.name product_name
             FROM product_cost_snapshots pcs JOIN products p ON p.id=pcs.product_id
             ORDER BY pcs.id DESC LIMIT 30'
        );
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Cost Snapshot History</h2></div><table><thead><tr><th>Date</th><th>Product</th><th>COGS</th><th>Price</th><th>Margin</th><th>Trigger</th><th>Status</th></tr></thead><tbody>';
        foreach($history as $h)echo '<tr><td>'.h($h['created_at']).'</td><td>'.h($h['product_name']).'</td><td>$'.number_format((float)$h['direct_cogs'],2).'</td><td>$'.number_format((float)$h['selling_price'],2).'</td><td>'.number_format((float)$h['margin_pct'],1).'%</td><td>'.h($h['trigger_type']).'<div class="muted">'.h($h['trigger_reference']).'</div></td><td>'.($h['is_complete']?'<span class="badge good">Complete</span>':'<span class="badge warn">Incomplete</span>').'</td></tr>';
        if(!$history)echo '<tr><td colspan="7" class="empty">No cost snapshots yet.</td></tr>';
        echo '</tbody></table></div>';

        $priceChanges=$db->all(
            "SELECT h.*,s.name supplier,
             CASE WHEN si.item_type='ingredient' THEN (SELECT name FROM ingredients WHERE id=si.item_id)
                  ELSE (SELECT name FROM packaging_items WHERE id=si.item_id) END item_name
             FROM supplier_price_history h
             JOIN supplier_items si ON si.id=h.supplier_item_id
             JOIN suppliers s ON s.id=si.supplier_id
             ORDER BY h.id DESC LIMIT 30"
        );
        echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Supplier Cost Changes</h2></div><table><thead><tr><th>Date</th><th>Item</th><th>Supplier</th><th>Old Unit Cost</th><th>New Unit Cost</th><th>Delta</th></tr></thead><tbody>';
        foreach($priceChanges as $h){$old=(float)($h['old_unit_cost']??0);$new=(float)$h['unit_cost'];$delta=$new-$old;echo '<tr><td>'.h($h['effective_at']).'</td><td>'.h($h['item_name']).'</td><td>'.h($h['supplier']).'</td><td>$'.number_format($old,4).'</td><td>$'.number_format($new,4).'</td><td>'.($delta>=0?'+':'').'$'.number_format($delta,4).'</td></tr>';}
        if(!$priceChanges)echo '<tr><td colspan="6" class="empty">Price changes appear here after using Supplier → Update Price.</td></tr>';
        echo '</tbody></table></div>';

        Ui::layoutEnd();
        exit;
    }

    http_response_code(404);
    exit('Page not found.');
}
