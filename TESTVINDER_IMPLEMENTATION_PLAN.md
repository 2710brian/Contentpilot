# Testvinder Container Support - Implementation Plan

## Overview
When replacing any product (e.g., `product-1`, `product-2`, `product-3`), we need to also regenerate content for the associated `testvinder-{number}` container (e.g., `testvinder-1`, `testvinder-2`, `testvinder-3`). These testvinder containers highlight the "best in test" product for each position. Currently, the replacement flow only handles `product-{number}` containers and doesn't check for or process `testvinder-{number}` containers.

## Current Flow Analysis

### Step 1: Collect AI Prompts (`collectPromptsForProduct`)
- **Location**: `Generator::collectPromptsForProduct()`
- **Current Behavior**: 
  - Finds container with CSS ID `product-{number}`
  - Collects AI prompts from that container only
  - Stores container and context registry state in checkpoint
- **Missing**: No check for `testvinder-{number}` container matching the product number

### Step 2: Process AI Fields (`execute_replace_step_2`)
- **Location**: `ActionHandler::execute_replace_step_2()`
- **Current Behavior**:
  - Loads context registry state from checkpoint
  - Processes all AI fields in the context registry
  - Saves updated context registry state
- **Status**: Should work for testvinder if prompts are collected in Step 1

### Step 3: Apply Content and Save (`execute_replace_step_3`)
- **Location**: `ActionHandler::execute_replace_step_3()` → `Generator::applyContentForProduct()`
- **Current Behavior**:
  - Loads checkpoint data
  - Applies content to `product-{number}` container only
  - Replaces container in Elementor data
  - Saves Elementor data
- **Missing**: No application of content to `testvinder` container

## Implementation Plan

### Phase 1: Container Detection and Finding

#### 1.1 Add Helper Method to Find Testvinder Container
**File**: `Generator.php`
**Method**: `findTestvinderContainer($elementor_data, $product_number)`
**Purpose**: Find container with CSS ID matching `testvinder-{product_number}` pattern
**Logic**:
- Search recursively through Elementor data
- Check `_element_id`, `_css_id`, or `css_id` in settings
- Match pattern: `testvinder-{product_number}` (e.g., `testvinder-1`, `testvinder-2`, etc.)
- Use regex pattern: `/^testvinder-(\d+)$/i` to match numbered testvinder containers
- Return container data or null if not found

#### 1.2 Update Container Finding Logic
**File**: `Generator.php`
**Method**: `collectPromptsForProduct()`
**Changes**:
- After finding `product-{number}` container, also search for `testvinder-{number}` container
- The testvinder number should match the product number (e.g., `product-1` → `testvinder-1`)
- Log whether testvinder container was found
- Store both containers in checkpoint data

### Phase 2: Prompt Collection Enhancement

#### 2.1 Update Context Registry to Support Multiple Containers
**File**: `Generator.php`
**Method**: `collectPromptsForProduct()`
**Changes**:
- For ANY product number, check if corresponding testvinder container exists:
  - Collect prompts from `product-{number}` container (existing logic)
  - Also collect prompts from `testvinder-{number}` container (if it exists)
  - Use same context registry but ensure field IDs are unique
  - Prefix testvinder field IDs with "testvinder-{number}-" to avoid conflicts
- Store both containers in checkpoint:
  ```php
  'container' => $product_container,
  'testvinder_container' => $testvinder_container, // NEW (can be null)
  ```

#### 2.2 Update `collectAIPromptsInContainer` Method
**File**: `Generator.php`
**Method**: `collectAIPromptsInContainer()`
**Changes**:
- Add optional parameter `$field_id_prefix = ''` to prefix field IDs
- When collecting from testvinder, use prefix "testvinder-{product_number}-"
- This ensures field IDs are unique: `testvinder-1-field-123` vs `field-123`
- Example: For product-2, testvinder fields would be: `testvinder-2-field-456`

### Phase 3: AI Field Processing

#### 3.1 Update Step 2 to Handle Testvinder Fields
**File**: `ActionHandler.php`
**Method**: `execute_replace_step_2()`
**Changes**:
- No changes needed! The existing logic processes ALL fields in context registry
- Since we're prefixing testvinder field IDs, they'll be processed automatically
- The context registry already handles multiple containers' fields

### Phase 4: Content Application

