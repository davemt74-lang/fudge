<?php
final class CostingService
{
    public function __construct(private Database $db, private UnitConversionService $units) {}

    public function inventoryItemCost(string $type, int $itemId): array
    {
        if (!in_array($type, ['ingredient','packaging'], true)) {
            throw new InvalidArgumentException('Unsupported costing item type.');
        }

        if ($type === 'ingredient') {
            $item = $this->db->one('SELECT id,name,inventory_unit FROM ingredients WHERE id=?', [$itemId]);
        } else {
            $item = $this->db->one('SELECT id,name,inventory_unit,current_unit_cost FROM packaging_items WHERE id=?', [$itemId]);
        }
        if (!$item) throw new RuntimeException('Costing item not found.');

        $supplier = $this->db->one(
            'SELECT si.id supplier_item_id,si.unit_cost,si.package_price,si.package_quantity,si.package_unit,
                    si.is_preferred,s.name supplier_name
             FROM supplier_items si
             JOIN suppliers s ON s.id=si.supplier_id AND s.is_active=1
             WHERE si.item_type=? AND si.item_id=? AND si.unit_cost>0
             ORDER BY si.is_preferred DESC,si.unit_cost ASC,si.package_price ASC
             LIMIT 1',
            [$type,$itemId]
        );

        if ($supplier) {
            return [
                'type'=>$type,
                'id'=>$itemId,
                'name'=>$item['name'],
                'inventory_unit'=>$item['inventory_unit'],
                'unit_cost'=>(float)$supplier['unit_cost'],
                'complete'=>true,
                'supplier_item_id'=>(int)$supplier['supplier_item_id'],
                'supplier_name'=>$supplier['supplier_name'],
                'source'=>$supplier['is_preferred'] ? 'preferred_supplier' : 'lowest_supplier_cost',
                'warning'=>null,
            ];
        }

        if ($type === 'packaging' && (float)$item['current_unit_cost'] > 0) {
            return [
                'type'=>$type,
                'id'=>$itemId,
                'name'=>$item['name'],
                'inventory_unit'=>$item['inventory_unit'],
                'unit_cost'=>(float)$item['current_unit_cost'],
                'complete'=>true,
                'supplier_item_id'=>null,
                'supplier_name'=>null,
                'source'=>'catalog_fallback',
                'warning'=>null,
            ];
        }

        return [
            'type'=>$type,
            'id'=>$itemId,
            'name'=>$item['name'],
            'inventory_unit'=>$item['inventory_unit'],
            'unit_cost'=>0.0,
            'complete'=>false,
            'supplier_item_id'=>null,
            'supplier_name'=>null,
            'source'=>'missing',
            'warning'=>'Missing current cost for ' . $item['name'],
        ];
    }

