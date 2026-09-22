<?php
function handle_phase3_page(string $page): never
{
    global $db,$demandPlanning;
    $uid=(int)user()['id'];

    if($page!=='planning'){
        http_response_code(404);
        exit('Page not found.');
    }

    require_permission('planning.view');

    if($_SERVER['REQUEST_METHOD']==='POST'){
        Security::validateCsrf();
        $action=$_POST['form_action']??'';
        try{
            switch($action){
                case 'allocate_order_flavor':
                    require_permission('orders.allocate_flavors');
                    $orderItemId=(int)($_POST['order_item_id']??0);
                    $flavorId=(int)($_POST['flavor_id']??0);
                    $quantity=(int)($_POST['quantity']??0);
                    $demandPlanning->allocateFlavor($orderItemId,$flavorId,$quantity);
                    audit('orders.flavor_allocated','order_item',$orderItemId,null,[
                        'flavor_id'=>$flavorId,'quantity'=>$quantity,
                    ]);
                    flash('success','Flavor allocation updated.');
                    redirect('?page=planning');

                case 'create_plan':
                    require_permission('planning.manage');
                    $planId=$demandPlanning->createPlan(
                        trim($_POST['start_date']??''),
                        trim($_POST['end_date']??''),
                        trim($_POST['notes']??'')?:null,
                        $uid
                    );
                    audit('planning.plan_created','production_plan',$planId,null,null);
                    flash('success','Production plan created and demand calculated.');
                    redirect('?page=planning&plan='.$planId);

                case 'rebuild_plan':
                    require_permission('planning.manage');
                    $planId=(int)($_POST['plan_id']??0);
                    $result=$demandPlanning->rebuild($planId);
                    audit('planning.plan_rebuilt','production_plan',$planId,null,$result);
                    flash('success','Production plan rebuilt from current orders, recipes, inventory and purchasing.');
                    redirect('?page=planning&plan='.$planId);

                case 'set_plan_quantity':
                    require_permission('planning.manage');
                    $planId=(int)($_POST['plan_id']??0);
                    $flavorId=(int)($_POST['flavor_id']??0);
                    $quantity=(int)($_POST['planned_quantity']??0);
                    $demandPlanning->setPlannedQuantity($planId,$flavorId,$quantity);
                    audit('planning.quantity_updated','production_plan',$planId,null,[
                        'flavor_id'=>$flavorId,'planned_quantity'=>$quantity,
                    ]);
                    flash('success','Planned quantity updated and material requirements recalculated.');
                    redirect('?page=planning&plan='.$planId);

                case 'lock_plan':
                    require_permission('planning.lock');
                    $planId=(int)($_POST['plan_id']??0);
                    $demandPlanning->lock($planId,$uid);
                    audit('planning.plan_locked','production_plan',$planId,null,null);
                    flash('success','Production plan locked.');
                    redirect('?page=planning&plan='.$planId);

                case 'launch_plan':
                    require_permission('planning.lock');
                    require_permission('production.create_batch');
                    $planId=(int)($_POST['plan_id']??0);
                    $batchId=$demandPlanning->launchProduction($planId,$uid);
                    audit('planning.plan_launched','production_plan',$planId,null,['batch_id'=>$batchId]);
                    flash('success','Production launched. Batch created from the locked plan.');
                    redirect('?page=production');

                case 'cancel_plan':
                    require_permission('planning.manage');
                    $planId=(int)($_POST['plan_id']??0);
                    $demandPlanning->cancel($planId);
                    audit('planning.plan_cancelled','production_plan',$planId,null,null);
                    flash('success','Production plan cancelled.');
                    redirect('?page=planning');
            }
        }catch(Throwable $e){
            flash('danger',$e->getMessage());
            $target='?page=planning';
            if(!empty($_POST['plan_id'])) $target.='&plan='.(int)$_POST['plan_id'];
            redirect($target);
        }
    }

    Ui::layoutStart('Production Planning','planning');
    Ui::pageHead(
        'Production Planning',
        'Turn confirmed order flavor allocations into production quantities, material requirements and a locked production batch.',
        '<a class="btn" href="?page=production">Production Board</a>'
    );

    $eligibleItems=$db->all(
        "SELECT oi.id order_item_id,oi.quantity order_quantity,
                o.id order_id,o.order_number,o.status,o.fulfillment_at,
                p.name product_name,COALESCE(NULLIF(p.box_capacity,0),1) box_capacity,
                COALESCE(SUM(oif.quantity),0) allocated_units
         FROM order_items oi
         JOIN orders o ON o.id=oi.order_id
         JOIN products p ON p.id=oi.product_id
         LEFT JOIN order_item_flavors oif ON oif.order_item_id=oi.id
         WHERE o.status IN ('new','paid','production')
         GROUP BY oi.id,o.id,o.order_number,o.status,o.fulfillment_at,p.name,p.box_capacity,oi.quantity
         ORDER BY COALESCE(o.fulfillment_at,o.created_at),o.id,oi.id
         LIMIT 100"
    );
    $flavors=$db->all('SELECT id,name FROM flavors WHERE is_active=1 ORDER BY name');

    echo '<div class="card" style="margin-bottom:18px"><div class="table-head"><h2>Order Flavor Allocation</h2><span class="muted">Every ordered donut must have a flavor before a plan can lock.</span></div>';
    if(!$eligibleItems){
        echo '<div class="empty">No production-eligible orders.</div>';
    }else{
        echo '<table><thead><tr><th>Order</th><th>Product</th><th>Required</th><th>Allocated</th><th>Current Mix</th><th>Update Flavor</th></tr></thead><tbody>';
        foreach($eligibleItems as $item){
            $required=max(1,(int)$item['box_capacity'])*max(1,(int)$item['order_quantity']);
            $allocations=$db->all(
                'SELECT oif.flavor_id,oif.quantity,f.name
                 FROM order_item_flavors oif
                 JOIN flavors f ON f.id=oif.flavor_id
                 WHERE oif.order_item_id=?
                 ORDER BY f.name',
                [$item['order_item_id']]
            );
            $mix=[];
            foreach($allocations as $a) $mix[]=$a['name'].' × '.$a['quantity'];
            $complete=(int)$item['allocated_units']===$required;
            echo '<tr><td><strong>'.h($item['order_number']).'</strong><div class="muted">'.h($item['fulfillment_at']?:'No fulfillment time').'</div></td><td>'.h($item['product_name']).'</td><td>'.$required.'</td><td><span class="badge '.($complete?'good':'warn').'">'.h($item['allocated_units']).' / '.$required.'</span></td><td>'.h($mix?implode(', ',$mix):'Not allocated').'</td><td>';
            if(can('orders.allocate_flavors')){
                echo '<form method="post" class="actions">'.Ui::csrf().'<input type="hidden" name="form_action" value="allocate_order_flavor"><input type="hidden" name="order_item_id" value="'.$item['order_item_id'].'"><select name="flavor_id" required><option value="">Flavor</option>';
                foreach($flavors as $flavor) echo '<option value="'.$flavor['id'].'">'.h($flavor['name']).'</option>';
                echo '</select><input type="number" min="0" max="'.$required.'" name="quantity" value="1" style="width:78px"><button class="btn small">Set</button></form>';
            }else echo '—';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    if(can('planning.manage')){
        $today=date('Y-m-d');
        $week=(new DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d');
        echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Create Production Plan</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="create_plan"><label>Start Date<input type="date" name="start_date" value="'.h($today).'" required></label><label>End Date<input type="date" name="end_date" value="'.h($week).'" required></label><label class="span-2">Notes<textarea name="notes" placeholder="Weekend production, catering run, etc."></textarea></label><button class="btn primary">Build Plan</button></form></div>';
    }

    $selectedPlanId=(int)($_GET['plan']??0);
    if($selectedPlanId){
        $plan=$db->one(
            'SELECT pp.*,u.name creator,lu.name locker,
                    pb.id batch_id,pb.batch_code,pb.status batch_status
             FROM production_plans pp
             LEFT JOIN users u ON u.id=pp.created_by
             LEFT JOIN users lu ON lu.id=pp.locked_by
             LEFT JOIN production_batches pb ON pb.production_plan_id=pp.id
             WHERE pp.id=?',
            [$selectedPlanId]
        );

        if($plan){
            echo '<div class="card" style="margin-bottom:18px"><div class="page-head"><div><div class="kicker">Production Plan</div><h1>'.h($plan['plan_code']).'</h1><div class="muted">'.h($plan['start_date']).' → '.h($plan['end_date']).' · Built '.h($plan['built_at']?:'Not built').'</div></div><div>'.Ui::statusBadge($plan['status']).'</div></div>';
            echo '<div class="grid cols-4"><div class="metric"><div class="label">Unallocated Units</div><div class="value">'.(int)$plan['unallocated_units'].'</div></div><div class="metric"><div class="label">Blocking Issues</div><div class="value">'.(int)$plan['blocking_issue_count'].'</div></div><div class="metric"><div class="label">Warnings</div><div class="value">'.(int)$plan['warning_count'].'</div></div><div class="metric"><div class="label">Batch</div><div class="value" style="font-size:18px">'.h($plan['batch_code']?:'Not launched').'</div></div></div>';
            if($plan['notes']) echo '<p class="muted" style="margin-top:14px">'.h($plan['notes']).'</p>';
            echo '</div>';

            $planItems=$db->all(
                'SELECT ppi.*,f.name flavor
                 FROM production_plan_items ppi
                 JOIN flavors f ON f.id=ppi.flavor_id
                 WHERE ppi.production_plan_id=?
                 ORDER BY f.name',
                [$selectedPlanId]
            );
            echo '<div class="table-card" style="margin-bottom:18px"><div class="table-head"><h2>Flavor Production</h2></div><table><thead><tr><th>Flavor</th><th>Order Demand</th><th>Planned</th><th>Extra</th><th></th></tr></thead><tbody>';
            foreach($planItems as $pi){
                $extra=(int)$pi['planned_quantity']-(int)$pi['required_quantity'];
                echo '<tr><td><strong>'.h($pi['flavor']).'</strong></td><td>'.(int)$pi['required_quantity'].'</td><td>'.(int)$pi['planned_quantity'].'</td><td>'.($extra>0?'+'.$extra:'0').'</td><td>';
                if($plan['status']==='draft'&&can('planning.manage')){
                    echo '<form method="post" class="actions">'.Ui::csrf().'<input type="hidden" name="form_action" value="set_plan_quantity"><input type="hidden" name="plan_id" value="'.$selectedPlanId.'"><input type="hidden" name="flavor_id" value="'.$pi['flavor_id'].'"><input type="number" min="'.(int)$pi['required_quantity'].'" name="planned_quantity" value="'.(int)$pi['planned_quantity'].'" style="width:90px"><button class="btn small">Update</button></form>';
                }else echo '—';
                echo '</td></tr>';
            }
            if(!$planItems) echo '<tr><td colspan="5" class="empty">No flavor demand in this plan.</td></tr>';
            echo '</tbody></table></div>';

            $requirements=$db->all(
                "SELECT ppr.*,
                        CASE WHEN ppr.item_type='ingredient'
                          THEN (SELECT name FROM ingredients WHERE id=ppr.item_id)
                          ELSE (SELECT name FROM packaging_items WHERE id=ppr.item_id)
                        END item_name
                 FROM production_plan_requirements ppr
                 WHERE ppr.production_plan_id=?
                 ORDER BY ppr.shortage_at_build DESC,item_name",
                [$selectedPlanId]
            );
            echo '<div class="table-card" style="margin-bottom:18px"><div class="table-head"><h2>Material Requirements</h2></div><table><thead><tr><th>Item</th><th>Required</th><th>On Hand</th><th>On Order</th><th>Shortage</th></tr></thead><tbody>';
            foreach($requirements as $req){
                $short=(float)$req['shortage_at_build'];
                echo '<tr><td><strong>'.h($req['item_name']).'</strong><div class="muted">'.h(ucfirst($req['item_type'])).'</div></td><td>'.number_format((float)$req['required_quantity'],3).' '.h($req['unit']).'</td><td>'.number_format((float)$req['on_hand_at_build'],3).'</td><td>'.number_format((float)$req['on_order_at_build'],3).'</td><td><span class="badge '.($short>0?'warn':'good').'">'.number_format($short,3).' '.h($req['unit']).'</span></td></tr>';
            }
            if(!$requirements) echo '<tr><td colspan="5" class="empty">No material requirements calculated.</td></tr>';
            echo '</tbody></table></div>';

            $issues=$db->all(
                'SELECT * FROM production_plan_issues WHERE production_plan_id=? ORDER BY FIELD(severity,"blocking","warning"),id',
                [$selectedPlanId]
            );
            echo '<div class="table-card" style="margin-bottom:18px"><div class="table-head"><h2>Plan Issues</h2></div><table><thead><tr><th>Severity</th><th>Code</th><th>Issue</th></tr></thead><tbody>';
            foreach($issues as $issue) echo '<tr><td><span class="badge '.($issue['severity']==='blocking'?'bad':'warn').'">'.h(ucfirst($issue['severity'])).'</span></td><td><span class="code">'.h($issue['issue_code']).'</span></td><td>'.h($issue['message']).'</td></tr>';
            if(!$issues) echo '<tr><td colspan="3" class="empty">No issues. This plan is clean.</td></tr>';
            echo '</tbody></table></div>';

            echo '<div class="actions" style="margin-bottom:24px">';
            if($plan['status']==='draft'&&can('planning.manage')){
                echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="rebuild_plan"><input type="hidden" name="plan_id" value="'.$selectedPlanId.'"><button class="btn">Rebuild from Current Data</button></form>';
            }
            if($plan['status']==='draft'&&can('planning.lock')){
                echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="lock_plan"><input type="hidden" name="plan_id" value="'.$selectedPlanId.'"><button class="btn primary" data-confirm="Lock this production plan? Changes to source orders or recipes will require a rebuild.">Lock Plan</button></form>';
            }
            if($plan['status']==='locked'&&can('planning.lock')&&can('production.create_batch')){
                echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="launch_plan"><input type="hidden" name="plan_id" value="'.$selectedPlanId.'"><button class="btn primary" data-confirm="Create the production batch and move linked orders into Production?">Launch Production</button></form>';
            }
            if(!in_array($plan['status'],['completed','cancelled','in_production'],true)&&can('planning.manage')){
                echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="cancel_plan"><input type="hidden" name="plan_id" value="'.$selectedPlanId.'"><button class="btn danger" data-confirm="Cancel this production plan?">Cancel Plan</button></form>';
            }
            echo '</div>';
        }
    }

    $plans=$db->all(
        'SELECT pp.*,u.name creator,
                (SELECT COUNT(*) FROM production_plan_orders ppo WHERE ppo.production_plan_id=pp.id) order_count,
                (SELECT COALESCE(SUM(planned_quantity),0) FROM production_plan_items ppi WHERE ppi.production_plan_id=pp.id) planned_units
         FROM production_plans pp
         LEFT JOIN users u ON u.id=pp.created_by
         ORDER BY pp.id DESC LIMIT 100'
    );
    echo '<div class="table-card"><div class="table-head"><h2>Production Plans</h2></div><table><thead><tr><th>Plan</th><th>Dates</th><th>Status</th><th>Orders</th><th>Units</th><th>Issues</th><th></th></tr></thead><tbody>';
    foreach($plans as $plan){
        echo '<tr><td><strong>'.h($plan['plan_code']).'</strong><div class="muted">'.h($plan['creator']).'</div></td><td>'.h($plan['start_date']).' → '.h($plan['end_date']).'</td><td>'.Ui::statusBadge($plan['status']).'</td><td>'.(int)$plan['order_count'].'</td><td>'.(int)$plan['planned_units'].'</td><td>'.((int)$plan['blocking_issue_count']).' blocking / '.((int)$plan['warning_count']).' warning</td><td><a class="btn small" href="?page=planning&plan='.$plan['id'].'">Open</a></td></tr>';
    }
    if(!$plans) echo '<tr><td colspan="7" class="empty">No production plans yet.</td></tr>';
    echo '</tbody></table></div>';

    Ui::layoutEnd();
    exit;
}