#### 4.1 Update `applyContentForProduct` Method
**File**: `Generator.php`
**Method**: `applyContentForProduct()`
**Changes**:
- Check if testvinder container exists in checkpoint (for any product number)
- After applying content to `product-{number}` container:
  - Also apply content to `testvinder-{number}` container (if it exists)
  - Use same context registry state (with prefixed field IDs)
  - Replace testvinder container in Elementor data

#### 4.2 Update Container Replacement Logic
**File**: `Generator.php`
**Method**: `applyContentForProduct()`
**Changes**:
- After replacing `product-{number}` container:
  - If testvinder container exists, also replace it:
    ```php
    if (!empty($testvinder_container)) {
        $testvinder_css_id = 'testvinder-' . $product_number;
        $updated_data = $this->replaceContainerInElementorData(
            $updated_data, 
            $testvinder_css_id, 
            $testvinder_container
        );
    }
    ```

#### 4.3 Update Content Application to Testvinder
**File**: `Generator.php`
**Method**: `applyContentToContainer()`
**Changes**:
- Add support for testvinder CSS ID pattern (`testvinder-{number}`)
- When applying content to testvinder:
  - Filter context registry to only testvinder-{number}-prefixed fields
  - Apply content using same logic as product containers
  - Update product variables (testvinder-{number} should reference product-{number})

### Phase 5: Checkpoint Data Structure

#### 5.1 Update Checkpoint State Structure
**Files**: `ActionHandler.php`, `Generator.php`
**Changes**:
- Update checkpoint state to include testvinder container:
  ```php
  $checkpoint_state = [
      'post_id' => $post_id,
      'product_number' => $product_number,
      'elementor_data' => $elementor_data,
      'container' => $product_container,
      'testvinder_container' => $testvinder_container, // NEW
      'context_registry_state' => $context_registry_state,
      'settings' => $settings,
      'has_testvinder' => !empty($testvinder_container), // NEW: flag for easy checking
  ];
  ```

### Phase 6: Variable Replacement in Testvinder

#### 6.1 Ensure Testvinder References Product-1
**File**: `Generator.php`
**Method**: `updateProductVariablesInContainer()`
**Changes**:
- When updating testvinder container:
  - Ensure all `{product-X}` variables reference `product-1`
  - Testvinder should always show product-1 data
  - Use same variable replacement logic as product containers

## Detailed Implementation Steps

### Step 1: Add Testvinder Container Finder
```php
// In Generator.php
private function findTestvinderContainer($elementor_data, $product_number) {
    // Similar to findProductContainer but searches for "testvinder-{number}" CSS ID
    $testvinder_css_id = 'testvinder-' . $product_number;
    return $this->findProductContainer($elementor_data, $testvinder_css_id);
    // This reuses the existing findProductContainer logic with testvinder CSS ID
}
```

### Step 2: Update collectPromptsForProduct
```php
// In Generator.php::collectPromptsForProduct()
$css_id = 'product-' . $product_number;
$container = $this->findProductContainer($elementor_data, $css_id);

// NEW: Find testvinder container for ANY product number
$testvinder_container = $this->findTestvinderContainer($elementor_data, $product_number);
if ($testvinder_container) {
    Logger::info("Found testvinder-{$product_number} container for product-{$product_number} replacement");
}

// Collect prompts from product container
$this->collectAIPromptsInContainer($processed_container, $product_number);

// NEW: Collect prompts from testvinder container
if ($testvinder_container) {
    $processed_testvinder = $this->deepCopyArray($testvinder_container);
    $field_prefix = 'testvinder-' . $product_number . '-';
    $this->collectAIPromptsInContainer($processed_testvinder, $product_number, $field_prefix);
}

return [
    'elementor_data' => $elementor_data,
    'container' => $processed_container,
    'testvinder_container' => $processed_testvinder ?? null, // NEW
    'context_registry_state' => $context_registry_state,
    'field_count' => $field_count
];
```

### Step 3: Update collectAIPromptsInContainer
```php
// In Generator.php
private function collectAIPromptsInContainer(&$container, $target_product_number, $field_id_prefix = '') {
    // Existing logic...
    // When registering field in context registry:
    $field_id = $field_id_prefix . $original_field_id;
    // This ensures testvinder fields have "testvinder-" prefix
}
```