    public function recipeVersionCost(int $recipeVersionId, array $stack = []): array
    {
        $version = $this->db->one(
            'SELECT rv.*,r.name recipe_name,r.id recipe_id,r.recipe_type
             FROM recipe_versions rv
             JOIN recipes r ON r.id=rv.recipe_id
             WHERE rv.id=?',
            [$recipeVersionId]
        );
        if (!$version) throw new RuntimeException('Recipe version not found.');

        $recipeId = (int)$version['recipe_id'];
        if (in_array($recipeId, $stack, true)) {
            $chain = implode(' → ', array_merge($stack, [$recipeId]));
            throw new RuntimeException('Recipe cycle detected: ' . $chain);
        }
        $stack[] = $recipeId;

        $yieldQty = (float)$version['yield_quantity'];
        if ($yieldQty <= 0) throw new RuntimeException('Recipe yield must be greater than zero.');

        $items = $this->db->all(
            'SELECT * FROM recipe_items WHERE recipe_version_id=? ORDER BY sort_order,id',
            [$recipeVersionId]
        );

        $materialCost = 0.0;
        $warnings = [];
        $details = [];

        foreach ($items as $item) {
            $componentType = $item['component_type'];
            $componentId = (int)$item['component_id'];
            $quantity = (float)$item['quantity'];
            $unit = (string)$item['unit'];

            if ($quantity < 0) {
                $warnings[] = 'Negative quantity on recipe component #' . $item['id'];
                continue;
            }

            if ($componentType === 'ingredient' || $componentType === 'packaging') {
                $costSource = $this->inventoryItemCost($componentType, $componentId);
                try {
                    $normalizedQty = $this->units->convert(
                        $quantity,
                        $unit,
                        (string)$costSource['inventory_unit']
                    );
                } catch (Throwable $e) {
                    $warnings[] = $costSource['name'] . ': ' . $e->getMessage();
                    $normalizedQty = 0.0;
                }

                if (!$costSource['complete'] && $costSource['warning']) {
                    $warnings[] = $costSource['warning'];
                }

                $componentCost = $normalizedQty * (float)$costSource['unit_cost'];
                $materialCost += $componentCost;
                $details[] = [
                    'recipe_item_id'=>(int)$item['id'],
                    'component_type'=>$componentType,
                    'component_id'=>$componentId,
                    'name'=>$costSource['name'],
                    'quantity'=>$quantity,
                    'unit'=>$unit,
                    'normalized_quantity'=>$normalizedQty,
                    'inventory_unit'=>$costSource['inventory_unit'],
                    'unit_cost'=>$costSource['unit_cost'],
                    'cost'=>$componentCost,
                    'source'=>$costSource['source'],
                    'supplier'=>$costSource['supplier_name'],
                    'complete'=>$costSource['complete'],
                ];
                continue;
            }

            if ($componentType === 'recipe') {
                $nestedVersion = !empty($item['component_recipe_version_id'])
                    ? $this->db->one(
                        'SELECT * FROM recipe_versions WHERE id=? AND recipe_id=?',
                        [(int)$item['component_recipe_version_id'],$componentId]
                    )
                    : $this->publishedVersionForRecipe($componentId);
                if (!$nestedVersion) {
                    $name = (string)($this->db->scalar('SELECT name FROM recipes WHERE id=?', [$componentId]) ?: 'Nested recipe');
                    $warnings[] = $name . ' has no published version.';
                    $details[] = [
                        'recipe_item_id'=>(int)$item['id'],'component_type'=>'recipe','component_id'=>$componentId,'name'=>$name,
                        'quantity'=>$quantity,'unit'=>$unit,'cost'=>0.0,'complete'=>false,
                    ];
                    continue;
                }

                $nested = $this->recipeVersionCost((int)$nestedVersion['id'], $stack);
                try {
                    $normalizedQty = $this->units->convert(
                        $quantity,
                        $unit,
                        (string)$nested['yield_unit']
                    );
                } catch (Throwable $e) {
                    $warnings[] = $nested['recipe_name'] . ': ' . $e->getMessage();
                    $normalizedQty = 0.0;
                }

                foreach ($nested['warnings'] as $warning) {
                    $warnings[] = $nested['recipe_name'] . ': ' . $warning;
                }

                $componentCost = $normalizedQty * (float)$nested['unit_cost'];
                $materialCost += $componentCost;
                $details[] = [
                    'recipe_item_id'=>(int)$item['id'],
                    'component_type'=>'recipe',
                    'component_id'=>$componentId,
                    'name'=>$nested['recipe_name'],
                    'quantity'=>$quantity,
                    'unit'=>$unit,
                    'normalized_quantity'=>$normalizedQty,
                    'inventory_unit'=>$nested['yield_unit'],
                    'unit_cost'=>$nested['unit_cost'],
                    'cost'=>$componentCost,
                    'source'=>'nested_recipe',
                    'supplier'=>null,
                    'complete'=>$nested['complete'],
                ];
                continue;
            }

            $warnings[] = 'Unsupported component type: ' . $componentType;
        }

        $warnings = array_values(array_unique($warnings));
        return [
            'recipe_id'=>$recipeId,
            'recipe_version_id'=>(int)$version['id'],
            'recipe_name'=>$version['recipe_name'],
            'recipe_type'=>$version['recipe_type'],
            'version_number'=>(int)$version['version_number'],
            'yield_quantity'=>$yieldQty,
            'yield_unit'=>$version['yield_unit'],
            'material_cost'=>$materialCost,
            'unit_cost'=>$materialCost / $yieldQty,
            'complete'=>count($warnings) === 0,
            'warnings'=>$warnings,
            'details'=>$details,
        ];
    }

