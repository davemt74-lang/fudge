<?php
final class ProductionExecutionService
{
    private const STAGES = [
        'prep' => 'Prep',
        'mixed' => 'Mixed',
        'molded' => 'Molded',
        'chilling' => 'Chilling',
        'glazed' => 'Glazed',
        'topped' => 'Topped',
        'wrapped' => 'Wrapped',
        'boxed' => 'Boxed',
    ];

    public function __construct(
        private Database $db,
        private UnitConversionService $units
    ) {}

    public function createManualBatch(?string $scheduledFor, ?string $notes, int $userId): int
    {
        if ($scheduledFor !== null && $scheduledFor !== '') {
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$scheduledFor);
            if(!$date || $date->format('Y-m-d')!==$scheduledFor) {
                throw new RuntimeException('Use a valid scheduled date.');
            }
        }
        return $this->db->insert(
            'INSERT INTO production_batches
             (batch_code,scheduled_for,status,notes,created_by)
             VALUES (?,?,"scheduled",?,?)',
            [Security::reference('BATCH'),$scheduledFor ?: null,$notes ?: null,$userId]
        );
    }

    public function addBatchItem(int $batchId, int $flavorId, int $plannedQuantity): int
    {
        if($plannedQuantity<1) throw new RuntimeException('Planned quantity must be at least 1.');

        return $this->db->transaction(function(Database $db) use ($batchId,$flavorId,$plannedQuantity) {
            $batch=$db->one('SELECT * FROM production_batches WHERE id=? FOR UPDATE',[$batchId]);
            if(!$batch || $batch['status']!=='scheduled') {
                throw new RuntimeException('Batch items can only be edited while the batch is scheduled.');
            }
            if((int)$db->scalar('SELECT COUNT(*) FROM production_batch_materials WHERE batch_id=? AND status="committed"',[$batchId])>0) {
                throw new RuntimeException('Batch items cannot change after material consumption is committed.');
            }

            $flavor=$db->one('SELECT id,name FROM flavors WHERE id=? AND is_active=1',[$flavorId]);
            if(!$flavor) throw new RuntimeException('Select an active flavor.');
            $version=$db->one(
                'SELECT rv.id
                 FROM recipes r
                 JOIN recipe_versions rv ON rv.recipe_id=r.id AND rv.status="published"
                 WHERE r.recipe_type="finished" AND r.flavor_id=? AND r.is_active=1
                 ORDER BY rv.version_number DESC LIMIT 1',
                [$flavorId]
            );
            if(!$version) throw new RuntimeException($flavor['name'].' does not have a published finished recipe.');

            $existing=$db->one(
                'SELECT id FROM production_batch_items WHERE batch_id=? AND flavor_id=?',
                [$batchId,$flavorId]
            );
            if($existing){
                $db->exec(
                    'UPDATE production_batch_items
                     SET recipe_version_id=?,planned_quantity=?,actual_quantity=NULL,waste_quantity=0
                     WHERE id=?',
                    [$version['id'],$plannedQuantity,$existing['id']]
                );
                $db->exec('DELETE FROM production_waste WHERE batch_item_id=?',[$existing['id']]);
                $this->clearSetup($batchId,$db);
                return (int)$existing['id'];
            }

            $id=$db->insert(
                'INSERT INTO production_batch_items
                 (batch_id,flavor_id,recipe_version_id,planned_quantity,actual_quantity,waste_quantity)
                 VALUES (?,?,?,?,NULL,0)',
                [$batchId,$flavorId,$version['id'],$plannedQuantity]
            );
            $this->clearSetup($batchId,$db);
            return $id;
        });
    }

    public function removeBatchItem(int $batchId, int $batchItemId): void
    {
        $this->db->transaction(function(Database $db) use ($batchId,$batchItemId) {
            $batch=$db->one('SELECT status FROM production_batches WHERE id=? FOR UPDATE',[$batchId]);
            if(!$batch || $batch['status']!=='scheduled') {
                throw new RuntimeException('Batch items can only be removed while the batch is scheduled.');
            }
            if((int)$db->scalar('SELECT COUNT(*) FROM production_batch_materials WHERE batch_id=? AND status="committed"',[$batchId])>0) {
                throw new RuntimeException('Batch items cannot change after material consumption is committed.');
            }
            $db->exec(
                'DELETE FROM production_batch_items WHERE id=? AND batch_id=?',
                [$batchItemId,$batchId]
            );
            $this->clearSetup($batchId,$db);
        });
    }

    public function initializeBatch(int $batchId): array
    {
        return $this->db->transaction(function(Database $db) use ($batchId) {
            $batch=$db->one('SELECT * FROM production_batches WHERE id=? FOR UPDATE',[$batchId]);
            if(!$batch) throw new RuntimeException('Production batch not found.');
            if($batch['status']!=='scheduled') {
                throw new RuntimeException('Only scheduled batches can be initialized or rebuilt.');
            }

            $items=$db->all(
                'SELECT pbi.*,f.name flavor_name
                 FROM production_batch_items pbi
                 JOIN flavors f ON f.id=pbi.flavor_id
                 WHERE pbi.batch_id=?
                 ORDER BY f.name',
                [$batchId]
            );
            if(!$items) throw new RuntimeException('Add at least one flavor to the batch before initialization.');

            if((int)$db->scalar('SELECT COUNT(*) FROM production_batch_materials WHERE batch_id=? AND status="committed"',[$batchId])>0) {
                throw new RuntimeException('A batch with committed material use cannot be rebuilt.');
            }

            $this->clearSetup($batchId,$db);

            $sort=10;
            foreach(self::STAGES as $key=>$label){
                $db->exec(
                    'INSERT INTO production_batch_steps(batch_id,step_key,label,sort_order,status)
                     VALUES (?,?,?,?,"pending")',
                    [$batchId,$key,$label,$sort]
                );
                $sort+=10;
            }

            foreach($items as $item){
                $checks=[
                    ['shape','Shape / mold release'],
                    ['target_weight','Target finished weight'],
                    ['glaze','Glaze coverage / appearance'],
                    ['topping','Topping amount / finish'],
                    ['wrapper','Individual wrapper sealed'],
                    ['sticker','Back sticker applied'],
                    ['count','Finished count verified'],
                ];
                $sort=10;
                foreach($checks as [$key,$label]){
                    $db->exec(
                        'INSERT INTO production_qc_checks
                         (batch_id,batch_item_id,check_key,label,sort_order,result)
                         VALUES (?,?,?,?,?,"pending")',
                        [$batchId,$item['id'],$key,$label,$sort]
                    );
                    $sort+=10;
                }
            }

            $requirements=[];
            if(!empty($batch['production_plan_id'])){
                $rows=$db->all(
                    'SELECT item_type,item_id,required_quantity,unit
                     FROM production_plan_requirements
                     WHERE production_plan_id=?
                     ORDER BY item_type,item_id',
                    [$batch['production_plan_id']]
                );
                foreach($rows as $row){
                    $this->addRequirement(
                        $requirements,$row['item_type'],(int)$row['item_id'],
                        (float)$row['required_quantity'],$row['unit']
                    );
                }
            }else{
                foreach($items as $item){
                    if(!$item['recipe_version_id']) {
                        throw new RuntimeException($item['flavor_name'].' does not have a captured recipe version.');
                    }
                    $this->expandRecipeVersion(
                        (int)$item['recipe_version_id'],
                        (float)$item['planned_quantity'],
                        $requirements,
                        []
                    );
                }
            }

            foreach($requirements as $req){
                $db->exec(
                    'INSERT INTO production_batch_materials
                     (batch_id,item_type,item_id,theoretical_quantity,actual_quantity,variance_quantity,unit,status)
                     VALUES (?,?,?,?,?,0,?,"planned")',
                    [
                        $batchId,$req['item_type'],$req['item_id'],
                        $req['quantity'],$req['quantity'],$req['unit']
                    ]
                );
            }

            return [
                'steps'=>count(self::STAGES),
                'batch_items'=>count($items),
                'materials'=>count($requirements),
                'qc_checks'=>count($items)*7,
            ];
        });
    }

    public function startBatch(int $batchId, int $userId): void
    {
        $this->db->transaction(function(Database $db) use ($batchId,$userId) {
            $batch=$db->one('SELECT * FROM production_batches WHERE id=? FOR UPDATE',[$batchId]);
            if(!$batch || $batch['status']!=='scheduled') {
                throw new RuntimeException('Only a scheduled batch can be started.');
            }

            if((int)$db->scalar('SELECT COUNT(*) FROM production_batch_steps WHERE batch_id=?',[$batchId])===0){
                $this->initializeBatch($batchId);
            }

            $db->exec('UPDATE production_batches SET status="prep" WHERE id=?',[$batchId]);
            $db->exec(
                'UPDATE production_batch_steps
                 SET status="in_progress",started_at=NOW(),started_by=?
                 WHERE batch_id=? AND step_key="prep"',
                [$userId,$batchId]
            );
        });
    }

    public function advanceBatch(int $batchId, int $userId): string
    {
        return $this->db->transaction(function(Database $db) use ($batchId,$userId) {
            $batch=$db->one('SELECT * FROM production_batches WHERE id=? FOR UPDATE',[$batchId]);
            if(!$batch) throw new RuntimeException('Production batch not found.');
            if(!array_key_exists($batch['status'],self::STAGES)) {
                throw new RuntimeException('This batch is not in an advanceable production stage.');
            }
            if($batch['status']==='boxed') {
                throw new RuntimeException('Use Complete Batch after boxing.');
            }

            $keys=array_keys(self::STAGES);
            $index=array_search($batch['status'],$keys,true);
            $next=$keys[$index+1];

            $db->exec(
                'UPDATE production_batch_steps
                 SET status="completed",completed_at=NOW(),completed_by=?
                 WHERE batch_id=? AND step_key=?',
                [$userId,$batchId,$batch['status']]
            );
            $db->exec(
                'UPDATE production_batch_steps
                 SET status="in_progress",started_at=COALESCE(started_at,NOW()),started_by=COALESCE(started_by,?)
                 WHERE batch_id=? AND step_key=?',
                [$userId,$batchId,$next]
            );
            $db->exec('UPDATE production_batches SET status=? WHERE id=?',[$next,$batchId]);

            return $next;
        });
    }

    public function setActualMaterial(int $materialId, float $quantity): void
    {
        if($quantity<0) throw new RuntimeException('Actual material quantity cannot be negative.');
        $this->db->transaction(function(Database $db) use ($materialId,$quantity) {
            $material=$db->one(
                'SELECT pbm.*,pb.status batch_status
                 FROM production_batch_materials pbm
                 JOIN production_batches pb ON pb.id=pbm.batch_id
                 WHERE pbm.id=? FOR UPDATE',
                [$materialId]
            );
            if(!$material) throw new RuntimeException('Batch material not found.');
            if($material['status']==='committed') throw new RuntimeException('Committed material use cannot be edited.');
            if(in_array($material['batch_status'],['completed','cancelled'],true)) {
                throw new RuntimeException('Material use cannot be edited on a closed batch.');
            }
            $variance=$quantity-(float)$material['theoretical_quantity'];
            $db->exec(
                'UPDATE production_batch_materials
                 SET actual_quantity=?,variance_quantity=?
                 WHERE id=?',
                [$quantity,$variance,$materialId]
            );
        });
    }

    public function commitMaterials(int $batchId, int $userId): array
    {
        $items=$this->db->all(
            'SELECT item_type,item_id
             FROM production_batch_materials
             WHERE batch_id=?
             ORDER BY item_type,item_id',
            [$batchId]
        );
        $locks=[];
        try{
            foreach($items as $item){
                $lockName='fudge_inventory_'.$item['item_type'].'_'.$item['item_id'];
                $acquired=(int)$this->db->scalar('SELECT GET_LOCK(?,5)',[$lockName])===1;
                if(!$acquired){
                    throw new RuntimeException('Another inventory operation is using '.$this->itemName($item['item_type'],(int)$item['item_id']).'. Try again after it finishes.');
                }
                $locks[]=$lockName;
            }
            return $this->commitMaterialsUnderLocks($batchId,$userId);
        }finally{
            foreach(array_reverse($locks) as $lockName){
                try{$this->db->scalar('SELECT RELEASE_LOCK(?)',[$lockName]);}catch(Throwable $ignored){}
            }
        }
    }

    private function commitMaterialsUnderLocks(int $batchId, int $userId): array
    {
        return $this->db->transaction(function(Database $db) use ($batchId,$userId) {
            $batch=$db->one('SELECT * FROM production_batches WHERE id=? FOR UPDATE',[$batchId]);
            if(!$batch) throw new RuntimeException('Production batch not found.');
            if(in_array($batch['status'],['scheduled','completed','cancelled'],true)) {
                throw new RuntimeException('Start the batch before committing material use.');
            }

            $materials=$db->all(
                'SELECT * FROM production_batch_materials
                 WHERE batch_id=? ORDER BY id FOR UPDATE',
                [$batchId]
            );
            if(!$materials) throw new RuntimeException('Initialize batch materials before committing consumption.');
            if(count(array_filter($materials,fn($m)=>$m['status']==='committed'))===count($materials)){
                return ['materials'=>count($materials),'allocations'=>(int)$db->scalar(
                    'SELECT COUNT(*) FROM production_consumption_allocations pca
                     JOIN production_batch_materials pbm ON pbm.id=pca.batch_material_id
                     WHERE pbm.batch_id=?',[$batchId]
                )];
            }
            if(count(array_filter($materials,fn($m)=>$m['status']==='committed'))>0){
                throw new RuntimeException('Partial material commits are not supported; resolve the batch before retrying.');
            }

            foreach($materials as $material){
                $needed=(float)$material['actual_quantity'];
                $available=$this->usableOnHand($material['item_type'],(int)$material['item_id']);
                if($needed-$available>0.000001){
                    $name=$this->itemName($material['item_type'],(int)$material['item_id']);
                    throw new RuntimeException(
                        $name.' needs '.number_format($needed,3).' '.$material['unit'].
                        ' but only '.number_format($available,3).' usable '.$material['unit'].' is available.'
                    );
                }
            }

            $allocations=0;
            foreach($materials as $material){
                $remaining=(float)$material['actual_quantity'];
                if($remaining<=0){
                    $db->exec(
                        'UPDATE production_batch_materials
                         SET status="committed",committed_at=NOW(),committed_by=?
                         WHERE id=?',
                        [$userId,$material['id']]
                    );
                    continue;
                }

                if($material['item_type']==='ingredient'){
                    $lots=$db->all(
                        "SELECT l.id,l.lot_number,l.expires_at,l.received_at,
                                COALESCE((SELECT SUM(t.quantity_delta)
                                  FROM inventory_transactions t
                                  WHERE t.item_type='ingredient'
                                    AND t.item_id=l.ingredient_id
                                    AND t.lot_id=l.id),0) on_hand
                         FROM ingredient_lots l
                         WHERE l.ingredient_id=?
                           AND (l.expires_at IS NULL OR DATE(l.expires_at)>=CURDATE())
                         HAVING on_hand>0
                         ORDER BY CASE WHEN l.expires_at IS NULL THEN 1 ELSE 0 END,
                                  l.expires_at,l.received_at,l.id
                         FOR UPDATE",
                        [$material['item_id']]
                    );

                    foreach($lots as $lot){
                        if($remaining<=0.000001) break;
                        $take=min($remaining,(float)$lot['on_hand']);
                        if($take<=0) continue;
                        $txId=$this->postConsumption(
                            $material,$take,(int)$lot['id'],$batch,$userId
                        );
                        $db->insert(
                            'INSERT INTO production_consumption_allocations
                             (batch_material_id,lot_id,quantity,unit,inventory_transaction_id)
                             VALUES (?,?,?,?,?)',
                            [$material['id'],$lot['id'],$take,$material['unit'],$txId]
                        );
                        $remaining-=$take;
                        $allocations++;
                    }

                    if($remaining>0.000001){
                        $untracked=(float)$db->scalar(
                            "SELECT COALESCE(SUM(quantity_delta),0)
                             FROM inventory_transactions
                             WHERE item_type='ingredient' AND item_id=? AND lot_id IS NULL",
                            [$material['item_id']]
                        );
                        if($untracked+0.000001<$remaining){
                            throw new RuntimeException('Untracked ingredient balance changed during FEFO allocation.');
                        }
                        $txId=$this->postConsumption(
                            $material,$remaining,null,$batch,$userId
                        );
                        $db->insert(
                            'INSERT INTO production_consumption_allocations
                             (batch_material_id,lot_id,quantity,unit,inventory_transaction_id)
                             VALUES (?,NULL,?,?,?)',
                            [$material['id'],$remaining,$material['unit'],$txId]
                        );
                        $remaining=0;
                        $allocations++;
                    }
                }else{
                    $txId=$this->postConsumption($material,$remaining,null,$batch,$userId);
                    $db->insert(
                        'INSERT INTO production_consumption_allocations
                         (batch_material_id,lot_id,quantity,unit,inventory_transaction_id)
                         VALUES (?,NULL,?,?,?)',
                        [$material['id'],$remaining,$material['unit'],$txId]
                    );
                    $allocations++;
                }

                $db->exec(
                    'UPDATE production_batch_materials
                     SET status="committed",committed_at=NOW(),committed_by=?,
                         variance_quantity=actual_quantity-theoretical_quantity
                     WHERE id=?',
                    [$userId,$material['id']]
                );
            }

            return ['materials'=>count($materials),'allocations'=>$allocations];
        });
    }

    public function recordQc(int $checkId, string $result, ?string $notes, int $userId): void
    {
        if(!in_array($result,['pass','fail','na'],true)) throw new RuntimeException('Select a valid QC result.');
        $this->db->transaction(function(Database $db) use ($checkId,$result,$notes,$userId) {
            $check=$db->one(
                'SELECT pqc.*,pb.status batch_status
                 FROM production_qc_checks pqc
                 JOIN production_batches pb ON pb.id=pqc.batch_id
                 WHERE pqc.id=? FOR UPDATE',
                [$checkId]
            );
            if(!$check) throw new RuntimeException('QC check not found.');
            if($check['batch_status']==='scheduled') {
                throw new RuntimeException('Start the batch before recording production QC.');
            }
            if(in_array($check['batch_status'],['completed','cancelled'],true)) {
                throw new RuntimeException('QC cannot be changed on a closed batch.');
            }
            $db->exec(
                'UPDATE production_qc_checks
                 SET result=?,notes=?,checked_by=?,checked_at=NOW()
                 WHERE id=?',
                [$result,$notes ?: null,$userId,$checkId]
            );
        });
    }

    public function setActualOutput(int $batchItemId, int $actualQuantity): void
    {
        if($actualQuantity<0) throw new RuntimeException('Actual finished quantity cannot be negative.');
        $this->db->transaction(function(Database $db) use ($batchItemId,$actualQuantity) {
            $item=$db->one(
                'SELECT pbi.*,pb.status batch_status
                 FROM production_batch_items pbi
                 JOIN production_batches pb ON pb.id=pbi.batch_id
                 WHERE pbi.id=? FOR UPDATE',
                [$batchItemId]
            );
            if(!$item) throw new RuntimeException('Batch item not found.');
            if($item['batch_status']==='scheduled') {
                throw new RuntimeException('Start the batch before recording finished output.');
            }
            if(in_array($item['batch_status'],['completed','cancelled'],true)) {
                throw new RuntimeException('Finished output cannot change on a closed batch.');
            }
            if($actualQuantity<(int)$item['waste_quantity']){
                throw new RuntimeException('Actual output cannot be lower than recorded waste.');
            }
            $db->exec(
                'UPDATE production_batch_items SET actual_quantity=? WHERE id=?',
                [$actualQuantity,$batchItemId]
            );
        });
    }

    public function recordWaste(
        int $batchItemId,
        int $quantity,
        ?int $reasonId,
        ?string $notes,
        int $userId
    ): int {
        if($quantity<1) throw new RuntimeException('Waste quantity must be at least 1.');

        return $this->db->transaction(function(Database $db) use ($batchItemId,$quantity,$reasonId,$notes,$userId) {
            $item=$db->one(
                'SELECT pbi.*,pb.status batch_status
                 FROM production_batch_items pbi
                 JOIN production_batches pb ON pb.id=pbi.batch_id
                 WHERE pbi.id=? FOR UPDATE',
                [$batchItemId]
            );
            if(!$item) throw new RuntimeException('Batch item not found.');
            if($item['batch_status']==='scheduled') {
                throw new RuntimeException('Start the batch before recording production waste.');
            }
            if(in_array($item['batch_status'],['completed','cancelled'],true)) {
                throw new RuntimeException('Waste cannot be added to a closed batch.');
            }
            if($reasonId){
                $valid=(int)$db->scalar('SELECT COUNT(*) FROM waste_reasons WHERE id=? AND is_active=1',[$reasonId]);
                if(!$valid) throw new RuntimeException('Select an active waste reason.');
            }

            $limit=$item['actual_quantity']!==null
                ? (int)$item['actual_quantity']
                : (int)$item['planned_quantity'];
            if((int)$item['waste_quantity']+$quantity>$limit){
                throw new RuntimeException('Recorded waste cannot exceed the current batch output.');
            }

            $id=$db->insert(
                'INSERT INTO production_waste
                 (batch_id,batch_item_id,reason_id,quantity,notes,created_by)
                 VALUES (?,?,?,?,?,?)',
                [$item['batch_id'],$batchItemId,$reasonId ?: null,$quantity,$notes ?: null,$userId]
            );
            $db->exec(
                'UPDATE production_batch_items SET waste_quantity=waste_quantity+? WHERE id=?',
                [$quantity,$batchItemId]
            );
            return $id;
        });
    }

    public function assignMember(
        int $batchId,
        int $memberId,
        ?string $role,
        ?string $station,
        int $userId
    ): void {
        $this->db->transaction(function(Database $db) use ($batchId,$memberId,$role,$station,$userId) {
            $batch=$db->one('SELECT status FROM production_batches WHERE id=? FOR UPDATE',[$batchId]);
            if(!$batch || in_array($batch['status'],['completed','cancelled'],true)){
                throw new RuntimeException('Team assignments cannot change on a closed batch.');
            }
            $member=$db->one('SELECT id FROM users WHERE id=? AND status="active"',[$memberId]);
            if(!$member) throw new RuntimeException('Select an active team member.');

            $db->exec(
                'INSERT INTO batch_assignments(batch_id,user_id,assignment_role,station,created_by)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE assignment_role=VALUES(assignment_role),station=VALUES(station)',
                [$batchId,$memberId,$role ?: null,$station ?: null,$userId]
            );
        });
    }

    public function removeAssignment(int $batchId, int $memberId): void
    {
        $this->db->transaction(function(Database $db) use ($batchId,$memberId) {
            $batch=$db->one('SELECT status FROM production_batches WHERE id=? FOR UPDATE',[$batchId]);
            if(!$batch || in_array($batch['status'],['completed','cancelled'],true)){
                throw new RuntimeException('Team assignments cannot change on a closed batch.');
            }
            $db->exec(
                'DELETE FROM batch_assignments WHERE batch_id=? AND user_id=?',
                [$batchId,$memberId]
            );
        });
    }

    public function deleteWaste(int $wasteId): void
    {
        $this->db->transaction(function(Database $db) use ($wasteId) {
            $waste=$db->one(
                'SELECT pw.*,pb.status batch_status
                 FROM production_waste pw
                 JOIN production_batches pb ON pb.id=pw.batch_id
                 WHERE pw.id=? FOR UPDATE',
                [$wasteId]
            );
            if(!$waste) throw new RuntimeException('Waste entry not found.');
            if(in_array($waste['batch_status'],['completed','cancelled'],true)){
                throw new RuntimeException('Waste cannot be changed on a closed batch.');
            }
            $db->exec('DELETE FROM production_waste WHERE id=?',[$wasteId]);
            $db->exec(
                'UPDATE production_batch_items
                 SET waste_quantity=GREATEST(0,waste_quantity-?)
                 WHERE id=?',
                [$waste['quantity'],$waste['batch_item_id']]
            );
        });
    }

    public function cancelManualBatch(int $batchId): void
    {
        $this->db->transaction(function(Database $db) use ($batchId) {
            $batch=$db->one('SELECT * FROM production_batches WHERE id=? FOR UPDATE',[$batchId]);
            if(!$batch) throw new RuntimeException('Production batch not found.');
            if(!empty($batch['production_plan_id'])){
                throw new RuntimeException('A batch launched from a production plan cannot be cancelled here.');
            }
            if($batch['status']!=='scheduled'){
                throw new RuntimeException('Only an unstarted manual batch can be cancelled.');
            }
            if((int)$db->scalar(
                'SELECT COUNT(*) FROM production_batch_materials
                 WHERE batch_id=? AND status="committed"',
                [$batchId]
            )>0){
                throw new RuntimeException('A batch with committed material use cannot be cancelled.');
            }
            $db->exec('UPDATE production_batches SET status="cancelled" WHERE id=?',[$batchId]);
        });
    }

    public function clockIn(int $batchId, int $userId, ?string $station): int
    {
        return $this->db->transaction(function(Database $db) use ($batchId,$userId,$station) {
            $batch=$db->one('SELECT status FROM production_batches WHERE id=? FOR UPDATE',[$batchId]);
            if(!$batch || in_array($batch['status'],['completed','cancelled'],true)){
                throw new RuntimeException('Cannot clock into a closed batch.');
            }
            $existing=$db->one(
                'SELECT id FROM labor_sessions WHERE user_id=? AND ended_at IS NULL FOR UPDATE',
                [$userId]
            );
            if($existing) throw new RuntimeException('Clock out of the current production session first.');

            $rate=(float)($db->scalar('SELECT COALESCE(hourly_rate,0) FROM users WHERE id=? AND status="active"',[$userId]) ?: 0);
            return $db->insert(
                'INSERT INTO labor_sessions(batch_id,user_id,station,hourly_rate_snapshot,started_at)
                 VALUES (?,?,?,?,NOW())',
                [$batchId,$userId,$station ?: null,$rate]
            );
        });
    }

    public function clockOut(int $userId): array
    {
        return $this->db->transaction(function(Database $db) use ($userId) {
            $session=$db->one(
                'SELECT * FROM labor_sessions
                 WHERE user_id=? AND ended_at IS NULL
                 ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [$userId]
            );
            if(!$session) throw new RuntimeException('No open production labor session was found.');

            $minutes=max(0,(int)$db->scalar(
                'SELECT TIMESTAMPDIFF(MINUTE,started_at,NOW()) FROM labor_sessions WHERE id=?',
                [$session['id']]
            ));
            $cost=round(($minutes/60)*(float)$session['hourly_rate_snapshot'],2);
            $db->exec(
                'UPDATE labor_sessions
                 SET ended_at=NOW(),duration_minutes=?,labor_cost=?
                 WHERE id=?',
                [$minutes,$cost,$session['id']]
            );
            return ['session_id'=>(int)$session['id'],'minutes'=>$minutes,'labor_cost'=>$cost];
        });
    }

    public function completeBatch(int $batchId, int $userId): array
    {
        return $this->db->transaction(function(Database $db) use ($batchId,$userId) {
            $batch=$db->one('SELECT * FROM production_batches WHERE id=? FOR UPDATE',[$batchId]);
            if(!$batch) throw new RuntimeException('Production batch not found.');
            if($batch['status']==='completed'){
                return [
                    'finished_units'=>(int)$db->scalar(
                        'SELECT COALESCE(SUM(quantity),0) FROM finished_inventory WHERE batch_id=?',
                        [$batchId]
                    ),
                    'already_completed'=>true,
                ];
            }
            if($batch['status']!=='boxed'){
                throw new RuntimeException('The batch must reach Boxed before completion.');
            }

            $uncommitted=(int)$db->scalar(
                'SELECT COUNT(*) FROM production_batch_materials
                 WHERE batch_id=? AND status<>"committed"',
                [$batchId]
            );
            if($uncommitted>0) throw new RuntimeException('Commit all material consumption before completing the batch.');

            $pendingQc=(int)$db->scalar(
                'SELECT COUNT(*) FROM production_qc_checks
                 WHERE batch_id=? AND result IN ("pending","fail")',
                [$batchId]
            );
            if($pendingQc>0) throw new RuntimeException('Resolve all pending or failed QC checks before completing the batch.');

            $openLabor=(int)$db->scalar(
                'SELECT COUNT(*) FROM labor_sessions WHERE batch_id=? AND ended_at IS NULL',
                [$batchId]
            );
            if($openLabor>0) throw new RuntimeException('Clock out all labor sessions before completing the batch.');

            $items=$db->all(
                'SELECT * FROM production_batch_items WHERE batch_id=? ORDER BY id FOR UPDATE',
                [$batchId]
            );
            if(!$items) throw new RuntimeException('The batch has no production items.');

            $finished=0;
            foreach($items as $item){
                if($item['actual_quantity']===null){
                    throw new RuntimeException('Enter actual finished output for every flavor before completion.');
                }
                $actual=(int)$item['actual_quantity'];
                $waste=(int)$item['waste_quantity'];
                if($waste>$actual) throw new RuntimeException('Waste cannot exceed actual finished output.');
                $net=$actual-$waste;

                $db->exec(
                    'INSERT INTO finished_inventory
                     (flavor_id,batch_id,production_batch_item_id,status,quantity,made_at)
                     VALUES (?,?,?,"available",?,NOW())
                     ON DUPLICATE KEY UPDATE
                       flavor_id=VALUES(flavor_id),
                       batch_id=VALUES(batch_id),
                       status="available",
                       quantity=VALUES(quantity),
                       made_at=COALESCE(made_at,VALUES(made_at))',
                    [$item['flavor_id'],$batchId,$item['id'],$net]
                );
                $finished+=$net;
            }

            $db->exec(
                'UPDATE production_batch_steps
                 SET status="completed",completed_at=COALESCE(completed_at,NOW()),completed_by=COALESCE(completed_by,?)
                 WHERE batch_id=? AND step_key="boxed"',
                [$userId,$batchId]
            );
            $db->exec('UPDATE production_batches SET status="completed" WHERE id=?',[$batchId]);

            if(!empty($batch['production_plan_id'])){
                $db->exec(
                    'UPDATE production_plans SET status="completed" WHERE id=? AND status="in_production"',
                    [$batch['production_plan_id']]
                );
                $db->exec(
                    'UPDATE orders o
                     JOIN production_plan_orders ppo ON ppo.order_id=o.id
                     SET o.status="packing"
                     WHERE ppo.production_plan_id=? AND o.status="production"',
                    [$batch['production_plan_id']]
                );
            }

            return ['finished_units'=>$finished,'already_completed'=>false];
        });
    }

    private function clearSetup(int $batchId, Database $db): void
    {
        $db->exec('DELETE FROM production_qc_checks WHERE batch_id=?',[$batchId]);
        $db->exec('DELETE FROM production_batch_steps WHERE batch_id=?',[$batchId]);
        $db->exec('DELETE FROM production_batch_materials WHERE batch_id=?',[$batchId]);
    }

    private function expandRecipeVersion(
        int $versionId,
        float $targetOutput,
        array &$requirements,
        array $stack
    ): void {
        $version=$this->db->one(
            'SELECT rv.*,r.id recipe_id,r.name recipe_name
             FROM recipe_versions rv
             JOIN recipes r ON r.id=rv.recipe_id
             WHERE rv.id=?',
            [$versionId]
        );
        if(!$version) throw new RuntimeException('Captured recipe version not found.');
        $recipeId=(int)$version['recipe_id'];
        if(in_array($recipeId,$stack,true)) throw new RuntimeException('Recipe cycle detected during batch setup.');
        $stack[]=$recipeId;

        $yield=(float)$version['yield_quantity'];
        if($yield<=0) throw new RuntimeException($version['recipe_name'].' has an invalid yield.');
        $scale=$targetOutput/$yield;

        $components=$this->db->all(
            'SELECT * FROM recipe_items WHERE recipe_version_id=? ORDER BY sort_order,id',
            [$versionId]
        );
        foreach($components as $component){
            $qty=(float)$component['quantity']*$scale;
            $type=$component['component_type'];
            $id=(int)$component['component_id'];

            if($type==='ingredient'){
                $item=$this->db->one('SELECT inventory_unit FROM ingredients WHERE id=?',[$id]);
                if(!$item) throw new RuntimeException('Recipe references a missing ingredient.');
                $this->addRequirement(
                    $requirements,'ingredient',$id,
                    $this->units->convert($qty,$component['unit'],$item['inventory_unit']),
                    $item['inventory_unit']
                );
                continue;
            }
            if($type==='packaging'){
                $item=$this->db->one('SELECT inventory_unit FROM packaging_items WHERE id=?',[$id]);
                if(!$item) throw new RuntimeException('Recipe references missing packaging.');
                $this->addRequirement(
                    $requirements,'packaging',$id,
                    $this->units->convert($qty,$component['unit'],$item['inventory_unit']),
                    $item['inventory_unit']
                );
                continue;
            }
            if($type==='recipe'){
                $nested=$this->db->one(
                    'SELECT rv.id,rv.yield_unit
                     FROM recipes r
                     JOIN recipe_versions rv ON rv.recipe_id=r.id
                     WHERE r.id=? AND rv.status="published"
                     ORDER BY rv.version_number DESC LIMIT 1',
                    [$id]
                );
                if(!$nested) throw new RuntimeException('Nested recipe has no published version.');
                $nestedTarget=$this->units->convert($qty,$component['unit'],$nested['yield_unit']);
                $this->expandRecipeVersion((int)$nested['id'],$nestedTarget,$requirements,$stack);
                continue;
            }
            throw new RuntimeException('Unsupported recipe component type.');
        }
    }

    private function addRequirement(
        array &$requirements,
        string $type,
        int $itemId,
        float $quantity,
        string $unit
    ): void {
        $key=$type.':'.$itemId;
        if(!isset($requirements[$key])){
            $requirements[$key]=[
                'item_type'=>$type,'item_id'=>$itemId,'quantity'=>0.0,'unit'=>$unit,
            ];
        }
        $requirements[$key]['quantity']+=$quantity;
    }

    private function usableOnHand(string $type, int $itemId): float
    {
        if($type==='packaging'){
            return (float)$this->db->scalar(
                "SELECT COALESCE(SUM(quantity_delta),0)
                 FROM inventory_transactions
                 WHERE item_type='packaging' AND item_id=?",
                [$itemId]
            );
        }

        $tracked=(float)$this->db->scalar(
            "SELECT COALESCE(SUM(t.quantity_delta),0)
             FROM inventory_transactions t
             JOIN ingredient_lots l ON l.id=t.lot_id
             WHERE t.item_type='ingredient' AND t.item_id=?
               AND (l.expires_at IS NULL OR DATE(l.expires_at)>=CURDATE())",
            [$itemId]
        );
        $untracked=(float)$this->db->scalar(
            "SELECT COALESCE(SUM(quantity_delta),0)
             FROM inventory_transactions
             WHERE item_type='ingredient' AND item_id=? AND lot_id IS NULL",
            [$itemId]
        );
        return max(0,$tracked)+max(0,$untracked);
    }

    private function itemName(string $type, int $itemId): string
    {
        if($type==='ingredient'){
            return (string)($this->db->scalar('SELECT name FROM ingredients WHERE id=?',[$itemId]) ?: 'Ingredient');
        }
        return (string)($this->db->scalar('SELECT name FROM packaging_items WHERE id=?',[$itemId]) ?: 'Packaging');
    }

    private function postConsumption(
        array $material,
        float $quantity,
        ?int $lotId,
        array $batch,
        int $userId
    ): int {
        return $this->db->insert(
            'INSERT INTO inventory_transactions
             (item_type,item_id,lot_id,quantity_delta,unit,reason,reference_type,reference_id,notes,created_by,created_at)
             VALUES (?,?,?,?,?,"Production Use","production_batch_material",?,?,?,NOW())',
            [
                $material['item_type'],$material['item_id'],$lotId,-$quantity,$material['unit'],
                $material['id'],$batch['batch_code'],$userId
            ]
        );
    }
}