### Step 4: Update applyContentForProduct
```php
// In Generator.php::applyContentForProduct()
$css_id = 'product-' . $product_number;
$updated_data = $this->replaceContainerInElementorData($elementor_data, $css_id, $container);

// NEW: Also handle testvinder container (for ANY product number)
if (!empty($testvinder_container)) {
    $testvinder_css_id = 'testvinder-' . $product_number;
    $updated_data = $this->replaceContainerInElementorData(
        $updated_data, 
        $testvinder_css_id, 
        $testvinder_container
    );
    
    // Apply content to testvinder container
    $updated_data = $this->applyContentToContainer(
        $updated_data, 
        $testvinder_css_id, 
        $products, 
        $title, 
        $product_number // testvinder-{number} references product-{number}
    );
    
    // Update variables in testvinder container
    $updated_data = $this->updateProductVariablesInContainer(
        $updated_data, 
        $testvinder_css_id, 
        $products, 
        $title, 
        $product_number
    );
}
```

### Step 5: Update Checkpoint Saving
```php
// In ActionHandler.php::execute_replace_step_1()
$checkpoint_state = [
    'post_id' => $post_id,
    'product_number' => $product_number,
    'elementor_data' => $result['elementor_data'],
    'container' => $result['container'],
    'testvinder_container' => $result['testvinder_container'] ?? null, // NEW
    'context_registry_state' => $result['context_registry_state'],
    'settings' => $settings,
    'job_start_time' => $job_start_time,
    'has_testvinder' => !empty($result['testvinder_container']), // NEW
];
```

### Step 6: Update Checkpoint Loading
```php
// In ActionHandler.php::execute_replace_step_3()
$container = $checkpoint_data['container'] ?? [];
$testvinder_container = $checkpoint_data['testvinder_container'] ?? null; // NEW
$has_testvinder = $checkpoint_data['has_testvinder'] ?? false; // NEW

// Pass to applyContentForProduct
$result = $generator->applyContentForProduct(
    $post_id, 
    $product_number, 
    $elementor_data, 
    $container, 
    $context_registry_state,
    $testvinder_container // NEW parameter
);
```

## Edge Cases and Error Handling

### 1. Testvinder Container Not Found
- **Scenario**: Product-1 is being replaced but testvinder container doesn't exist
- **Handling**: Log warning, continue with product-1 replacement only
- **Code**: Check if testvinder_container is null before processing

### 2. Testvinder Container Has No AI Fields
- **Scenario**: Testvinder container exists but has no AI-enabled fields
- **Handling**: Still replace container and update variables, just skip AI processing
- **Code**: Check field_count for testvinder before processing

### 3. Testvinder Container References Wrong Product
- **Scenario**: Testvinder-2 container has `{product-1}` or other product references
- **Handling**: Force all variables to reference the matching product number
- **Code**: In `updateProductVariablesInContainer`, use the same product_number for testvinder-{number}
- **Example**: `testvinder-2` should reference `product-2`, not `product-1`

### 4. Multiple Testvinder Containers with Same Number
- **Scenario**: Multiple containers with "testvinder-1" CSS ID
- **Handling**: Use first one found, log warning
- **Code**: `findTestvinderContainer` returns first match (same as `findProductContainer`)

### 5. Testvinder Container Number Mismatch
- **Scenario**: `testvinder-1` exists but we're replacing `product-2`
- **Handling**: Only process testvinder container if number matches product number
- **Code**: `findTestvinderContainer($elementor_data, $product_number)` only finds matching number

### 6. Testvinder Container Structure Mismatch
- **Scenario**: Testvinder container structure is different from expected
- **Handling**: Validate structure, log error, skip if invalid
- **Code**: Reuse existing `findProductContainer` validation (same structure as product containers)

## Testing Checklist

### Unit Tests
- [ ] `findTestvinderContainer()` finds container correctly
- [ ] `findTestvinderContainer()` returns null when not found
- [ ] `collectAIPromptsInContainer()` prefixes testvinder field IDs correctly
- [ ] Context registry stores testvinder fields separately
- [ ] `applyContentToContainer()` works with testvinder CSS ID
- [ ] Variable replacement works for testvinder container

### Integration Tests
- [ ] Replace product-1 with testvinder present → both containers updated
- [ ] Replace product-1 without testvinder → only product-1 updated
- [ ] Replace product-2 → testvinder not touched
- [ ] Testvinder container content regenerated correctly
- [ ] Testvinder variables reference product-1 correctly
- [ ] Checkpoint saves and loads testvinder container correctly

### Manual Testing
- [ ] Replace product-1 in admin interface
- [ ] Verify testvinder container content is regenerated
- [ ] Verify testvinder shows new product-1 data
- [ ] Check logs for testvinder processing messages
- [ ] Verify Elementor data structure is correct after replacement

## Logging and Debugging