    public function publishedVersionForRecipe(int $recipeId): ?array
    {
        return $this->db->one(
            'SELECT * FROM recipe_versions
             WHERE recipe_id=? AND status="published"
             ORDER BY version_number DESC LIMIT 1',
            [$recipeId]
        );
    }

    public function flavorCosts(): array
    {
        $rows = $this->db->all(
            'SELECT r.id recipe_id,r.name recipe_name,f.id flavor_id,f.name flavor_name
             FROM recipes r
             JOIN flavors f ON f.id=r.flavor_id AND f.is_active=1
             WHERE r.recipe_type="finished" AND r.is_active=1
             ORDER BY f.name'
        );
        $out = [];
        foreach ($rows as $row) {
            $version = $this->publishedVersionForRecipe((int)$row['recipe_id']);
            if (!$version) continue;
            $cost = $this->recipeVersionCost((int)$version['id']);
            $cost['flavor_id'] = (int)$row['flavor_id'];
            $cost['flavor_name'] = $row['flavor_name'];
            $out[] = $cost;
        }
        return $out;
    }

    public function productCost(int $productId, string $basis = 'average'): array
    {
        if (!in_array($basis, ['average','minimum','maximum'], true)) $basis = 'average';

        $product = $this->db->one('SELECT * FROM products WHERE id=?', [$productId]);
        if (!$product) throw new RuntimeException('Product not found.');

        $flavors = $this->flavorCosts();
        $warnings = [];
        if (!$flavors) $warnings[] = 'No active finished-flavor recipes have a published version.';

        $flavorUnitCosts = array_map(fn($f)=>(float)$f['unit_cost'],$flavors);
        $flavorComplete = true;
        foreach ($flavors as $flavor) {
            if (!$flavor['complete']) {
                $flavorComplete = false;
                foreach ($flavor['warnings'] as $warning) {
                    $warnings[] = $flavor['flavor_name'] . ': ' . $warning;
                }
            }
        }

        $basisCost = 0.0;
        if ($flavorUnitCosts) {
            $basisCost = match ($basis) {
                'minimum' => min($flavorUnitCosts),
                'maximum' => max($flavorUnitCosts),
                default => array_sum($flavorUnitCosts) / count($flavorUnitCosts),
            };
        }

        $capacity = max(1, (int)($product['box_capacity'] ?: 1));
        $donutCost = $basisCost * $capacity;
        $packagingCost = 0.0;
        $packagingDetails = [];

        $components = $this->db->all(
            'SELECT ppc.*,p.name packaging_name,p.inventory_unit
             FROM product_packaging_components ppc
             JOIN packaging_items p ON p.id=ppc.packaging_item_id
             WHERE ppc.product_id=?
             ORDER BY ppc.id',
            [$productId]
        );

        foreach ($components as $component) {
            $source = $this->inventoryItemCost('packaging',(int)$component['packaging_item_id']);
            try {
                $normalizedQty = $this->units->convert(
                    (float)$component['quantity'],
                    (string)$component['unit'],
                    (string)$source['inventory_unit']
                );
            } catch (Throwable $e) {
                $warnings[] = $component['packaging_name'] . ': ' . $e->getMessage();
                $normalizedQty = 0.0;
            }

            if (!$source['complete'] && $source['warning']) $warnings[] = $source['warning'];
            $cost = $normalizedQty * (float)$source['unit_cost'];
            $packagingCost += $cost;
            $packagingDetails[] = [
                'name'=>$component['packaging_name'],
                'quantity'=>$component['quantity'],
                'unit'=>$component['unit'],
                'cost'=>$cost,
                'complete'=>$source['complete'],
            ];
        }

        $directCogs = $donutCost + $packagingCost;
        $price = (float)$product['price'];
        $grossProfit = $price - $directCogs;
        $marginPct = $price > 0 ? ($grossProfit / $price) * 100 : 0.0;
        $warnings = array_values(array_unique($warnings));
        $complete = $flavorComplete && count($warnings) === 0 && count($flavors) > 0;

        return [
            'product_id'=>(int)$product['id'],
            'product_name'=>$product['name'],
            'sku'=>$product['sku'],
            'capacity'=>$capacity,
            'selling_price'=>$price,
            'flavor_cost_basis'=>$basis,
            'flavor_unit_cost'=>$basisCost,
            'donut_cost'=>$donutCost,
            'packaging_cost'=>$packagingCost,
            'direct_cogs'=>$directCogs,
            'gross_profit'=>$grossProfit,
            'margin_pct'=>$marginPct,
            'complete'=>$complete,
            'warnings'=>$warnings,
            'flavors'=>$flavors,
            'packaging'=>$packagingDetails,
        ];
    }

