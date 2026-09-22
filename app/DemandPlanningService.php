<?php
final class DemandPlanningService
{
    private const ELIGIBLE_STATUSES = ['new','paid','production'];

    public function __construct(
        private Database $db,
        private UnitConversionService $units
    ) {}

    public function allocateFlavor(int $orderItemId, int $flavorId, int $quantity): void
    {
        if ($quantity < 0) throw new RuntimeException('Flavor allocation cannot be negative.');

        $item = $this->db->one(
            'SELECT oi.*,o.status,p.box_capacity,p.name product_name
             FROM order_items oi
             JOIN orders o ON o.id=oi.order_id
             JOIN products p ON p.id=oi.product_id
             WHERE oi.id=?',
            [$orderItemId]
        );
        if (!$item) throw new RuntimeException('Order item not found.');
        if (!in_array($item['status'], self::ELIGIBLE_STATUSES, true)) {
            throw new RuntimeException('Flavor allocation can only be changed before packing begins.');
        }

        $flavor = $this->db->one('SELECT id,name FROM flavors WHERE id=? AND is_active=1',[$flavorId]);
        if (!$flavor) throw new RuntimeException('Select an active flavor.');

        $capacity = max(1,(int)($item['box_capacity'] ?: 1));
        $maximum = $capacity * max(1,(int)$item['quantity']);
        $other = (int)$this->db->scalar(
            'SELECT COALESCE(SUM(quantity),0)
             FROM order_item_flavors
             WHERE order_item_id=? AND flavor_id<>?',
            [$orderItemId,$flavorId]
        );

        if ($other + $quantity > $maximum) {
            throw new RuntimeException(
                'Flavor allocations cannot exceed '.$maximum.' unit'.($maximum===1?'':'s').' for this order item.'
            );
        }

        if ($quantity === 0) {
            $this->db->exec(
                'DELETE FROM order_item_flavors WHERE order_item_id=? AND flavor_id=?',
                [$orderItemId,$flavorId]
            );
            return;
        }

        $this->db->exec(
            'INSERT INTO order_item_flavors(order_item_id,flavor_id,quantity)
             VALUES(?,?,?)
             ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)',
            [$orderItemId,$flavorId,$quantity]
        );
    }

    public function createPlan(string $startDate, string $endDate, ?string $notes, int $userId): int
    {
        $this->validateDates($startDate,$endDate);

        $planId = $this->db->insert(
            'INSERT INTO production_plans
             (plan_code,start_date,end_date,status,notes,created_by)
             VALUES (?,?,?,"draft",?,?)',
            [Security::reference('PLAN'),$startDate,$endDate,$notes ?: null,$userId]
        );

        $this->rebuild($planId);
        return $planId;
    }

    public function rebuild(int $planId): array
    {
        return $this->db->transaction(function(Database $db) use ($planId) {
            $plan = $db->one('SELECT * FROM production_plans WHERE id=? FOR UPDATE',[$planId]);
            if (!$plan) throw new RuntimeException('Production plan not found.');
            if ($plan['status'] !== 'draft') throw new RuntimeException('Only draft production plans can be rebuilt.');

            $db->exec('DELETE FROM production_plan_issues WHERE production_plan_id=?',[$planId]);
            $db->exec('DELETE FROM production_plan_requirements WHERE production_plan_id=?',[$planId]);
            $db->exec('DELETE FROM production_plan_items WHERE production_plan_id=?',[$planId]);
            $db->exec('DELETE FROM production_plan_orders WHERE production_plan_id=?',[$planId]);

            $snapshot = $this->calculateDemand($plan['start_date'],$plan['end_date']);

            foreach ($snapshot['order_ids'] as $orderId) {
                $db->exec(
                    'INSERT INTO production_plan_orders(production_plan_id,order_id) VALUES(?,?)',
                    [$planId,$orderId]
                );
            }

            foreach ($snapshot['flavor_demand'] as $flavorId=>$qty) {
                $db->exec(
                    'INSERT INTO production_plan_items
                     (production_plan_id,flavor_id,required_quantity,planned_quantity)
                     VALUES(?,?,?,?)',
                    [$planId,(int)$flavorId,(int)$qty,(int)$qty]
                );
            }

            foreach ($snapshot['requirements'] as $key=>$req) {
                $onHand=$this->onHand($req['item_type'],$req['item_id']);
                $onOrder=$this->onOrder($req['item_type'],$req['item_id'],$req['unit']);
                $shortage=max(0,(float)$req['required_quantity']-$onHand-$onOrder);

                $db->exec(
                    'INSERT INTO production_plan_requirements
                     (production_plan_id,item_type,item_id,required_quantity,on_hand_at_build,on_order_at_build,shortage_at_build,unit)
                     VALUES(?,?,?,?,?,?,?,?)',
                    [
                        $planId,$req['item_type'],$req['item_id'],$req['required_quantity'],
                        $onHand,$onOrder,$shortage,$req['unit']
                    ]
                );

                if ($shortage > 0.000001) {
                    $snapshot['issues'][]=[
                        'severity'=>'warning',
                        'issue_code'=>'MATERIAL_SHORTAGE',
                        'message'=>$req['name'].' is short by '.number_format($shortage,3).' '.$req['unit'].'.',
                        'order_id'=>null,'order_item_id'=>null,'flavor_id'=>null,
                    ];
                }
            }

            foreach ($snapshot['issues'] as $issue) {
                $db->exec(
                    'INSERT INTO production_plan_issues
                     (production_plan_id,severity,issue_code,message,order_id,order_item_id,flavor_id)
                     VALUES(?,?,?,?,?,?,?)',
                    [
                        $planId,$issue['severity'],$issue['issue_code'],$issue['message'],
                        $issue['order_id']??null,$issue['order_item_id']??null,$issue['flavor_id']??null
                    ]
                );
            }

            $blocking=count(array_filter($snapshot['issues'],fn($i)=>$i['severity']==='blocking'));
            $warnings=count(array_filter($snapshot['issues'],fn($i)=>$i['severity']==='warning'));
            $fingerprint=$this->sourceFingerprint($plan['start_date'],$plan['end_date']);

            $db->exec(
                'UPDATE production_plans
                 SET unallocated_units=?,blocking_issue_count=?,warning_count=?,
                     source_fingerprint=?,built_at=NOW()
                 WHERE id=?',
                [$snapshot['unallocated_units'],$blocking,$warnings,$fingerprint,$planId]
            );

            return [
                'orders'=>count($snapshot['order_ids']),
                'flavors'=>count($snapshot['flavor_demand']),
                'unallocated_units'=>$snapshot['unallocated_units'],
                'blocking_issues'=>$blocking,
                'warnings'=>$warnings,
            ];
        });
    }

    public function lock(int $planId, int $userId): void
    {
        $this->db->transaction(function(Database $db) use ($planId,$userId) {
            $plan=$db->one('SELECT * FROM production_plans WHERE id=? FOR UPDATE',[$planId]);
            if(!$plan) throw new RuntimeException('Production plan not found.');
            if($plan['status']!=='draft') throw new RuntimeException('Only draft plans can be locked.');
            if((int)$plan['blocking_issue_count']>0 || (int)$plan['unallocated_units']>0) {
                throw new RuntimeException('Resolve all blocking issues and unallocated order units before locking this plan.');
            }

            $current=$this->sourceFingerprint($plan['start_date'],$plan['end_date']);
            if(!$plan['source_fingerprint'] || !hash_equals($plan['source_fingerprint'],$current)) {
                throw new RuntimeException('Orders, flavor allocations, recipes, or packaging changed after this plan was built. Rebuild the plan before locking it.');
            }

            $db->exec(
                'UPDATE production_plans
                 SET status="locked",locked_by=?,locked_at=NOW()
                 WHERE id=?',
                [$userId,$planId]
            );
        });
    }

    public function setPlannedQuantity(int $planId, int $flavorId, int $quantity): void
    {
        $plan=$this->db->one('SELECT status FROM production_plans WHERE id=?',[$planId]);
        if(!$plan || $plan['status']!=='draft') throw new RuntimeException('Only draft plans can be edited.');

        $row=$this->db->one(
            'SELECT required_quantity FROM production_plan_items
             WHERE production_plan_id=? AND flavor_id=?',
            [$planId,$flavorId]
        );
        if(!$row) throw new RuntimeException('Flavor is not in this production plan.');
        if($quantity < (int)$row['required_quantity']) {
            throw new RuntimeException('Planned quantity cannot be lower than confirmed order demand.');
        }

        $this->db->exec(
            'UPDATE production_plan_items SET planned_quantity=? WHERE production_plan_id=? AND flavor_id=?',
            [$quantity,$planId,$flavorId]
        );
        $this->refreshMaterialRequirements($planId);
    }

    public function cancel(int $planId): void
    {
        $plan=$this->db->one('SELECT status FROM production_plans WHERE id=?',[$planId]);
        if(!$plan || in_array($plan['status'],['completed','cancelled'],true)) {
            throw new RuntimeException('This production plan cannot be cancelled.');
        }
        $this->db->exec('UPDATE production_plans SET status="cancelled" WHERE id=?',[$planId]);
    }

    public function sourceFingerprint(string $startDate, string $endDate): string
    {
        $this->validateDates($startDate,$endDate);

        $orders=$this->db->all(
            "SELECT o.id order_id,o.status,o.fulfillment_at,o.updated_at,
                    oi.id order_item_id,oi.product_id,oi.quantity,p.box_capacity,p.updated_at product_updated_at
             FROM orders o
             JOIN order_items oi ON oi.order_id=o.id
             JOIN products p ON p.id=oi.product_id
             WHERE o.status IN ('new','paid','production')
               AND COALESCE(DATE(o.fulfillment_at),DATE(o.created_at)) BETWEEN ? AND ?
             ORDER BY o.id,oi.id",
            [$startDate,$endDate]
        );

        $allocations=$this->db->all(
            "SELECT oif.order_item_id,oif.flavor_id,oif.quantity
             FROM order_item_flavors oif
             JOIN order_items oi ON oi.id=oif.order_item_id
             JOIN orders o ON o.id=oi.order_id
             WHERE o.status IN ('new','paid','production')
               AND COALESCE(DATE(o.fulfillment_at),DATE(o.created_at)) BETWEEN ? AND ?
             ORDER BY oif.order_item_id,oif.flavor_id",
            [$startDate,$endDate]
        );

        $recipes=$this->db->all(
            "SELECT r.id recipe_id,r.flavor_id,rv.id version_id,rv.version_number,
                    rv.yield_quantity,rv.yield_unit,
                    ri.component_type,ri.component_id,ri.quantity,ri.unit,ri.sort_order
             FROM recipes r
             JOIN recipe_versions rv ON rv.recipe_id=r.id AND rv.status='published'
             LEFT JOIN recipe_items ri ON ri.recipe_version_id=rv.id
             WHERE r.is_active=1
             ORDER BY r.id,rv.id,ri.sort_order,ri.id"
        );

        $packaging=$this->db->all(
            'SELECT product_id,packaging_item_id,quantity,unit
             FROM product_packaging_components
             ORDER BY product_id,packaging_item_id'
        );

        return hash(
            'sha256',
            json_encode(
                ['orders'=>$orders,'allocations'=>$allocations,'recipes'=>$recipes,'packaging'=>$packaging],
                JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION
            )
        );
    }

    public function calculateDemand(string $startDate, string $endDate): array
    {
        $this->validateDates($startDate,$endDate);

        $items=$this->db->all(
            "SELECT o.id order_id,o.order_number,o.status,o.fulfillment_at,
                    oi.id order_item_id,oi.product_id,oi.quantity order_quantity,
                    p.name product_name,COALESCE(NULLIF(p.box_capacity,0),1) box_capacity
             FROM orders o
             JOIN order_items oi ON oi.order_id=o.id
             JOIN products p ON p.id=oi.product_id
             WHERE o.status IN ('new','paid','production')
               AND COALESCE(DATE(o.fulfillment_at),DATE(o.created_at)) BETWEEN ? AND ?
             ORDER BY o.id,oi.id",
            [$startDate,$endDate]
        );

        $orderIds=[];
        $flavorDemand=[];
        $requirements=[];
        $issues=[];
        $unallocated=0;

        if(!$items){
            $issues[]=[
                'severity'=>'blocking','issue_code'=>'NO_ELIGIBLE_ORDERS',
                'message'=>'No production-eligible orders exist in this date range.',
                'order_id'=>null,'order_item_id'=>null,'flavor_id'=>null,
            ];
        }

        foreach($items as $item){
            $orderIds[(int)$item['order_id']]=(int)$item['order_id'];
            $capacity=max(1,(int)$item['box_capacity']);
            $unitsRequired=$capacity*max(1,(int)$item['order_quantity']);

            $allocations=$this->db->all(
                'SELECT oif.flavor_id,oif.quantity,f.name flavor_name,f.is_active
                 FROM order_item_flavors oif
                 JOIN flavors f ON f.id=oif.flavor_id
                 WHERE oif.order_item_id=?
                 ORDER BY f.name',
                [$item['order_item_id']]
            );

            $allocated=0;
            foreach($allocations as $allocation){
                $qty=max(0,(int)$allocation['quantity']);
                $allocated+=$qty;

                if(!(int)$allocation['is_active']){
                    $issues[]=[
                        'severity'=>'blocking','issue_code'=>'INACTIVE_FLAVOR',
                        'message'=>$item['order_number'].' uses inactive flavor '.$allocation['flavor_name'].'.',
                        'order_id'=>(int)$item['order_id'],'order_item_id'=>(int)$item['order_item_id'],
                        'flavor_id'=>(int)$allocation['flavor_id'],
                    ];
                }

                $flavorDemand[(int)$allocation['flavor_id']]=
                    ($flavorDemand[(int)$allocation['flavor_id']]??0)+$qty;
            }

            if($allocated>$unitsRequired){
                $issues[]=[
                    'severity'=>'blocking','issue_code'=>'OVER_ALLOCATED_ORDER_ITEM',
                    'message'=>$item['order_number'].' '.$item['product_name'].' is over-allocated by '.($allocated-$unitsRequired).' unit(s).',
                    'order_id'=>(int)$item['order_id'],'order_item_id'=>(int)$item['order_item_id'],'flavor_id'=>null,
                ];
            } elseif($allocated<$unitsRequired){
                $missing=$unitsRequired-$allocated;
                $unallocated+=$missing;
                $issues[]=[
                    'severity'=>'blocking','issue_code'=>'UNALLOCATED_ORDER_UNITS',
                    'message'=>$item['order_number'].' '.$item['product_name'].' still needs '.$missing.' flavor allocation(s).',
                    'order_id'=>(int)$item['order_id'],'order_item_id'=>(int)$item['order_item_id'],'flavor_id'=>null,
                ];
            }

            $productPackaging=$this->db->all(
                'SELECT ppc.*,pi.name packaging_name,pi.inventory_unit
                 FROM product_packaging_components ppc
                 JOIN packaging_items pi ON pi.id=ppc.packaging_item_id
                 WHERE ppc.product_id=?
                 ORDER BY ppc.id',
                [$item['product_id']]
            );
            foreach($productPackaging as $component){
                try{
                    $qty=$this->units->convert(
                        (float)$component['quantity']*(int)$item['order_quantity'],
                        (string)$component['unit'],
                        (string)$component['inventory_unit']
                    );
                    $this->addRequirement(
                        $requirements,'packaging',(int)$component['packaging_item_id'],
                        $component['packaging_name'],$qty,$component['inventory_unit']
                    );
                }catch(Throwable $e){
                    $issues[]=[
                        'severity'=>'blocking','issue_code'=>'PACKAGING_UNIT_ERROR',
                        'message'=>$item['product_name'].' packaging: '.$e->getMessage(),
                        'order_id'=>(int)$item['order_id'],'order_item_id'=>(int)$item['order_item_id'],'flavor_id'=>null,
                    ];
                }
            }
        }

        foreach($flavorDemand as $flavorId=>$quantity){
            if($quantity<=0) continue;
            $recipe=$this->db->one(
                'SELECT r.id recipe_id,r.name,rv.id version_id
                 FROM recipes r
                 JOIN recipe_versions rv ON rv.recipe_id=r.id AND rv.status="published"
                 WHERE r.recipe_type="finished" AND r.flavor_id=? AND r.is_active=1
                 ORDER BY rv.version_number DESC LIMIT 1',
                [$flavorId]
            );

            if(!$recipe){
                $flavorName=(string)($this->db->scalar('SELECT name FROM flavors WHERE id=?',[$flavorId])?:'Flavor');
                $issues[]=[
                    'severity'=>'blocking','issue_code'=>'MISSING_PUBLISHED_RECIPE',
                    'message'=>$flavorName.' does not have an active published finished recipe.',
                    'order_id'=>null,'order_item_id'=>null,'flavor_id'=>$flavorId,
                ];
                continue;
            }

            try{
                $this->expandRecipeVersion(
                    (int)$recipe['version_id'],(float)$quantity,$requirements,$issues,[]
                );
            }catch(Throwable $e){
                $issues[]=[
                    'severity'=>'blocking','issue_code'=>'RECIPE_EXPANSION_ERROR',
                    'message'=>$recipe['name'].': '.$e->getMessage(),
                    'order_id'=>null,'order_item_id'=>null,'flavor_id'=>$flavorId,
                ];
            }
        }

        return [
            'order_ids'=>array_values($orderIds),
            'flavor_demand'=>$flavorDemand,
            'requirements'=>$requirements,
            'issues'=>$issues,
            'unallocated_units'=>$unallocated,
        ];
    }

    private function expandRecipeVersion(
        int $versionId,
        float $targetOutput,
        array &$requirements,
        array &$issues,
        array $stack
    ): void {
        $version=$this->db->one(
            'SELECT rv.*,r.id recipe_id,r.name recipe_name
             FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id
             WHERE rv.id=?',
            [$versionId]
        );
        if(!$version) throw new RuntimeException('Recipe version not found.');

        $recipeId=(int)$version['recipe_id'];
        if(in_array($recipeId,$stack,true)) {
            throw new RuntimeException('Recipe cycle detected while expanding production requirements.');
        }
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
                $item=$this->db->one('SELECT name,inventory_unit FROM ingredients WHERE id=?',[$id]);
                if(!$item) throw new RuntimeException('Recipe references a missing ingredient.');
                $normalized=$this->units->convert($qty,$component['unit'],$item['inventory_unit']);
                $this->addRequirement($requirements,'ingredient',$id,$item['name'],$normalized,$item['inventory_unit']);
                continue;
            }

            if($type==='packaging'){
                $item=$this->db->one('SELECT name,inventory_unit FROM packaging_items WHERE id=?',[$id]);
                if(!$item) throw new RuntimeException('Recipe references a missing packaging item.');
                $normalized=$this->units->convert($qty,$component['unit'],$item['inventory_unit']);
                $this->addRequirement($requirements,'packaging',$id,$item['name'],$normalized,$item['inventory_unit']);
                continue;
            }

            if($type==='recipe'){
                $nested=$this->db->one(
                    'SELECT rv.id,rv.yield_unit,r.name
                     FROM recipes r
                     JOIN recipe_versions rv ON rv.recipe_id=r.id AND rv.status="published"
                     WHERE r.id=? AND r.is_active=1
                     ORDER BY rv.version_number DESC LIMIT 1',
                    [$id]
                );
                if(!$nested) throw new RuntimeException('Nested recipe #'.$id.' has no active published version.');
                $nestedTarget=$this->units->convert($qty,$component['unit'],$nested['yield_unit']);
                $this->expandRecipeVersion((int)$nested['id'],$nestedTarget,$requirements,$issues,$stack);
                continue;
            }

            throw new RuntimeException('Unsupported recipe component type '.$type.'.');
        }
    }

    private function addRequirement(
        array &$requirements,
        string $type,
        int $itemId,
        string $name,
        float $quantity,
        string $unit
    ): void {
        $key=$type.':'.$itemId;
        if(!isset($requirements[$key])){
            $requirements[$key]=[
                'item_type'=>$type,'item_id'=>$itemId,'name'=>$name,
                'required_quantity'=>0.0,'unit'=>$unit,
            ];
        }
        $requirements[$key]['required_quantity']+=(float)$quantity;
    }

    private function refreshMaterialRequirements(int $planId): void
    {
        $this->db->transaction(function(Database $db) use ($planId) {
            $plan=$db->one('SELECT * FROM production_plans WHERE id=? FOR UPDATE',[$planId]);
            if(!$plan || $plan['status']!=='draft') {
                throw new RuntimeException('Only draft production plans can be recalculated.');
            }

            $db->exec(
                'DELETE FROM production_plan_issues
                 WHERE production_plan_id=? AND issue_code IN ("MATERIAL_SHORTAGE","RECIPE_EXPANSION_ERROR","PACKAGING_UNIT_ERROR","MISSING_PUBLISHED_RECIPE")',
                [$planId]
            );
            $db->exec('DELETE FROM production_plan_requirements WHERE production_plan_id=?',[$planId]);

            $requirements=[];
            $issues=[];

            $orderItems=$db->all(
                'SELECT oi.product_id,oi.quantity,p.name product_name
                 FROM production_plan_orders ppo
                 JOIN order_items oi ON oi.order_id=ppo.order_id
                 JOIN products p ON p.id=oi.product_id
                 WHERE ppo.production_plan_id=?
                 ORDER BY oi.id',
                [$planId]
            );

            foreach($orderItems as $item){
                $components=$db->all(
                    'SELECT ppc.*,pi.name packaging_name,pi.inventory_unit
                     FROM product_packaging_components ppc
                     JOIN packaging_items pi ON pi.id=ppc.packaging_item_id
                     WHERE ppc.product_id=? ORDER BY ppc.id',
                    [$item['product_id']]
                );
                foreach($components as $component){
                    try{
                        $qty=$this->units->convert(
                            (float)$component['quantity']*(int)$item['quantity'],
                            (string)$component['unit'],
                            (string)$component['inventory_unit']
                        );
                        $this->addRequirement(
                            $requirements,'packaging',(int)$component['packaging_item_id'],
                            $component['packaging_name'],$qty,$component['inventory_unit']
                        );
                    }catch(Throwable $e){
                        $issues[]=[
                            'severity'=>'blocking','issue_code'=>'PACKAGING_UNIT_ERROR',
                            'message'=>$item['product_name'].' packaging: '.$e->getMessage(),
                            'order_id'=>null,'order_item_id'=>null,'flavor_id'=>null,
                        ];
                    }
                }
            }

            $planItems=$db->all(
                'SELECT ppi.flavor_id,ppi.planned_quantity,f.name flavor_name
                 FROM production_plan_items ppi
                 JOIN flavors f ON f.id=ppi.flavor_id
                 WHERE ppi.production_plan_id=? ORDER BY f.name',
                [$planId]
            );

            foreach($planItems as $planItem){
                $recipe=$db->one(
                    'SELECT r.name,rv.id version_id
                     FROM recipes r
                     JOIN recipe_versions rv ON rv.recipe_id=r.id AND rv.status="published"
                     WHERE r.recipe_type="finished" AND r.flavor_id=? AND r.is_active=1
                     ORDER BY rv.version_number DESC LIMIT 1',
                    [$planItem['flavor_id']]
                );
                if(!$recipe){
                    $issues[]=[
                        'severity'=>'blocking','issue_code'=>'MISSING_PUBLISHED_RECIPE',
                        'message'=>$planItem['flavor_name'].' does not have an active published finished recipe.',
                        'order_id'=>null,'order_item_id'=>null,'flavor_id'=>(int)$planItem['flavor_id'],
                    ];
                    continue;
                }
                try{
                    $this->expandRecipeVersion(
                        (int)$recipe['version_id'],(float)$planItem['planned_quantity'],
                        $requirements,$issues,[]
                    );
                }catch(Throwable $e){
                    $issues[]=[
                        'severity'=>'blocking','issue_code'=>'RECIPE_EXPANSION_ERROR',
                        'message'=>$recipe['name'].': '.$e->getMessage(),
                        'order_id'=>null,'order_item_id'=>null,'flavor_id'=>(int)$planItem['flavor_id'],
                    ];
                }
            }

            foreach($requirements as $req){
                $onHand=$this->onHand($req['item_type'],$req['item_id']);
                $onOrder=$this->onOrder($req['item_type'],$req['item_id'],$req['unit']);
                $shortage=max(0,(float)$req['required_quantity']-$onHand-$onOrder);
                $db->exec(
                    'INSERT INTO production_plan_requirements
                     (production_plan_id,item_type,item_id,required_quantity,on_hand_at_build,on_order_at_build,shortage_at_build,unit)
                     VALUES(?,?,?,?,?,?,?,?)',
                    [$planId,$req['item_type'],$req['item_id'],$req['required_quantity'],$onHand,$onOrder,$shortage,$req['unit']]
                );
                if($shortage>0.000001){
                    $issues[]=[
                        'severity'=>'warning','issue_code'=>'MATERIAL_SHORTAGE',
                        'message'=>$req['name'].' is short by '.number_format($shortage,3).' '.$req['unit'].'.',
                        'order_id'=>null,'order_item_id'=>null,'flavor_id'=>null,
                    ];
                }
            }

            foreach($issues as $issue){
                $db->exec(
                    'INSERT INTO production_plan_issues
                     (production_plan_id,severity,issue_code,message,order_id,order_item_id,flavor_id)
                     VALUES(?,?,?,?,?,?,?)',
                    [$planId,$issue['severity'],$issue['issue_code'],$issue['message'],$issue['order_id']??null,$issue['order_item_id']??null,$issue['flavor_id']??null]
                );
            }

            $blocking=(int)$db->scalar(
                'SELECT COUNT(*) FROM production_plan_issues WHERE production_plan_id=? AND severity="blocking"',
                [$planId]
            );
            $warnings=(int)$db->scalar(
                'SELECT COUNT(*) FROM production_plan_issues WHERE production_plan_id=? AND severity="warning"',
                [$planId]
            );
            $db->exec(
                'UPDATE production_plans SET blocking_issue_count=?,warning_count=? WHERE id=?',
                [$blocking,$warnings,$planId]
            );
        });
    }

    private function onHand(string $type, int $itemId): float
    {
        return (float)$this->db->scalar(
            'SELECT COALESCE(SUM(quantity_delta),0)
             FROM inventory_transactions
             WHERE item_type=? AND item_id=?',
            [$type,$itemId]
        );
    }

    private function onOrder(string $type, int $itemId, string $inventoryUnit): float
    {
        $rows=$this->db->all(
            "SELECT poi.ordered_packages,poi.received_packages,poi.package_quantity,poi.package_unit
             FROM purchase_order_items poi
             JOIN purchase_orders po ON po.id=poi.purchase_order_id
             WHERE poi.item_type=? AND poi.item_id=?
               AND po.status IN ('submitted','partial')
               AND poi.received_packages < poi.ordered_packages",
            [$type,$itemId]
        );

        $total=0.0;
        foreach($rows as $row){
            $remaining=max(0,(float)$row['ordered_packages']-(float)$row['received_packages']);
            if($remaining<=0) continue;
            try{
                $total+=$this->units->convert(
                    $remaining*(float)$row['package_quantity'],
                    (string)$row['package_unit'],
                    $inventoryUnit
                );
            }catch(Throwable $ignored){}
        }
        return $total;
    }

    private function validateDates(string $startDate, string $endDate): void
    {
        $start=DateTimeImmutable::createFromFormat('!Y-m-d',$startDate);
        $end=DateTimeImmutable::createFromFormat('!Y-m-d',$endDate);
        if(!$start||!$end||$start->format('Y-m-d')!==$startDate||$end->format('Y-m-d')!==$endDate){
            throw new RuntimeException('Use valid production-plan dates.');
        }
        if($end<$start) throw new RuntimeException('Plan end date cannot be before the start date.');
        if($start->diff($end)->days>31) throw new RuntimeException('A production plan can cover at most 31 days.');
    }
}