### New Log Messages
- `[AEBG] Found testvinder container for product-1 replacement`
- `[AEBG] Testvinder container not found (product-1 replacement)`
- `[AEBG] Collected X AI prompts from testvinder container`
- `[AEBG] Applying content to testvinder container`
- `[AEBG] Updated variables in testvinder container`

### Debug Information
- Log testvinder container structure when found
- Log testvinder field IDs being processed
- Log testvinder content before/after replacement

## Performance Considerations

### Optimization
- Only search for testvinder when `product_number === 1`
- Cache testvinder container in checkpoint (don't re-find in Step 3)
- Process testvinder fields in same batch as product-1 fields

### Memory
- Testvinder container stored in checkpoint (same as product container)
- Context registry handles both containers' fields efficiently
- No additional memory overhead beyond storing container data

## Backward Compatibility

### Existing Behavior
- Replacing products other than product-1: No change
- Replacing product-1 without testvinder: No change (just logs warning)
- All existing functionality remains intact

### New Behavior
- Replacing product-1 with testvinder: Both containers regenerated
- This is additive functionality, doesn't break existing code

## Rollout Plan

### Phase 1: Implementation
1. Add `findTestvinderContainer()` method
2. Update `collectPromptsForProduct()` to find and collect from testvinder
3. Update checkpoint structure to include testvinder container
4. Update `applyContentForProduct()` to apply content to testvinder

### Phase 2: Testing
1. Test with product-1 replacement (with testvinder)
2. Test with product-1 replacement (without testvinder)
3. Test with product-2 replacement (should not touch testvinder)
4. Verify all edge cases

### Phase 3: Deployment
1. Deploy to staging
2. Test with real articles
3. Monitor logs for errors
4. Deploy to production

## Success Criteria

✅ When replacing ANY product (product-1, product-2, product-3, etc.):
- Corresponding testvinder container is found (if it exists)
  - Replacing product-1 → looks for testvinder-1
  - Replacing product-2 → looks for testvinder-2
  - Replacing product-3 → looks for testvinder-3
  - etc.
- AI prompts are collected from testvinder container
- AI content is generated for testvinder fields
- Testvinder container content is updated with new product data
- All variables in testvinder reference the matching product number correctly
- Elementor data is saved correctly with both containers updated

✅ When testvinder container doesn't exist:
- No testvinder processing occurs
- Product container replacement works normally
- Existing behavior unchanged

✅ Error Handling:
- Graceful handling when testvinder not found
- Proper logging for debugging
- No errors in logs

## Files to Modify

1. **Generator.php**
   - Add `findTestvinderContainer($elementor_data, $product_number)` method
   - Update `collectPromptsForProduct()` method (find testvinder-{number} for any product)
   - Update `collectAIPromptsInContainer()` method (add prefix parameter)
   - Update `applyContentForProduct()` method (handle testvinder-{number} for any product)

2. **ActionHandler.php**
   - Update `execute_replace_step_1()` (save testvinder in checkpoint)
   - Update `execute_replace_step_3()` (load and pass testvinder to applyContentForProduct)

3. **No changes needed in:**
   - `ProductReplacementScheduler.php` (scheduling logic unchanged)
   - `ActionHandler::execute_replace_step_2()` (processes all fields automatically)

## Implementation Notes

### CSS ID Pattern
- Testvinder containers have CSS ID pattern: `testvinder-{number}` (e.g., `testvinder-1`, `testvinder-2`, `testvinder-3`)
- The number should match the product number (e.g., `testvinder-1` for `product-1`)
- Use regex pattern: `/^testvinder-(\d+)$/i` to match and extract the number
- Reuse existing `findProductContainer` logic with testvinder CSS ID

### Field ID Prefixing
- Product container fields: `field-123`, `icon-list-item-456`, etc.
- Testvinder fields: `testvinder-{number}-field-123`, `testvinder-{number}-icon-list-item-456`
- Examples:
  - Product-1 fields: `field-123`, `icon-list-item-456`
  - Testvinder-1 fields: `testvinder-1-field-123`, `testvinder-1-icon-list-item-456`
  - Testvinder-2 fields: `testvinder-2-field-789`, `testvinder-2-icon-list-item-012`
- This ensures no conflicts in context registry across all containers

### Variable References
- Testvinder-{number} should reference product-{number} (matching numbers)
- Example: `testvinder-2` should reference `product-2`, not `product-1`
- Even if testvinder-2 has `{product-1}` in prompts, force to product-2
- This ensures each testvinder container shows the correct product data for its position