    public function captureSnapshots(int $userId, string $triggerType = 'manual', ?string $triggerReference = null): array
    {
        $recipeCount = 0;
        $productCount = 0;

        foreach ($this->db->all(
            'SELECT rv.id FROM recipe_versions rv
             WHERE rv.status="published"
             ORDER BY rv.id'
        ) as $row) {
            $cost = $this->recipeVersionCost((int)$row['id']);
            $this->db->insert(
                'INSERT INTO recipe_cost_snapshots
                 (recipe_version_id,material_cost,yield_quantity,unit_cost,is_complete,warning_count,trigger_type,trigger_reference,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [
                    $cost['recipe_version_id'],$cost['material_cost'],$cost['yield_quantity'],$cost['unit_cost'],
                    $cost['complete']?1:0,count($cost['warnings']),$triggerType,$triggerReference,$userId
                ]
            );
            $recipeCount++;
        }

        foreach ($this->db->all('SELECT id FROM products WHERE is_active=1 ORDER BY id') as $row) {
            $cost = $this->productCost((int)$row['id']);
            $this->db->insert(
                'INSERT INTO product_cost_snapshots
                 (product_id,direct_cogs,selling_price,gross_profit,margin_pct,is_complete,warning_count,flavor_cost_basis,trigger_type,trigger_reference,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $cost['product_id'],$cost['direct_cogs'],$cost['selling_price'],$cost['gross_profit'],$cost['margin_pct'],
                    $cost['complete']?1:0,count($cost['warnings']),$cost['flavor_cost_basis'],$triggerType,$triggerReference,$userId
                ]
            );
            $productCount++;
        }

        return ['recipes'=>$recipeCount,'products'=>$productCount];
    }
}

final class RecipeService
{
    public function __construct(private Database $db, private CostingService $costing) {}

    public function createRecipe(string $name, string $type, ?int $flavorId, int $userId): int
    {
        $name = trim($name);
        if ($name === '') throw new RuntimeException('Recipe name is required.');
        if (!in_array($type,['base','component','finished'],true)) throw new RuntimeException('Invalid recipe type.');
        if ($type === 'finished' && !$flavorId) throw new RuntimeException('Finished recipes require a flavor.');
        if ($type === 'finished' && $flavorId) {
            $exists = (int)$this->db->scalar(
                'SELECT COUNT(*) FROM recipes WHERE recipe_type="finished" AND flavor_id=? AND is_active=1',
                [$flavorId]
            );
            if ($exists > 0) throw new RuntimeException('That flavor already has an active finished recipe.');
        }

        return $this->db->transaction(function(Database $db) use ($name,$type,$flavorId,$userId) {
            $recipeId = $db->insert(
                'INSERT INTO recipes (name,recipe_type,flavor_id,is_active) VALUES (?,?,?,1)',
                [$name,$type,$flavorId ?: null]
            );
            $db->insert(
                'INSERT INTO recipe_versions (recipe_id,version_number,yield_quantity,yield_unit,status,created_by)
                 VALUES (?,1,1,"each","draft",?)',
                [$recipeId,$userId]
            );
            return $recipeId;
        });
    }

