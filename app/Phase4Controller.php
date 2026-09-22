<?php
function handle_phase4_page(string $page): never
{
    global $db,$productionExecution;
    $uid=(int)user()['id'];

    if($page!=='production'){
        http_response_code(404);
        exit('Page not found.');
    }

    require_permission('production.view');

    if($_SERVER['REQUEST_METHOD']==='POST'){
        Security::validateCsrf();
        $action=$_POST['form_action']??'';
        try{
            switch($action){
                case 'create_manual_batch':
                    require_permission('production.create_batch');
                    $batchId=$productionExecution->createManualBatch(
                        trim($_POST['scheduled_for']??'')?:null,
                        trim($_POST['notes']??'')?:null,
                        $uid
                    );
                    audit('production.batch_created','production_batch',$batchId,null,['source'=>'manual']);
                    flash('success','Manual production batch created.');
                    redirect('?page=production&batch='.$batchId);

                case 'add_batch_item':
                    require_permission('production.update_batch');
                    $batchId=(int)($_POST['batch_id']??0);
                    $itemId=$productionExecution->addBatchItem(
                        $batchId,
                        (int)($_POST['flavor_id']??0),
                        (int)($_POST['planned_quantity']??0)
                    );
                    audit('production.batch_item_saved','production_batch_item',$itemId,null,['batch_id'=>$batchId]);
                    flash('success','Batch flavor quantity saved.');
                    redirect('?page=production&batch='.$batchId);

                case 'remove_batch_item':
                    require_permission('production.update_batch');
                    $batchId=(int)($_POST['batch_id']??0);
                    $itemId=(int)($_POST['batch_item_id']??0);
                    $productionExecution->removeBatchItem($batchId,$itemId);
                    audit('production.batch_item_removed','production_batch_item',$itemId,null,['batch_id'=>$batchId]);
                    flash('success','Batch flavor removed.');
                    redirect('?page=production&batch='.$batchId);

                case 'initialize_batch':
                    require_permission('production.update_batch');
                    $batchId=(int)($_POST['batch_id']??0);
                    $result=$productionExecution->initializeBatch($batchId);
                    audit('production.batch_initialized','production_batch',$batchId,null,$result);
                    flash('success','Batch setup rebuilt: '.$result['materials'].' materials and '.$result['qc_checks'].' QC checks.');
                    redirect('?page=production&batch='.$batchId);

                case 'start_batch':
                    require_permission('production.update_batch');
                    $batchId=(int)($_POST['batch_id']??0);
                    $productionExecution->startBatch($batchId,$uid);
                    audit('production.batch_started','production_batch',$batchId,null,null);
                    flash('success','Production batch started.');
                    redirect('?page=production&batch='.$batchId);

                case 'advance_batch':
                    require_permission('production.update_batch');
                    $batchId=(int)($_POST['batch_id']??0);
                    $next=$productionExecution->advanceBatch($batchId,$uid);
                    audit('production.batch_advanced','production_batch',$batchId,null,['status'=>$next]);
                    flash('success','Batch advanced to '.ucwords(str_replace('_',' ',$next)).'.');
                    redirect('?page=production&batch='.$batchId);

                case 'set_actual_material':
                    require_permission('production.manage_materials');
                    $batchId=(int)($_POST['batch_id']??0);
                    $materialId=(int)($_POST['material_id']??0);
                    $productionExecution->setActualMaterial(
                        $materialId,
                        (float)($_POST['actual_quantity']??0)
                    );
                    audit('production.material_actual_updated','production_batch_material',$materialId,null,[
                        'actual_quantity'=>(float)($_POST['actual_quantity']??0),
                    ]);
                    flash('success','Actual material quantity updated.');
                    redirect('?page=production&batch='.$batchId);

                case 'commit_materials':
                    require_permission('production.manage_materials');
                    $batchId=(int)($_POST['batch_id']??0);
                    $result=$productionExecution->commitMaterials($batchId,$uid);
                    audit('production.materials_committed','production_batch',$batchId,null,$result);
                    flash('success','Material consumption committed to inventory.');
                    redirect('?page=production&batch='.$batchId);

                case 'record_qc':
                    require_permission('production.record_qc');
                    $batchId=(int)($_POST['batch_id']??0);
                    $checkId=(int)($_POST['check_id']??0);
                    $productionExecution->recordQc(
                        $checkId,
                        $_POST['result']??'pending',
                        trim($_POST['notes']??'')?:null,
                        $uid
                    );
                    audit('production.qc_recorded','production_qc_check',$checkId,null,['result'=>$_POST['result']??'']);
                    flash('success','QC result saved.');
                    redirect('?page=production&batch='.$batchId);

                case 'set_actual_output':
                    require_permission('production.update_batch');
                    $batchId=(int)($_POST['batch_id']??0);
                    $itemId=(int)($_POST['batch_item_id']??0);
                    $productionExecution->setActualOutput(
                        $itemId,
                        (int)($_POST['actual_quantity']??0)
                    );
                    audit('production.output_updated','production_batch_item',$itemId,null,[
                        'actual_quantity'=>(int)($_POST['actual_quantity']??0),
                    ]);
                    flash('success','Actual finished output updated.');
                    redirect('?page=production&batch='.$batchId);

                case 'record_waste':
                    require_permission('production.record_waste');
                    $batchId=(int)($_POST['batch_id']??0);
                    $wasteId=$productionExecution->recordWaste(
                        (int)($_POST['batch_item_id']??0),
                        (int)($_POST['quantity']??0),
                        (int)($_POST['reason_id']??0)?:null,
                        trim($_POST['notes']??'')?:null,
                        $uid
                    );
                    audit('production.waste_recorded','production_waste',$wasteId,null,['batch_id'=>$batchId]);
                    flash('success','Production waste recorded.');
                    redirect('?page=production&batch='.$batchId);

                case 'delete_waste':
                    require_permission('production.record_waste');
                    $batchId=(int)($_POST['batch_id']??0);
                    $wasteId=(int)($_POST['waste_id']??0);
                    $productionExecution->deleteWaste($wasteId);
                    audit('production.waste_deleted','production_waste',$wasteId,null,['batch_id'=>$batchId]);
                    flash('success','Waste entry removed.');
                    redirect('?page=production&batch='.$batchId);

                case 'assign_member':
                    require_permission('production.assign_team');
                    $batchId=(int)($_POST['batch_id']??0);
                    $memberId=(int)($_POST['member_id']??0);
                    $productionExecution->assignMember(
                        $batchId,$memberId,
                        trim($_POST['assignment_role']??'')?:null,
                        trim($_POST['station']??'')?:null,
                        $uid
                    );
                    audit('production.member_assigned','production_batch',$batchId,null,['user_id'=>$memberId]);
                    flash('success','Team assignment saved.');
                    redirect('?page=production&batch='.$batchId);

                case 'remove_assignment':
                    require_permission('production.assign_team');
                    $batchId=(int)($_POST['batch_id']??0);
                    $memberId=(int)($_POST['member_id']??0);
                    $productionExecution->removeAssignment($batchId,$memberId);
                    audit('production.member_unassigned','production_batch',$batchId,null,['user_id'=>$memberId]);
                    flash('success','Team assignment removed.');
                    redirect('?page=production&batch='.$batchId);

                case 'clock_in':
                    require_permission('production.track_labor');
                    $batchId=(int)($_POST['batch_id']??0);
                    $sessionId=$productionExecution->clockIn(
                        $batchId,$uid,trim($_POST['station']??'')?:null
                    );
                    audit('production.labor_clock_in','labor_session',$sessionId,null,['batch_id'=>$batchId]);
                    flash('success','Clocked into production.');
                    redirect('?page=production&batch='.$batchId);

                case 'clock_out':
                    require_permission('production.track_labor');
                    $result=$productionExecution->clockOut($uid);
                    audit('production.labor_clock_out','labor_session',$result['session_id'],null,$result);
                    flash('success','Clocked out: '.$result['minutes'].' minutes, $'.number_format((float)$result['labor_cost'],2).' labor cost.');
                    redirect('?page=production'.(!empty($_POST['batch_id'])?'&batch='.(int)$_POST['batch_id']:''));

                case 'save_production_stage':
                    require_permission('production.manage_settings');
                    $id=$productionExecution->saveStage(
                        (int)($_POST['id']??0)?:null,
                        trim($_POST['stage_key']??''),
                        trim($_POST['label']??''),
                        (int)($_POST['sort_order']??0),
                        (int)($_POST['is_active']??1)===1
                    );
                    audit('production.stage_saved','production_stage',$id,null,null);
                    flash('success','Production stage saved. New batch setups will use the updated template.');
                    redirect('?page=production&settings=1');

                case 'save_qc_template':
                    require_permission('production.manage_settings');
                    $id=$productionExecution->saveQcTemplate(
                        (int)($_POST['id']??0)?:null,
                        trim($_POST['check_key']??''),
                        trim($_POST['label']??''),
                        (int)($_POST['sort_order']??0),
                        (int)($_POST['is_active']??1)===1
                    );
                    audit('production.qc_template_saved','production_qc_template',$id,null,null);
                    flash('success','QC template saved. New batch setups will use the updated template.');
                    redirect('?page=production&settings=1');

                case 'save_waste_reason':
                    require_permission('production.manage_settings');
                    $id=$productionExecution->saveWasteReason(
                        (int)($_POST['id']??0)?:null,
                        trim($_POST['name']??''),
                        (int)($_POST['is_active']??1)===1
                    );
                    audit('production.waste_reason_saved','waste_reason',$id,null,null);
                    flash('success','Waste reason saved.');
                    redirect('?page=production&settings=1');

                case 'complete_batch':
                    require_permission('production.complete_batch');
                    $batchId=(int)($_POST['batch_id']??0);
                    $result=$productionExecution->completeBatch($batchId,$uid);
                    audit('production.batch_completed','production_batch',$batchId,null,$result);
                    flash('success','Batch completed. '.$result['finished_units'].' finished units added to inventory.');
                    redirect('?page=production&batch='.$batchId);

                case 'cancel_manual_batch':
                    require_permission('production.update_batch');
                    $batchId=(int)($_POST['batch_id']??0);
                    $productionExecution->cancelManualBatch($batchId);
                    audit('production.batch_cancelled','production_batch',$batchId,null,null);
                    flash('success','Manual batch cancelled.');
                    redirect('?page=production&batch='.$batchId);
            }
        }catch(Throwable $e){
            flash('danger',$e->getMessage());
            $target='?page=production';
            if(!empty($_POST['batch_id'])) $target.='&batch='.(int)$_POST['batch_id'];
            redirect($target);
        }
    }

    Ui::layoutStart('Production','production');
    Ui::pageHead(
        'Production Execution',
        'Run batches from scheduled production through materials, QC, packing and finished inventory.',
        can('planning.view')?'<a class="btn" href="?page=planning">Production Planning</a>':''
    );

    $openSession=$db->one(
        'SELECT ls.*,pb.batch_code
         FROM labor_sessions ls
         JOIN production_batches pb ON pb.id=ls.batch_id
         WHERE ls.user_id=? AND ls.ended_at IS NULL
         ORDER BY ls.id DESC LIMIT 1',
        [$uid]
    );
    if($openSession){
        echo '<div class="alert info"><strong>Clocked in:</strong> '.h($openSession['batch_code']).($openSession['station']?' · '.h($openSession['station']):'').' since '.h($openSession['started_at']).'. ';
        if(can('production.track_labor')){
            echo '<form method="post" style="display:inline">'.Ui::csrf().'<input type="hidden" name="form_action" value="clock_out"><input type="hidden" name="batch_id" value="'.$openSession['batch_id'].'"><button class="btn small">Clock Out</button></form>';
        }
        echo '</div>';
    }

    $active=(int)$db->scalar("SELECT COUNT(*) FROM production_batches WHERE status NOT IN ('completed','cancelled')");
    $scheduled=(int)$db->scalar("SELECT COUNT(*) FROM production_batches WHERE status='scheduled'");
    $finishedToday=(int)$db->scalar("SELECT COALESCE(SUM(quantity),0) FROM finished_inventory WHERE DATE(made_at)=CURDATE()");
    $laborToday=(float)$db->scalar("SELECT COALESCE(SUM(labor_cost),0) FROM labor_sessions WHERE DATE(started_at)=CURDATE() AND ended_at IS NOT NULL");
    echo '<div class="grid cols-4" style="margin-bottom:18px">';
    foreach([
        ['Active Batches',$active,'Open production work'],
        ['Scheduled',$scheduled,'Waiting to start'],
        ['Finished Today',$finishedToday,'Available finished units'],
        ['Labor Today','$'.number_format($laborToday,2),'Completed labor sessions'],
    ] as $metric){
        echo '<div class="card metric"><div class="label">'.h($metric[0]).'</div><div class="value">'.h($metric[1]).'</div><div class="sub">'.h($metric[2]).'</div></div>';
    }
    echo '</div>';

    if(can('production.create_batch')){
        echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Create Manual Batch</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="create_manual_batch"><label>Scheduled Date<input type="date" name="scheduled_for" value="'.h(date('Y-m-d')).'"></label><label>Notes<input name="notes" placeholder="Stock build, test run, event prep..."></label><button class="btn primary">Create Batch</button></form></div>';
    }

    $batchId=(int)($_GET['batch']??0);
    if($batchId){
        $batch=$db->one(
            'SELECT pb.*,pp.plan_code,
                    u.name creator
             FROM production_batches pb
             LEFT JOIN production_plans pp ON pp.id=pb.production_plan_id
             LEFT JOIN users u ON u.id=pb.created_by
             WHERE pb.id=?',
            [$batchId]
        );

        if($batch){
            $steps=$db->all(
                'SELECT * FROM production_batch_steps WHERE batch_id=? ORDER BY sort_order,id',
                [$batchId]
            );
            $currentStep=null;
            $nextStep=null;
            foreach($steps as $idx=>$candidate){
                if($candidate['step_key']===$batch['status']){
                    $currentStep=$candidate;
                    $nextStep=$steps[$idx+1]??null;
                    break;
                }
            }

            echo '<div class="card" style="margin-bottom:18px"><div class="page-head"><div><div class="kicker">Production Batch</div><h1>'.h($batch['batch_code']).'</h1><div class="muted">'.($batch['plan_code']?'Plan '.h($batch['plan_code']).' · ':'').($batch['scheduled_for']?'Scheduled '.h($batch['scheduled_for']).' · ':'').'Created by '.h($batch['creator']).'</div></div><div>'.Ui::statusBadge($batch['status']).'</div></div></div>';

            $items=$db->all(
                'SELECT pbi.*,f.name flavor,rv.version_number
                 FROM production_batch_items pbi
                 JOIN flavors f ON f.id=pbi.flavor_id
                 LEFT JOIN recipe_versions rv ON rv.id=pbi.recipe_version_id
                 WHERE pbi.batch_id=?
                 ORDER BY f.name',
                [$batchId]
            );

            echo '<div class="table-card" style="margin-bottom:18px"><div class="table-head"><h2>Batch Flavors</h2><span class="muted">'.count($items).' flavor'.(count($items)===1?'':'s').'</span></div><table><thead><tr><th>Flavor</th><th>Recipe</th><th>Planned</th><th>Actual</th><th>Waste</th><th>Good</th><th></th></tr></thead><tbody>';
            foreach($items as $item){
                $actual=$item['actual_quantity']===null?null:(int)$item['actual_quantity'];
                $good=$actual===null?'—':max(0,$actual-(int)$item['waste_quantity']);
                echo '<tr><td><strong>'.h($item['flavor']).'</strong></td><td>v'.h($item['version_number']??'—').'</td><td>'.(int)$item['planned_quantity'].'</td><td>'.($actual===null?'—':$actual).'</td><td>'.(int)$item['waste_quantity'].'</td><td>'.$good.'</td><td>';
                if($batch['status']==='scheduled'&&can('production.update_batch')){
                    echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="remove_batch_item"><input type="hidden" name="batch_id" value="'.$batchId.'"><input type="hidden" name="batch_item_id" value="'.$item['id'].'"><button class="btn small danger" data-confirm="Remove this flavor from the batch?">Remove</button></form>';
                }
                echo '</td></tr>';
            }
            if(!$items) echo '<tr><td colspan="7" class="empty">No flavors are assigned to this batch yet.</td></tr>';
            echo '</tbody></table></div>';

            if($batch['status']==='scheduled'&&can('production.update_batch')){
                $flavors=$db->all('SELECT id,name FROM flavors WHERE is_active=1 ORDER BY name');
                echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Add / Update Flavor Quantity</h2><form method="post" class="form-grid">'.Ui::csrf().'<input type="hidden" name="form_action" value="add_batch_item"><input type="hidden" name="batch_id" value="'.$batchId.'"><label>Flavor<select name="flavor_id" required><option value="">Select flavor</option>';
                foreach($flavors as $flavor) echo '<option value="'.$flavor['id'].'">'.h($flavor['name']).'</option>';
                echo '</select></label><label>Planned Quantity<input type="number" min="1" name="planned_quantity" value="12" required></label><button class="btn primary">Save Flavor</button></form></div>';
            }

            if($steps){
                echo '<div class="card" style="margin-bottom:18px"><h2 class="section-title">Production Stages</h2><div class="list">';
                foreach($steps as $step){
                    echo '<div class="list-row"><div><strong>'.h($step['label']).'</strong><div class="muted">'.h($step['started_at']?:'Not started').($step['completed_at']?' → '.h($step['completed_at']):'').'</div></div>'.Ui::statusBadge($step['status']).'</div>';
                }
                echo '</div></div>';
            }

            echo '<div class="actions" style="margin-bottom:18px">';
            if($batch['status']==='scheduled'&&can('production.update_batch')){
                echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="initialize_batch"><input type="hidden" name="batch_id" value="'.$batchId.'"><button class="btn">Initialize / Rebuild Setup</button></form>';
                if($items) echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="start_batch"><input type="hidden" name="batch_id" value="'.$batchId.'"><button class="btn primary" data-confirm="Start this production batch?">Start Batch</button></form>';
                if(empty($batch['production_plan_id'])) echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="cancel_manual_batch"><input type="hidden" name="batch_id" value="'.$batchId.'"><button class="btn danger" data-confirm="Cancel this manual batch?">Cancel Batch</button></form>';
            }elseif($currentStep&&$nextStep&&can('production.update_batch')){
                echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="advance_batch"><input type="hidden" name="batch_id" value="'.$batchId.'"><button class="btn primary">Complete '.h($currentStep['label']).' → '.h($nextStep['label']).'</button></form>';
            }elseif($currentStep&&!$nextStep&&can('production.complete_batch')){
                echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="complete_batch"><input type="hidden" name="batch_id" value="'.$batchId.'"><button class="btn primary" data-confirm="Complete this batch and create finished inventory?">Complete Batch</button></form>';
            }
            echo '</div>';

            $materials=$db->all(
                "SELECT pbm.*,
                        CASE WHEN pbm.item_type='ingredient'
                          THEN (SELECT name FROM ingredients WHERE id=pbm.item_id)
                          ELSE (SELECT name FROM packaging_items WHERE id=pbm.item_id)
                        END item_name
                 FROM production_batch_materials pbm
                 WHERE pbm.batch_id=?
                 ORDER BY pbm.item_type,item_name",
                [$batchId]
            );
            echo '<div class="table-card" style="margin-bottom:18px"><div class="table-head"><h2>Material Use</h2><span class="muted">Actual use posts to inventory only when committed.</span></div><table><thead><tr><th>Item</th><th>Theoretical</th><th>Actual</th><th>Variance</th><th>Status</th><th></th></tr></thead><tbody>';
            foreach($materials as $material){
                echo '<tr><td><strong>'.h($material['item_name']).'</strong><div class="muted">'.h(ucfirst($material['item_type'])).'</div></td><td>'.number_format((float)$material['theoretical_quantity'],3).' '.h($material['unit']).'</td><td>'.number_format((float)$material['actual_quantity'],3).' '.h($material['unit']).'</td><td>'.(((float)$material['variance_quantity']>0)?'+':'').number_format((float)$material['variance_quantity'],3).'</td><td>'.Ui::statusBadge($material['status']).'</td><td>';
                if($material['status']==='planned'&&can('production.manage_materials')&&!in_array($batch['status'],['completed','cancelled'],true)){
                    echo '<form method="post" class="actions">'.Ui::csrf().'<input type="hidden" name="form_action" value="set_actual_material"><input type="hidden" name="batch_id" value="'.$batchId.'"><input type="hidden" name="material_id" value="'.$material['id'].'"><input type="number" min="0" step="0.001" name="actual_quantity" value="'.h($material['actual_quantity']).'" style="width:110px"><button class="btn small">Update</button></form>';
                }
                echo '</td></tr>';
            }
            if(!$materials) echo '<tr><td colspan="6" class="empty">Initialize the batch to calculate materials.</td></tr>';
            echo '</tbody></table></div>';
            if($materials&&can('production.manage_materials')&&!in_array($batch['status'],['scheduled','completed','cancelled'],true)){
                $uncommitted=count(array_filter($materials,fn($m)=>$m['status']!=='committed'));
                if($uncommitted){
                    echo '<form method="post" style="margin:0 0 18px">'.Ui::csrf().'<input type="hidden" name="form_action" value="commit_materials"><input type="hidden" name="batch_id" value="'.$batchId.'"><button class="btn primary" data-confirm="Commit actual material usage to the inventory ledger? This cannot be edited afterward.">Commit Material Consumption</button></form>';
                }
            }

            $qc=$db->all(
                'SELECT pqc.*,f.name flavor
                 FROM production_qc_checks pqc
                 LEFT JOIN production_batch_items pbi ON pbi.id=pqc.batch_item_id
                 LEFT JOIN flavors f ON f.id=pbi.flavor_id
                 WHERE pqc.batch_id=?
                 ORDER BY f.name,pqc.sort_order,pqc.id',
                [$batchId]
            );
            echo '<div class="table-card" style="margin-bottom:18px"><div class="table-head"><h2>Quality Control</h2></div><table><thead><tr><th>Flavor</th><th>Check</th><th>Result</th><th>Notes</th><th></th></tr></thead><tbody>';
            foreach($qc as $check){
                echo '<tr><td>'.h($check['flavor']?:'Batch').'</td><td><strong>'.h($check['label']).'</strong></td><td>'.Ui::statusBadge($check['result']).'</td><td>'.h($check['notes']).'</td><td>';
                if(can('production.record_qc')&&!in_array($batch['status'],['scheduled','completed','cancelled'],true)){
                    echo '<form method="post" class="actions">'.Ui::csrf().'<input type="hidden" name="form_action" value="record_qc"><input type="hidden" name="batch_id" value="'.$batchId.'"><input type="hidden" name="check_id" value="'.$check['id'].'"><select name="result"><option value="pass">Pass</option><option value="fail" '.($check['result']==='fail'?'selected':'').'>Fail</option><option value="na" '.($check['result']==='na'?'selected':'').'>N/A</option></select><input name="notes" value="'.h($check['notes']).'" placeholder="Optional notes"><button class="btn small">Save</button></form>';
                }
                echo '</td></tr>';
            }
            if(!$qc) echo '<tr><td colspan="5" class="empty">Initialize the batch to create QC checks.</td></tr>';
            echo '</tbody></table></div>';

            $reasons=$db->all('SELECT id,name FROM waste_reasons WHERE is_active=1 ORDER BY name');
            echo '<div class="table-card" style="margin-bottom:18px"><div class="table-head"><h2>Yield & Finished Waste</h2></div><table><thead><tr><th>Flavor</th><th>Planned</th><th>Actual</th><th>Waste</th><th>Good</th><th>Actions</th></tr></thead><tbody>';
            foreach($items as $item){
                $actual=$item['actual_quantity']===null?null:(int)$item['actual_quantity'];
                $good=$actual===null?null:max(0,$actual-(int)$item['waste_quantity']);
                echo '<tr><td><strong>'.h($item['flavor']).'</strong></td><td>'.(int)$item['planned_quantity'].'</td><td>'.($actual===null?'—':$actual).'</td><td>'.(int)$item['waste_quantity'].'</td><td>'.($good===null?'—':$good).'</td><td>';
                if(!in_array($batch['status'],['scheduled','completed','cancelled'],true)){
                    if(can('production.update_batch')){
                        echo '<form method="post" class="actions" style="margin-bottom:6px">'.Ui::csrf().'<input type="hidden" name="form_action" value="set_actual_output"><input type="hidden" name="batch_id" value="'.$batchId.'"><input type="hidden" name="batch_item_id" value="'.$item['id'].'"><input type="number" min="'.(int)$item['waste_quantity'].'" name="actual_quantity" value="'.h($actual??$item['planned_quantity']).'" style="width:90px"><button class="btn small">Set Actual</button></form>';
                    }
                    if(can('production.record_waste')){
                        echo '<form method="post" class="actions">'.Ui::csrf().'<input type="hidden" name="form_action" value="record_waste"><input type="hidden" name="batch_id" value="'.$batchId.'"><input type="hidden" name="batch_item_id" value="'.$item['id'].'"><input type="number" min="1" name="quantity" value="1" style="width:70px"><select name="reason_id"><option value="">Reason</option>';
                        foreach($reasons as $reason) echo '<option value="'.$reason['id'].'">'.h($reason['name']).'</option>';
                        echo '</select><input name="notes" placeholder="Optional note"><button class="btn small">Record Waste</button></form>';
                    }
                }
                echo '</td></tr>';
            }
            if(!$items) echo '<tr><td colspan="6" class="empty">No batch items.</td></tr>';
            echo '</tbody></table></div>';

            $wasteRows=$db->all(
                'SELECT pw.*,f.name flavor,wr.name reason,u.name user_name
                 FROM production_waste pw
                 JOIN production_batch_items pbi ON pbi.id=pw.batch_item_id
                 JOIN flavors f ON f.id=pbi.flavor_id
                 LEFT JOIN waste_reasons wr ON wr.id=pw.reason_id
                 LEFT JOIN users u ON u.id=pw.created_by
                 WHERE pw.batch_id=?
                 ORDER BY pw.id DESC',
                [$batchId]
            );
            if($wasteRows){
                echo '<div class="table-card" style="margin-bottom:18px"><div class="table-head"><h2>Waste Log</h2></div><table><thead><tr><th>Date</th><th>Flavor</th><th>Qty</th><th>Reason</th><th>Notes</th><th></th></tr></thead><tbody>';
                foreach($wasteRows as $waste){
                    echo '<tr><td>'.h($waste['created_at']).'</td><td>'.h($waste['flavor']).'</td><td>'.(int)$waste['quantity'].'</td><td>'.h($waste['reason']?:'Other').'</td><td>'.h($waste['notes']).'</td><td>';
                    if(can('production.record_waste')&&!in_array($batch['status'],['completed','cancelled'],true)){
                        echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="delete_waste"><input type="hidden" name="batch_id" value="'.$batchId.'"><input type="hidden" name="waste_id" value="'.$waste['id'].'"><button class="btn small danger" data-confirm="Remove this waste entry?">Remove</button></form>';
                    }
                    echo '</td></tr>';
                }
                echo '</tbody></table></div>';
            }

            $assignments=$db->all(
                'SELECT ba.*,u.name,u.job_title
                 FROM batch_assignments ba
                 JOIN users u ON u.id=ba.user_id
                 WHERE ba.batch_id=?
                 ORDER BY u.name',
                [$batchId]
            );
            echo '<div class="grid cols-2" style="margin-bottom:18px"><div class="card"><h2 class="section-title">Team Assignments</h2><div class="list">';
            foreach($assignments as $assignment){
                echo '<div class="list-row"><div><strong>'.h($assignment['name']).'</strong><div class="muted">'.h($assignment['assignment_role']?:$assignment['job_title']).($assignment['station']?' · '.h($assignment['station']):'').'</div></div>';
                if(can('production.assign_team')&&!in_array($batch['status'],['completed','cancelled'],true)){
                    echo '<form method="post">'.Ui::csrf().'<input type="hidden" name="form_action" value="remove_assignment"><input type="hidden" name="batch_id" value="'.$batchId.'"><input type="hidden" name="member_id" value="'.$assignment['user_id'].'"><button class="btn small danger">Remove</button></form>';
                }
                echo '</div>';
            }
            if(!$assignments) echo '<div class="empty">No team assigned yet.</div>';
            echo '</div>';
            if(can('production.assign_team')&&!in_array($batch['status'],['completed','cancelled'],true)){
                $members=$db->all('SELECT id,name,job_title FROM users WHERE status="active" ORDER BY name');
                echo '<form method="post" class="form-grid" style="margin-top:14px">'.Ui::csrf().'<input type="hidden" name="form_action" value="assign_member"><input type="hidden" name="batch_id" value="'.$batchId.'"><label>Member<select name="member_id" required><option value="">Select member</option>';
                foreach($members as $member) echo '<option value="'.$member['id'].'">'.h($member['name'].($member['job_title']?' · '.$member['job_title']:'')).'</option>';
                echo '</select></label><label>Role<input name="assignment_role" placeholder="Lead, Production, Packing"></label><label>Station<input name="station" placeholder="Mixing, Glazing, Packing"></label><div><button class="btn primary" style="margin-top:27px">Assign</button></div></form>';
            }
            echo '</div>';

            $labor=$db->all(
                'SELECT ls.*,u.name
                 FROM labor_sessions ls
                 JOIN users u ON u.id=ls.user_id
                 WHERE ls.batch_id=?
                 ORDER BY ls.started_at DESC',
                [$batchId]
            );
            $laborMinutes=array_sum(array_map(fn($l)=>(int)($l['duration_minutes']??0),$labor));
            $laborCost=array_sum(array_map(fn($l)=>(float)($l['labor_cost']??0),$labor));
            echo '<div class="card"><h2 class="section-title">Labor</h2><div class="two-col-stat"><div><span class="muted">Closed Time</span><div><strong>'.$laborMinutes.' min</strong></div></div><div><span class="muted">Labor Cost</span><div><strong>$'.number_format($laborCost,2).'</strong></div></div></div>';
            if(can('production.track_labor')&&!in_array($batch['status'],['completed','cancelled'],true)){
                if($openSession&&((int)$openSession['batch_id']===$batchId)){
                    echo '<form method="post" style="margin-top:14px">'.Ui::csrf().'<input type="hidden" name="form_action" value="clock_out"><input type="hidden" name="batch_id" value="'.$batchId.'"><button class="btn primary">Clock Out</button></form>';
                }elseif(!$openSession){
                    echo '<form method="post" class="actions" style="margin-top:14px">'.Ui::csrf().'<input type="hidden" name="form_action" value="clock_in"><input type="hidden" name="batch_id" value="'.$batchId.'"><input name="station" placeholder="Station / task"><button class="btn primary">Clock In</button></form>';
                }
            }
            if($labor){
                echo '<table style="margin-top:14px"><thead><tr><th>Member</th><th>Station</th><th>Start</th><th>End</th><th>Minutes</th><th>Cost</th></tr></thead><tbody>';
                foreach($labor as $row) echo '<tr><td>'.h($row['name']).'</td><td>'.h($row['station']).'</td><td>'.h($row['started_at']).'</td><td>'.h($row['ended_at']?:'Open').'</td><td>'.h($row['duration_minutes']??'—').'</td><td>'.($row['labor_cost']===null?'—':'$'.number_format((float)$row['labor_cost'],2)).'</td></tr>';
                echo '</tbody></table>';
            }
            echo '</div></div>';

            if($batch['status']==='completed'){
                $finished=$db->all(
                    'SELECT fi.*,f.name flavor
                     FROM finished_inventory fi
                     JOIN flavors f ON f.id=fi.flavor_id
                     WHERE fi.batch_id=?
                     ORDER BY f.name',
                    [$batchId]
                );
                echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Finished Inventory Created</h2></div><table><thead><tr><th>Flavor</th><th>Quantity</th><th>Status</th><th>Made</th></tr></thead><tbody>';
                foreach($finished as $row) echo '<tr><td><strong>'.h($row['flavor']).'</strong></td><td>'.(int)$row['quantity'].'</td><td>'.Ui::statusBadge($row['status']).'</td><td>'.h($row['made_at']).'</td></tr>';
                echo '</tbody></table></div>';
            }
        }
    }

    $batches=$db->all(
        'SELECT pb.*,pp.plan_code,u.name creator,
                (SELECT COALESCE(SUM(planned_quantity),0) FROM production_batch_items pbi WHERE pbi.batch_id=pb.id) planned_units,
                (SELECT COALESCE(SUM(COALESCE(actual_quantity,0)-waste_quantity),0) FROM production_batch_items pbi WHERE pbi.batch_id=pb.id) good_units
         FROM production_batches pb
         LEFT JOIN production_plans pp ON pp.id=pb.production_plan_id
         LEFT JOIN users u ON u.id=pb.created_by
         ORDER BY CASE
                    WHEN pb.status='scheduled' THEN 1
                    WHEN pb.status='completed' THEN 3
                    WHEN pb.status='cancelled' THEN 4
                    ELSE 0
                  END,pb.id DESC
         LIMIT 100'
    );
    echo '<div class="table-card" style="margin-top:18px"><div class="table-head"><h2>Production Batches</h2></div><table><thead><tr><th>Batch</th><th>Source</th><th>Status</th><th>Scheduled</th><th>Planned</th><th>Good Output</th><th></th></tr></thead><tbody>';
    foreach($batches as $row){
        echo '<tr><td><strong>'.h($row['batch_code']).'</strong><div class="muted">'.h($row['creator']).'</div></td><td>'.h($row['plan_code']?:'Manual').'</td><td>'.Ui::statusBadge($row['status']).'</td><td>'.h($row['scheduled_for']).'</td><td>'.(int)$row['planned_units'].'</td><td>'.(int)$row['good_units'].'</td><td><a class="btn small" href="?page=production&batch='.$row['id'].'">Open</a></td></tr>';
    }
    if(!$batches) echo '<tr><td colspan="7" class="empty">No production batches yet.</td></tr>';
    echo '</tbody></table></div>';

    Ui::layoutEnd();
    exit;
}