    public function updateRecipe(int $recipeId, string $name, bool $active): void
    {
        $name = trim($name);
        if ($name === '') throw new RuntimeException('Recipe name is required.');
        $recipe = $this->db->one('SELECT * FROM recipes WHERE id=?',[$recipeId]);
        if (!$recipe) throw new RuntimeException('Recipe not found.');
        if ($active && $recipe['recipe_type'] === 'finished' && $recipe['flavor_id']) {
            $duplicate = (int)$this->db->scalar(
                'SELECT COUNT(*) FROM recipes
                 WHERE recipe_type="finished" AND flavor_id=? AND is_active=1 AND id<>?',
                [$recipe['flavor_id'],$recipeId]
            );
            if ($duplicate > 0) throw new RuntimeException('That flavor already has another active finished recipe.');
        }
        $this->db->exec('UPDATE recipes SET name=?,is_active=? WHERE id=?',[$name,$active?1:0,$recipeId]);
    }

    public function createDraftVersion(int $recipeId, int $userId, bool $cloneCurrent = true): int
    {
        return $this->db->transaction(function(Database $db) use ($recipeId,$userId,$cloneCurrent) {
            $recipe = $db->one('SELECT * FROM recipes WHERE id=? FOR UPDATE',[$recipeId]);
            if (!$recipe) throw new RuntimeException('Recipe not found.');

            $draft = $db->one(
                'SELECT id FROM recipe_versions WHERE recipe_id=? AND status="draft" ORDER BY version_number DESC LIMIT 1',
                [$recipeId]
            );
            if ($draft) return (int)$draft['id'];

            $next = (int)$db->scalar('SELECT COALESCE(MAX(version_number),0)+1 FROM recipe_versions WHERE recipe_id=?',[$recipeId]);
            $source = $cloneCurrent
                ? $db->one('SELECT * FROM recipe_versions WHERE recipe_id=? AND status="published" ORDER BY version_number DESC LIMIT 1',[$recipeId])
                : null;

            $versionId = $db->insert(
                'INSERT INTO recipe_versions (recipe_id,version_number,yield_quantity,yield_unit,notes,status,created_by)
                 VALUES (?,?,?,?,?,"draft",?)',
                [
                    $recipeId,$next,
                    $source ? $source['yield_quantity'] : 1,
                    $source ? $source['yield_unit'] : 'each',
                    $source ? $source['notes'] : null,
                    $userId
                ]
            );

            if ($source) {
                $db->exec(
                    'INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
                     SELECT ?,component_type,component_id,quantity,unit,sort_order
                     FROM recipe_items WHERE recipe_version_id=?',
                    [$versionId,$source['id']]
                );
            }
            return $versionId;
        });
    }

    public function saveVersionMeta(int $versionId, float $yieldQty, string $yieldUnit, ?string $notes): void
    {
        if ($yieldQty <= 0) throw new RuntimeException('Recipe yield must be greater than zero.');
        if ((int)$this->db->scalar('SELECT COUNT(*) FROM units WHERE symbol=?',[$yieldUnit]) < 1) {
            throw new RuntimeException('Select a valid yield unit.');
        }
        $this->requireDraft($versionId);
        $this->db->exec(
            'UPDATE recipe_versions SET yield_quantity=?,yield_unit=?,notes=? WHERE id=?',
            [$yieldQty,$yieldUnit,$notes ?: null,$versionId]
        );
    }

    public function addComponent(int $versionId, string $type, int $componentId, float $quantity, string $unit): int
    {
        $version = $this->requireDraft($versionId);
        if (!in_array($type,['ingredient','packaging','recipe'],true)) throw new RuntimeException('Invalid recipe component type.');
        if ($quantity <= 0) throw new RuntimeException('Recipe component quantity must be greater than zero.');
        if ((int)$this->db->scalar('SELECT COUNT(*) FROM units WHERE symbol=?',[$unit]) < 1) {
            throw new RuntimeException('Select a valid component unit.');
        }
        $exists = match ($type) {
            'ingredient' => (int)$this->db->scalar('SELECT COUNT(*) FROM ingredients WHERE id=?',[$componentId]),
            'packaging' => (int)$this->db->scalar('SELECT COUNT(*) FROM packaging_items WHERE id=?',[$componentId]),
            'recipe' => (int)$this->db->scalar('SELECT COUNT(*) FROM recipes WHERE id=?',[$componentId]),
        };
        if ($exists < 1) throw new RuntimeException('Recipe component not found.');
        if ($type === 'recipe' && $componentId === (int)$version['recipe_id']) {
            throw new RuntimeException('A recipe cannot contain itself.');
        }
        $duplicate = (int)$this->db->scalar(
            'SELECT COUNT(*) FROM recipe_items
             WHERE recipe_version_id=? AND component_type=? AND component_id=?',
            [$versionId,$type,$componentId]
        );
        if ($duplicate > 0) {
            throw new RuntimeException('That component is already in this recipe version. Edit the existing row instead.');
        }
        $sort = (int)$this->db->scalar('SELECT COALESCE(MAX(sort_order),0)+10 FROM recipe_items WHERE recipe_version_id=?',[$versionId]);
        return $this->db->insert(
            'INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
             VALUES (?,?,?,?,?,?)',
            [$versionId,$type,$componentId,$quantity,$unit,$sort]
        );
    }

    public function updateComponent(int $versionId, int $itemId, float $quantity, string $unit): void
    {
        $this->requireDraft($versionId);
        if ($quantity <= 0) throw new RuntimeException('Recipe component quantity must be greater than zero.');
        if ((int)$this->db->scalar('SELECT COUNT(*) FROM units WHERE symbol=?',[$unit]) < 1) {
            throw new RuntimeException('Select a valid component unit.');
        }
        $updated = $this->db->exec(
            'UPDATE recipe_items SET quantity=?,unit=? WHERE id=? AND recipe_version_id=?',
            [$quantity,$unit,$itemId,$versionId]
        );
        if ($updated < 1 && (int)$this->db->scalar('SELECT COUNT(*) FROM recipe_items WHERE id=? AND recipe_version_id=?',[$itemId,$versionId]) < 1) {
            throw new RuntimeException('Recipe component not found.');
        }
    }

    public function removeComponent(int $versionId, int $itemId): void
    {
        $this->requireDraft($versionId);
        $this->db->exec('DELETE FROM recipe_items WHERE id=? AND recipe_version_id=?',[$itemId,$versionId]);
    }

    public function publish(int $versionId): array
    {
        return $this->db->transaction(function(Database $db) use ($versionId) {
            $version = $db->one('SELECT * FROM recipe_versions WHERE id=? FOR UPDATE',[$versionId]);
            if (!$version || $version['status'] !== 'draft') {
                throw new RuntimeException('Only draft recipe versions can be published.');
            }

            $componentCount = (int)$db->scalar(
                'SELECT COUNT(*) FROM recipe_items WHERE recipe_version_id=?',
                [$versionId]
            );
            if ($componentCount < 1) {
                throw new RuntimeException('Add at least one component before publishing a recipe.');
            }

            $this->validateStructure($versionId);
            $this->bindNestedRecipeVersions($versionId);
            $cost = $this->costing->recipeVersionCost($versionId);

            $db->exec(
                'UPDATE recipe_versions SET status="retired"
                 WHERE recipe_id=? AND status="published"',
                [$version['recipe_id']]
            );
            $db->exec(
                'UPDATE recipe_versions SET status="published",published_at=NOW() WHERE id=?',
                [$versionId]
            );
            return $cost;
        });
    }

    private function bindNestedRecipeVersions(int $versionId): void
    {
        $items=$this->db->all(
            'SELECT id,component_id
             FROM recipe_items
             WHERE recipe_version_id=? AND component_type="recipe"
             ORDER BY id',
            [$versionId]
        );
        foreach($items as $item){
            $nested=$this->db->one(
                'SELECT id
                 FROM recipe_versions
                 WHERE recipe_id=? AND status="published"
                 ORDER BY version_number DESC LIMIT 1',
                [$item['component_id']]
            );
            if(!$nested){
                $name=(string)($this->db->scalar(
                    'SELECT name FROM recipes WHERE id=?',
                    [$item['component_id']]
                )?:'Nested recipe');
                throw new RuntimeException(
                    $name.' must have a published version before this recipe can be published.'
                );
            }
            $this->db->exec(
                'UPDATE recipe_items
                 SET component_recipe_version_id=?
                 WHERE id=? AND recipe_version_id=?',
                [$nested['id'],$item['id'],$versionId]
            );
        }
    }

    private function validateStructure(int $versionId): void
    {
        $version = $this->db->one('SELECT * FROM recipe_versions WHERE id=?',[$versionId]);
        if (!$version) throw new RuntimeException('Recipe version not found.');

        $items = $this->db->all('SELECT * FROM recipe_items WHERE recipe_version_id=?',[$versionId]);
        foreach ($items as $item) {
            $type = $item['component_type'];
            $componentId = (int)$item['component_id'];
            $fromUnit = (string)$item['unit'];

            $from = $this->db->one('SELECT unit_type FROM units WHERE symbol=?',[$fromUnit]);
            if (!$from) throw new RuntimeException('Unknown unit on recipe component: '.$fromUnit);

            if ($type === 'ingredient') {
                $target = $this->db->one(
                    'SELECT i.name,u.unit_type
                     FROM ingredients i JOIN units u ON u.symbol=i.inventory_unit
                     WHERE i.id=?',
                    [$componentId]
                );
                if (!$target) throw new RuntimeException('Recipe references a missing ingredient.');
                if ($from['unit_type'] !== $target['unit_type']) {
                    throw new RuntimeException($target['name'].' uses an incompatible recipe unit.');
                }
                continue;
            }

            if ($type === 'packaging') {
                $target = $this->db->one(
                    'SELECT p.name,u.unit_type
                     FROM packaging_items p JOIN units u ON u.symbol=p.inventory_unit
                     WHERE p.id=?',
                    [$componentId]
                );
                if (!$target) throw new RuntimeException('Recipe references a missing packaging item.');
                if ($from['unit_type'] !== $target['unit_type']) {
                    throw new RuntimeException($target['name'].' uses an incompatible recipe unit.');
                }
                continue;
            }

            if ($type === 'recipe') {
                $nested = $this->db->one(
                    'SELECT r.name,rv.yield_unit,u.unit_type
                     FROM recipes r
                     JOIN recipe_versions rv ON rv.recipe_id=r.id AND rv.status="published"
                     JOIN units u ON u.symbol=rv.yield_unit
                     WHERE r.id=?
                     ORDER BY rv.version_number DESC LIMIT 1',
                    [$componentId]
                );
                if (!$nested) {
                    $name=(string)($this->db->scalar('SELECT name FROM recipes WHERE id=?',[$componentId])?:'Nested recipe');
                    throw new RuntimeException($name.' must have a published version before it can be used in a published recipe.');
                }
                if ($from['unit_type'] !== $nested['unit_type']) {
                    throw new RuntimeException($nested['name'].' uses an incompatible nested-recipe unit.');
                }
                continue;
            }

            throw new RuntimeException('Unsupported recipe component type.');
        }
    }

    private function requireDraft(int $versionId): array
    {
        $version = $this->db->one('SELECT * FROM recipe_versions WHERE id=?',[$versionId]);
        if (!$version || $version['status'] !== 'draft') {
            throw new RuntimeException('Only draft recipe versions can be edited.');
        }
        return $version;
    }
}
